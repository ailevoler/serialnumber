<?php
declare(strict_types=1);
// CLI only. Run against a COPY of the database after importing database/bcash_transfers_migration.sql:
//   DB_HOST=... DB_NAME=... DB_USER=... DB_PASS=... php tests/bcash_transfer_test.php
// Checks BCash Send / Receive: balances, books, replay safety and the KYC rule. Deletes everything it creates.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$check = static function (bool $ok, string $msg): void { if (!$ok) throw new RuntimeException('FAILED: ' . $msg); echo "PASS: $msg\n"; };
$throws = static function (callable $fn, string $needle, string $msg) use ($check): void {
    try { $fn(); } catch (InvalidArgumentException $e) { $check(str_contains($e->getMessage(), $needle), $msg . ' (' . $e->getMessage() . ')'); return; }
    $check(false, $msg . ' — expected an error');
};
$pdo = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4', getenv('DB_USER') ?: '', getenv('DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$GLOBALS['pdo'] = $pdo;
require __DIR__ . '/../includes/rate_limit.php';
require __DIR__ . '/../includes/bcash.php';
require_once __DIR__ . '/../includes/kyc.php';

$users = [];
$savedRule = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key = 'kyc.required_user'")->fetchColumn();
$newUser = static function (string $name, int $cash) use ($pdo, &$users): int {
    $pdo->prepare("INSERT INTO users (full_name, mobile, email, password_hash, qr_code, credits) VALUES (?, ?, ?, 'x', ?, 0)")
        ->execute([$name, '0918' . random_int(1000000, 9999999), 'bct' . bin2hex(random_bytes(4)) . '@test.local', 'UA-' . strtoupper(bin2hex(random_bytes(5)))]);
    $id = (int) $pdo->lastInsertId();
    $users[] = $id;
    if ($cash) $pdo->prepare('INSERT INTO boracay_cash_wallets (user_id, balance_centavos) VALUES (?, ?)')->execute([$id, $cash]);
    return $id;
};
$key = static fn(): string => bin2hex(random_bytes(32));
try {
    setting_save($pdo, 'kyc.required_user', '0', null);
    $a = $newUser('Bea Sender', 50000);
    $b = $newUser('Carlo Receiver', 0); // no BCash wallet row yet

    $k = $key();
    $ref = bcash_transfer($pdo, $a, $b, 12345, $k, 'Lunch');
    $check(str_starts_with($ref, 'BS-'), 'transfer returns a BS- reference');
    $check(bcash_balance($pdo, $a) === 50000 - 12345 && bcash_balance($pdo, $b) === 12345, 'PHP 123.45 moved; receiver wallet created');
    $check(bcash_transfer($pdo, $a, $b, 12345, $k, 'Lunch') === $ref && bcash_balance($pdo, $a) === 50000 - 12345, 'same request key: no double send');
    $sum = $pdo->prepare('SELECT SUM(debit_centavos) d, SUM(credit_centavos) c FROM ledger_entries WHERE entry_group = ?');
    $sum->execute([$ref]); $l = $sum->fetch();
    $check((int) $l['d'] === 12345 && (int) $l['c'] === 12345, 'books balanced: user_cash sender debit = receiver credit');
    $throws(fn() => bcash_transfer($pdo, $a, $b, 999999, $key(), null), 'Not enough BCash', 'more than the balance is refused');
    $throws(fn() => bcash_transfer($pdo, $a, $a, 100, $key(), null), 'own QR', 'sending to yourself is refused');
    $throws(fn() => bcash_transfer($pdo, $a, $b, 50, $key(), null), 'Invalid', 'below PHP 1.00 is refused');
    $check(bcash_balance($pdo, $a) === 50000 - 12345, 'refused transfers move nothing');

    $hist = bcash_transfer_history($pdo, $b);
    $check(count($hist) === 1 && $hist[0]['amount_centavos'] === 12345 && str_contains($hist[0]['title'], 'Received BCash from Bea S.'), 'receiver history shows the transfer');
    $hist = bcash_transfer_history($pdo, $a);
    $check($hist[0]['amount_centavos'] === -12345 && str_contains($hist[0]['title'], 'Sent BCash to Carlo R.'), 'sender history shows a negative amount');

    setting_save($pdo, 'kyc.required_user', '1', null);
    $throws(fn() => bcash_transfer($pdo, $a, $b, 100, $key(), null), 'verify your identity', 'customer KYC rule on: unverified cannot send BCash');
    echo "\nAll BCash transfer tests passed.\n";
} finally {
    if ($savedRule === false) setting_clear($pdo, 'kyc.required_user'); else setting_save($pdo, 'kyc.required_user', (string) $savedRule, null);
    $ids = $users ? implode(',', $users) : '0';
    $pdo->exec("DELETE FROM ledger_entries WHERE entry_group IN (SELECT reference FROM bcash_transfers WHERE payer_id IN ($ids) OR payee_id IN ($ids))");
    $pdo->exec("DELETE FROM bcash_transfers WHERE payer_id IN ($ids) OR payee_id IN ($ids)");
    $pdo->exec("DELETE FROM boracay_cash_wallets WHERE user_id IN ($ids)");
    $pdo->exec("DELETE FROM users WHERE id IN ($ids)");
}
