<?php
require_once __DIR__ . '/../../includes/core.php';
Permissions::requireAccessJson('terminals');
requireCsrfOrJson();
$action = $_POST['action'] ?? '';

function normPhone(string $p): string {
    $p = preg_replace('/\D/', '', $p);
    if (strlen($p) === 10 && $p[0] === '9') return '0' . $p;
    if (strlen($p) === 12 && substr($p, 0, 2) === '63') return '0' . substr($p, 2);
    return $p;
}

if ($action === 'save_terminal') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? ''); $address = trim($_POST['address'] ?? '');
    $lat = trim($_POST['lat'] ?? ''); $lng = trim($_POST['lng'] ?? '');
    $vehicle = $_POST['vehicle_type'] ?? 'tricycle';
    if ($name === '' || $address === '') jsonResponse(false, 'Name and address are required.');
    if (!in_array($vehicle, ['tricycle','e_trike','motorcycle'], true)) jsonResponse(false, 'Invalid vehicle type.');
    if (!is_numeric($lat) || !is_numeric($lng) || abs((float)$lat) > 90 || abs((float)$lng) > 180) {
        jsonResponse(false, 'Enter the terminal latitude and longitude (right-click the spot in Google Maps to copy them).');
    }
    if ($id) {
        if (!DB::row('SELECT id FROM tl_terminals WHERE id=?', [$id])) jsonResponse(false, 'Terminal not found.', null, 404);
        DB::run('UPDATE tl_terminals SET name=?, address=?, lat=?, lng=?, vehicle_type=? WHERE id=?', [$name, $address, $lat, $lng, $vehicle, $id]);
        logActivity('update', 'terminals', "Updated terminal {$name} (#{$id})");
        jsonResponse(true, 'Terminal updated.');
    }
    $code = generateUniqueCode('TRM-', 'tl_terminals', 'terminal_code');
    DB::run('INSERT INTO tl_terminals (terminal_code, name, address, lat, lng, vehicle_type) VALUES (?,?,?,?,?,?)', [$code, $name, $address, $lat, $lng, $vehicle]);
    logActivity('create', 'terminals', "Created terminal {$name} ({$code})");
    jsonResponse(true, 'Terminal created.');
}

if ($action === 'toggle_terminal') {
    $id = (int)($_POST['id'] ?? 0); $active = (int)($_POST['is_active'] ?? 0) ? 1 : 0;
    $t = DB::row('SELECT name FROM tl_terminals WHERE id=?', [$id]);
    if (!$t) jsonResponse(false, 'Terminal not found.', null, 404);
    DB::run('UPDATE tl_terminals SET is_active=? WHERE id=?', [$active, $id]);
    if (!$active) {
        // Closing a terminal empties its pila; waiting passengers go to nearby street drivers
        DB::run("UPDATE tl_terminal_queue SET status='left', ended_at=NOW() WHERE terminal_id=? AND status='waiting'", [$id]);
        DB::run("UPDATE tl_bookings SET booking_mode='street' WHERE terminal_id=? AND booking_mode='terminal' AND status='searching' AND driver_id IS NULL", [$id]);
        DB::run("UPDATE tl_drivers SET work_mode='street', work_terminal_id=NULL WHERE work_terminal_id=?", [$id]);
    }
    logActivity('update', 'terminals', "Set terminal {$t['name']} " . ($active ? 'active' : 'inactive'));
    jsonResponse(true, 'Terminal status updated.');
}

if ($action === 'save_dispatcher') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['full_name'] ?? ''); $phone = normPhone($_POST['phone'] ?? '');
    $terminalId = (int)($_POST['terminal_id'] ?? 0); $pin = (string)($_POST['pin'] ?? '');
    if ($name === '') jsonResponse(false, 'Name is required.');
    if (!preg_match('/^09\d{9}$/', $phone)) jsonResponse(false, 'Enter a valid mobile number (09XXXXXXXXX).');
    if (!DB::row('SELECT id FROM tl_terminals WHERE id=?', [$terminalId])) jsonResponse(false, 'Select a terminal.');
    if ((!$id || $pin !== '') && !preg_match('/^\d{6}$/', $pin)) jsonResponse(false, 'PIN must be exactly 6 digits.');
    if ((int)DB::val('SELECT COUNT(*) FROM tl_dispatchers WHERE phone=? AND id<>?', [$phone, $id])) jsonResponse(false, 'Mobile number is already used by another dispatcher.');
    if ($id) {
        if (!DB::row('SELECT id FROM tl_dispatchers WHERE id=?', [$id])) jsonResponse(false, 'Dispatcher not found.', null, 404);
        $sql = 'UPDATE tl_dispatchers SET full_name=?, phone=?, terminal_id=?'; $args = [$name, $phone, $terminalId];
        if ($pin !== '') { $sql .= ', pin_hash=?, pin_attempts=0, pin_locked_until=NULL'; $args[] = password_hash($pin, PASSWORD_BCRYPT, ['cost' => 12]); }
        $args[] = $id; DB::run($sql . ' WHERE id=?', $args);
        logActivity('update', 'terminals', "Updated dispatcher {$name} (#{$id})");
        jsonResponse(true, 'Dispatcher updated.');
    }
    $code = generateUniqueCode('DSP-', 'tl_dispatchers', 'dispatcher_code');
    DB::run('INSERT INTO tl_dispatchers (dispatcher_code, full_name, phone, terminal_id, pin_hash) VALUES (?,?,?,?,?)',
        [$code, $name, $phone, $terminalId, password_hash($pin, PASSWORD_BCRYPT, ['cost' => 12])]);
    logActivity('create', 'terminals', "Created dispatcher {$name} ({$code})");
    jsonResponse(true, 'Dispatcher account created.');
}

if ($action === 'toggle_dispatcher') {
    $id = (int)($_POST['id'] ?? 0); $status = ($_POST['status'] ?? '') === 'active' ? 'active' : 'suspended';
    $d = DB::row('SELECT full_name FROM tl_dispatchers WHERE id=?', [$id]);
    if (!$d) jsonResponse(false, 'Dispatcher not found.', null, 404);
    DB::run('UPDATE tl_dispatchers SET status=? WHERE id=?', [$status, $id]);
    logActivity('update', 'terminals', "Set dispatcher {$d['full_name']} {$status}");
    jsonResponse(true, 'Dispatcher status updated.');
}

jsonResponse(false, 'Unknown action.');
