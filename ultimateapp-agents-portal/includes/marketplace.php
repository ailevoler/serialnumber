<?php
declare(strict_types=1);

/**
 * Marketplace for merchants: UGo (activities and tours), ULocal (local services) and UEat (restaurants and menus).
 *
 * - Listings: merchants add them in the Merchant Portal (Admin approves when "review listings" is on); Admin can add
 *   listings for any merchant or run them as Ultimate App (request only, pay on the day).
 * - UGo / ULocal bookings: date, time, guests; paid with Credits to the merchant (like a QR payment) or on the day.
 *   The merchant confirms or declines (full refund); the customer can cancel for free until the listing's cut-off.
 * - UEat: a merchant's restaurant profile + menu items; customers order like Grab Food, pay with Credits, and follow the
 *   kitchen (accepted, preparing, on the way / ready, completed). Declined or cancelled orders are refunded in full.
 */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/ledger.php';
require_once __DIR__ . '/p2m.php';
require_once __DIR__ . '/agents.php';

const MK_SERVICES = ['ugo', 'ulocal', 'ueat'];

function mk_services(): array
{
    return [
        'ugo' => ['code' => 'UGo', 'label' => 'UGo', 'title' => 'Activities & tours', 'noun' => 'activity', 'page' => 'ugo.php',
            'categories' => ['Land Tour', 'Island Hopping', 'Water Sports', 'Diving & Snorkeling', 'Sunset Sail', 'Adventure', 'Food Tour', 'Packages'],
            'units' => ['person', 'group'], 'default_unit' => 'person'],
        'ulocal' => ['code' => 'ULocal', 'label' => 'ULocal', 'title' => 'Local services', 'noun' => 'service', 'page' => 'ulocal.php',
            'categories' => ['Massage & Spa', 'Hair & Beauty', 'Photography', 'Laundry', 'Tour Guide', 'Cleaning', 'Repairs', 'Babysitting', 'Errands & Delivery', 'Other services'],
            'units' => ['session', 'hour', 'person', 'load', 'day', 'item'], 'default_unit' => 'session'],
        'ueat' => ['code' => 'UEat', 'label' => 'UEat', 'title' => 'Food delivery', 'noun' => 'menu item', 'page' => 'ueat.php',
            'categories' => [], 'units' => ['item'], 'default_unit' => 'item'],
    ];
}

function mk_cuisines(): array
{
    return ['Filipino', 'Seafood', 'Cafe & Breakfast', 'Burgers & Fast Food', 'Pizza & Pasta', 'Asian', 'Grill & BBQ', 'Desserts & Drinks', 'Healthy', 'Bar & Pub'];
}

function mk_units(): array
{
    return ['person' => 'per person', 'group' => 'per group', 'hour' => 'per hour', 'session' => 'per session', 'item' => 'each', 'load' => 'per load', 'day' => 'per day'];
}

function mk_booking_statuses(): array
{
    return ['pending' => 'Waiting for confirmation', 'confirmed' => 'Confirmed', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'declined' => 'Declined'];
}

function mk_order_statuses(): array
{
    return ['pending' => 'Waiting for the restaurant', 'accepted' => 'Accepted', 'preparing' => 'Preparing your food', 'on_the_way' => 'On the way',
        'ready' => 'Ready for pick-up', 'completed' => 'Completed', 'declined' => 'Declined by the restaurant', 'cancelled' => 'Cancelled', 'sample' => 'Sample'];
}

function mk_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try { $GLOBALS['pdo']->query('SELECT id FROM mk_listings LIMIT 0'); $GLOBALS['pdo']->query('SELECT merchant_id FROM ueat_orders LIMIT 0'); $ready = true; } catch (Throwable) { $ready = false; }
    }
    return $ready;
}

function mk_credits_checkout(): bool
{
    return (string) setting('market.credits_checkout', '1') === '1';
}

function mk_review_listings(): bool
{
    return (string) setting('market.review_listings', '1') === '1';
}

function mk_money(int $centavos): string
{
    return '₱' . number_format($centavos / 100, $centavos % 100 ? 2 : 0);
}

/** "₱850 / person" */
function mk_price_label(array $l): string
{
    return mk_money((int) $l['price_centavos']) . ($l['price_unit'] === 'item' ? '' : ' ' . (mk_units()[$l['price_unit']] ?? ''));
}

function mk_duration_label(?int $minutes): string
{
    if (!$minutes) return '';
    if ($minutes < 60) return $minutes . ' min';
    $h = intdiv($minutes, 60); $m = $minutes % 60;
    return $h . ' hr' . ($h > 1 ? 's' : '') . ($m ? ' ' . $m . ' min' : '');
}

/* ---------------------------------------------------------------- photos */

function mk_storage_dir(): string
{
    $dir = dirname(__DIR__) . '/storage/market';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    return $dir;
}

/** Saves an uploaded photo ($_FILES entry) as a clean JPEG (max 1280 px). Returns the file name, or null when none sent. */
function mk_store_photo(?array $file, string $label = 'The photo'): ?string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name']) && PHP_SAPI !== 'cli') throw new InvalidArgumentException($label . ' could not be uploaded. Please try again.');
    if ((int) $file['size'] > 8 * 1024 * 1024) throw new InvalidArgumentException($label . ' is too large (8 MB max).');
    $bytes = (string) file_get_contents((string) $file['tmp_name']);
    $info = @getimagesizefromstring($bytes);
    if (!$info || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) throw new InvalidArgumentException($label . ' must be a JPG, PNG or WebP photo.');
    if (min($info[0], $info[1]) < 240) throw new InvalidArgumentException($label . ' is too small. Use a photo at least 240 px wide.');
    if (!function_exists('imagecreatefromstring') || !($img = @imagecreatefromstring($bytes))) throw new InvalidArgumentException($label . ' could not be read.');
    $w = imagesx($img); $h = imagesy($img); $max = 1280;
    if (max($w, $h) > $max) {
        $k = $max / max($w, $h);
        $dst = imagecreatetruecolor((int) round($w * $k), (int) round($h * $k));
        imagecopyresampled($dst, $img, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
        imagedestroy($img); $img = $dst;
    } elseif ($info['mime'] !== 'image/jpeg') {
        $dst = imagecreatetruecolor($w, $h); imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopy($dst, $img, 0, 0, 0, 0, $w, $h); imagedestroy($img); $img = $dst;
    }
    $name = bin2hex(random_bytes(16)) . '.jpg';
    $ok = imagejpeg($img, mk_storage_dir() . '/' . $name, 82);
    imagedestroy($img);
    if (!$ok) throw new RuntimeException('Could not save the photo.');
    return $name;
}

