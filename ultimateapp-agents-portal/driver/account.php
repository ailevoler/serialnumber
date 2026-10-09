<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/kyc.php';
$driver = driver_require();
$id = (int) $driver['id'];
$editable = in_array($driver['status'], ['pending', 'under_review', 'needs_info'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit_enforce('driver_account', 'driver:' . $id, 30, 600);
    $action = dp('action');
    try {
        if ($action === 'reupload') {
            // Replace uploads whose files are no longer on the server (allowed in any status).
            $dir = uride_storage_dir() . '/';
            $selfie = uride_save_selfie(dp('selfie_data'), $_FILES['selfie_file'] ?? null);
            if ($selfie) $pdo->prepare('UPDATE uride_drivers SET selfie_file = ? WHERE id = ?')->execute([$selfie, $id]);
            $added = 0;
            foreach (array_keys(uride_doc_types()) as $k) {
                if (!isset($_FILES["doc_$k"]) || !uride_store_document($pdo, $id, $k, $_FILES["doc_$k"])) continue;
                $added++;
                $old = $pdo->prepare('SELECT id, stored_name FROM uride_driver_documents WHERE driver_id = ? AND doc_type = ? AND id <> LAST_INSERT_ID()');
                $old->execute([$id, $k]);
                foreach ($old as $o) if (!is_file($dir . basename($o['stored_name']))) $pdo->prepare('DELETE FROM uride_driver_documents WHERE id = ?')->execute([$o['id']]);
            }
            if (!$selfie && !$added) throw new InvalidArgumentException('Choose a photo or at least one document to upload.');
            dflash('success', 'Thanks! Your files were uploaded.');
        } elseif ($action === 'details') {
            if (!$editable) throw new InvalidArgumentException('Your details are locked after approval. Contact support to change them.');
            $data = uride_validate_driver($_POST, false);
            foreach (['email' => 'This email', 'mobile' => 'This mobile number', 'plate_no' => 'This plate number'] as $col => $label) {
                $stmt = $pdo->prepare("SELECT 1 FROM uride_drivers WHERE $col = ? AND id <> ?");
                $stmt->execute([$data[$col], $id]);
                if ($stmt->fetch()) throw new InvalidArgumentException("$label is already used by another driver.");
            }
            $sets = implode(', ', array_map(static fn($c) => "$c = ?", array_keys($data)));
            $pdo->prepare("UPDATE uride_drivers SET $sets WHERE id = ?")->execute([...array_values($data), $id]);
            $selfie = uride_save_selfie(dp('selfie_data'), $_FILES['selfie_file'] ?? null);
            if ($selfie) $pdo->prepare('UPDATE uride_drivers SET selfie_file = ? WHERE id = ?')->execute([$selfie, $id]);
            $added = 0;
            foreach (array_keys(uride_doc_types()) as $k) if (isset($_FILES["doc_$k"]) && uride_store_document($pdo, $id, $k, $_FILES["doc_$k"])) $added++;
            if ($driver['status'] === 'needs_info' && dp('resubmit') === '1') {
                $pdo->prepare("UPDATE uride_drivers SET status = 'pending' WHERE id = ? AND status = 'needs_info'")->execute([$id]);
                dflash('success', 'Thanks! Your updated application was sent for review.');
            } else {
                dflash('success', 'Details saved' . ($added ? " and $added document(s) uploaded" : '') . '.');
            }
        } elseif ($action === 'password') {
            if (!password_verify(dp('current_password'), $driver['password_hash'])) throw new InvalidArgumentException('Your current password is incorrect.');
            $new = dp('password');
            if (strlen($new) < 8 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) throw new InvalidArgumentException('New password must be at least 8 characters with letters and numbers.');
            if (!hash_equals($new, dp('password_confirm'))) throw new InvalidArgumentException('Passwords do not match.');
            $pdo->prepare('UPDATE uride_drivers SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $id]);
            session_regenerate_id(true);
            dflash('success', 'Password changed.');
        }
    } catch (InvalidArgumentException $ex) {
        dflash('error', $ex->getMessage());
    }
    redirect('account.php');
}

$stmt = $pdo->prepare('SELECT id, doc_type, original_name, stored_name, created_at FROM uride_driver_documents WHERE driver_id = ? ORDER BY id DESC');
$stmt->execute([$id]);
$docs = $stmt->fetchAll();
$docTypes = uride_doc_types();
$fileDir = uride_storage_dir() . '/';
$selfieMissing = $driver['selfie_file'] && !is_file($fileDir . basename((string) $driver['selfie_file']));
$missingDocs = array_values(array_filter($docs, static fn($d) => !is_file($fileDir . basename((string) $d['stored_name']))));
$val = static fn(string $k): string => e((string) ($driver[$k] ?? ''));
$rating = uride_rating($driver);
driver_header('Account', 'account');
?>
<section class="d-profile">
    <?php if ($driver['selfie_file'] && !$selfieMissing): ?><img src="file.php?selfie=1" alt="" class="d-profile-photo" width="72" height="72"><?php else: ?><span class="d-profile-photo d-avatar"><?= e(mb_strtoupper(mb_substr($driver['full_name'], 0, 1))) ?></span><?php endif; ?>
    <div><b><?= e($driver['full_name']) ?></b><small><?= e($driver['code']) ?> · <?= e(uride_vehicles()[$driver['vehicle_type']]['label'] ?? '') ?> · <?= e($driver['plate_no']) ?></small>
        <span><?= driver_badge($driver['status']) ?><?= $rating !== null ? ' <span class="badge muted">★ ' . number_format($rating, 1) . ' (' . (int) $driver['rating_count'] . ')</span>' : '' ?> <span class="badge muted"><?= (int) $driver['trips_completed'] ?> trip<?= (int) $driver['trips_completed'] === 1 ? '' : 's' ?></span></span></div>
</section>
<?php if ($driver['status'] === 'needs_info' && $driver['review_note']): ?><div class="alert warn"><b>Reviewer note:</b> <?= e($driver['review_note']) ?></div><?php endif; ?>

<?php $kycState = kyc_status($pdo, 'driver', $id); ?>
<a class="d-card" href="kyc.php" style="display:flex;justify-content:space-between;align-items:center;text-decoration:none;color:inherit"><span><b>Verify ID (KYC)</b><br><small>Valid ID and selfie check</small></span><?= kyc_badge($kycState) ?></a>
<form class="d-card" method="post" enctype="multipart/form-data" id="documents">
    <h2>Driver &amp; vehicle details</h2>
    <?php if (!$editable): ?><p class="table-meta">Details are locked after approval. Contact Ultimate App support if your vehicle, plate or license changes.</p><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="details">
    <fieldset <?= $editable ? '' : 'disabled' ?> class="d-fieldset">
    <div class="form-grid">
        <div class="field"><label for="full_name">Full name</label><input type="text" id="full_name" name="full_name" required value="<?= $val('full_name') ?>"></div>
        <div class="field"><label for="birthdate">Birthdate</label><input id="birthdate" name="birthdate" type="date" required value="<?= $val('birthdate') ?>"></div>
        <div class="field"><label for="mobile">Mobile</label><input id="mobile" name="mobile" type="tel" required value="<?= $val('mobile') ?>"></div>
        <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" required value="<?= $val('email') ?>"></div>
        <div class="field"><label for="barangay">Barangay</label><select id="barangay" name="barangay"><?php foreach (uride_barangays() as $b): ?><option<?= $driver['barangay'] === $b ? ' selected' : '' ?>><?= e($b) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="address">Address</label><input type="text" id="address" name="address" required value="<?= $val('address') ?>"></div>
        <div class="field"><label for="license_no">License no.</label><input type="text" id="license_no" name="license_no" required value="<?= $val('license_no') ?>"></div>
        <div class="field"><label for="license_expiry">License expiry</label><input id="license_expiry" name="license_expiry" type="date" required value="<?= $val('license_expiry') ?>"></div>
        <div class="field"><label for="vehicle_type">Vehicle type</label><select id="vehicle_type" name="vehicle_type"><?php foreach (uride_vehicles() as $k => $v): ?><option value="<?= e($k) ?>"<?= $driver['vehicle_type'] === $k ? ' selected' : '' ?>><?= e($v['label']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="plate_no">Plate number</label><input type="text" id="plate_no" name="plate_no" required value="<?= $val('plate_no') ?>"></div>
        <div class="field"><label for="vehicle_model">Make &amp; model</label><input type="text" id="vehicle_model" name="vehicle_model" required value="<?= $val('vehicle_model') ?>"></div>
        <div class="field"><label for="vehicle_color">Color</label><input type="text" id="vehicle_color" name="vehicle_color" required value="<?= $val('vehicle_color') ?>"></div>
        <div class="field"><label for="franchise_no">Franchise / MTOP no.</label><input type="text" id="franchise_no" name="franchise_no" value="<?= $val('franchise_no') ?>"></div>
        <div class="field"><label for="toda_name">TODA / association</label><input type="text" id="toda_name" name="toda_name" value="<?= $val('toda_name') ?>"></div>
    </div>
    <?php if ($editable): ?>
        <p class="section-title">New selfie <small class="muted">(optional — leave empty to keep your photo)</small></p>
        <div class="d-selfie small" data-selfie><video playsinline muted hidden data-selfie-video></video><img alt="New selfie" hidden data-selfie-preview><div class="d-selfie-empty" data-selfie-empty><?= driver_icon('account') ?><small>Keep current photo</small></div></div>
        <input type="hidden" name="selfie_data" data-selfie-data>
        <div class="d-selfie-actions"><button type="button" class="btn" data-selfie-start>Open camera</button><button type="button" class="btn" data-selfie-snap hidden>Take photo</button><button type="button" class="btn" data-selfie-retake hidden>Retake</button><label class="btn d-file">Upload photo<input type="file" name="selfie_file" accept="image/*" capture="user" hidden data-selfie-file></label></div>
        <p class="table-meta" role="status" data-selfie-status></p>
        <p class="section-title">Add documents</p>
        <?php foreach ($docTypes as $k => [$label]): ?><div class="file-row"><label for="doc_<?= e($k) ?>"><?= e($label) ?></label><input id="doc_<?= e($k) ?>" name="doc_<?= e($k) ?>" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp"></div><?php endforeach; ?>
        <?php if ($driver['status'] === 'needs_info'): ?><label class="d-agree"><input type="checkbox" name="resubmit" value="1" checked> Send my application back for review</label><?php endif; ?>
        <button class="btn grad" type="submit">Save<?= $driver['status'] === 'needs_info' ? ' &amp; resubmit' : '' ?></button>
    <?php endif; ?>
    </fieldset>
</form>

<?php if (!$editable && ($selfieMissing || $missingDocs)): ?>
<form class="d-card" method="post" enctype="multipart/form-data">
    <h2>Please upload again</h2>
    <div class="alert warn">Some of your files could not be found on our server<?= $selfieMissing ? ' (including your selfie)' : '' ?>. Upload them again so passengers and the Ultimate App team can see them. Your approval stays the same.</div>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="reupload">
    <?php if ($selfieMissing): ?>
        <div class="d-selfie small" data-selfie><video playsinline muted hidden data-selfie-video></video><img alt="New selfie" hidden data-selfie-preview><div class="d-selfie-empty" data-selfie-empty><?= driver_icon('account') ?><small>No photo yet</small></div></div>
        <input type="hidden" name="selfie_data" data-selfie-data>
        <div class="d-selfie-actions"><button type="button" class="btn" data-selfie-start>Open camera</button><button type="button" class="btn" data-selfie-snap hidden>Take photo</button><button type="button" class="btn" data-selfie-retake hidden>Retake</button><label class="btn d-file">Upload photo<input type="file" name="selfie_file" accept="image/*" capture="user" hidden data-selfie-file></label></div>
        <p class="table-meta" role="status" data-selfie-status></p>
    <?php endif; ?>
    <?php foreach (array_unique(array_column($missingDocs, 'doc_type')) as $k): ?><div class="file-row"><label for="re_<?= e($k) ?>"><?= e($docTypes[$k][0] ?? $k) ?></label><input id="re_<?= e($k) ?>" name="doc_<?= e($k) ?>" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp"></div><?php endforeach; ?>
    <button class="btn grad" type="submit">Upload</button>
</form>
<?php endif; ?>
<section class="d-card"><h2>Documents on file</h2>
    <?php if (!$docs): ?><p class="d-empty">No documents uploaded.</p><?php endif; ?>
    <div class="d-mini-list"><?php foreach ($docs as $d): ?><div><span><?= e($docTypes[$d['doc_type']][0] ?? $d['doc_type']) ?><small><?= e($d['original_name']) ?> · <?= e(date('M j, Y', strtotime($d['created_at']))) ?></small></span><?= is_file($fileDir . basename((string) $d['stored_name'])) ? '<a class="btn small" href="file.php?doc=' . (int) $d['id'] . '" target="_blank" rel="noopener">View</a>' : '<span class="badge bad">Upload again</span>' ?></div><?php endforeach; ?></div>
</section>

<form class="d-card" method="post"><h2>Change password</h2>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="password">
    <div class="form-grid">
        <div class="field"><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" required autocomplete="current-password"></div>
        <div class="field"><label for="npw">New password</label><input id="npw" name="password" type="password" required autocomplete="new-password"></div>
        <div class="field"><label for="npw2">Confirm new password</label><input id="npw2" name="password_confirm" type="password" required autocomplete="new-password"></div>
    </div>
    <button class="btn" type="submit">Change password</button>
</form>

<form method="post" action="logout.php" class="d-logout"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="btn ghost" type="submit"><?= driver_icon('logout') ?> Sign out</button></form>
<p class="table-meta d-help">Need help? Email or message Ultimate App support with your driver code <?= e($driver['code']) ?>.</p>
<?php driver_footer(['assets/driver.js?v=4']);
