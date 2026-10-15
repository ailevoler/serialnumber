<?php
require_once __DIR__ . '/_bootstrap.php';
if (driver_current()) redirect('dashboard.php');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit_enforce('driver_register', 'ip:' . rate_limit_client_ip(), 10, 3600);
    try {
        if (dp('agree') !== '1') throw new InvalidArgumentException('Please confirm your details and accept the driver terms.');
        $data = uride_validate_driver($_POST, true);
        $id = uride_register_driver($pdo, $data, dp('selfie_data'), $_FILES['selfie_file'] ?? null, $_FILES);
        session_regenerate_id(true);
        $_SESSION = ['driver_id' => $id];
        dflash('success', 'Application submitted! We will review your documents and notify you here.');
        redirect('dashboard.php');
    } catch (InvalidArgumentException $ex) {
        $error = $ex->getMessage();
    } catch (Throwable $ex) {
        error_log('Driver registration failed: ' . get_class($ex) . ' ' . $ex->getMessage());
        $error = 'We could not submit your application. Please try again.';
    }
}
$v = static fn(string $k): string => e(dp($k));
$sel = static fn(string $k, string $val): string => dp($k) === $val ? ' selected' : '';
driver_header('Driver application');
?>
<form class="auth-card wide" method="post" enctype="multipart/form-data" data-driver-form>
    <div class="brand"><span class="brand-mark"></span><span><b>URIDE DRIVER</b><small>Ultimate App Boracay</small></span></div>
    <h1>Apply to drive with URide</h1>
    <p>E-Trike, motorcycle and car drivers in Boracay can receive nearby ride requests from Ultimate App passengers. Approval takes 1–2 working days.</p>
    <?php if ($error): ?><div class="alert" role="alert"><?= e($error) ?></div><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <div class="reg-section"><h2>1 · Personal details</h2><p>As shown on your driver's license.</p>
        <div class="form-grid">
            <div class="field"><label for="full_name">Full name</label><input id="full_name" name="full_name" type="text" required maxlength="120" autocomplete="name" value="<?= $v('full_name') ?>"></div>
            <div class="field"><label for="birthdate">Birthdate</label><input id="birthdate" name="birthdate" type="date" required value="<?= $v('birthdate') ?>"></div>
            <div class="field"><label for="mobile">Mobile number</label><input id="mobile" name="mobile" type="tel" required placeholder="09XXXXXXXXX" autocomplete="tel" value="<?= $v('mobile') ?>"></div>
            <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" required autocomplete="email" value="<?= $v('email') ?>"></div>
            <div class="field"><label for="barangay">Barangay</label><select id="barangay" name="barangay" required><option value="">Choose…</option><?php foreach (uride_barangays() as $b): ?><option<?= $sel('barangay', $b) ?>><?= e($b) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="field mt"><label for="address">Home address</label><input id="address" name="address" type="text" required maxlength="255" placeholder="Sitio / street / landmark" value="<?= $v('address') ?>"></div>
    </div>

    <div class="reg-section"><h2>2 · Selfie photo</h2><p>Face the camera in good light, no hat or sunglasses. Passengers see this photo when you accept their ride.</p>
        <div class="d-selfie" data-selfie>
            <video playsinline muted hidden data-selfie-video></video>
            <img alt="Your selfie" hidden data-selfie-preview>
            <div class="d-selfie-empty" data-selfie-empty><?= driver_icon('account') ?><small>No photo yet</small></div>
        </div>
        <input type="hidden" name="selfie_data" data-selfie-data>
        <div class="d-selfie-actions">
            <button type="button" class="btn" data-selfie-start>Open camera</button>
            <button type="button" class="btn" data-selfie-snap hidden>Take photo</button>
            <button type="button" class="btn" data-selfie-retake hidden>Retake</button>
            <label class="btn d-file">Upload photo<input type="file" name="selfie_file" accept="image/*" capture="user" hidden data-selfie-file></label>
        </div>
        <p class="table-meta" role="status" data-selfie-status></p>
    </div>

    <div class="reg-section"><h2>3 · License &amp; vehicle</h2><p>You can only receive requests for the vehicle type you register. <b>Boat operators:</b> enter your boat captain's (MARINA) license and the boat's registration number as the plate number. Boats get point-to-point rides only.</p>
        <div class="form-grid">
            <div class="field"><label for="license_no">Driver's license no.</label><input id="license_no" name="license_no" type="text" required maxlength="20" placeholder="N01-23-456789" value="<?= $v('license_no') ?>"></div>
            <div class="field"><label for="license_expiry">License expiry</label><input id="license_expiry" name="license_expiry" type="date" required value="<?= $v('license_expiry') ?>"></div>
            <div class="field"><label for="vehicle_type">Vehicle type</label><select id="vehicle_type" name="vehicle_type" required><option value="">Choose…</option><?php foreach (uride_vehicles() as $k => $veh): ?><option value="<?= e($k) ?>"<?= $sel('vehicle_type', $k) ?>><?= e($veh['label']) ?> · <?= e($veh['seats']) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="plate_no">Plate number</label><input id="plate_no" name="plate_no" type="text" required maxlength="12" placeholder="123 ABC" value="<?= $v('plate_no') ?>"></div>
            <div class="field"><label for="vehicle_model">Make &amp; model</label><input id="vehicle_model" name="vehicle_model" type="text" required maxlength="80" placeholder="e.g. Bemac E-Trike, Honda Click" value="<?= $v('vehicle_model') ?>"></div>
            <div class="field"><label for="vehicle_color">Color</label><input id="vehicle_color" name="vehicle_color" type="text" required maxlength="40" value="<?= $v('vehicle_color') ?>"></div>
            <div class="field"><label for="franchise_no">Franchise / MTOP no. <small class="muted">(if any)</small></label><input id="franchise_no" name="franchise_no" type="text" maxlength="60" value="<?= $v('franchise_no') ?>"></div>
            <div class="field"><label for="toda_name">TODA / association <small class="muted">(if any)</small></label><input id="toda_name" name="toda_name" type="text" maxlength="120" value="<?= $v('toda_name') ?>"></div>
        </div>
    </div>

    <div class="reg-section"><h2>4 · Documents</h2><p>Clear photos or PDF, up to 6 MB each. Visible only to you and Ultimate App reviewers.</p>
        <?php foreach (uride_doc_types() as $k => [$label, $required]): ?>
            <div class="file-row"><label for="doc_<?= e($k) ?>"><?= e($label) ?><?= $required ? ' <b class="req">*</b>' : ' <small class="muted">(optional)</small>' ?></label><input id="doc_<?= e($k) ?>" name="doc_<?= e($k) ?>" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp"<?= $required ? ' required' : '' ?>></div>
        <?php endforeach; ?>
    </div>

    <div class="reg-section"><h2>5 · Password</h2><p>Sign in with your email or mobile number and this password.</p>
        <div class="form-grid">
            <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" required autocomplete="new-password"><small>8+ characters with letters and numbers.</small></div>
            <div class="field"><label for="password_confirm">Confirm password</label><input id="password_confirm" name="password_confirm" type="password" required autocomplete="new-password"></div>
        </div>
    </div>

    <div class="reg-section">
        <div class="alert info">How earnings work: passengers pay the fare shown in the app, in cash or Ultimate App Credits. URide's commission (currently <?= e(uride_commission_label()) ?> for rides and <?= e(uride_commission_label('rental')) ?> for Rent a Ride) is deducted from your driver wallet after each trip, so keep your wallet topped up (via QR Ph) to stay online. Credits fares are added to your wallet and can be withdrawn.</div>
        <label class="d-agree"><input type="checkbox" name="agree" value="1"<?= dp('agree') === '1' ? ' checked' : '' ?>> I confirm these details are true and agree to the URide driver terms, commission and safety rules.</label>
        <div class="form-actions"><button class="btn grad" type="submit" data-submit>Submit application</button><a href="login.php">I already have an account</a></div>
    </div>
</form>
<?php driver_footer(['assets/driver.js?v=5']);
