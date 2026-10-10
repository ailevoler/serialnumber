<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ubarangay.php';
$user = require_auth();
if (!ubarangay_enabled()) {
    // Temporarily off: no new applications or payments. Earlier applications and issued certificates stay viewable.
    $stmt = $pdo->prepare('SELECT a.reference, a.status, a.created_at, t.name FROM brgy_applications a JOIN brgy_permit_types t ON t.id = a.permit_type_id WHERE a.user_id = ? ORDER BY a.id DESC LIMIT 20');
    $stmt->execute([$user['id']]);
    $mine = $stmt->fetchAll();
    $pageTitle = 'UBarangay';
    $active = 'home';
    require __DIR__ . '/includes/header.php';
    ?>
<section class="screen seed-screen">
    <header class="page-head"><a href="dashboard.php" aria-label="Back"><span data-icon="arrow-left"></span></a><h1>UBarangay</h1><span></span></header>
    <p class="seed-notice">UBarangay is temporarily unavailable. New permit applications and payments are paused. Please visit your barangay hall for permits and clearances.</p>
    <?php if ($mine): ?><h2>My applications</h2><div class="seed-request-list"><?php foreach ($mine as $m): ?><a href="brgy-application.php?ref=<?= e($m['reference']) ?>"><span><small><?= e($m['reference']) ?></small><strong><?= e($m['name']) ?></strong><small><?= e(ucwords(str_replace('_', ' ', $m['status']))) ?> · <?= e(date('M j, Y', strtotime($m['created_at']))) ?></small></span></a><?php endforeach; ?></div><?php endif; ?>
    <a class="btn dark" href="dashboard.php">Back to Home</a>
</section>
<?php
    require __DIR__ . '/includes/nav.php';
    require __DIR__ . '/includes/footer.php';
    exit;
}
$active = 'home';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'barangay') {
    verify_csrf();
    $b = is_string($_POST['barangay'] ?? null) ? $_POST['barangay'] : '';
    if (brgy_valid($b)) $pdo->prepare('UPDATE users SET barangay = ? WHERE id = ?')->execute([$b, $user['id']]);
    redirect('ubarangay.php');
}
$barangay = brgy_valid((string) ($user['barangay'] ?? '')) ? $user['barangay'] : null;
$tab = in_array($_GET['tab'] ?? '', ['permits', 'news', 'announcements', 'activities'], true) ? $_GET['tab'] : 'permits';
$pageTitle = 'UBarangay';
require __DIR__ . '/includes/header.php';
?>
<section class="screen with-nav ub-screen">
    <header class="page-head"><a href="dashboard.php" aria-label="Back"><span data-icon="arrow-left"></span></a><h1>UBarangay</h1><a href="community.php?view=barangay" aria-label="Barangay hall directions" title="Barangay hall directions"><span data-icon="map-pin"></span></a></header>
    <?php if (!$barangay || isset($_GET['change'])): ?>
        <div class="ub-hero"><small>BORACAY · MALAY, AKLAN</small><h2>Choose your barangay</h2><p>Permits, news and activities are shown for the barangay where you live or do business.</p></div>
        <form method="post" class="ub-pick"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="barangay">
            <?php foreach (array_keys(brgy_barangays()) as $b): ?><button type="submit" name="barangay" value="<?= e($b) ?>" class="<?= $barangay === $b ? 'on' : '' ?>"><span data-icon="home"></span><strong>Barangay <?= e($b) ?></strong><small><?= e(brgy_officials($b)['address']) ?></small></button><?php endforeach; ?>
        </form>
    <?php else: $off = brgy_officials($barangay); ?>
        <div class="ub-hero"><small>BORACAY · MALAY, AKLAN</small><h2>Barangay <?= e($barangay) ?></h2><p><?= e($off['address']) ?><?= $off['contact'] ? ' · ' . e($off['contact']) : '' ?></p><a href="?change=1">Change barangay</a></div>
        <nav class="ub-tabs" aria-label="UBarangay sections">
            <?php foreach (['permits' => 'Permits', 'news' => 'News', 'announcements' => 'Announcements', 'activities' => 'Activities'] as $k => $l): ?><a href="?tab=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= e($l) ?></a><?php endforeach; ?>
        </nav>
        <?php if ($tab === 'permits'):
            $types = brgy_types($pdo);
            $mine = $pdo->prepare('SELECT a.reference, a.status, a.created_at, a.certificate_no, a.revoked_at, a.valid_until, t.name FROM brgy_applications a JOIN brgy_permit_types t ON t.id = a.permit_type_id WHERE a.user_id = ? ORDER BY a.id DESC LIMIT 20');
            $mine->execute([$user['id']]); $mine = $mine->fetchAll(); ?>
            <?php if ($mine): ?><h3 class="ub-h">My applications</h3><div class="ub-apps"><?php foreach ($mine as $a): ?>
                <a href="brgy-application.php?ref=<?= e($a['reference']) ?>"><span><strong><?= e($a['name']) ?></strong><small><?= e($a['certificate_no'] ?? $a['reference']) ?> · <?= e(date('M j, Y', strtotime($a['created_at']))) ?></small></span><b class="ub-st st-<?= e($a['status']) ?>"><?= e($a['revoked_at'] ? 'Revoked' : brgy_statuses()[$a['status']]) ?></b></a>
            <?php endforeach; ?></div><?php endif; ?>
            <h3 class="ub-h">Apply online</h3>
            <p class="pay-fine">Fill in the form, take a selfie, upload the requirements and pay with Credits, BCash or QR Ph. After the barangay approves, print your certificate.</p>
            <div class="ub-types"><?php foreach ($types as $t): $reqs = brgy_requirements($t); ?>
                <a href="brgy-apply.php?type=<?= e($t['code']) ?>" class="ub-type"><span class="ub-ic" data-icon="receipt"></span><span><strong><?= e($t['name']) ?></strong><small><?= e($t['description']) ?></small><small class="ub-req"><?= count($reqs) ?> requirement<?= count($reqs) === 1 ? '' : 's' ?> · valid <?= (int) $t['validity_days'] ?> days</small></span><b><?= (int) $t['fee_centavos'] === 0 ? 'Free' : '₱' . peso((int) $t['fee_centavos']) ?></b></a>
            <?php endforeach; ?></div>
        <?php else:
            $type = ['news' => 'news', 'announcements' => 'announcement', 'activities' => 'activity'][$tab];
            $order = $type === 'activity' ? 'CASE WHEN event_at >= NOW() THEN 0 ELSE 1 END, CASE WHEN event_at >= NOW() THEN event_at END ASC, event_at DESC' : 'created_at DESC';
            $posts = $pdo->prepare("SELECT * FROM brgy_posts WHERE type = ? AND published = 1 AND (barangay IS NULL OR barangay = ?) ORDER BY $order LIMIT 30");
            $posts->execute([$type, $barangay]); $posts = $posts->fetchAll(); ?>
            <?php if (!$posts): ?><p class="notice-empty">No <?= e($tab) ?> yet from Barangay <?= e($barangay) ?>.</p><?php endif; ?>
            <div class="ub-posts"><?php foreach ($posts as $p): $upcoming = $p['event_at'] && $p['event_at'] >= date('Y-m-d H:i:s'); ?>
                <a href="brgy-post.php?id=<?= (int) $p['id'] ?>" class="ub-post <?= e($type) ?>">
                    <?php if ($type === 'activity' && $p['event_at']): ?><span class="ub-date<?= $upcoming ? ' up' : '' ?>"><b><?= e(date('d', strtotime($p['event_at']))) ?></b><?= e(strtoupper(date('M', strtotime($p['event_at'])))) ?></span><?php endif; ?>
                    <span><small><?= $type === 'activity' ? e(($p['event_at'] ? date('D, g:i A', strtotime($p['event_at'])) : 'Date to follow') . ($p['location'] ? ' · ' . $p['location'] : '')) : e(date('M j, Y', strtotime($p['created_at']))) . ($p['barangay'] ? '' : ' · All barangays') ?></small><strong><?= e($p['title']) ?></strong><em><?= e(mb_strimwidth(preg_replace('/\s+/u', ' ', $p['body']) ?? '', 0, 120, '…')) ?></em></span>
                </a>
            <?php endforeach; ?></div>
        <?php endif; ?>
    <?php endif; ?>
    <?php require __DIR__ . '/includes/nav.php'; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
