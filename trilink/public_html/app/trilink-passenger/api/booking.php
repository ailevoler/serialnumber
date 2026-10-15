<?php
// ============================================================
// TRILINK – api/booking.php
// Handles: book_ride, cancel_booking, get_status, rate_booking
// ============================================================

require_once __DIR__ . '/../includes/core.php';

// Must be logged in
if (!Auth::check()) {
    jsonResponse(false, 'Unauthorized.', null, 401);
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$userId = Auth::userId();

// ── BOOK RIDE ────────────────────────────────────────────────
if ($action === 'book_ride') {
    if (!Session::verifyCsrf($_POST[CSRF_TOKEN_NAME] ?? '')) {
        jsonResponse(false, 'Invalid CSRF token.');
    }

    $activeTrip = getActiveTrip($userId);
    if ($activeTrip) {
        $labels = ['ride' => 'ride', 'delivery' => 'delivery', 'order' => 'order'];
        $label = $labels[$activeTrip['kind']] ?? 'trip';
        jsonResponse(false, "May kasalukuyan ka nang aktibong {$label} (#{$activeTrip['code']}). Kumpletuhin o kanselahin muna ito bago mag-book ulit.", [
            'active_trip' => $activeTrip,
        ]);
    }

    $pickupAddr  = clean($_POST['pickup_address']  ?? '');
    $dropoffAddr = clean($_POST['dropoff_address'] ?? '');
    $vehicleType = $_POST['vehicle_type']    ?? 'tricycle';
    $payMethod   = $_POST['payment_method']  ?? 'wallet';
    $distanceKm  = (float)($_POST['distance_km'] ?? 1.0);
    $fareSent    = (float)($_POST['fare_total']   ?? 0.0);
    $pickupLat   = (float)($_POST['pickup_lat']   ?? 0);
    $pickupLng   = (float)($_POST['pickup_lng']   ?? 0);
    $dropoffLat  = (float)($_POST['dropoff_lat']  ?? 0);
    $dropoffLng  = (float)($_POST['dropoff_lng']  ?? 0);

    // Terminal or Not in Terminal (street)
    $bookingMode = ($_POST['booking_mode'] ?? 'street') === 'terminal' ? 'terminal' : 'street';
    $terminalId  = null;
    if ($bookingMode === 'terminal') {
        require_once __DIR__ . '/../../trilink-dispatch/lib/Dispatch.php';
        $terminal = Dispatch::terminal((int)($_POST['terminal_id'] ?? 0));
        if (!$terminal) jsonResponse(false, 'Pumili ng terminal kung saan ka sasakay.');
        // Pickup is the terminal itself; the terminal decides the vehicle type
        $terminalId  = (int)$terminal['id'];
        $pickupAddr  = clean($terminal['name'] . ' – ' . $terminal['address']);
        $pickupLat   = (float)$terminal['lat'];
        $pickupLng   = (float)$terminal['lng'];
        $vehicleType = $terminal['vehicle_type'];
    }

    // Validate
    if (strlen($pickupAddr) < 3)  jsonResponse(false, 'Please enter a valid pick-up location.');
    if (strlen($dropoffAddr) < 3) jsonResponse(false, 'Please enter a valid drop-off location.');
    if (!in_array($vehicleType, ['tricycle','e_trike','motorcycle'])) jsonResponse(false, 'Invalid vehicle type.');
    if (!in_array($payMethod,   ['wallet','cash','gcash']))           jsonResponse(false, 'Invalid payment method.');

    // Server-side fare calculation (trust server, not client)
    $fare = calculateFare($vehicleType, max($distanceKm, 0.5));
    $fareTotal = $fare['fare_total'];

    // Wallet balance check
    $user = Auth::user();
    if ($payMethod === 'wallet' && (float)$user['wallet_balance'] < $fareTotal) {
        jsonResponse(false, 'Insufficient wallet balance. Please top up first.');
    }

    // Generate booking
    $bookingCode = generateBookingCode();

    try {
        DB::run(
            "INSERT INTO tl_bookings
             (booking_code,user_id,vehicle_type,booking_mode,terminal_id,pickup_address,pickup_lat,pickup_lng,
              dropoff_address,dropoff_lat,dropoff_lng,distance_km,fare_base,fare_total,
              payment_method,status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [
                $bookingCode, $userId, $vehicleType, $bookingMode, $terminalId,
                $pickupAddr, $pickupLat ?: null, $pickupLng ?: null,
                $dropoffAddr, $dropoffLat ?: null, $dropoffLng ?: null,
                $distanceKm, $fare['base_fare'], $fareTotal,
                $payMethod, 'searching',
            ]
        );
        $bookingId = (int) DB::get()->lastInsertId();

        // Debit wallet immediately if wallet payment
        if ($payMethod === 'wallet') {
            $ok = walletDebit($userId, $fareTotal, 'ride_payment', 'booking', $bookingId,
                "Ride – {$dropoffAddr}");
            if (!$ok) {
                DB::run("UPDATE tl_bookings SET status='cancelled',cancel_reason='Insufficient wallet balance',cancelled_by='system' WHERE id=?", [$bookingId]);
                jsonResponse(false, 'Insufficient wallet balance.');
            }
        }

        jsonResponse(true, $bookingMode === 'terminal'
            ? 'Booking created! Hinihintay ang dispatcher ng terminal…'
            : 'Booking created! Searching for drivers…', [
            'booking_id'   => $bookingId,
            'booking_code' => $bookingCode,
            'fare_total'   => $fareTotal,
            'status'       => 'searching',
            'redirect'     => APP_URL . '/pages/tracking.php?code=' . $bookingCode,
        ]);

    } catch (Exception $e) {
        jsonResponse(false, 'Booking failed. Please try again.');
    }
}

// ── CANCEL BOOKING ───────────────────────────────────────────
if ($action === 'cancel_booking') {
    $bookingId  = (int)($_POST['booking_id'] ?? 0);
    $reason     = clean($_POST['reason'] ?? 'Cancelled by user');

    $booking = DB::row(
        "SELECT * FROM tl_bookings WHERE id=? AND user_id=?",
        [$bookingId, $userId]
    );
    if (!$booking) jsonResponse(false, 'Booking not found.');

    $cancellable = ['pending','searching','accepted'];
    if (!in_array($booking['status'], $cancellable)) {
        jsonResponse(false, 'This booking can no longer be cancelled.');
    }

    DB::run(
        "UPDATE tl_bookings SET status='cancelled',cancel_reason=?,cancelled_by='user' WHERE id=?",
        [$reason, $bookingId]
    );

    // Terminal ride cancelled after dispatch → driver gets their pila spot back
    if (($booking['booking_mode'] ?? 'street') === 'terminal' && $booking['status'] === 'accepted') {
        require_once __DIR__ . '/../../trilink-dispatch/lib/Dispatch.php';
        Dispatch::returnDriverToQueue($bookingId);
    }

    // Refund if wallet was charged
    if ($booking['payment_method'] === 'wallet') {
        walletCredit($userId, (float)$booking['fare_total'], 'refund', 'booking', $bookingId,
            "Refund – cancelled ride #{$booking['booking_code']}");
    }

    jsonResponse(true, 'Booking cancelled and refund issued.', ['booking_code' => $booking['booking_code']]);
}

// ── GET BOOKING STATUS ───────────────────────────────────────
if ($action === 'get_status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $code = clean($_GET['code'] ?? '');
    $booking = DB::row(
        "SELECT b.*, d.full_name AS driver_name, d.phone AS driver_phone,
                d.rating AS driver_rating, d.plate_number, d.vehicle_type AS driver_vehicle,
                d.current_lat, d.current_lng
         FROM tl_bookings b
         LEFT JOIN tl_drivers d ON b.driver_id = d.id
         WHERE b.booking_code=? AND b.user_id=?",
        [$code, $userId]
    );
    if (!$booking) jsonResponse(false, 'Booking not found.', null, 404);
    jsonResponse(true, 'OK', $booking);
}

// ── RATE BOOKING ─────────────────────────────────────────────
if ($action === 'rate_booking') {
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $rating    = (int)($_POST['rating'] ?? 0);
    $note      = clean($_POST['note'] ?? '');

    if ($rating < 1 || $rating > 5) jsonResponse(false, 'Rating must be between 1 and 5.');

    $booking = DB::row(
        "SELECT * FROM tl_bookings WHERE id=? AND user_id=? AND status='completed'",
        [$bookingId, $userId]
    );
    if (!$booking) jsonResponse(false, 'Booking not found or not yet completed.');
    if ($booking['rating']) jsonResponse(false, 'Already rated.');

    DB::run(
        "UPDATE tl_bookings SET rating=?,rating_note=? WHERE id=?",
        [$rating, $note, $bookingId]
    );

    // Update driver average rating
    if ($booking['driver_id']) {
        DB::run(
            "UPDATE tl_drivers SET rating = (SELECT AVG(rating) FROM tl_bookings WHERE driver_id=? AND rating IS NOT NULL), total_ratings = total_ratings + 1 WHERE id=?",
            [$booking['driver_id'], $booking['driver_id']]
        );
    }

    jsonResponse(true, 'Thank you for your rating!');
}

// ── GET MY BOOKINGS LIST ─────────────────────────────────────
if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $status = $_GET['status'] ?? 'all';
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = 10;
    $offset = ($page - 1) * $limit;

    $where = $status !== 'all' ? "AND b.status=?" : '';
    $params = $status !== 'all'
        ? [$userId, $status, $limit, $offset]
        : [$userId, $limit, $offset];

    $bookings = DB::all(
        "SELECT b.*, d.full_name AS driver_name, d.phone AS driver_phone, d.rating AS driver_rating
         FROM tl_bookings b
         LEFT JOIN tl_drivers d ON b.driver_id = d.id
         WHERE b.user_id=? {$where}
         ORDER BY b.created_at DESC LIMIT ? OFFSET ?",
        $params
    );

    $total = DB::val(
        "SELECT COUNT(*) FROM tl_bookings WHERE user_id=?" . ($status !== 'all' ? " AND status=?" : ""),
        $status !== 'all' ? [$userId, $status] : [$userId]
    );

    jsonResponse(true, 'OK', ['bookings' => $bookings, 'total' => $total, 'page' => $page]);
}

jsonResponse(false, 'Unknown action.', null, 400);