function mk_photo_url(?string $name, string $base = ''): string
{
    return $name && preg_match('/^[a-f0-9]{32}\.jpg$/D', $name) ? $base . 'market-photo.php?f=' . $name : '';
}

function mk_delete_photo(?string $name): void
{
    if ($name && preg_match('/^[a-f0-9]{32}\.jpg$/D', $name)) @unlink(mk_storage_dir() . '/' . $name);
}

/* ---------------------------------------------------------------- listings */

/** Cleans a listing form. Returns the columns to save. */
function mk_validate_listing(array $in, string $service): array
{
    if (!in_array($service, MK_SERVICES, true)) throw new InvalidArgumentException('Choose UGo, ULocal or UEat.');
    $cfg = mk_services()[$service];
    $str = static fn(string $k, int $max) => mb_substr(trim(preg_replace('/[ \t]+/u', ' ', (string) ($in[$k] ?? '')) ?? ''), 0, $max);
    $title = $str('title', 120);
    if (mb_strlen($title) < 3) throw new InvalidArgumentException('Enter a name (at least 3 characters).');
    $category = $str('category', 40);
    if ($service === 'ueat') { if ($category === '') $category = 'Menu'; }
    elseif (!in_array($category, $cfg['categories'], true)) throw new InvalidArgumentException('Choose a category.');
    $price = trim((string) ($in['price'] ?? ''));
    if (!preg_match('/^\d{1,6}(?:\.\d{1,2})?$/D', $price) || (float) $price < 1) throw new InvalidArgumentException('Enter the price in pesos (at least ₱1).');
    $unit = (string) ($in['price_unit'] ?? $cfg['default_unit']);
    if (!in_array($unit, $cfg['units'], true)) $unit = $cfg['default_unit'];
    $int = static function (string $k, int $min, int $max, ?int $default) use ($in): ?int {
        $v = trim((string) ($in[$k] ?? ''));
        if ($v === '') return $default;
        if (!preg_match('/^\d+$/D', $v) || (int) $v < $min || (int) $v > $max) throw new InvalidArgumentException('Check the number in "' . str_replace('_', ' ', $k) . '" (' . $min . ' to ' . $max . ').');
        return (int) $v;
    };
    $slots = null;
    if ($service === 'ugo' && trim((string) ($in['time_slots'] ?? '')) !== '') {
        $parts = array_unique(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) $in['time_slots']) ?: [])));
        foreach ($parts as $t) if (!preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/D', $t)) throw new InvalidArgumentException('Start times must look like 08:00, 13:30.');
        $parts = array_map(static fn($t) => str_pad($t, 5, '0', STR_PAD_LEFT), $parts);
        sort($parts);
        if (count($parts) > 12) throw new InvalidArgumentException('Up to 12 start times.');
        $slots = implode(',', $parts);
    }
    $minQty = $int('min_qty', 1, 50, 1); $maxQty = $int('max_qty', 1, 100, $service === 'ueat' ? 10 : 20);
    if ($minQty > $maxQty) throw new InvalidArgumentException('Minimum guests cannot be more than the maximum.');
    $lat = trim((string) ($in['latitude'] ?? '')); $lng = trim((string) ($in['longitude'] ?? ''));
    $hasPin = $lat !== '' && $lng !== '' && is_numeric($lat) && is_numeric($lng) && (float) $lat >= 4 && (float) $lat <= 22 && (float) $lng >= 116 && (float) $lng <= 127;
    $badge = $str('badge', 30);
    return [
        'service' => $service, 'category' => $category, 'title' => $title,
        'summary' => $str('summary', 200) ?: null,
        'description' => mb_substr(trim((string) ($in['description'] ?? '')), 0, 3000) ?: null,
        'includes_text' => mb_substr(trim(str_replace("\r", '', (string) ($in['includes_text'] ?? ''))), 0, 500) ?: null,
        'price_centavos' => (int) round((float) $price * 100), 'price_unit' => $unit,
        'duration_minutes' => $service === 'ueat' ? null : $int('duration_minutes', 5, 1440, null),
        'time_slots' => $slots,
        'capacity' => $service === 'ugo' ? $int('capacity', 1, 500, null) : null,
        'min_qty' => $minQty, 'max_qty' => $maxQty,
        'location' => $service === 'ueat' ? null : ($str('location', 160) ?: null),
        'meeting_point' => $service === 'ugo' ? ($str('meeting_point', 200) ?: null) : null,
        'latitude' => $hasPin ? round((float) $lat, 7) : null, 'longitude' => $hasPin ? round((float) $lng, 7) : null,
        'cancel_hours' => $service === 'ueat' ? 0 : $int('cancel_hours', 0, 168, 24),
        'instant_confirm' => !empty($in['instant_confirm']) && $service !== 'ueat' ? 1 : 0,
        'badge' => $badge !== '' ? $badge : null,
        'sort_order' => $int('sort_order', 0, 9999, 100),
    ];
}

/**
 * Creates or updates a listing. $merchantId: owner (null = Ultimate App). $adminId: set when Admin saves (Admin chooses
 * the status); a merchant's new or edited listing goes to review when "review listings" is on.
 */
