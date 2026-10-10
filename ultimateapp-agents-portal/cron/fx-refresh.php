<?php
declare(strict_types=1);
// Refreshes the exchange rates (USD/PHP and every currency offered). Hostinger › Advanced › Cron Jobs, every hour:
//   php /home/<user>/public_html/cron/fx-refresh.php
// The exchange page also refreshes the rate on its own; this keeps it fresh when nobody is using it.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/fx.php';
if (!fx_ready($pdo)) { fwrite(STDERR, "Import database/fx_migration.sql first.\n"); exit(1); }
try {
    $res = fx_refresh($pdo);
    foreach ($res['rates'] as $ccy => $row) echo $ccy . '/PHP ' . fx_format_rate(fx_rate_scaled((string) $row['rate'])) . ' from ' . $row['source'] . ' (published ' . ($row['rate_date'] ?? '?') . ")\n";
    foreach ($res['errors'] as $err) fwrite(STDERR, 'Skipped ' . $err . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
