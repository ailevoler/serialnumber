<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ubarangay.php';
$user = require_auth();
if (!ubarangay_enabled()) redirect('ubarangay.php');
header('Cache-Control: no-store');
$barangay = brgy_valid((string) ($user['barangay'] ?? '')) ? $user['barangay'] : null;
if (!$barangay) redirect('ubarangay.php');

$app = null;
if (is_string($_GET['ref'] ?? null) || is_string($_POST['ref'] ?? null)) {
    $stmt = $pdo->prepare('SELECT * FROM brgy_applications WHERE reference = ? AND user_id = ?');
    $stmt->execute([(string) ($_POST['ref'] ?? $_GET['ref']), $user['id']]);
    $app = $stmt->fetch() ?: null;
    if (!$app || $app['status'] !== 'needs_info') redirect('ubarangay.php');
    $type = brgy_type($pdo, (int) $app['permit_type_id']);
} else {
    $type = brgy_type($pdo, (string) ($_POST['type'] ?? $_GET['type'] ?? ''));
    if (!$type || !(int) $type['active']) redirect('ubarangay.php');
}
$reqs = brgy_requirements($type);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit_enforce('brgy_apply', 'user:' . $user['id'], 12, 3600);
    try {
        if (!$app && ($_POST['certify'] ?? '') !== '1') throw new InvalidArgumentException('Please certify that the information is true and correct.');
        $form = brgy_validate_form($_POST, $type);
        $selfie = is_string($_POST['selfie_data'] ?? null) ? $_POST['selfie_data'] : '';
        if ($app) {
            brgy_resubmit($pdo, $app, $type, $form, $selfie, $_FILES['selfie_file'] ?? null, $_FILES);
            redirect('brgy-application.php?ref=' . rawurlencode($app['reference']) . '&resubmitted=1');
        }
        $ref = brgy_create_application($pdo, (int) $user['id'], $type, $barangay, $form, $selfie, $_FILES['selfie_file'] ?? null, $_FILES);
        redirect(((int) $type['fee_centavos'] === 0 ? 'brgy-application.php' : 'brgy-pay.php') . '?ref=' . rawurlencode($ref));
    } catch (InvalidArgumentException $ex) {
        $error = $ex->getMessage();
    } catch (Throwable $ex) {
        error_log('UBarangay application failed: ' . $ex->getMessage());
        $error = 'We could not submit your application. Please try again.';
    }
}
$v = static function (string $k, string $default = '') use ($app): string {
    if (isset($_POST[$k]) && is_string($_POST[$k])) return e($_POST[$k]);
    return e((string) ($app[$k] ?? $default));
};
$sel = static function (string $k, string $val) use ($app): string {
    $cur = is_string($_POST[$k] ?? null) ? $_POST[$k] : (string) ($app[$k] ?? '');
    return $cur === $val ? ' selected' : '';
};
$pageTitle = $type['name'];
require __DIR__ . '/includes/header.php';
?>
<section class="screen ub-screen">
    <header class="page-head"><a href="<?= $app ? 'brgy-application.php?ref=' . e($app['reference']) : 'ubarangay.php' ?>" aria-label="Back"><span data-icon="arrow-left"></span></a><h1><?= $app ? 'Update application' : 'Apply' ?></h1><span></span></header>
    <div class="ub-hero small"><small>BARANGAY <?= e(mb_strtoupper($barangay)) ?></small><h2><?= e($type['name']) ?></h2><p><?= (int) $type['fee_centavos'] === 0 ? 'Free of charge' : 'Fee ₱' . peso((int) $type['fee_centavos']) ?> · valid <?= (int) $type['validity_days'] ?> days after approval</p></div>
    <?php if ($app && $app['review_note']): ?><p class="alert" role="status"><b>Barangay note:</b> <?= e($app['review_note']) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>
    <ol class="ub-steps" aria-label="Steps"><li class="on">Form</li><li>Selfie</li><li>Requirements</li><li><?= (int) $type['fee_centavos'] === 0 ? 'Submit' : 'Pay' ?></li></ol>
    <form method="post" enctype="multipart/form-data" class="pay-form ub-form" data-brgy-form<?= $app ? ' data-resubmit' : '' ?>>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <?php if ($app): ?><input type="hidden" name="ref" value="<?= e($app['reference']) ?>"><?php else: ?><input type="hidden" name="type" value="<?= e($type['code']) ?>"><?php endif; ?>

        <fieldset class="ub-section"><legend>1 · Personal information</legend>
            <label for="full_name">Complete name <small>(First Middle Last)</small></label><input class="pay-note" id="full_name" name="full_name" required maxlength="120" value="<?= $v('full_name', $user['full_name']) ?>" autocomplete="name">
            <div class="ub-grid">
                <div><label for="birthdate">Birthdate</label><input class="pay-note" id="birthdate" name="birthdate" type="date" required value="<?= $v('birthdate') ?>"></div>
                <div><label for="sex">Sex</label><select class="pay-note" id="sex" name="sex" required><option value="">Choose…</option><option value="female"<?= $sel('sex', 'female') ?>>Female</option><option value="male"<?= $sel('sex', 'male') ?>>Male</option></select></div>
                <div><label for="civil_status">Civil status</label><select class="pay-note" id="civil_status" name="civil_status" required><option value="">Choose…</option><?php foreach (['single', 'married', 'widowed', 'separated'] as $c): ?><option value="<?= $c ?>"<?= $sel('civil_status', $c) ?>><?= ucfirst($c) ?></option><?php endforeach; ?></select></div>
                <div><label for="contact">Mobile number</label><input class="pay-note" id="contact" name="contact" type="tel" required value="<?= $v('contact', $user['mobile']) ?>" placeholder="09XXXXXXXXX"></div>
            </div>
            <label for="address">House no. / street / sitio in Barangay <?= e($barangay) ?></label><input class="pay-note" id="address" name="address" required maxlength="255" value="<?= $v('address') ?>">
            <div class="ub-grid">
                <div><label for="purok">Purok / zone <small>(optional)</small></label><input class="pay-note" id="purok" name="purok" maxlength="60" value="<?= $v('purok') ?>"></div>
                <div><label for="years_residency">Years living here</label><input class="pay-note" id="years_residency" name="years_residency" type="number" min="0" max="120" inputmode="numeric" value="<?= $v('years_residency') ?>"></div>
            </div>
            <label for="purpose">Purpose</label><input class="pay-note" id="purpose" name="purpose" required maxlength="200" value="<?= $v('purpose') ?>" placeholder="e.g. employment, bank requirement, scholarship">
            <?php if ((int) $type['needs_business'] === 1): ?>
            <p class="ub-sub">Business details</p>
            <label for="business_name">Business name</label><input class="pay-note" id="business_name" name="business_name" required maxlength="160" value="<?= $v('business_name') ?>">
            <label for="business_address">Business address</label><input class="pay-note" id="business_address" name="business_address" required maxlength="255" value="<?= $v('business_address') ?>">
            <label for="business_nature">Nature of business</label><input class="pay-note" id="business_nature" name="business_nature" required maxlength="120" value="<?= $v('business_nature') ?>" placeholder="e.g. restaurant, sari-sari store, tour services">
            <?php endif; ?>
        </fieldset>

        <fieldset class="ub-section"><legend>2 · Selfie photo</legend>
            <p class="pay-fine">Face the camera in good light, no hat or sunglasses. This photo appears on your certificate.<?= $app ? ' Leave it as is to keep your current photo.' : '' ?></p>
            <div class="ub-selfie" data-selfie>
                <video playsinline muted hidden data-selfie-video></video>
                <img alt="Your selfie" hidden data-selfie-preview>
                <div class="ub-selfie-empty" data-selfie-empty><span data-icon="user"></span><small>No photo yet</small></div>
            </div>
            <input type="hidden" name="selfie_data" data-selfie-data>
            <div class="ub-selfie-actions">
                <button type="button" class="btn dark" data-selfie-start>Open camera</button>
                <button type="button" class="btn dark" data-selfie-snap hidden>Take photo</button>
                <button type="button" class="btn light" data-selfie-retake hidden>Retake</button>
                <label class="btn light ub-file">Upload photo<input type="file" name="selfie_file" accept="image/*" capture="user" hidden data-selfie-file></label>
            </div>
            <p class="qr-status" role="status" data-selfie-status></p>
        </fieldset>

        <fieldset class="ub-section"><legend>3 · Requirements</legend>
            <p class="pay-fine">Take a clear photo of each document or upload a PDF.<?= $app ? ' Upload only what the barangay asked for; earlier files are kept.' : '' ?></p>
            <?php foreach ($reqs as $r): ?>
                <div class="ub-req-row">
                    <span><strong><?= e($r['label']) ?></strong><small><?= !empty($r['required']) ? 'Required' : 'Optional' ?></small><small class="ub-file-name" data-file-name></small></span>
                    <label class="btn light small ub-file"><span data-icon="image"></span> Add<input type="file" name="req_<?= e($r['key']) ?>" accept="image/*,application/pdf" hidden<?= !empty($r['required']) && !$app ? ' required' : '' ?> data-req-file></label>
                </div>
            <?php endforeach; ?>
        </fieldset>

        <?php if (!$app): ?><label class="ub-certify"><input type="checkbox" name="certify" value="1" required> I certify that the information above is true and correct, and I consent to the barangay processing my data for this request.</label><?php endif; ?>
        <button class="btn dark" type="submit" data-pay-submit><?= $app ? 'Resubmit for review' : ((int) $type['fee_centavos'] === 0 ? 'Submit application' : 'Continue to payment') ?></button>
    </form>
</section>
<script defer src="assets/js/ubarangay.js?v=1"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
