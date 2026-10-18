<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();

$name = input('f');
$path = backup_path($name);
if (!$path) {
    http_response_code(404);
    exit('Backup not found.');
}
log_activity((int)$me['id'], "Downloaded backup {$name}");
while (ob_get_level()) {
    ob_end_clean();
}
header('Content-Type: ' . (str_ends_with($name, '.gz') ? 'application/gzip' : 'application/sql'));
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store');
readfile($path);
exit;
