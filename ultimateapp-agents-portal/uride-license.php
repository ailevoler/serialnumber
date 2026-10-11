<?php
// Customer's driver's license for URide self-drive rentals.
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/uride.php';
$user = require_auth();
header('Cache-Control: no-store');
$error = '';
if (!uride_licenses_ready($pdo)) { http_response_code(503); exit('Self-drive is not set up yet.'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit_enforce('uride_license', 'user:' . $user['id'], 10, 3600);
    try {
        uride_save_license($pdo, (int) $user['id'], $_POST, $_FILES['photo'] ?? null);
        redirect('uride.php?mode=rent&drive=self');
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('License upload failed: ' . $e->getMessage());
        $error = 'Could not save your license. Please try again.';
    }
}
$license = uride_user_license($pdo, (int) $user['id']);
$pageTitle = 'Driver\'s license';
require __DIR__ . '/includes/header.php';
?>
<section class="screen uride-screen">
    <header class="page-head"><a href="uride.php?mode=rent&amp;drive=self" aria-label="Back"><span data-icon="arrow-left"></span></a><h1>Driver's license</h1><span></span></header>
    <p class="uride-help">For <b>self-drive</b> rentals. The vehicle owner also checks your physical license and ID at hand-over. Your photo is private: only Ultimate App and the owner of a vehicle you rent can see it.</p>
    <?php if ($license): ?>
        <div class="uride-license-card <?= e($license['status']) ?>">
            <small><?= $license['status'] === 'approved' ? 'Checked by Ultimate App' : ($license['status'] === 'rejected' ? 'Not accepted' : 'On file · waiting for review') ?></small>
            <strong><?= e(uride_license_label($license)) ?></strong>
            <?php if ($license['status'] === 'rejected' && $license['review_note']): ?><p><?= e($license['review_note']) ?></p><?php endif; ?>
        </div>
        <h3>Update my license</h3>
    <?php endif; ?>
    <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="uride-license-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label>License number<input name="license_no" required maxlength="25" autocapitalize="characters" placeholder="e.g. N01-12-345678" value="<?= e(is_string($_POST['license_no'] ?? null) ? $_POST['license_no'] : '') ?>"></label>
        <label>Expiry date<input type="date" name="expiry" required min="<?= e(date('Y-m-d')) ?>" value="<?= e(is_string($_POST['expiry'] ?? null) ? $_POST['expiry'] : '') ?>"></label>
        <fieldset><legend>My license allows me to drive</legend>
            <label class="check"><input type="checkbox" name="can_motorcycle" value="1"<?= ($_POST['can_motorcycle'] ?? '') === '1' ? ' checked' : '' ?>> Motorcycle / tricycle, incl. e-trike (code A or A1)</label>
            <label class="check"><input type="checkbox" name="can_car" value="1"<?= ($_POST['can_car'] ?? '') === '1' ? ' checked' : '' ?>> Car (code B)</label>
        </fieldset>
        <label>Photo of the front of the license<input type="file" name="photo" accept="image/*" capture="environment" required></label>
        <p class="uride-help">Lay the card flat in good light. All text must be readable. Foreign licenses are accepted if they show your name, number, expiry and vehicle type in English.</p>
        <button class="uride-submit" type="submit">Save my license <span data-icon="arrow-right"></span></button>
    </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
