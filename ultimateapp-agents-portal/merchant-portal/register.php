<?php
require_once __DIR__ . '/_bootstrap.php';
if (merchant_current()) redirect('dashboard.php');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit_enforce('merchant_register', 'ip:' . rate_limit_client_ip(), 6, 3600);
    try {
        if (mp('agree') !== '1') throw new InvalidArgumentException('Please confirm the details are true and accept the merchant terms.');
        $data = merchant_validate_profile($_POST, true);
        $pdo->beginTransaction();
        $exists = $pdo->prepare('SELECT 1 FROM merchants WHERE email = ?');
        $exists->execute([$data['email']]);
        if ($exists->fetch()) throw new InvalidArgumentException('A merchant account with this email already exists. Sign in instead.');
        $data['code'] = merchant_new_code($pdo);
        $cols = array_keys($data);
        $pdo->prepare('INSERT INTO merchants (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_values($data));
        $id = (int) $pdo->lastInsertId();
        foreach (array_keys(merchant_document_types()) as $type) {
            if (isset($_FILES['doc_' . $type])) merchant_store_document($pdo, $id, $type, $_FILES['doc_' . $type]);
        }
        $pdo->commit();
        session_regenerate_id(true);
        $_SESSION = ['merchant_id' => $id];
        mflash('success', 'Application submitted! Our team will review it, usually within 1–2 business days.');
        redirect('dashboard.php');
    } catch (InvalidArgumentException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $ex->getMessage();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Merchant registration failed: ' . $ex->getMessage());
        $error = 'We could not submit your application. Please try again.';
    }
}
$v = static fn(string $k): string => e(mp($k));
$sel = static fn(string $k, string $val): string => mp($k) === $val ? ' selected' : '';
portal_header('Merchant pre-onboarding');
?>
<form class="auth-card wide" method="post" enctype="multipart/form-data">
    <div class="brand"><span class="brand-mark"></span><span><b>ULTIMATE APP</b><small>Boracay · Merchant Portal</small></span></div>
    <h1>Accept Ultimate App payments</h1>
    <p>Tell us about your business. Once approved, customers can pay you with Credits or BCash by scanning your QR.</p>
    <?php if ($error): ?><div class="alert" role="alert"><?= e($error) ?></div><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div class="reg-section"><h2>1 · Business</h2><p>As it appears on your permit.</p>
        <div class="form-grid">
            <div class="field"><label for="business_name">Business name</label><input id="business_name" name="business_name" type="text" required maxlength="160" value="<?= $v('business_name') ?>"></div>
            <div class="field"><label for="business_type">Business type</label><select id="business_type" name="business_type" required><?php foreach (merchant_business_types() as $k => $l): ?><option value="<?= e($k) ?>"<?= $sel('business_type', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="category">Category</label><select id="category" name="category" required><option value="">Choose…</option><?php foreach (merchant_categories() as $c): ?><option<?= $sel('category', $c) ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="barangay">Barangay</label><select id="barangay" name="barangay" required><option value="">Choose…</option><?php foreach (merchant_barangays() as $b): ?><option<?= $sel('barangay', $b) ?>><?= e($b) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="field mt"><label for="address">Business address</label><input id="address" name="address" type="text" required maxlength="255" placeholder="Street / Station / landmark" value="<?= $v('address') ?>"></div>
    </div>
    <div class="reg-section"><h2>Location on the map <small class="muted">(optional)</small></h2><p>Pin your store so customers can find you on the Ultimate App map.</p>
        <?= merchant_map_picker(mp('latitude') ?: null, mp('longitude') ?: null) ?>
    </div>
    <div class="reg-section"><h2>2 · Owner &amp; login</h2><p>You will use this email and password to sign in.</p>
        <div class="form-grid">
            <div class="field"><label for="owner_name">Owner / authorised person</label><input id="owner_name" name="owner_name" type="text" required maxlength="120" value="<?= $v('owner_name') ?>"></div>
            <div class="field"><label for="mobile">Mobile number</label><input id="mobile" name="mobile" type="tel" required placeholder="09XXXXXXXXX" value="<?= $v('mobile') ?>"></div>
            <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" required autocomplete="username" value="<?= $v('email') ?>"></div>
            <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" required autocomplete="new-password"><small>8+ characters with letters and numbers.</small></div>
            <div class="field"><label for="password_confirm">Confirm password</label><input id="password_confirm" name="password_confirm" type="password" required autocomplete="new-password"></div>
        </div>
    </div>
    <div class="reg-section"><h2>3 · Registration <small class="muted">(optional now, needed for approval)</small></h2><p>Add what you have. You can complete the rest after signing in.</p>
        <div class="form-grid">
            <div class="field"><label for="tin">TIN</label><input id="tin" name="tin" type="text" placeholder="123-456-789-000" value="<?= $v('tin') ?>"></div>
            <div class="field"><label for="registration_no">DTI / SEC / CDA no.</label><input id="registration_no" name="registration_no" type="text" value="<?= $v('registration_no') ?>"></div>
            <div class="field"><label for="mayors_permit_no">Mayor's permit no.</label><input id="mayors_permit_no" name="mayors_permit_no" type="text" value="<?= $v('mayors_permit_no') ?>"></div>
        </div>
        <?php foreach (merchant_document_types() as $k => $l): ?><div class="file-row"><label for="doc_<?= e($k) ?>"><?= e($l) ?></label><input id="doc_<?= e($k) ?>" name="doc_<?= e($k) ?>" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp"></div><?php endforeach; ?>
        <p class="table-meta">PDF, JPG, PNG or WEBP · up to 5 MB each. Files are private to you and Ultimate App reviewers.</p>
    </div>
    <div class="reg-section"><h2>4 · Payout account</h2><p>Where we send your settlements.</p>
        <div class="form-grid">
            <div class="field"><label for="bank_name">Bank / e-wallet</label><input id="bank_name" name="bank_name" type="text" placeholder="e.g. BDO, Landbank, GCash" value="<?= $v('bank_name') ?>"></div>
            <div class="field"><label for="bank_account_name">Account name</label><input id="bank_account_name" name="bank_account_name" type="text" value="<?= $v('bank_account_name') ?>"></div>
            <div class="field"><label for="bank_account_no">Account number</label><input id="bank_account_no" name="bank_account_no" type="text" inputmode="numeric" value="<?= $v('bank_account_no') ?>"></div>
        </div>
    </div>
    <div class="reg-section"><label><input type="checkbox" name="agree" value="1"<?= mp('agree') === '1' ? ' checked' : '' ?>> I confirm these details are true and I agree to the Ultimate App merchant terms and applicable charges.</label>
        <div class="form-actions"><button class="btn grad" type="submit">Submit application</button><a href="login.php">I already have an account</a></div></div>
</form>
<?php portal_footer(['../assets/js/gmaps.js?v=1']);
