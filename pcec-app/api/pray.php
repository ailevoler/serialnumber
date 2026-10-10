<?php
// POST id=<prayer request id>  →  toggles "I prayed".
require __DIR__ . '/../includes/bootstrap.php';
require_login();
if (!is_post()) json_out(['error' => 'POST required'], 405);
csrf_check();

$id = (int) ($_POST['id'] ?? 0);
$me = uid();
$pr = q_one('SELECT id, user_id, title FROM prayer_requests WHERE id = ?', [$id]);
if (!$pr) json_out(['error' => 'Not found'], 404);

if (q_val('SELECT 1 FROM prayer_responses WHERE prayer_id = ? AND user_id = ?', [$id, $me])) {
    q('DELETE FROM prayer_responses WHERE prayer_id = ? AND user_id = ?', [$id, $me]);
    $on = false;
} else {
    q('INSERT INTO prayer_responses (prayer_id, user_id) VALUES (?,?)', [$id, $me]);
    notify((int) $pr['user_id'], $me, 'prayer', display_name(current_user()) . ' prayed for "' . mb_strimwidth($pr['title'], 0, 60, '…') . '".', 'prayer.php');
    $on = true;
}
json_out(['on' => $on, 'count' => (int) q_val('SELECT COUNT(*) FROM prayer_responses WHERE prayer_id = ?', [$id])]);
