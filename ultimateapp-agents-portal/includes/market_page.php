<?php
declare(strict_types=1);

require_once __DIR__ . '/marketplace.php';

/** UGo (activities) and ULocal (services) for customers: browse, details and booking. */
function mk_service_page(PDO $pdo, array $user, string $service): void
{
    $cfg = mk_services()[$service];
    $self = $cfg['page'];
    if (!mk_ready()) { http_response_code(503); exit($cfg['label'] . ' is being set up. Please try again later.'); }
    $id = (int) ($_GET['id'] ?? 0);
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        rate_limit_enforce('mk_book', 'user:' . $user['id'], 30, 3600);
        $id = (int) ($_POST['listing'] ?? 0);
        try {
            $ref = mk_book($pdo, (int) $user['id'], $id, $_POST, (string) ($_POST['request_key'] ?? ''));
            unset($_SESSION['mk_book_key']);
            redirect('mk-booking.php?ref=' . rawurlencode($ref) . '&new=1');
        } catch (InvalidArgumentException $ex) {
            $error = $ex->getMessage();
        } catch (Throwable $ex) {
            error_log('Booking failed: ' . get_class($ex) . ' ' . $ex->getMessage());
            $error = 'Could not book right now. No Credits were deducted. Please try again.';
        }
    }
    if (empty($_SESSION['mk_book_key'])) $_SESSION['mk_book_key'] = bin2hex(random_bytes(32));
    $listing = $id ? mk_listing($pdo, $id) : null;
    if ($id && (!$listing || $listing['service'] !== $service || !mk_listing_live($listing))) { http_response_code(404); $listing = null; $error = $error ?: 'This listing is no longer available.'; }
    $category = in_array($_GET['cat'] ?? '', $cfg['categories'], true) ? (string) $_GET['cat'] : '';
    $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 60);
    $pageTitle = $listing ? $listing['title'] : $cfg['label'];
    $GLOBALS['pageTitle'] = $pageTitle;
    $e = 'e';
    require __DIR__ . '/header.php';
    echo '<link rel="stylesheet" href="assets/css/market.css?v=1">';
    if ($listing) { mk_render_detail($pdo, $user, $listing, $cfg, $error); }
    else { mk_render_browse($pdo, $service, $cfg, $category, $q, $error); }
    echo '<script defer src="assets/js/market.js?v=1"></script>';
    require __DIR__ . '/footer.php';
}

function mk_render_browse(PDO $pdo, string $service, array $cfg, string $category, string $q, string $error): void
{
    $rows = mk_browse($pdo, $service, $category, $q);
    $featured = !$category && $q === '' ? array_values(array_filter($rows, static fn($r) => (int) $r['featured'])) : [];
    $self = $cfg['page'];
    $counts = [];
    foreach (mk_browse($pdo, $service) as $r) $counts[$r['category']] = ($counts[$r['category']] ?? 0) + 1;
    ?>
<section class="screen mk-screen mk-<?= e($service) ?>">
    <header class="page-head"><a href="dashboard.php" aria-label="Back"><span data-icon="arrow-left"></span></a><h1><?= e($cfg['label']) ?></h1><a href="mk-bookings.php?s=<?= e($service) ?>" aria-label="My bookings" title="My bookings"><span data-icon="receipt"></span></a></header>
    <div class="mk-hero-text"><small>BORACAY · <?= e(strtoupper($cfg['title'])) ?></small><h2><?= $service === 'ugo' ? 'Things to do on the island' : 'Book trusted local services' ?></h2><p><?= $service === 'ugo' ? 'Land tours, island hopping, water sports and more, run by local partners. Book in seconds and pay with Credits.' : 'Massage, laundry, photographers, guides and more, at your hotel or anywhere in Boracay.' ?></p></div>
    <form class="mk-search" method="get" role="search"><span data-icon="search"></span><input type="search" name="q" value="<?= e($q) ?>" placeholder="Search <?= $service === 'ugo' ? 'activities' : 'services' ?>" aria-label="Search"><?php if ($category): ?><input type="hidden" name="cat" value="<?= e($category) ?>"><?php endif; ?></form>
    <nav class="mk-chips" aria-label="Categories">
        <a href="<?= e($self) ?>" class="<?= $category === '' ? 'on' : '' ?>">All</a>
        <?php foreach ($cfg['categories'] as $c): if (!isset($counts[$c])) continue; ?><a href="<?= e($self) ?>?cat=<?= rawurlencode($c) ?>" class="<?= $category === $c ? 'on' : '' ?>"><span class="mk-chip-img" style="<?= mk_img_style(null, $service, $c) ?>"></span><?= e($c) ?></a><?php endforeach; ?>
    </nav>
    <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>
    <?php if ($featured): ?>
        <h3 class="mk-section">Popular now</h3>
        <div class="mk-rail"><?php foreach ($featured as $r): ?><a class="mk-feature" href="<?= e($self) ?>?id=<?= (int) $r['id'] ?>"><span class="mk-img" style="<?= mk_img_style($r['photo'], $service, $r['category']) ?>"><?php if ($r['badge']): ?><em><?= e($r['badge']) ?></em><?php endif; ?></span><b><?= e($r['title']) ?></b><small><?= e(mk_price_label($r)) ?></small></a><?php endforeach; ?></div>
    <?php endif; ?>
    <h3 class="mk-section"><?= $category !== '' ? e($category) : ($q !== '' ? 'Results for “' . e($q) . '”' : 'All ' . ($service === 'ugo' ? 'activities' : 'services')) ?> <small><?= count($rows) ?></small></h3>
    <?php if (!$rows): ?><p class="mk-empty">Nothing here yet. Try another category<?= $q !== '' ? ' or search word' : '' ?>.</p><?php endif; ?>
    <div class="mk-grid">
    <?php foreach ($rows as $r): ?>
        <a class="mk-card" href="<?= e($self) ?>?id=<?= (int) $r['id'] ?>">
            <span class="mk-img" style="<?= mk_img_style($r['photo'], $service, $r['category']) ?>"><?php if ($r['badge']): ?><em><?= e($r['badge']) ?></em><?php endif; ?></span>
            <span class="mk-card-body"><small><?= e($r['category']) ?><?= $r['duration_minutes'] ? ' · ' . e(mk_duration_label((int) $r['duration_minutes'])) : '' ?></small><b><?= e($r['title']) ?></b>
                <i><?= e($r['business_name'] ?: 'Ultimate App') ?></i><strong><?= e(mk_money((int) $r['price_centavos'])) ?> <small><?= e(mk_units()[$r['price_unit']] ?? '') ?></small></strong></span>
        </a>
    <?php endforeach; ?>
    </div>
    <p class="mk-join">Run tours or services in Boracay? <a href="merchant-portal/register.php">List them on Ultimate App</a></p>
    <?php require __DIR__ . '/nav.php'; ?>
</section>
    <?php
}

