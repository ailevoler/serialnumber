<?php
require_once __DIR__ . '/includes/functions.php';
require_auth();
$active = 'community';
$view = is_string($_GET['view'] ?? null) ? $_GET['view'] : '';
if (!in_array($view, ['', 'lgu','emergency','barangay'], true)) { http_response_code(404); exit('Section not found.'); }
$title = ['' => 'Community', 'lgu'=>'LGU Malay','emergency'=>'Emergency','barangay'=>'UBarangay'][$view];
$pageTitle = $title;
require __DIR__ . '/includes/header.php';
?>
<section class="screen with-nav community-screen">
    <header class="page-head"><a href="<?= $view === '' ? 'dashboard.php' : 'community.php' ?>" aria-label="Back"><span data-icon="arrow-left"></span></a><h1><?= e($title) ?></h1><span></span></header>
    <?php if ($view === ''): ?>
        <div class="community-heading"><small>BORACAY - COMMUNITY</small><h2>Your island community</h2><p>Local government, emergency help, barangays and island news in one place.</p></div>
        <div class="community-links">
            <a href="community.php?view=emergency"><span data-icon="phone"></span><span><strong>Emergency</strong><small>National hotline 911</small></span><span data-icon="chevron-right"></span></a>
            <a href="community.php?view=lgu"><span data-icon="home"></span><span><strong>LGU Malay</strong><small>Municipal office contacts</small></span><span data-icon="chevron-right"></span></a>
            <?php if (ubarangay_enabled()): ?><a href="ubarangay.php"><span data-icon="map-pin"></span><span><strong>UBarangay</strong><small>Barangay certificates and clearances</small></span><span data-icon="chevron-right"></span></a><?php endif; ?>
            <a href="community.php?view=barangay"><span data-icon="map-pin"></span><span><strong>Island barangays</strong><small>Balabag, Manoc-Manoc, Yapak</small></span><span data-icon="chevron-right"></span></a>
            <a href="news.php"><span data-icon="megaphone"></span><span><strong>UNews</strong><small>Island news and announcements</small></span><span data-icon="chevron-right"></span></a>
            <a href="support.php"><span data-icon="circle-alert"></span><span><strong>UHelp</strong><small>Get help with the app</small></span><span data-icon="chevron-right"></span></a>
        </div>
    <?php elseif ($view === 'lgu'): ?>
        <div class="community-heading"><small>BORACAY - AKLAN</small><h2>Municipality of Malay</h2><p>Local government contact for municipal inquiries.</p></div>
        <div class="community-links"><a href="tel:+63362888775"><span data-icon="phone"></span><span><strong>Call municipal office</strong><small>(036) 288-8775</small></span><span data-icon="chevron-right"></span></a><a href="mailto:lgumalayaklan2@yahoo.com"><span data-icon="mail"></span><span><strong>Email municipal office</strong><small>lgumalayaklan2@yahoo.com</small></span><span data-icon="chevron-right"></span></a><a href="https://aklan.gov.ph/list-of-aklan-municipal-lgu-contact-details/" target="_blank" rel="noopener noreferrer"><span data-icon="receipt"></span><span><strong>Official Aklan directory</strong><small>Verify current LGU contact details</small></span><span data-icon="chevron-right"></span></a></div>
        <p class="community-footnote">For urgent danger or medical emergencies, use 911 instead of the municipal office.</p>
    <?php elseif ($view === 'emergency'): ?>
        <div class="community-heading"><small>PHILIPPINES - EMERGENCY</small><h2>Need urgent help?</h2><p>Call the national emergency hotline. This app does not dispatch responders.</p></div>
        <a class="emergency-call" href="tel:911"><span data-icon="phone"></span><span><small>National emergency hotline</small><strong>911</strong></span><span>Call now</span></a>
        <p class="community-footnote">If calling is unavailable, ask someone nearby for help. Check the <a href="https://ehotlines.e.gov.ph/" target="_blank" rel="noopener noreferrer">official emergency hotline directory</a> for other government contacts.</p>
    <?php else: ?>
        <div class="community-heading"><small>BORACAY - MALAY</small><h2>Island barangays</h2><p>Find the three barangays on Boracay Island.</p></div>
        <div class="barangay-list">
            <?php foreach (['Balabag','Manoc-Manoc','Yapak'] as $barangay): ?><a href="https://www.google.com/maps/search/?api=1&amp;query=<?= rawurlencode('Barangay Hall ' . $barangay . ', Boracay, Malay, Aklan') ?>" target="_blank" rel="noopener noreferrer"><span data-icon="map-pin"></span><span><strong><?= e($barangay) ?></strong><small>Open map search for barangay hall</small></span><span data-icon="chevron-right"></span></a><?php endforeach; ?>
        </div>
        <p class="community-footnote">Map search results are not verified office pins. <a href="https://aklan.gov.ph/tourism/malay/" target="_blank" rel="noopener noreferrer">Aklan provincial tourism reference</a>.</p>
    <?php endif; ?>
    <?php require __DIR__ . '/includes/nav.php'; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
