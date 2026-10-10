<?php
declare(strict_types=1);
// Refreshes the USD/PHP rate. Hostinger › Advanced › Cron Jobs, every hour:
//   php /home/<user>/public_html/cron/fx-refresh.php
// The exchange page also refreshes the rate on its own; this keeps it fresh when nobody is using it.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/fx.php';
if (!fx_ready($pdo)) { fwrite(STDERR, "Import database/fx_migration.sql first.\n"); exit(1); }
try {
    $row = fx_refresh($pdo);
    echo 'USD/PHP ' . $row['rate'] . ' from ' . $row['source'] . ' (published ' . ($row['rate_date'] ?? '?') . ")\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
