<?php
require_once __DIR__ . '/_bootstrap.php';
$a = agent_require();
$id = (int) $a['id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit_enforce('agent_profile', 'agent:' . $id, 30, 3600);
    try {
        $action = ap('action');
        if ($action === 'profile') {
            $in = $_POST; $in['email'] = $a['email'];
            $data = agent_validate_profile($in, false);
            $pdo->prepare('UPDATE agents SET full_name = ?, mobile = ?, barangay = ? WHERE id = ?')->execute([$data['full_name'], $data['mobile'], $data['barangay'], $id]);
            aflash('success', 'Details saved.');
        } elseif ($action === 'payout') {
            if (!password_verify(ap('current_password'), $a['password_hash'])) throw new InvalidArgumentException('Your current password is incorrect.');
            [$method, $name, $number] = agent_validate_payout_account(ap('payout_method'), ap('payout_account_name'), ap('payout_account_no'));
            $pdo->prepare('UPDATE agents SET payout_method = ?, payout_account_name = ?, payout_account_no = ? WHERE id = ?')->execute([$method, $name, $number, $id]);
            aflash('success', 'Payout account saved.');
        } elseif ($action === 'password') {
            if (!password_verify(ap('current_password'), $a['password_hash'])) throw new InvalidArgumentException('Your current password is incorrect.');
            $new = ap('new_password');
            if (strlen($new) < 8 || strlen($new) > 72 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) throw new InvalidArgumentException('New password needs 8+ characters with letters and numbers.');
            if (!hash_equals($new, ap('new_password_confirm'))) throw new InvalidArgumentException('New passwords do not match.');
            $pdo->prepare('UPDATE agents SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $id]);
            session_regenerate_id(true);
            aflash('success', 'Password changed.');
        }
    } catch (InvalidArgumentException $ex) {
        aflash('error', $ex->getMessage());
    }
    redirect('profile.php');
}
$sel = static fn(?string $cur, string $val): string => $cur === $val ? ' selected' : '';
agent_header('Profile', 'profile');
?>
<div class="grid half">
    <section class="card"><h2>Your details</h2>
        <form method="post" class="stack"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="profile">
            <div class="field"><label>Referral code</label><input type="text" value="<?= e($a['code']) ?>" readonly></div>
            <div class="field"><label>Email (login)</label><input type="email" value="<?= e($a['email']) ?>" readonly></div>
            <div class="field"><label for="full_name">Full name</label><input id="full_name" name="full_name" type="text" required maxlength="120" value="<?= e($a['full_name']) ?>"></div>
            <div class="field"><label for="mobile">Mobile number</label><input id="mobile" name="mobile" type="tel" required value="<?= e($a['mobile']) ?>"></div>
            <div class="field"><label for="barangay">Barangay</label><select id="barangay" name="barangay"><option value="">Choose…</option><?php foreach (agent_barangays() as $b): ?><option<?= $sel($a['barangay'], $b) ?>><?= e($b) ?></option><?php endforeach; ?></select></div>
            <button class="btn primary" type="submit">Save details</button></form>
    </section>
    <div class="stack">
        <section class="card"><h2>Payout account</h2><p class="muted">Current: <?= $a['payout_account_no'] ? e($a['payout_method'] . ' · ' . $a['payout_account_name'] . ' · ' . agent_mask_account($a['payout_account_no'])) : 'Not set' ?></p>
            <form method="post" class="stack"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="payout">
                <div class="field"><label for="payout_method">Bank / e-wallet</label><select id="payout_method" name="payout_method" required><option value="">Choose…</option><?php foreach (agent_payout_methods() as $mth): ?><option<?= $sel($a['payout_method'], $mth) ?>><?= e($mth) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label for="payout_account_name">Account name</label><input id="payout_account_name" name="payout_account_name" type="text" required maxlength="120" value="<?= e((string) $a['payout_account_name']) ?>"></div>
                <div class="field"><label for="payout_account_no">Account / GCash number</label><input id="payout_account_no" name="payout_account_no" type="text" required maxlength="40" inputmode="numeric"></div>
                <div class="field"><label for="pp_current">Current password</label><input id="pp_current" name="current_password" type="password" required autocomplete="current-password"></div>
                <button class="btn primary" type="submit">Save payout account</button></form>
        </section>
        <section class="card"><h2>Change password</h2>
            <form method="post" class="stack"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="password">
                <div class="field"><label for="pw_current">Current password</label><input id="pw_current" name="current_password" type="password" required autocomplete="current-password"></div>
                <div class="field"><label for="pw_new">New password</label><input id="pw_new" name="new_password" type="password" required autocomplete="new-password"></div>
                <div class="field"><label for="pw_confirm">Confirm new password</label><input id="pw_confirm" name="new_password_confirm" type="password" required autocomplete="new-password"></div>
                <button class="btn" type="submit">Change password</button></form>
        </section>
    </div>
</div>
<?php agent_footer();