function mk_listing_save(PDO $pdo, ?int $id, array $data, ?int $merchantId, ?int $adminId, ?string $photo, ?string $status = null): int
{
    if ($adminId === null) {
        $status = mk_review_listings() ? 'pending' : 'active';
    } elseif (!in_array($status, ['pending', 'active', 'paused', 'rejected'], true)) {
        $status = 'active';
    }
    if ($data['service'] !== 'ueat' && $merchantId === null) $data['instant_confirm'] = 0;
    $cols = $data + ['merchant_id' => $merchantId, 'status' => $status];
    if ($adminId !== null && isset($data['featured'])) $cols['featured'] = (int) $data['featured'];
    if ($photo !== null) $cols['photo'] = $photo;
    if ($id) {
        $old = mk_listing($pdo, $id);
        if (!$old) throw new InvalidArgumentException('Listing not found.');
        if ($adminId === null && (int) $old['merchant_id'] !== $merchantId) throw new InvalidArgumentException('Listing not found.');
        // A paused listing stays paused after a merchant edit unless it needs review.
        if ($adminId === null && $old['status'] === 'paused' && !mk_review_listings()) $cols['status'] = 'paused';
        $cols['review_note'] = $adminId === null ? null : $old['review_note'];
        $set = implode(', ', array_map(static fn($c) => "$c = ?", array_keys($cols)));
        $pdo->prepare("UPDATE mk_listings SET $set WHERE id = ?")->execute([...array_values($cols), $id]);
        if ($photo !== null && $old['photo'] && $old['photo'] !== $photo) mk_delete_photo($old['photo']);
        return $id;
    }
    $cols['created_by_admin'] = $adminId;
    $pdo->prepare('INSERT INTO mk_listings (' . implode(', ', array_keys($cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_values($cols));
    return (int) $pdo->lastInsertId();
}

function mk_listing(PDO $pdo, int $id): ?array
{
    $s = $pdo->prepare('SELECT l.*, m.business_name, m.status merchant_status, m.mobile merchant_mobile FROM mk_listings l LEFT JOIN merchants m ON m.id = l.merchant_id WHERE l.id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

/** Can customers see and book it now? */
function mk_listing_live(array $l): bool
{
    return $l['status'] === 'active' && ($l['merchant_id'] === null || $l['merchant_status'] === 'approved');
}

/** Live UGo / ULocal listings, optionally by category and search words. */
function mk_browse(PDO $pdo, string $service, string $category = '', string $q = ''): array
{
    $sql = "SELECT l.*, m.business_name FROM mk_listings l LEFT JOIN merchants m ON m.id = l.merchant_id
        WHERE l.service = ? AND l.status = 'active' AND (l.merchant_id IS NULL OR m.status = 'approved')";
    $args = [$service];
    if ($category !== '') { $sql .= ' AND l.category = ?'; $args[] = $category; }
    if ($q !== '') {
        foreach (array_slice(preg_split('/\s+/', mb_strtolower($q)) ?: [], 0, 5) as $w) {
            $sql .= " AND LOWER(CONCAT_WS(' ', l.title, l.summary, l.category, l.location, m.business_name)) LIKE ?";
            $args[] = '%' . str_replace(['%', '_'], ['\%', '\_'], $w) . '%';
        }
    }
    $s = $pdo->prepare($sql . ' ORDER BY l.featured DESC, l.sort_order, l.id DESC LIMIT 200');
    $s->execute($args);
    return $s->fetchAll();
}

/** Admin decision on a listing (approve, reject with a note, pause). */
function mk_listing_review(PDO $pdo, int $id, string $status, string $note): void
{
    if (!in_array($status, ['active', 'rejected', 'paused', 'pending'], true)) throw new InvalidArgumentException('Unknown status.');
    if ($status === 'rejected' && mb_strlen(trim($note)) < 5) throw new InvalidArgumentException('Tell the merchant what to fix (at least 5 characters).');
    $pdo->prepare('UPDATE mk_listings SET status = ?, review_note = ? WHERE id = ?')->execute([$status, trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null, $id]);
}

/** Merchant pause / resume of an approved listing. */
function mk_listing_toggle(PDO $pdo, int $merchantId, int $id, bool $pause): void
{
    $s = $pdo->prepare("UPDATE mk_listings SET status = ? WHERE id = ? AND merchant_id = ? AND status = ?");
    $s->execute([$pause ? 'paused' : 'active', $id, $merchantId, $pause ? 'active' : 'paused']);
    if ($s->rowCount() !== 1) throw new InvalidArgumentException($pause ? 'Only a live listing can be paused.' : 'Only a paused listing can be resumed.');
}

function mk_listing_delete(PDO $pdo, int $id, ?int $merchantId): void
{
    $l = mk_listing($pdo, $id);
    if (!$l || ($merchantId !== null && (int) $l['merchant_id'] !== $merchantId)) throw new InvalidArgumentException('Listing not found.');
    $used = $pdo->prepare('SELECT COUNT(*) FROM mk_bookings WHERE listing_id = ?'); $used->execute([$id]);
    if ((int) $used->fetchColumn() > 0) {
        // Keep it for the booking history; hide it from customers.
        $pdo->prepare("UPDATE mk_listings SET status = 'paused' WHERE id = ?")->execute([$id]);
        return;
    }
    $pdo->prepare('DELETE FROM mk_listings WHERE id = ?')->execute([$id]);
    mk_delete_photo($l['photo']);
}

/* ---------------------------------------------------------------- UEat restaurants */

function mk_store(PDO $pdo, int $merchantId): ?array
{
    $s = $pdo->prepare('SELECT s.*, m.business_name, m.status merchant_status, m.address, m.barangay, m.latitude, m.longitude, m.mobile merchant_mobile FROM mk_stores s JOIN merchants m ON m.id = s.merchant_id WHERE s.merchant_id = ?');
    $s->execute([$merchantId]);
    return $s->fetch() ?: null;
}

function mk_store_save(PDO $pdo, int $merchantId, array $in, ?string $cover, ?int $adminId, ?string $status = null): void
{
    $name = mb_substr(trim((string) ($in['display_name'] ?? '')), 0, 120);
    if (mb_strlen($name) < 2) throw new InvalidArgumentException('Enter the restaurant name customers will see.');
    $cuisine = (string) ($in['cuisine'] ?? '');
    if (!in_array($cuisine, mk_cuisines(), true)) throw new InvalidArgumentException('Choose the cuisine.');
    $peso = static function (string $k, int $max) use ($in): int {
        $v = trim((string) ($in[$k] ?? '0'));
        if ($v === '') $v = '0';
        if (!preg_match('/^\d{1,5}(?:\.\d{1,2})?$/D', $v) || (float) $v > $max) throw new InvalidArgumentException('Check the peso amounts (0 to ' . $max . ').');
        return (int) round((float) $v * 100);
    };
    $time = static function (string $k, string $default) use ($in): string {
        $v = trim((string) ($in[$k] ?? ''));
        if ($v === '') return $default;
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', substr($v, 0, 5))) throw new InvalidArgumentException('Opening hours must look like 08:00.');
        return substr($v, 0, 5) . ':00';
    };
    $prep = (int) ($in['prep_minutes'] ?? 25);
    if ($prep < 5 || $prep > 180) throw new InvalidArgumentException('Preparation time must be 5 to 180 minutes.');
    $delivery = !empty($in['offers_delivery']); $pickup = !empty($in['offers_pickup']);
    if (!$delivery && !$pickup) throw new InvalidArgumentException('Offer delivery, pick-up or both.');
    $cols = [
        'display_name' => $name, 'cuisine' => $cuisine, 'tagline' => mb_substr(trim((string) ($in['tagline'] ?? '')), 0, 160) ?: null,
        'prep_minutes' => $prep, 'delivery_fee_centavos' => $peso('delivery_fee', 1000), 'min_order_centavos' => $peso('min_order', 5000),
        'offers_delivery' => $delivery ? 1 : 0, 'offers_pickup' => $pickup ? 1 : 0,
        'opens_at' => $time('opens_at', '08:00'), 'closes_at' => $time('closes_at', '22:00'),
        'promo_text' => mb_substr(trim((string) ($in['promo_text'] ?? '')), 0, 80) ?: null,
    ];
    if ($cover !== null) $cols['cover_photo'] = $cover;
    $old = mk_store($pdo, $merchantId);
    if ($adminId !== null && in_array($status, ['pending', 'active', 'paused', 'rejected'], true)) $cols['status'] = $status;
    elseif (!$old) $cols['status'] = mk_review_listings() ? 'pending' : 'active';
    if ($old) {
        $set = implode(', ', array_map(static fn($c) => "$c = ?", array_keys($cols)));
        $pdo->prepare("UPDATE mk_stores SET $set WHERE merchant_id = ?")->execute([...array_values($cols), $merchantId]);
        if ($cover !== null && $old['cover_photo'] && $old['cover_photo'] !== $cover) mk_delete_photo($old['cover_photo']);
    } else {
        $cols['merchant_id'] = $merchantId;
        $pdo->prepare('INSERT INTO mk_stores (' . implode(', ', array_keys($cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_values($cols));
    }
}

/** Is the restaurant taking orders right now (switch on and within opening hours)? */
function mk_store_open(array $s, ?DateTimeImmutable $now = null): bool
{
    if (!(int) $s['is_open'] || $s['status'] !== 'active') return false;
    $t = ($now ?? new DateTimeImmutable())->format('H:i:s');
    $o = (string) $s['opens_at']; $c = (string) $s['closes_at'];
    return $o <= $c ? ($t >= $o && $t < $c) : ($t >= $o || $t < $c); // overnight hours, e.g. 18:00 to 02:00
}

/** Live restaurants (approved merchant, approved profile, at least one live menu item), open ones first. */
function mk_restaurants(PDO $pdo, string $cuisine = '', string $q = ''): array
{
    $sql = "SELECT s.*, m.business_name, m.barangay, m.address, m.latitude, m.longitude,
            (SELECT COUNT(*) FROM mk_listings l WHERE l.merchant_id = s.merchant_id AND l.service = 'ueat' AND l.status = 'active') items,
            (SELECT MIN(price_centavos) FROM mk_listings l WHERE l.merchant_id = s.merchant_id AND l.service = 'ueat' AND l.status = 'active') from_price,
            (SELECT COUNT(*) FROM ueat_orders o WHERE o.merchant_id = s.merchant_id AND o.status = 'completed') orders_done
        FROM mk_stores s JOIN merchants m ON m.id = s.merchant_id WHERE s.status = 'active' AND m.status = 'approved'";
    $args = [];
    if ($cuisine !== '') { $sql .= ' AND s.cuisine = ?'; $args[] = $cuisine; }
    if ($q !== '') {
        $sql .= " AND (LOWER(CONCAT_WS(' ', s.display_name, s.cuisine, s.tagline)) LIKE ? OR EXISTS (SELECT 1 FROM mk_listings l WHERE l.merchant_id = s.merchant_id AND l.service = 'ueat' AND l.status = 'active' AND LOWER(CONCAT_WS(' ', l.title, l.summary, l.category)) LIKE ?))";
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_strtolower(trim($q))) . '%';
        array_push($args, $like, $like);
    }
    $s = $pdo->prepare($sql . ' HAVING items > 0 ORDER BY s.display_name LIMIT 200');
    $s->execute($args);
    $rows = $s->fetchAll();
    foreach ($rows as &$r) $r['open_now'] = mk_store_open($r);
    unset($r);
    usort($rows, static fn($a, $b) => [$b['open_now'], (int) $b['orders_done']] <=> [$a['open_now'], (int) $a['orders_done']]);
    return $rows;
}

/** Live menu of a restaurant grouped by section: ['Best sellers' => [items], ...]. */
function mk_menu(PDO $pdo, int $merchantId, bool $liveOnly = true): array
{
    $s = $pdo->prepare("SELECT * FROM mk_listings WHERE merchant_id = ? AND service = 'ueat'" . ($liveOnly ? " AND status = 'active'" : '') . ' ORDER BY sort_order, id');
    $s->execute([$merchantId]);
    $menu = [];
    foreach ($s->fetchAll() as $item) $menu[$item['category']][] = $item;
    return $menu;
}

/* ---------------------------------------------------------------- UGo / ULocal bookings */

function mk_booking_starts(array $b): DateTimeImmutable
{
    return new DateTimeImmutable($b['booking_date'] . ' ' . ($b['booking_time'] ?: '08:00:00'));
}

/** Guests already booked for a listing on a date and time (not cancelled or declined). */
function mk_slot_taken(PDO $pdo, int $listingId, string $date, ?string $time): int
{
    $s = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM mk_bookings WHERE listing_id = ? AND booking_date = ? AND " . ($time === null ? 'booking_time IS NULL' : 'booking_time = ?') . " AND status IN ('pending','confirmed','completed')");
    $s->execute($time === null ? [$listingId, $date] : [$listingId, $date, $time]);
    return (int) $s->fetchColumn();
}

/** Price breakdown shown before booking and charged when paying with Credits (QR payment charges apply). */
function mk_booking_quote(array $l, int $qty, string $payMethod): array
{
    $amount = (int) $l['price_centavos'] * ($l['price_unit'] === 'group' ? 1 : $qty);
    if ($payMethod === 'credits') {
        $q = p2m_quote($amount, 'credits');
        return ['amount' => $amount, 'fee' => $q['bearer'] === 'customer' ? $q['fee'] : 0, 'total' => $q['customer_debit']];
    }
    return ['amount' => $amount, 'fee' => 0, 'total' => $amount];
}

/** Can this listing be paid with Credits now (merchant listing, approved merchant, Credits checkout on)? */
function mk_can_pay_credits(array $l): bool
{
    return mk_credits_checkout() && $l['merchant_id'] !== null && ($l['merchant_status'] ?? '') === 'approved';
}

/**
 * Books a UGo / ULocal listing. $in: date (Y-m-d), time (H:i), quantity, pay_method (credits|on_site), contact_mobile,
 * address, notes. Returns the booking reference. Same $requestKey = same booking (double taps are safe).
 */
function mk_book(PDO $pdo, int $userId, int $listingId, array $in, string $requestKey, ?DateTimeImmutable $now = null): string
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $requestKey)) throw new InvalidArgumentException('Please reload the page and try again.');
    $now ??= new DateTimeImmutable();
    $l = mk_listing($pdo, $listingId);
    if (!$l || $l['service'] === 'ueat' || !mk_listing_live($l)) throw new InvalidArgumentException('This listing is not available right now.');
    $date = (string) ($in['date'] ?? '');
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) throw new InvalidArgumentException('Choose a date.');
    if ($date < $now->format('Y-m-d') || $date > $now->modify('+180 days')->format('Y-m-d')) throw new InvalidArgumentException('Choose a date from today up to 6 months ahead.');
    $time = trim((string) ($in['time'] ?? ''));
    $slots = $l['time_slots'] ? explode(',', $l['time_slots']) : [];
    if ($slots) {
        if (!in_array($time, $slots, true)) throw new InvalidArgumentException('Choose one of the start times.');
    } elseif ($time !== '') {
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $time)) throw new InvalidArgumentException('Choose a valid time.');
    } else {
        throw new InvalidArgumentException('Choose a time.');
    }
    $start = new DateTimeImmutable($date . ' ' . $time . ':00');
    if ($start < $now->modify('+1 hour')) throw new InvalidArgumentException('Book at least 1 hour ahead. Choose a later time.');
    $qty = filter_var($in['quantity'] ?? null, FILTER_VALIDATE_INT);
    if ($qty === false || $qty < (int) $l['min_qty'] || $qty > (int) $l['max_qty']) throw new InvalidArgumentException('Choose ' . $l['min_qty'] . ' to ' . $l['max_qty'] . ($l['service'] === 'ugo' ? ' guests.' : '.'));
    $pay = (string) ($in['pay_method'] ?? 'credits');
    if (!in_array($pay, ['credits', 'on_site'], true)) $pay = 'credits';
    if ($pay === 'credits' && !mk_can_pay_credits($l)) $pay = 'on_site';
    $mobile = preg_replace('/\D/', '', (string) ($in['contact_mobile'] ?? '')) ?? '';
    if (!preg_match('/^(09\d{9}|639\d{9})$/D', $mobile)) throw new InvalidArgumentException('Enter your mobile number (09XXXXXXXXX) so the provider can reach you.');
    $address = mb_substr(trim((string) ($in['address'] ?? '')), 0, 255);
    if ($l['service'] === 'ulocal' && mb_strlen($address) < 5) throw new InvalidArgumentException('Enter where the service will be done (hotel, room or address).');
    $notes = mb_substr(trim((string) ($in['notes'] ?? '')), 0, 500);
    $quote = mk_booking_quote($l, (int) $qty, $pay);

    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('SELECT reference FROM mk_bookings WHERE user_id = ? AND request_key = ?');
        $s->execute([$userId, $requestKey]);
        if ($prior = $s->fetchColumn()) { $pdo->commit(); return (string) $prior; }
        $pdo->prepare('SELECT id FROM mk_listings WHERE id = ? FOR UPDATE')->execute([$listingId]); // one booking at a time per listing (capacity)
        if ($l['capacity']) {
            $left = (int) $l['capacity'] - mk_slot_taken($pdo, $listingId, $date, $time . ':00');
            if ($qty > $left) throw new InvalidArgumentException($left > 0 ? 'Only ' . $left . ' slot(s) left at ' . $time . ' on that day.' : 'That time is fully booked. Choose another time or date.');
        }
        $reference = ($l['service'] === 'ugo' ? 'UG-' : 'UL-') . strtoupper(bin2hex(random_bytes(6)));
        $status = (int) $l['instant_confirm'] ? 'confirmed' : 'pending';
        $payRef = null;
        if ($pay === 'credits') {
            $payRef = p2m_pay_tx($pdo, $userId, (int) $l['merchant_id'], 'credits', $quote['amount'], hash('sha256', 'mk-booking:' . $requestKey), mb_substr(strtoupper($l['service'] === 'ugo' ? 'UGo' : 'ULocal') . ' ' . $reference . ' ' . $l['title'], 0, 140));
        }
        $pdo->prepare('INSERT INTO mk_bookings (reference, request_key, user_id, listing_id, merchant_id, service, title, booking_date, booking_time, quantity, unit_centavos, total_centavos, contact_mobile, address, notes, pay_method, payment_status, payment_ref, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$reference, $requestKey, $userId, $listingId, $l['merchant_id'], $l['service'], $l['title'], $date, $time . ':00', $qty, $l['price_centavos'], $quote['total'],
                $mobile, $address ?: null, $notes ?: null, $pay, $pay === 'credits' ? 'paid' : 'unpaid', $payRef, $status]);
        $bookingId = (int) $pdo->lastInsertId();
        $code = $l['service'] === 'ugo' ? 'UGo' : 'ULocal';
        agent_record_commission($pdo, $userId, $code, 'mk_booking', $bookingId, $reference, $quote['amount'], false);
        $pdo->commit();
        return $reference;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function mk_booking(PDO $pdo, string $reference): ?array
{
    $s = $pdo->prepare('SELECT b.*, l.cancel_hours, l.photo, l.location, l.meeting_point, l.duration_minutes, l.price_unit, l.category, m.business_name, m.mobile merchant_mobile, u.full_name customer_name, u.mobile customer_mobile
        FROM mk_bookings b JOIN mk_listings l ON l.id = b.listing_id LEFT JOIN merchants m ON m.id = b.merchant_id JOIN users u ON u.id = b.user_id WHERE b.reference = ?');
    $s->execute([$reference]);
    return $s->fetch() ?: null;
}

/** Can the customer still cancel for free? */
function mk_booking_cancellable(array $b, ?DateTimeImmutable $now = null): bool
{
    if ($b['status'] === 'pending') return true;
    if ($b['status'] !== 'confirmed') return false;
    $now ??= new DateTimeImmutable();
    return $now <= mk_booking_starts($b)->modify('-' . (int) $b['cancel_hours'] . ' hours');
}

/**
 * Changes a booking. $by: 'customer' (cancel), 'merchant' (confirm, decline, complete), 'admin' (any).
 * Declines and cancellations of paid bookings are refunded in full.
 */
function mk_booking_update(PDO $pdo, string $reference, string $action, string $by, int $actorId, string $note = '', ?DateTimeImmutable $now = null): array
{
    $now ??= new DateTimeImmutable();
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('SELECT b.*, l.cancel_hours FROM mk_bookings b JOIN mk_listings l ON l.id = b.listing_id WHERE b.reference = ? FOR UPDATE');
        $s->execute([$reference]);
        $b = $s->fetch();
        if (!$b) throw new InvalidArgumentException('Booking not found.');
        if ($by === 'customer' && (int) $b['user_id'] !== $actorId) throw new InvalidArgumentException('Booking not found.');
        if ($by === 'merchant' && (int) $b['merchant_id'] !== $actorId) throw new InvalidArgumentException('Booking not found.');
        $allowed = [
            'customer' => ['cancel'],
            'merchant' => ['confirm', 'decline', 'complete'],
            'admin' => ['confirm', 'decline', 'complete', 'cancel'],
        ][$by] ?? [];
        if (!in_array($action, $allowed, true)) throw new InvalidArgumentException('That is not allowed.');
        $from = $b['status'];
        $to = ['confirm' => 'confirmed', 'decline' => 'declined', 'complete' => 'completed', 'cancel' => 'cancelled'][$action];
        $ok = match ($action) {
            'confirm' => $from === 'pending',
            'decline' => $from === 'pending' || ($by === 'admin' && $from === 'confirmed'),
            'complete' => $from === 'confirmed' && $b['booking_date'] <= $now->format('Y-m-d'),
            'cancel' => $by === 'admin' ? in_array($from, ['pending', 'confirmed'], true) : mk_booking_cancellable($b, $now),
        };
        if (!$ok) {
            throw new InvalidArgumentException(match ($action) {
                'complete' => $from === 'confirmed' ? 'You can mark it completed on or after the booking date.' : 'Only a confirmed booking can be completed.',
                'cancel' => $from === 'confirmed' ? 'Free cancellation ended ' . (int) $b['cancel_hours'] . ' hours before the start. Please contact the provider.' : 'This booking can no longer be cancelled.',
                default => 'This booking is already ' . mk_booking_statuses()[$from] . '.',
            });
        }
        if ($action === 'decline' && $by !== 'admin' && mb_strlen(trim($note)) < 3) throw new InvalidArgumentException('Tell the customer why (e.g. fully booked, weather).');
        if (in_array($to, ['declined', 'cancelled'], true) && $b['payment_status'] === 'paid' && $b['payment_ref']) {
            $p = $pdo->prepare('SELECT id FROM merchant_payments WHERE reference = ?'); $p->execute([$b['payment_ref']]);
            p2m_refund_tx($pdo, (int) $p->fetchColumn(), $by === 'admin' ? $actorId : null);
            $pdo->prepare("UPDATE mk_bookings SET payment_status = 'refunded' WHERE id = ?")->execute([$b['id']]);
        }
        if ($to === 'completed' && $b['pay_method'] === 'on_site') $pdo->prepare("UPDATE mk_bookings SET payment_status = 'paid' WHERE id = ?")->execute([$b['id']]);
        $pdo->prepare('UPDATE mk_bookings SET status = ?, status_note = ? WHERE id = ?')->execute([$to, trim($note) !== '' ? mb_substr(trim($note), 0, 255) : $b['status_note'], $b['id']]);
        if ($to === 'completed') agent_approve_source($pdo, 'mk_booking', (int) $b['id'], 'Booking completed');
        if (in_array($to, ['declined', 'cancelled'], true)) agent_reverse_source($pdo, 'mk_booking', (int) $b['id'], 'Booking ' . $to);
        $pdo->commit();
        return ['reference' => $reference, 'status' => $to, 'refunded' => in_array($to, ['declined', 'cancelled'], true) && $b['payment_status'] === 'paid'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/* ---------------------------------------------------------------- UEat cart and orders */

/** Session cart: ['merchant' => id, 'items' => [listing id => qty]]. Drops items that are no longer live. */
function mk_cart(PDO $pdo, int $userId): array
{
    $c = $_SESSION['mk_cart'][$userId] ?? null;
    if (!is_array($c) || empty($c['merchant']) || !is_array($c['items'] ?? null)) return ['merchant' => null, 'items' => []];
    $ids = array_map('intval', array_keys($c['items']));
    if (!$ids) return ['merchant' => null, 'items' => []];
    $s = $pdo->prepare("SELECT id FROM mk_listings WHERE service = 'ueat' AND status = 'active' AND merchant_id = ? AND id IN (" . implode(',', $ids) . ')');
    $s->execute([(int) $c['merchant']]);
    $live = array_flip(array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN)));
    $items = [];
    foreach ($c['items'] as $id => $q) if (isset($live[(int) $id]) && is_int($q) && $q >= 1 && $q <= 20) $items[(int) $id] = $q;
    return $items ? ['merchant' => (int) $c['merchant'], 'items' => $items] : ['merchant' => null, 'items' => []];
}

/** Adds (+n) or sets a quantity. Returns the new cart. Switching restaurant needs $replace = true. */
function mk_cart_set(PDO $pdo, int $userId, int $listingId, int $qty, bool $add, bool $replace = false): array
{
    $l = mk_listing($pdo, $listingId);
    if (!$l || $l['service'] !== 'ueat' || !mk_listing_live($l)) throw new InvalidArgumentException('This item is not available right now.');
    $cart = mk_cart($pdo, $userId);
    if ($cart['merchant'] && $cart['merchant'] !== (int) $l['merchant_id']) {
        if (!$replace) throw new LogicException('other-restaurant');
        $cart = ['merchant' => null, 'items' => []];
    }
    $new = $add ? ($cart['items'][$listingId] ?? 0) + $qty : $qty;
    if ($new < 0 || $new > min(20, (int) $l['max_qty'])) throw new InvalidArgumentException('You can order up to ' . min(20, (int) $l['max_qty']) . ' of this item.');
    if ($new === 0) unset($cart['items'][$listingId]); else $cart['items'][$listingId] = $new;
    if (array_sum($cart['items']) > 50) throw new InvalidArgumentException('Up to 50 items per order.');
    $cart['merchant'] = $cart['items'] ? (int) $l['merchant_id'] : null;
    $_SESSION['mk_cart'][$userId] = $cart;
    return $cart;
}

/** Cart lines and totals. $fulfillment: delivery | pickup. */
function mk_cart_summary(PDO $pdo, array $cart, string $fulfillment = 'delivery'): array
{
    $out = ['store' => null, 'lines' => [], 'count' => 0, 'subtotal' => 0, 'delivery_fee' => 0, 'fee' => 0, 'total' => 0];
    if (!$cart['merchant'] || !$cart['items']) return $out;
    $out['store'] = mk_store($pdo, $cart['merchant']);
    $s = $pdo->prepare("SELECT * FROM mk_listings WHERE id IN (" . implode(',', array_map('intval', array_keys($cart['items']))) . ')');
    $s->execute();
    foreach ($s->fetchAll() as $l) {
        $q = $cart['items'][(int) $l['id']];
        $out['lines'][] = ['id' => (int) $l['id'], 'name' => $l['title'], 'photo' => $l['photo'], 'qty' => $q, 'unit' => (int) $l['price_centavos'], 'total' => (int) $l['price_centavos'] * $q];
        $out['count'] += $q; $out['subtotal'] += (int) $l['price_centavos'] * $q;
    }
    if ($out['store'] && $fulfillment === 'delivery') $out['delivery_fee'] = (int) $out['store']['delivery_fee_centavos'];
    $amount = $out['subtotal'] + $out['delivery_fee'];
    if ($amount > 0) {
        try { $q = p2m_quote($amount, 'credits'); $out['fee'] = $q['bearer'] === 'customer' ? $q['fee'] : 0; } catch (InvalidArgumentException) { $out['fee'] = 0; }
    }
    $out['total'] = $amount + $out['fee'];
    return $out;
}

/** Places a UEat order and pays the restaurant with Credits. Returns the order reference. */
function mk_place_order(PDO $pdo, int $userId, array $cart, array $in, string $requestKey, ?DateTimeImmutable $now = null): string
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $requestKey)) throw new InvalidArgumentException('Please reload the page and try again.');
    if (!mk_credits_checkout()) throw new InvalidArgumentException('Ordering with Credits is turned off right now.');
    $s = $pdo->prepare('SELECT reference FROM ueat_orders WHERE user_id = ? AND request_key = ?');
    $s->execute([$userId, $requestKey]);
    if ($prior = $s->fetchColumn()) return (string) $prior;
    $fulfillment = (string) ($in['fulfillment'] ?? 'delivery');
    if (!in_array($fulfillment, ['delivery', 'pickup'], true)) $fulfillment = 'delivery';
    $sum = mk_cart_summary($pdo, $cart, $fulfillment);
    $store = $sum['store'];
    if (!$store || !$sum['lines']) throw new InvalidArgumentException('Your basket is empty.');
    if ($store['merchant_status'] !== 'approved' || !mk_store_open($store, $now)) throw new InvalidArgumentException($store['display_name'] . ' is closed right now. Please try again later.');
    if ($fulfillment === 'delivery' && !(int) $store['offers_delivery']) throw new InvalidArgumentException('This restaurant offers pick-up only.');
    if ($fulfillment === 'pickup' && !(int) $store['offers_pickup']) throw new InvalidArgumentException('This restaurant offers delivery only.');
    if ($sum['subtotal'] < (int) $store['min_order_centavos']) throw new InvalidArgumentException('Minimum order is ' . mk_money((int) $store['min_order_centavos']) . '. Add a little more.');
    $address = mb_substr(trim((string) ($in['address'] ?? '')), 0, 500);
    if ($fulfillment === 'delivery' && mb_strlen($address) < 5) throw new InvalidArgumentException('Enter the delivery address (hotel, room, street or landmark).');
    $mobile = preg_replace('/\D/', '', (string) ($in['contact_mobile'] ?? '')) ?? '';
    if (!preg_match('/^(09\d{9}|639\d{9})$/D', $mobile)) throw new InvalidArgumentException('Enter your mobile number (09XXXXXXXXX) for the rider.');
    $notes = mb_substr(trim((string) ($in['notes'] ?? '')), 0, 500);
    $merchantId = (int) $store['merchant_id'];
    $amount = $sum['subtotal'] + $sum['delivery_fee'];

    $pdo->beginTransaction();
    try {
        $reference = 'UE-' . strtoupper(bin2hex(random_bytes(6)));
        $payRef = p2m_pay_tx($pdo, $userId, $merchantId, 'credits', $amount, hash('sha256', 'ueat:' . $requestKey), mb_substr('UEat ' . $reference . ' ' . $store['display_name'], 0, 140));
        $pdo->prepare("INSERT INTO ueat_orders (reference, request_key, user_id, restaurant_id, merchant_id, fulfillment, delivery_address, contact_mobile, notes, total_credits, subtotal_centavos, delivery_fee_centavos, payment_status, payment_ref, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'paid', ?, 'pending')")
            ->execute([$reference, $requestKey, $userId, 'm' . $merchantId, $merchantId, $fulfillment, $fulfillment === 'delivery' ? $address : null, $mobile, $notes ?: null,
                centavos_to_decimal($sum['total']), $sum['subtotal'], $sum['delivery_fee'], $payRef]);
        $orderId = (int) $pdo->lastInsertId();
        $line = $pdo->prepare('INSERT INTO ueat_order_items (order_id, menu_item_id, item_name, quantity, unit_credits, line_credits) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($sum['lines'] as $l) $line->execute([$orderId, (string) $l['id'], mb_substr($l['name'], 0, 120), $l['qty'], centavos_to_decimal($l['unit']), centavos_to_decimal($l['total'])]);
        agent_record_commission($pdo, $userId, 'UEat', 'ueat', $orderId, $reference, $sum['subtotal'], false);
        $pdo->commit();
        unset($_SESSION['mk_cart'][$userId]);
        return $reference;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Next kitchen steps the restaurant can take from a status. */
function mk_order_next(array $o): array
{
    return match ($o['status']) {
        'pending' => ['accepted' => 'Accept order', 'declined' => 'Decline'],
        'accepted' => ['preparing' => 'Start preparing'],
        'preparing' => $o['fulfillment'] === 'delivery' ? ['on_the_way' => 'Out for delivery'] : ['ready' => 'Ready for pick-up'],
        'on_the_way', 'ready' => ['completed' => $o['fulfillment'] === 'delivery' ? 'Delivered' : 'Picked up'],
        default => [],
    };
}

/**
 * Moves a UEat order. $by: 'merchant' (kitchen steps, decline), 'customer' (cancel while waiting), 'admin' (cancel any open order).
 * Declined and cancelled orders are refunded in full.
 */
function mk_order_update(PDO $pdo, string $reference, string $to, string $by, int $actorId, string $note = ''): array
{
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('SELECT * FROM ueat_orders WHERE reference = ? FOR UPDATE');
        $s->execute([$reference]);
        $o = $s->fetch();
        if (!$o || !$o['merchant_id']) throw new InvalidArgumentException('Order not found.');
        if ($by === 'merchant' && (int) $o['merchant_id'] !== $actorId) throw new InvalidArgumentException('Order not found.');
        if ($by === 'customer' && (int) $o['user_id'] !== $actorId) throw new InvalidArgumentException('Order not found.');
        $ok = match ($by) {
            'merchant' => isset(mk_order_next($o)[$to]),
            'customer' => $to === 'cancelled' && $o['status'] === 'pending',
            'admin' => in_array($to, ['cancelled', 'completed'], true) && !in_array($o['status'], ['completed', 'cancelled', 'declined'], true),
            default => false,
        };
        if (!$ok) throw new InvalidArgumentException($by === 'customer' ? 'The restaurant already accepted your order. Please contact them to change it.' : ($by === 'merchant' && mk_order_next($o) ? 'Do the steps in order. Next: ' . implode(' or ', mk_order_next($o)) . '.' : 'This order is already ' . strtolower(mk_order_statuses()[$o['status']] ?? $o['status']) . '.'));
        if ($to === 'declined' && mb_strlen(trim($note)) < 3) throw new InvalidArgumentException('Tell the customer why (e.g. sold out, closing soon).');
        if (in_array($to, ['declined', 'cancelled'], true) && $o['payment_status'] === 'paid' && $o['payment_ref']) {
            $p = $pdo->prepare('SELECT id FROM merchant_payments WHERE reference = ?'); $p->execute([$o['payment_ref']]);
            p2m_refund_tx($pdo, (int) $p->fetchColumn(), $by === 'admin' ? $actorId : null);
            $pdo->prepare("UPDATE ueat_orders SET payment_status = 'refunded' WHERE id = ?")->execute([$o['id']]);
        }
        $pdo->prepare('UPDATE ueat_orders SET status = ?, status_note = COALESCE(?, status_note) WHERE id = ?')->execute([$to, trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null, $o['id']]);
        if ($to === 'completed') agent_approve_source($pdo, 'ueat', (int) $o['id'], 'Order completed');
        if (in_array($to, ['declined', 'cancelled'], true)) agent_reverse_source($pdo, 'ueat', (int) $o['id'], 'Order ' . $to);
        $pdo->commit();
        return ['reference' => $reference, 'status' => $to];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Counts for the merchant's dashboard badge: new bookings + new orders waiting for them. */
function mk_merchant_waiting(PDO $pdo, int $merchantId): array
{
    if (!mk_ready()) return ['bookings' => 0, 'orders' => 0];
    $b = $pdo->prepare("SELECT COUNT(*) FROM mk_bookings WHERE merchant_id = ? AND status = 'pending'"); $b->execute([$merchantId]);
    $o = $pdo->prepare("SELECT COUNT(*) FROM ueat_orders WHERE merchant_id = ? AND status IN ('pending','accepted','preparing','on_the_way','ready')"); $o->execute([$merchantId]);
    return ['bookings' => (int) $b->fetchColumn(), 'orders' => (int) $o->fetchColumn()];
}

/* ---------------------------------------------------------------- pictures */

/** Built-in picture (Boracay photo sheet, 5 × 4) used when a listing or restaurant has no photo yet. */
function mk_fallback_index(string $service, string $category): int
{
    $map = [
        'Land Tour' => 5, 'Island Hopping' => 15, 'Water Sports' => 16, 'Diving & Snorkeling' => 18, 'Sunset Sail' => 17, 'Adventure' => 16, 'Food Tour' => 11, 'Packages' => 0,
        'Massage & Spa' => 6, 'Hair & Beauty' => 9, 'Photography' => 7, 'Laundry' => 8, 'Tour Guide' => 5, 'Cleaning' => 2, 'Repairs' => 3, 'Babysitting' => 0, 'Errands & Delivery' => 19, 'Other services' => 1,
        'Filipino' => 10, 'Seafood' => 11, 'Cafe & Breakfast' => 4, 'Burgers & Fast Food' => 13, 'Pizza & Pasta' => 13, 'Asian' => 10, 'Grill & BBQ' => 11, 'Desserts & Drinks' => 14, 'Healthy' => 12, 'Bar & Pub' => 13,
    ];
    return $map[$category] ?? ['ugo' => 15, 'ulocal' => 5, 'ueat' => 10][$service] ?? 0;
}

/** Inline style for a picture box: the uploaded photo, or the built-in picture for the category. */
function mk_img_style(?string $photo, string $service, string $category, string $base = ''): string
{
    $url = mk_photo_url($photo, $base);
    if ($url !== '') return "background-image:url('" . htmlspecialchars($url, ENT_QUOTES) . "');background-size:cover;background-position:center";
    $i = mk_fallback_index($service, $category);
    return "background-image:url('" . $base . "assets/images/boracay-listings.png');background-size:500% 400%;background-position:" . (($i % 5) * 25) . '% ' . round(intdiv($i, 5) * (100 / 3), 3) . '%';
}
