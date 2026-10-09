<?php
declare(strict_types=1);

require_once __DIR__ . '/kyc.php';

/**
 * Handles the KYC form post for any portal. Returns an error message, or redirects on success.
 * Call before any output (the page must already have verified the signed-in account).
 */
function kyc_handle_post(PDO $pdo, string $type, int $subjectId, string $redirect): string
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['kyc_action'] ?? '') !== 'submit') return '';
    verify_csrf();
    rate_limit_enforce('kyc_submit', $type . ':' . $subjectId, 8, 86400);
    try {
        $ref = kyc_submit($pdo, $type, $subjectId, $_POST);
        $_SESSION['flash'][] = ['success', 'Thank you! Your identity verification ' . $ref . ' was sent. We usually review it within 1 business day.'];
        redirect($redirect);
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    } catch (Throwable $e) {
        error_log('KYC submit failed: ' . get_class($e) . ' ' . $e->getMessage());
        return 'We could not save your verification. Please try again.';
    }
}

/** The KYC panel: current status, then the ID + liveness wizard when a submission is needed. */
function kyc_render(PDO $pdo, string $type, int $subjectId, string $defaultName, string $assetBase, string $error = ''): void
{
    $status = kyc_status($pdo, $type, $subjectId);
    $latest = kyc_latest($pdo, $type, $subjectId);
    $e = static fn(?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $why = [
        'user' => 'Verifying keeps your wallet safe and unlocks sending money and e-wallet cash-in.',
        'merchant' => 'The owner or authorised person verifies once. Needed before your store can accept payments.',
        'driver' => 'Needed before you can accept rides. It protects passengers and you.',
        'agent' => 'Needed before your account is approved and before you can cash out.',
    ][$type];
    echo '<link rel="stylesheet" href="' . $e($assetBase) . 'assets/css/kyc.css?v=1">';
    echo '<section class="kyc">';
    echo '<div class="kyc-status kyc-' . $e($status) . '"><div><h2>Identity verification ' . kyc_badge($status) . '</h2>';
    if ($status === 'approved') {
        echo '<p>Your identity is verified. Thank you!</p>';
    } elseif ($status === 'pending') {
        echo '<p>We received your ID and selfie' . ($latest ? ' (' . $e($latest['reference']) . ', ' . $e(date('M j, Y g:i A', strtotime($latest['created_at']))) . ')' : '') . '. We usually review it within 1 business day.</p>';
    } else {
        echo '<p>' . $e($why) . '</p>';
        if ($latest && in_array($status, ['rejected', 'needs_info'], true) && $latest['review_note']) echo '<p class="kyc-note"><b>From our team:</b> ' . $e($latest['review_note']) . '</p>';
    }
    echo '</div></div>';
    if (in_array($status, ['approved', 'pending'], true)) { echo '</section>'; return; }
    if ($error !== '') echo '<p class="kyc-alert" role="alert">' . $e($error) . '</p>';
    $vendor = $assetBase . 'assets/vendor/mediapipe/';
    ?>
<form method="post" class="kyc-form" data-kyc data-vendor="<?= $e($vendor) ?>" novalidate>
    <input type="hidden" name="csrf" value="<?= $e(csrf_token()) ?>"><input type="hidden" name="kyc_action" value="submit">
    <ol class="kyc-steps" aria-label="Steps"><li class="on" data-step-dot="1">Your ID</li><li data-step-dot="2">Selfie check</li><li data-step-dot="3">Send</li></ol>

    <fieldset class="kyc-pane" data-pane="1">
        <legend>1 · Your valid ID</legend>
        <label>ID type<select name="id_type" required><option value="">Choose…</option><?php foreach (kyc_id_types() as $k => $l): ?><option value="<?= $e($k) ?>"><?= $e($l) ?></option><?php endforeach; ?></select></label>
        <label>Full name as printed on the ID<input name="full_name" required maxlength="120" autocomplete="name" value="<?= $e($defaultName) ?>"></label>
        <div class="kyc-row">
            <label>ID number <small>(optional)</small><input name="id_number" maxlength="60" autocomplete="off"></label>
            <label>Birthdate <small>(optional)</small><input name="birthdate" type="date"></label>
        </div>
        <div class="kyc-ids">
            <div class="kyc-id" data-id-side="id_front"><span class="kyc-id-title">Front of ID</span><img alt="Front of ID preview" hidden data-preview><span class="kyc-id-empty" data-empty>Clear photo, all corners visible</span>
                <label class="kyc-btn">Take or choose photo<input type="file" accept="image/*" capture="environment" data-file hidden></label><input type="hidden" name="id_front" data-value></div>
            <div class="kyc-id" data-id-side="id_back"><span class="kyc-id-title">Back of ID</span><img alt="Back of ID preview" hidden data-preview><span class="kyc-id-empty" data-empty>Turn the card over</span>
                <label class="kyc-btn">Take or choose photo<input type="file" accept="image/*" capture="environment" data-file hidden></label><input type="hidden" name="id_back" data-value></div>
        </div>
        <p class="kyc-hint">Tips: lay the ID on a dark table, no glare, no fingers over the text.</p>
        <button type="button" class="kyc-btn primary" data-next="2">Next: selfie check</button>
    </fieldset>

    <fieldset class="kyc-pane" data-pane="2" hidden>
        <legend>2 · Selfie liveness check</legend>
        <p class="kyc-hint">We will ask you to look straight, turn left, turn right, look up, look down, then blink. Photos are taken automatically. Good light, no cap or sunglasses.</p>
        <div class="kyc-cam">
            <video playsinline muted autoplay data-video></video>
            <div class="kyc-oval" aria-hidden="true"></div>
            <p class="kyc-say" data-say role="status" aria-live="polite">Tap Start to turn on the front camera.</p>
        </div>
        <ol class="kyc-checks"><?php foreach (kyc_step_labels() as $k => $l): ?><li data-check="<?= $e($k) ?>"><img alt="" hidden data-thumb><span><?= $e($l) ?></span></li><?php endforeach; ?></ol>
        <?php foreach (KYC_STEPS as $s): ?><input type="hidden" name="frame_<?= $e($s) ?>" data-frame="<?= $e($s) ?>"><?php endforeach; ?>
        <input type="hidden" name="liveness_log" data-log><input type="hidden" name="liveness_mode" value="auto" data-mode>
        <div class="kyc-actions">
            <button type="button" class="kyc-btn primary" data-start>Start</button>
            <button type="button" class="kyc-btn" data-manual hidden>Having trouble? Take this photo</button>
            <button type="button" class="kyc-btn" data-retry hidden>Start over</button>
        </div>
        <div class="kyc-actions"><button type="button" class="kyc-btn" data-next="1">Back</button><button type="button" class="kyc-btn primary" data-next="3" disabled data-to-send>Next</button></div>
    </fieldset>

    <fieldset class="kyc-pane" data-pane="3" hidden>
        <legend>3 · Review and send</legend>
        <p class="kyc-hint">Check that your ID is readable and your face is clear in every photo.</p>
        <div class="kyc-review" data-review></div>
        <label class="kyc-agree"><input type="checkbox" required data-agree> I confirm this is my own valid ID and my own face, and I agree that Ultimate App may use them to verify my identity.</label>
        <div class="kyc-actions"><button type="button" class="kyc-btn" data-next="2">Back</button><button type="submit" class="kyc-btn primary" data-submit disabled>Send for verification</button></div>
    </fieldset>
    <noscript><p class="kyc-alert">Please turn on JavaScript and allow camera access to verify your identity.</p></noscript>
</form>
<?php
    echo '</section>';
    echo '<script src="' . $e($assetBase) . 'assets/js/kyc.js?v=1" defer></script>';
}
