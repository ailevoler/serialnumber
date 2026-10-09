<?php
require_once __DIR__ . '/_bootstrap.php';
if (agent_current()) redirect('dashboard.php');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit_enforce('agent_register', 'ip:' . rate_limit_client_ip(), 6, 3600);
    try {
        if (ap('agree') !== '1') throw new InvalidArgumentException('Please confirm your details and accept the agent terms.');
        $data = agent_validate_profile($_POST, true);
        if (ap('payout_method') !== '' || ap('payout_account_no') !== '') {
            [$data['payout_method'], $data['payout_account_name'], $data['payout_account_no']] = agent_validate_payout_account(ap('payout_method'), ap('payout_account_name'), ap('payout_account_no'));
        }
        $master = null;
        if (trim(ap('master_code')) !== '') {
            $master = agent_find_master_by_code($pdo, ap('master_code'));
            if (!$master) throw new InvalidArgumentException('That Master Agent code was not found. Check it with your Master Agent, or leave it blank.');
            $data['parent_agent_id'] = (int) $master['id'];
            if (setting('agents.sub_auto_approve', '0') === '1') $data['status'] = 'approved';
        }
        $pdo->beginTransaction();
        $exists = $pdo->prepare('SELECT 1 FROM agents WHERE email = ?');
        $exists->execute([$data['email']]);
        if ($exists->fetch()) throw new InvalidArgumentException('An agent account with this email already exists. Sign in instead.');
        $data['code'] = agent_new_code($pdo);
        $cols = array_keys($data);
        $pdo->prepare('INSERT INTO agents (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_values($data));
        $id = (int) $pdo->lastInsertId();
        $pdo->commit();
        session_fresh(['agent_id' => $id]);
        aflash('success', ($master ? 'You joined the team of Master Agent ' . $master['full_name'] . '. ' : '') . 'Your referral code is ' . $data['code'] . '. '
            . (($data['status'] ?? 'pending') === 'approved' ? 'You can start sharing it now.' : 'It starts earning once Ultimate App approves your account.'));
        redirect('dashboard.php');
    } catch (InvalidArgumentException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $ex->getMessage();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Agent registration failed: ' . $ex->getMessage());
        $error = 'We could not submit your application. Please try again.';
    }
}
$masterCode = $_SERVER['REQUEST_METHOD'] === 'POST' ? ap('master_code') : aq('master');
$inviter = $masterCode !== '' ? agent_find_master_by_code($pdo, $masterCode) : null;
$v = static fn(string $k): string => e(ap($k));
$sel = static fn(string $k, string $val): string => ap($k) === $val ? ' selected' : '';
agent_header('Become an agent');
?>
<form class="auth-card wide" method="post">
    <div class="brand"><span class="brand-mark"></span><span><b>ULTIMATE APP</b><small>Boracay · Agents Portal</small></span></div>
    <h1>Earn by sharing Ultimate App</h1>
    <p>Share your referral link or code. When people sign up with it, you earn a commission every time they use URide, UPass, UGo, ULocal and UEat.</p>
    <div class="rate-strip"><?php foreach (agent_services() as $code => $svc): ?><span><b><?= e($svc['label']) ?></b><?= e(agent_rate_label($code, ($inviter ?? null) ? 'sub' : 'direct')) ?></span><?php endforeach; ?></div>
    <?php if ($error): ?><div class="alert" role="alert"><?= e($error) ?></div><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div class="reg-section"><h2>1 · About you</h2>
        <div class="form-grid">
            <div class="field"><label for="full_name">Full name</label><input id="full_name" name="full_name" type="text" required maxlength="120" autocomplete="name" value="<?= $v('full_name') ?>"></div>
            <div class="field"><label for="mobile">Mobile number</label><input id="mobile" name="mobile" type="tel" required placeholder="09XXXXXXXXX" autocomplete="tel-national" value="<?= $v('mobile') ?>"></div>
            <div class="field"><label for="barangay">Barangay</label><select id="barangay" name="barangay"><option value="">Choose…</option><?php foreach (agent_barangays() as $b): ?><option<?= $sel('barangay', $b) ?>><?= e($b) ?></option><?php endforeach; ?></select></div>
        </div>
    </div>
    <div class="reg-section"><h2>Master Agent <small class="muted">(optional)</small></h2><p><?= $inviter ? 'You are joining the team of <b>' . e($inviter['full_name']) . '</b>. You become their Sub-Agent.' : 'Invited by a Master Agent? Enter their code to join their team as a Sub-Agent. Leave blank to apply on your own.' ?></p>
        <div class="form-grid"><div class="field"><label for="master_code">Master Agent code</label><input id="master_code" name="master_code" type="text" maxlength="12" autocomplete="off" placeholder="AGXXXXXX" value="<?= e($masterCode) ?>"></div></div>
    </div>
    <div class="reg-section"><h2>2 · Login</h2><p>You will use this email and password to sign in.</p>
        <div class="form-grid">
            <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" required autocomplete="username" value="<?= $v('email') ?>"></div>
            <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" required autocomplete="new-password"><small>8+ characters with letters and numbers.</small></div>
            <div class="field"><label for="password_confirm">Confirm password</label><input id="password_confirm" name="password_confirm" type="password" required autocomplete="new-password"></div>
        </div>
    </div>
    <div class="reg-section"><h2>3 · Payout account <small class="muted">(optional now, needed to cash out)</small></h2>
        <div class="form-grid">
            <div class="field"><label for="payout_method">Bank / e-wallet</label><select id="payout_method" name="payout_method"><option value="">Choose…</option><?php foreach (agent_payout_methods() as $mth): ?><option<?= $sel('payout_method', $mth) ?>><?= e($mth) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="payout_account_name">Account name</label><input id="payout_account_name" name="payout_account_name" type="text" maxlength="120" value="<?= $v('payout_account_name') ?>"></div>
            <div class="field"><label for="payout_account_no">Account / GCash number</label><input id="payout_account_no" name="payout_account_no" type="text" inputmode="numeric" maxlength="40" value="<?= $v('payout_account_no') ?>"></div>
        </div>
    </div>
    <div class="reg-section"><label><input type="checkbox" name="agree" value="1"<?= ap('agree') === '1' ? ' checked' : '' ?>> I confirm these details are true. I will not use spam, fake accounts or my own accounts to earn; commissions from such activity are reversed.</label>
        <div class="form-actions"><button class="btn grad" type="submit">Submit application</button><a href="login.php">I already have an account</a></div></div>
</form>
<?php agent_footer();
