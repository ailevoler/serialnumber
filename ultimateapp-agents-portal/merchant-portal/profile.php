<?php
require_once __DIR__ . '/_bootstrap.php';
$m = merchant_require();
$id = (int) $m['id'];
$editable = in_array($m['status'], ['pending', 'needs_info', 'rejected'], true);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit_enforce('merchant_profile', 'merchant:' . $id, 30, 3600);
    $action = mp('action');
    try {
        if ($action === 'profile' && $editable) {
            $in = $_POST; $in['email'] = $m['email'];
            $data = merchant_validate_profile($in, false);
            unset($data['email'], $data['bank_name'], $data['bank_account_name'], $data['bank_account_no']);
            $set = implode(', ', array_map(static fn($k) => "$k = ?", array_keys($data)));
            $pdo->prepare("UPDATE merchants SET $set WHERE id = ?")->execute([...array_values($data), $id]);
            mflash('success', 'Business details saved.' . ($m['status'] === 'needs_info' ? ' Resubmit from your dashboard when ready.' : ''));
        } elseif ($action === 'docs') {
            $pdo->beginTransaction();
            $n = 0;
            foreach (array_keys(merchant_document_types()) as $type) {
                if (($_FILES['doc_' . $type]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) { merchant_store_document($pdo, $id, $type, $_FILES['doc_' . $type]); $n++; }
            }
            $pdo->commit();
            mflash($n ? 'success' : 'info', $n ? "$n document(s) uploaded." : 'Choose at least one file to upload.');
        } elseif ($action === 'bank') {
            if (!password_verify(mp('current_password'), $m['password_hash'])) throw new InvalidArgumentException('Your current password is incorrect.');
            $name = mb_substr(trim(mp('bank_name')), 0, 80); $acctName = mb_substr(trim(mp('bank_account_name')), 0, 120); $acct = preg_replace('/[^0-9A-Za-z-]/', '', mp('bank_account_no'));
            if ($name === '' || $acctName === '' || strlen($acct) < 6) throw new InvalidArgumentException('Enter bank, account name and a valid account number.');
            $pdo->prepare('UPDATE merchants SET bank_name = ?, bank_account_name = ?, bank_account_no = ? WHERE id = ?')->execute([$name, $acctName, mb_substr($acct, 0, 40), $id]);
            $pdo->prepare("INSERT INTO crm_notes (entity_type, entity_id, admin_id, body) VALUES ('merchant', ?, NULL, ?)")->execute([$id, 'Merchant changed payout account to ' . $name . ' ending ' . substr($acct, -4) . ' from the portal.']);
            mflash('success', 'Payout account updated. Our team is notified of the change.');
        } elseif ($action === 'password') {
            if (!password_verify(mp('current_password'), $m['password_hash'])) throw new InvalidArgumentException('Your current password is incorrect.');
            $new = mp('new_password');
            if (strlen($new) < 8 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) throw new InvalidArgumentException('New password needs 8+ characters with letters and numbers.');
            if (!hash_equals($new, mp('new_password_confirm'))) throw new InvalidArgumentException('New passwords do not match.');
            $pdo->prepare('UPDATE merchants SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $id]);
            session_regenerate_id(true);
            mflash('success', 'Password changed.');
        }
    } catch (InvalidArgumentException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        mflash('error', $ex->getMessage());
    }
    redirect('profile.php');
}
$docs = $pdo->prepare('SELECT * FROM merchant_documents WHERE merchant_id = ? ORDER BY id');
$docs->execute([$id]); $docs = $docs->fetchAll();
$types = merchant_document_types();
$val = static fn(string $k): string => e((string) ($m[$k] ?? ''));
portal_header('Business profile', 'profile');
?>
<div class="grid two">
<div class="stack">
<form class="card" method="post"><h2>Business details <?= portal_badge($m['status']) ?></h2>
    <?php if (!$editable): ?><p class="alert info">Details are locked while your account is <?= e(strtolower(merchant_statuses()[$m['status']])) ?>. Open a support ticket to change them.</p><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="profile">
    <fieldset <?= $editable ? '' : 'disabled' ?>>
    <div class="form-grid">
        <div class="field"><label for="bn">Business name</label><input id="bn" name="business_name" type="text" value="<?= $val('business_name') ?>" required></div>
        <div class="field"><label for="bt">Business type</label><select id="bt" name="business_type"><?php foreach (merchant_business_types() as $k => $l): ?><option value="<?= e($k) ?>"<?= $m['business_type'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="cat">Category</label><select id="cat" name="category"><?php foreach (merchant_categories() as $c): ?><option<?= $m['category'] === $c ? ' selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="brgy">Barangay</label><select id="brgy" name="barangay"><?php foreach (merchant_barangays() as $b): ?><option<?= $m['barangay'] === $b ? ' selected' : '' ?>><?= e($b) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="own">Owner</label><input id="own" name="owner_name" type="text" value="<?= $val('owner_name') ?>" required></div>
        <div class="field"><label for="mob">Mobile</label><input id="mob" name="mobile" type="tel" value="<?= $val('mobile') ?>" required></div>
        <div class="field"><label for="tin">TIN</label><input id="tin" name="tin" type="text" value="<?= $val('tin') ?>"></div>
        <div class="field"><label for="reg">DTI / SEC / CDA no.</label><input id="reg" name="registration_no" type="text" value="<?= $val('registration_no') ?>"></div>
        <div class="field"><label for="mp">Mayor's permit no.</label><input id="mp" name="mayors_permit_no" type="text" value="<?= $val('mayors_permit_no') ?>"></div>
    </div>
    <div class="field mt"><label for="addr">Address</label><input id="addr" name="address" type="text" value="<?= $val('address') ?>" required></div>
    <p class="section-title">Map location</p>
    <?= merchant_map_picker($m['latitude'], $m['longitude'], !$editable) ?>
    <?php if ($editable): ?><div class="form-actions"><button class="btn primary" type="submit">Save details</button></div><?php endif; ?>
    </fieldset>
</form>
<form class="card" method="post" enctype="multipart/form-data"><h2>Documents</h2>
    <?php foreach ($docs as $d): ?><div class="kv"><span><?= e($types[$d['doc_type']] ?? $d['doc_type']) ?><small class="sub"><?= e($d['original_name']) ?> · <?= e(date('M j, Y', strtotime($d['created_at']))) ?></small></span><a class="btn small" href="doc.php?id=<?= (int) $d['id'] ?>" target="_blank" rel="noopener">View</a></div><?php endforeach; ?>
    <?php if (!$docs): ?><p class="muted">No documents yet. Upload your business permit and a valid ID of the owner.</p><?php endif; ?>
    <p class="section-title">Upload more</p>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="docs">
    <?php foreach ($types as $k => $l): ?><div class="file-row"><label for="d_<?= e($k) ?>"><?= e($l) ?></label><input id="d_<?= e($k) ?>" name="doc_<?= e($k) ?>" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp"></div><?php endforeach; ?>
    <div class="form-actions"><button class="btn" type="submit">Upload</button><small class="muted">PDF/JPG/PNG/WEBP, 5 MB each</small></div>
</form>
</div>
<div class="stack">
<section class="card"><h2>Account</h2><div class="kv"><span>Merchant code</span><b><?= e($m['code']) ?></b></div><div class="kv"><span>Login email</span><b><?= e($m['email']) ?></b></div><div class="kv"><span>Member since</span><b><?= e(date('M j, Y', strtotime($m['created_at']))) ?></b></div></section>
<form class="card" method="post" autocomplete="off"><h2>Payout account</h2>
    <div class="kv"><span>Current</span><b><?= e($m['bank_name'] ?? 'Not set') ?> <?= e(merchant_mask_account($m['bank_account_no'])) ?></b></div>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="bank">
    <div class="field mt"><label for="bk">Bank / e-wallet</label><input id="bk" name="bank_name" type="text" value="<?= $val('bank_name') ?>" required></div>
    <div class="field mt"><label for="ban">Account name</label><input id="ban" name="bank_account_name" type="text" value="<?= $val('bank_account_name') ?>" required></div>
    <div class="field mt"><label for="bno">Account number</label><input id="bno" name="bank_account_no" type="text" inputmode="numeric" required></div>
    <div class="field mt"><label for="cp1">Current password</label><input id="cp1" name="current_password" type="password" required autocomplete="current-password"></div>
    <div class="form-actions"><button class="btn" type="submit">Update payout account</button></div>
</form>
<form class="card" method="post"><h2>Change password</h2><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="password">
    <div class="field"><label for="cp2">Current password</label><input id="cp2" name="current_password" type="password" required autocomplete="current-password"></div>
    <div class="field mt"><label for="np">New password</label><input id="np" name="new_password" type="password" required autocomplete="new-password"></div>
    <div class="field mt"><label for="np2">Confirm new password</label><input id="np2" name="new_password_confirm" type="password" required autocomplete="new-password"></div>
    <div class="form-actions"><button class="btn" type="submit">Change password</button></div>
</form>
</div>
</div>
<?php portal_footer(['../assets/js/gmaps.js?v=2']);