function mk_render_detail(PDO $pdo, array $user, array $l, array $cfg, string $error): void
{
    $service = $l['service'];
    $slots = $l['time_slots'] ? explode(',', $l['time_slots']) : [];
    $credits = mk_can_pay_credits($l);
    $includes = array_filter(array_map('trim', explode("\n", (string) $l['includes_text'])));
    $old = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [];
    $val = static fn(string $k, string $d = '') => e(is_string($old[$k] ?? null) ? $old[$k] : $d);
    $qty = max((int) $l['min_qty'], (int) ($old['quantity'] ?? $l['min_qty']));
    $unitIsGroup = $l['price_unit'] === 'group';
    $fee = 0;
    try { $fee = $credits ? mk_booking_quote($l, $qty, 'credits')['fee'] : 0; } catch (InvalidArgumentException) { $credits = false; }
    ?>
<section class="screen mk-screen mk-detail">
    <header class="page-head mk-float"><a href="<?= e($cfg['page']) ?>" aria-label="Back"><span data-icon="arrow-left"></span></a><h1 class="sr-only"><?= e($l['title']) ?></h1><span></span></header>
    <div class="mk-cover" style="<?= mk_img_style($l['photo'], $service, $l['category']) ?>" role="img" aria-label="<?= e($l['title']) ?>"></div>
    <div class="mk-sheet">
        <small class="mk-kicker"><?= e($cfg['label']) ?> · <?= e($l['category']) ?></small>
        <h2><?= e($l['title']) ?></h2>
        <p class="mk-by">by <b><?= e($l['business_name'] ?: 'Ultimate App') ?></b></p>
        <ul class="mk-facts">
            <?php if ($l['duration_minutes']): ?><li><span data-icon="clock"></span><?= e(mk_duration_label((int) $l['duration_minutes'])) ?></li><?php endif; ?>
            <?php if ($l['location']): ?><li><span data-icon="map-pin"></span><?= e($l['location']) ?></li><?php endif; ?>
            <li><span data-icon="check-circle"></span><?= (int) $l['cancel_hours'] ? 'Free cancellation up to ' . (int) $l['cancel_hours'] . ' hours before' : 'Free cancellation while pending' ?></li>
            <?php if ((int) $l['instant_confirm']): ?><li><span data-icon="zap"></span>Instant confirmation</li><?php endif; ?>
        </ul>
        <?php if ($l['summary']): ?><p class="mk-summary"><?= e($l['summary']) ?></p><?php endif; ?>
        <?php if ($l['description']): ?><p class="mk-desc"><?= nl2br(e($l['description'])) ?></p><?php endif; ?>
        <?php if ($includes): ?><h3>What's included</h3><ul class="mk-incl"><?php foreach ($includes as $i): ?><li><?= e($i) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php if ($l['meeting_point']): ?><h3>Meeting point</h3><p class="mk-desc"><?= e($l['meeting_point']) ?><?php if ($l['latitude']): ?> · <a href="https://www.google.com/maps/search/?api=1&amp;query=<?= e($l['latitude'] . ',' . $l['longitude']) ?>" target="_blank" rel="noopener">Map</a><?php endif; ?></p><?php endif; ?>

        <form method="post" class="mk-book" id="book" data-mk-book data-price="<?= (int) $l['price_centavos'] ?>" data-group="<?= $unitIsGroup ? 1 : 0 ?>" data-fee="<?= e(json_encode(p2m_fee_rule('credits'))) ?>">
            <h3><?= $service === 'ugo' ? 'Book this activity' : 'Book this service' ?></h3>
            <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="request_key" value="<?= e($_SESSION['mk_book_key']) ?>"><input type="hidden" name="listing" value="<?= (int) $l['id'] ?>">
            <div class="mk-row">
                <label>Date<input type="date" name="date" required min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+180 days')) ?>" value="<?= $val('date', date('Y-m-d', strtotime('+1 day'))) ?>"></label>
                <?php if ($slots): ?>
                    <label>Start time<select name="time" required><?php foreach ($slots as $t): ?><option value="<?= e($t) ?>"<?= ($old['time'] ?? '') === $t ? ' selected' : '' ?>><?= e(date('g:i A', strtotime($t))) ?></option><?php endforeach; ?></select></label>
                <?php else: ?>
                    <label>Time<input type="time" name="time" required value="<?= $val('time', '10:00') ?>"></label>
                <?php endif; ?>
            </div>
            <label class="mk-qty"><span><?= $service === 'ugo' ? 'Guests' : ucfirst(mk_units()[$l['price_unit']] === 'each' ? 'Quantity' : str_replace('per ', '', mk_units()[$l['price_unit']]) . 's') ?><small><?= (int) $l['min_qty'] ?>–<?= (int) $l['max_qty'] ?></small></span>
                <span class="mk-stepper"><button type="button" data-step="-1" aria-label="Less">−</button><input type="number" name="quantity" min="<?= (int) $l['min_qty'] ?>" max="<?= (int) $l['max_qty'] ?>" value="<?= $qty ?>" required inputmode="numeric"><button type="button" data-step="1" aria-label="More">+</button></span></label>
            <label>Mobile number<input type="tel" name="contact_mobile" required maxlength="13" placeholder="09XXXXXXXXX" autocomplete="tel" value="<?= $val('contact_mobile', (string) ($user['mobile'] ?? '')) ?>"></label>
            <?php if ($service === 'ulocal'): ?><label>Where?<input name="address" required maxlength="255" placeholder="Hotel and room, or address" value="<?= $val('address') ?>"></label><?php endif; ?>
            <label>Notes <small>(optional)</small><textarea name="notes" rows="2" maxlength="500" placeholder="<?= $service === 'ugo' ? 'Hotel for pick-up, kids, special needs…' : 'Anything the provider should know' ?>"><?= $val('notes') ?></textarea></label>
            <fieldset class="mk-pay"><legend>Payment</legend>
                <?php if ($credits): ?><label><input type="radio" name="pay_method" value="credits" <?= ($old['pay_method'] ?? 'credits') === 'credits' ? 'checked' : '' ?>><span><b>Pay now with Credits</b><small>Balance <?= format_credits((float) $user['credits']) ?> · refunded in full if declined or cancelled in time</small></span></label><?php endif; ?>
                <label><input type="radio" name="pay_method" value="on_site" <?= !$credits || ($old['pay_method'] ?? '') === 'on_site' ? 'checked' : '' ?>><span><b>Pay on the day</b><small>Pay the provider directly<?= $credits ? '' : ' (this listing is booked as a request)' ?></small></span></label>
            </fieldset>
            <div class="mk-total"><span>Total</span><strong data-total><?= e(mk_money((int) $l['price_centavos'] * ($unitIsGroup ? 1 : $qty) + ($credits ? $fee : 0))) ?></strong></div>
            <p class="mk-fine" data-fee-note<?= $credits && $fee ? '' : ' hidden' ?>>Includes the Credits payment charge.</p>
            <button class="mk-cta" type="submit"><?= (int) $l['instant_confirm'] ? 'Book now' : 'Request booking' ?> · <span data-total><?= e(mk_money((int) $l['price_centavos'] * ($unitIsGroup ? 1 : $qty) + ($credits ? $fee : 0))) ?></span></button>
            <p class="mk-fine"><?= (int) $l['instant_confirm'] ? 'Confirmed right away.' : 'The provider confirms your booking, usually within a few hours. If they decline, Credits are returned right away.' ?></p>
        </form>
    </div>
    <a class="mk-sticky" href="#book"><span><small>From</small><b><?= e(mk_price_label($l)) ?></b></span><em>Book</em></a>
</section>
    <?php
}
