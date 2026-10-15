<?php
// ============================================================
// TRILINK DISPATCH – lib/Dispatch.php
// Terminal pila (queue) + dispatching of terminal ride bookings.
// Shared by the passenger, driver and dispatcher apps.
//
// Booking modes:
//   street   – "Not in Terminal": nearby drivers see and accept it.
//   terminal – passenger is at a terminal: the booking is hidden from
//              street drivers and the terminal dispatcher assigns it to
//              the driver at the front of the pila.
// ============================================================
if (!defined('DB_HOST')) {
    require_once __DIR__ . '/../../trilink-driver/config.php';
}

class DispatchException extends Exception {}

class Dispatch {
    private static $pdo = null;

    // ── DB ──────────────────────────────────────────────────────
    public static function db(): PDO {
        if (self::$pdo === null) {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . (defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
            self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$pdo;
    }
    private static function run(string $sql, array $p = []): PDOStatement {
        $s = self::db()->prepare($sql);
        $s->execute($p);
        return $s;
    }
    private static function row(string $sql, array $p = []): ?array {
        $r = self::run($sql, $p)->fetch();
        return $r ?: null;
    }
    private static function all(string $sql, array $p = []): array {
        return self::run($sql, $p)->fetchAll();
    }
    private static function val(string $sql, array $p = []) {
        return self::run($sql, $p)->fetchColumn();
    }
    private static function setting(string $key, $default = null) {
        $v = self::val("SELECT `value` FROM tl_settings WHERE `key`=?", [$key]);
        return $v !== false ? $v : $default;
    }

    // ── Terminals ───────────────────────────────────────────────
    public static function activeTerminals(): array {
        return self::all(
            "SELECT t.id, t.terminal_code, t.name, t.address, t.lat, t.lng, t.vehicle_type,
                    (SELECT COUNT(*) FROM tl_terminal_queue q WHERE q.terminal_id=t.id AND q.status='waiting') AS queue_count
             FROM tl_terminals t WHERE t.is_active=1 ORDER BY t.name"
        );
    }
    public static function terminal(int $id, bool $activeOnly = true): ?array {
        return self::row("SELECT * FROM tl_terminals WHERE id=?" . ($activeOnly ? " AND is_active=1" : ''), [$id]);
    }

    // ── Pila (queue) ────────────────────────────────────────────
    /** Waiting drivers of a terminal, front of the line first. */
    public static function queue(int $terminalId): array {
        $rows = self::all(
            "SELECT q.id AS queue_id, q.driver_id, q.joined_at, q.added_by,
                    d.driver_code, d.full_name, d.phone, d.plate_number, d.vehicle_type,
                    d.wallet_balance, d.is_online
             FROM tl_terminal_queue q
             JOIN tl_drivers d ON d.id = q.driver_id
             WHERE q.terminal_id=? AND q.status='waiting'
             ORDER BY q.joined_at ASC, q.id ASC",
            [$terminalId]
        );
        foreach ($rows as $i => &$r) $r['position'] = $i + 1;
        return $rows;
    }

    /** The driver's current place in a pila, or null. */
    public static function driverQueue(int $driverId): ?array {
        $q = self::row(
            "SELECT q.id AS queue_id, q.terminal_id, q.joined_at, t.name AS terminal_name, t.address AS terminal_address
             FROM tl_terminal_queue q JOIN tl_terminals t ON t.id=q.terminal_id
             WHERE q.driver_id=? AND q.status='waiting' LIMIT 1",
            [$driverId]
        );
        if (!$q) return null;
        $q['position'] = 1 + (int)self::val(
            "SELECT COUNT(*) FROM tl_terminal_queue
             WHERE terminal_id=? AND status='waiting'
               AND (joined_at < ? OR (joined_at = ? AND id < ?))",
            [$q['terminal_id'], $q['joined_at'], $q['joined_at'], $q['queue_id']]
        );
        $q['waiting_count'] = (int)self::val("SELECT COUNT(*) FROM tl_terminal_queue WHERE terminal_id=? AND status='waiting'", [$q['terminal_id']]);
        return $q;
    }

    /**
     * Puts a driver at the end of a terminal's pila.
     * $byDispatcher skips the GPS distance check (the dispatcher sees the driver).
     */
    public static function join(int $terminalId, int $driverId, bool $byDispatcher = false): array {
        $t = self::terminal($terminalId);
        if (!$t) throw new DispatchException('Hindi active ang terminal na ito.');

        $d = self::row("SELECT id, full_name, status, current_lat, current_lng FROM tl_drivers WHERE id=?", [$driverId]);
        if (!$d) throw new DispatchException('Driver not found.');
        if ($d['status'] !== 'active') throw new DispatchException('Hindi active ang driver account na ito.');
        if (self::driverBusy($driverId)) throw new DispatchException('May aktibong trip pa ang driver. Tapusin muna bago pumila.');

        $current = self::driverQueue($driverId);
        if ($current) {
            if ((int)$current['terminal_id'] === $terminalId) throw new DispatchException("Nasa pila na (#{$current['position']}).");
            throw new DispatchException("Nasa pila pa ng {$current['terminal_name']}. Umalis muna doon.");
        }

        if (!$byDispatcher) {
            $radius = (int)self::setting('terminal_join_radius_m', 500);
            if ($radius > 0 && $t['lat'] !== null && $t['lng'] !== null) {
                if ($d['current_lat'] === null || $d['current_lng'] === null) {
                    throw new DispatchException('I-on ang GPS/location para makapila sa terminal.');
                }
                $m = self::distanceM((float)$d['current_lat'], (float)$d['current_lng'], (float)$t['lat'], (float)$t['lng']);
                if ($m > $radius) {
                    throw new DispatchException('Malayo ka pa sa terminal (' . number_format($m / 1000, 2) . ' km). Pumunta muna sa ' . $t['name'] . '.');
                }
            }
        }

        self::run(
            "INSERT INTO tl_terminal_queue (terminal_id, driver_id, status, added_by, joined_at) VALUES (?,?, 'waiting', ?, NOW())",
            [$terminalId, $driverId, $byDispatcher ? 'dispatcher' : 'driver']
        );
        return self::driverQueue($driverId);
    }

    // ── Driver work mode (Terminal / Not in Terminal) ───────────
    /** ['mode' => street|terminal, 'terminal_id', 'terminal_name', 'queue' => driverQueue()] */
    public static function driverMode(int $driverId): array {
        $d = self::row(
            "SELECT d.work_mode, d.work_terminal_id, t.name AS terminal_name, t.is_active
             FROM tl_drivers d LEFT JOIN tl_terminals t ON t.id=d.work_terminal_id WHERE d.id=?",
            [$driverId]
        );
        $terminal = $d && $d['work_mode'] === 'terminal' && $d['work_terminal_id'] && (int)$d['is_active'];
        return [
            'mode'          => $terminal ? 'terminal' : 'street',
            'terminal_id'   => $terminal ? (int)$d['work_terminal_id'] : null,
            'terminal_name' => $terminal ? $d['terminal_name'] : null,
            'queue'         => self::driverQueue($driverId),
        ];
    }

    /** True when the driver only takes dispatched terminal rides (no street requests). */
    public static function inTerminalMode(int $driverId): bool {
        return self::driverMode($driverId)['mode'] === 'terminal';
    }

    /**
     * Switches the driver between Terminal and Not in Terminal.
     *  street   → leaves any pila.
     *  terminal → joins the terminal's pila (GPS radius check unless the
     *             dispatcher adds them). A driver can switch terminals; a
     *             dispatcher cannot pull a driver out of another terminal.
     * The mode only changes if joining the pila succeeds.
     */
    public static function setMode(int $driverId, string $mode, int $terminalId = 0, bool $byDispatcher = false): array {
        if ($mode === 'street') {
            self::leave($driverId);
            self::run("UPDATE tl_drivers SET work_mode='street', work_terminal_id=NULL WHERE id=?", [$driverId]);
            return self::driverMode($driverId);
        }
        if ($mode !== 'terminal') throw new DispatchException('Invalid mode.');

        $current = self::driverQueue($driverId);
        if (!$current || (int)$current['terminal_id'] !== $terminalId) {
            if ($current && !$byDispatcher) {
                // Moving to another terminal: check the new one first, then give up the old spot
                if (!self::terminal($terminalId)) throw new DispatchException('Hindi active ang terminal na ito.');
                self::leave($driverId);
                try {
                    self::join($terminalId, $driverId, false);
                } catch (DispatchException $e) {
                    // Couldn't join the new terminal → keep the old spot
                    self::run("UPDATE tl_terminal_queue SET status='waiting', ended_at=NULL WHERE id=?", [$current['queue_id']]);
                    throw $e;
                }
            } else {
                self::join($terminalId, $driverId, $byDispatcher);
            }
        }
        self::run("UPDATE tl_drivers SET work_mode='terminal', work_terminal_id=? WHERE id=?", [$terminalId, $driverId]);
        return self::driverMode($driverId);
    }

    /** Removes the driver from any pila (driver left, went offline, or took another job). */
    public static function leave(int $driverId): void {
        self::run("UPDATE tl_terminal_queue SET status='left', ended_at=NOW() WHERE driver_id=? AND status='waiting'", [$driverId]);
    }

    public static function removeFromQueue(int $queueId, int $terminalId): void {
        $n = self::run(
            "UPDATE tl_terminal_queue SET status='left', ended_at=NOW() WHERE id=? AND terminal_id=? AND status='waiting'",
            [$queueId, $terminalId]
        )->rowCount();
        if (!$n) throw new DispatchException('Wala na sa pila ang driver na ito.');
    }

    public static function moveToEnd(int $queueId, int $terminalId): void {
        $n = self::run(
            "UPDATE tl_terminal_queue SET joined_at=NOW() WHERE id=? AND terminal_id=? AND status='waiting'",
            [$queueId, $terminalId]
        )->rowCount();
        if (!$n) throw new DispatchException('Wala na sa pila ang driver na ito.');
    }

    /** Finds a driver by driver code, phone or plate number (for the dispatcher's "add to pila"). */
    public static function findDriver(string $q): ?array {
        $q = trim($q);
        if ($q === '') return null;
        $phone = preg_replace('/\D/', '', $q);
        if (strlen($phone) === 10 && $phone[0] === '9') $phone = '0' . $phone;
        if (strlen($phone) === 12 && substr($phone, 0, 2) === '63') $phone = '0' . substr($phone, 2);
        return self::row(
            "SELECT id, driver_code, full_name, phone, plate_number, vehicle_type, status
             FROM tl_drivers
             WHERE driver_code=? OR (phone<>'' AND phone=?) OR REPLACE(UPPER(plate_number),' ','')=REPLACE(UPPER(?),' ','')
             LIMIT 1",
            [strtoupper($q), $phone, $q]
        );
    }

    // ── Terminal bookings ───────────────────────────────────────
    public static function pendingBookings(int $terminalId): array {
        return self::all(
            "SELECT b.id, b.booking_code, b.pickup_address, b.dropoff_address, b.fare_total, b.distance_km,
                    b.payment_method, b.vehicle_type, b.created_at,
                    TIMESTAMPDIFF(SECOND, b.created_at, NOW()) AS waiting_secs,
                    u.full_name AS passenger_name, u.phone AS passenger_phone
             FROM tl_bookings b JOIN tl_users u ON u.id=b.user_id
             WHERE b.terminal_id=? AND b.booking_mode='terminal' AND b.status='searching' AND b.driver_id IS NULL
             ORDER BY b.created_at ASC",
            [$terminalId]
        );
    }

    public static function recentDispatches(int $terminalId, int $limit = 15): array {
        return self::all(
            "SELECT b.booking_code, b.dropoff_address, b.fare_total, b.status, b.dispatched_at,
                    d.full_name AS driver_name, d.plate_number, u.full_name AS passenger_name
             FROM tl_bookings b
             JOIN tl_drivers d ON d.id=b.driver_id
             JOIN tl_users u ON u.id=b.user_id
             WHERE b.terminal_id=? AND b.dispatched_at IS NOT NULL AND DATE(b.dispatched_at)=CURDATE()
             ORDER BY b.dispatched_at DESC LIMIT " . (int)$limit,
            [$terminalId]
        );
    }

    /**
     * Assigns a terminal booking to a driver in the pila. If $queueId is 0
     * the driver at the front of the line gets it.
     * Same rules as a street accept: one job at a time, minimum wallet
     * balance, and the per-trip commission is deducted.
     */
    public static function dispatch(int $bookingId, int $queueId, int $terminalId, int $dispatcherId): array {
        $pdo = self::db();
        $pdo->beginTransaction();
        try {
            $b = self::row(
                "SELECT * FROM tl_bookings WHERE id=? AND terminal_id=? AND booking_mode='terminal' FOR UPDATE",
                [$bookingId, $terminalId]
            );
            if (!$b) throw new DispatchException('Booking not found.');
            if ($b['status'] !== 'searching' || $b['driver_id']) throw new DispatchException('Na-assign o na-cancel na ang booking na ito.');

            $q = $queueId
                ? self::row("SELECT * FROM tl_terminal_queue WHERE id=? AND terminal_id=? AND status='waiting' FOR UPDATE", [$queueId, $terminalId])
                : self::row("SELECT * FROM tl_terminal_queue WHERE terminal_id=? AND status='waiting' ORDER BY joined_at ASC, id ASC LIMIT 1 FOR UPDATE", [$terminalId]);
            if (!$q) throw new DispatchException($queueId ? 'Wala na sa pila ang driver na ito.' : 'Walang driver sa pila.');
            $driverId = (int)$q['driver_id'];

            $d = self::row("SELECT id, full_name, plate_number, status, wallet_balance FROM tl_drivers WHERE id=? FOR UPDATE", [$driverId]);
            if (!$d || $d['status'] !== 'active') throw new DispatchException('Hindi active ang driver account.');
            if (self::driverBusy($driverId)) throw new DispatchException("May aktibong trip pa si {$d['full_name']}. Alisin muna sa pila.");

            $minBalance = (float)self::setting('driver_min_wallet_balance', 20.00);
            $commission = round((float)self::setting('driver_commission_per_trip', 5.00), 2);
            $balance    = round((float)$d['wallet_balance'], 2);
            if ($balance < $minBalance || $balance < $commission) {
                throw new DispatchException("Kulang ang wallet ni {$d['full_name']} (₱" . number_format($balance, 2) . "). Kailangan ng minimum ₱" . number_format($minBalance, 2) . ' — mag-Top Up muna siya.');
            }

            self::run(
                "UPDATE tl_bookings SET driver_id=?, status='accepted', accepted_at=NOW(), dispatcher_id=?, dispatched_at=NOW() WHERE id=?",
                [$driverId, $dispatcherId, $bookingId]
            );
            self::run(
                "UPDATE tl_terminal_queue SET status='dispatched', booking_id=?, ended_at=NOW() WHERE id=?",
                [$bookingId, $q['id']]
            );

            if ($commission > 0) {
                $after = round($balance - $commission, 2);
                self::run("UPDATE tl_drivers SET wallet_balance=? WHERE id=?", [$after, $driverId]);
                self::run(
                    "INSERT INTO tl_driver_wallet_transactions
                     (txn_code, driver_id, type, amount, direction, balance_before, balance_after, reference_type, reference_id, description, status)
                     VALUES (?,?, 'commission', ?, 'debit', ?, ?, 'booking', ?, ?, 'completed')",
                    ['DTXN-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3))), $driverId, $commission, $balance, $after,
                     $bookingId, "Platform commission – booking #{$b['booking_code']}"]
                );
            }

            $pdo->commit();
            return ['booking_code' => $b['booking_code'], 'driver_name' => $d['full_name'], 'plate_number' => $d['plate_number']];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Lets nearby street drivers take a terminal booking (e.g. pila is empty). */
    public static function releaseToStreet(int $bookingId, int $terminalId): void {
        $n = self::run(
            "UPDATE tl_bookings SET booking_mode='street'
             WHERE id=? AND terminal_id=? AND booking_mode='terminal' AND status='searching' AND driver_id IS NULL",
            [$bookingId, $terminalId]
        )->rowCount();
        if (!$n) throw new DispatchException('Hindi na pwedeng i-release ang booking na ito.');
    }

    /** Dispatcher cancels a terminal booking that has no driver yet; refunds wallet payments. */
    public static function cancelBooking(int $bookingId, int $terminalId, string $reason): void {
        $pdo = self::db();
        $pdo->beginTransaction();
        try {
            $b = self::row(
                "SELECT * FROM tl_bookings WHERE id=? AND terminal_id=? AND booking_mode='terminal' AND status='searching' AND driver_id IS NULL FOR UPDATE",
                [$bookingId, $terminalId]
            );
            if (!$b) throw new DispatchException('Hindi na pwedeng i-cancel ang booking na ito.');
            self::run(
                "UPDATE tl_bookings SET status='cancelled', cancelled_by='system', cancel_reason=? WHERE id=?",
                [mb_substr('Dispatcher: ' . ($reason !== '' ? $reason : 'Walang available na tricycle'), 0, 250), $bookingId]
            );
            if ($b['payment_method'] === 'wallet' && (float)$b['fare_total'] > 0) {
                $u = self::row("SELECT wallet_balance FROM tl_users WHERE id=? FOR UPDATE", [$b['user_id']]);
                $before = round((float)$u['wallet_balance'], 2);
                $after  = round($before + (float)$b['fare_total'], 2);
                self::run("UPDATE tl_users SET wallet_balance=? WHERE id=?", [$after, $b['user_id']]);
                self::run(
                    "INSERT INTO tl_wallet_transactions
                     (txn_code, user_id, type, amount, direction, balance_before, balance_after, reference_type, reference_id, description, status)
                     VALUES (?,?, 'refund', ?, 'credit', ?, ?, 'booking', ?, ?, 'completed')",
                    ['TXN-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3))), $b['user_id'], (float)$b['fare_total'],
                     $before, $after, $bookingId, "Refund – cancelled ride #{$b['booking_code']}"]
                );
            }
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * When a dispatched terminal ride is cancelled before pickup, the driver
     * gets their old spot back at the front of the pila.
     */
    public static function returnDriverToQueue(int $bookingId): void {
        self::run(
            "UPDATE tl_terminal_queue q
             SET q.status='waiting', q.booking_id=NULL, q.ended_at=NULL
             WHERE q.booking_id=? AND q.status='dispatched'
               AND NOT EXISTS (SELECT 1 FROM (SELECT driver_id FROM tl_terminal_queue WHERE status='waiting') w WHERE w.driver_id=q.driver_id)",
            [$bookingId]
        );
    }

    // ── Helpers ─────────────────────────────────────────────────
    /** True if the driver has a ride, delivery or merchant order in progress. */
    public static function driverBusy(int $driverId): bool {
        return (bool)self::val(
            "SELECT (EXISTS(SELECT 1 FROM tl_bookings WHERE driver_id=? AND status NOT IN ('completed','cancelled'))
                  OR EXISTS(SELECT 1 FROM tl_deliveries WHERE driver_id=? AND status NOT IN ('delivered','cancelled'))
                  OR EXISTS(SELECT 1 FROM tl_merchant_orders WHERE driver_id=? AND status NOT IN ('completed','cancelled')))",
            [$driverId, $driverId, $driverId]
        );
    }

    public static function distanceM(float $lat1, float $lng1, float $lat2, float $lng2): float {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
