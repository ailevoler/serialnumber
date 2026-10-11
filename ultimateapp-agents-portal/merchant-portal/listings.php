<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/market_forms.php';
$m = merchant_require();
$id = (int) $m['id'];
$service = in_array(mq('s'), MK_SERVICES, true) ? mq('s') : (str_contains((string) $m['category'], 'Food') ? 'ueat' : (in_array($m['category'], ['Wellness & Spa', 'Services'], true) ? 'ulocal' : 'ugo'));
if (!mk_ready()) { portal_header('Listings', 'listings'); echo '<div class="flash error">UGo, ULocal and UEat are being set up. Please check again later.</div>'; portal_footer(); exit; }
$back = 'listings.php?s=' . $service;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit_enforce('merchant_listings', 'merchant:' . $id, 120, 3600);
    try {
        $action = mp('action');
        if ($action === 'save_listing') {
            if ($service === 'ueat' && !mk_store($pdo, $id)) throw new InvalidArgumentException('Set up your restaurant profile first.');
            mk_handle_listing_post($pdo, $id, null);
            mflash('success', mk_review_listings() ? 'Saved. We will review it shortly; you will see "Live" when customers can book it.' : 'Saved. It is live.');
        } elseif ($action === 'save_store') {
            $cover = mk_store_photo($_FILES['cover_photo'] ?? null, 'The cover photo');
            try { mk_store_save($pdo, $id, $_POST, $cover, null); } catch (Throwable $e) { mk_delete_photo($cover); throw $e; }
            mflash('success', 'Restaurant saved.');
        } elseif ($action === 'pause' || $action === 'resume') {
            mk_listing_toggle($pdo, $id, (int) mp('id'), $action === 'pause');
            mflash('success', $action === 'pause' ? 'Paused: customers do not see it now.' : 'Live again.');
        } elseif ($action === 'delete') {
            mk_listing_delete($pdo, (int) mp('id'), $id);
            mflash('success', 'Removed.');
        } elseif ($action === 'store_open') {
            $pdo->prepare('UPDATE mk_stores SET is_open = ? WHERE merchant_id = ?')->execute([mp('open') === '1' ? 1 : 0, $id]);
            mflash('success', mp('open') === '1' ? 'You are open: customers can order now.' : 'Closed: customers can see your menu but cannot order.');
        }
        redirect($back);
    } catch (InvalidArgumentException $ex) {
        mflash('error', $ex->getMessage());
        redirect($back . (mp('id') ? '&edit=' . (int) mp('id') : (mp('action') === 'save_listing' ? '&new=1' : '')));
    }
}
$cfg = mk_services()[$service];
$store = $service === 'ueat' ? mk_store($pdo, $id) : null;
$s = $pdo->prepare('SELECT * FROM mk_listings WHERE merchant_id = ? AND service = ? ORDER BY sort_order, category, id');
$s->execute([$id, $service]); $rows = $s->fetchAll();
$counts = $pdo->prepare('SELECT service, COUNT(*) FROM mk_listings WHERE merchant_id = ? GROUP BY service'); $counts->execute([$id]); $counts = $counts->fetchAll(PDO::FETCH_KEY_PAIR);
$edit = null;
if (mq('edit') !== '') { $edit = mk_listing($pdo, (int) mq('edit')); if (!$edit || (int) $edit['merchant_id'] !== $id) $edit = null; }
$showForm = $edit || mq('new') === '1' || (!$rows && ($service !== 'ueat' || $store));
portal_header('My listings', 'listings');
?>
<link rel="stylesheet" href="../assets/css/market-admin.css?v=1">
<div class="tabs"><?php foreach (mk_services() as $k => $c): ?><a class="<?= $service === $k ? 'active' : '' ?>" href="?s=<?= $k ?>"><?= e($c['label']) ?> · <?= e($k === 'ueat' ? 'Restaurant menu' : $c['title']) ?><span><?= (int) ($counts[$k] ?? 0) ?></span></a><?php endforeach; ?></div>
<?php if ($m['status'] !== 'approved'): ?><div class="flash info mt">You can prepare your listings now. Customers see them after your merchant account is approved.</div><?php endif; ?>
<p class="muted mt"><?= $service === 'upass' ? 'Passes customers can book in UPass: airport transfers, island and day passes, activity bundles, entrance fees.' : ($service === 'ugo' ? 'Activities and tours customers can book in UGo: land tours, island hopping, water sports and more.' : ($service === 'ulocal' ? 'Services customers can book in ULocal: massage, laundry, photography, guides, repairs and more.' : 'Your restaurant and menu in UEat. Orders arrive in Orders; you prepare and deliver the food.')) ?> Payments with Credits go to your balance like QR payments and are paid out with your settlements.</p>

<?php if ($service === 'ueat' && $store): $open = mk_store_open($store); ?>
<section class="card mk-open-card <?= $open ? 'on' : '' ?>"><div><h2><?= e($store['display_name']) ?> <?= portal_status_pill((string) $store['status']) ?></h2><p class="muted"><?= $open ? 'Open now: customers can order.' : ((int) $store['is_open'] ? 'Outside your hours (' . e(substr($store['opens_at'], 0, 5)) . '–' . e(substr($store['closes_at'], 0, 5)) . ').' : 'Closed: customers cannot order.') ?></p></div>
    <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="store_open"><input type="hidden" name="open" value="<?= (int) $store['is_open'] ? '0' : '1' ?>"><button class="btn <?= (int) $store['is_open'] ? '' : 'primary' ?>" type="submit"><?= (int) $store['is_open'] ? 'Close for now' : 'Open for orders' ?></button></form></section>
<?php endif; ?>

<div class="grid two mt">
    <div class="stack">
    <?php if ($service === 'ueat'): mk_store_form($store, $m, 'listings.php?s=ueat', '../'); endif; ?>
    <section class="card"><h2><?= $service === 'ueat' ? 'Menu' : 'Your ' . e($cfg['noun']) . ' listings' ?> <small><?= count($rows) ?></small><?php if ($service !== 'ueat' || $store): ?> <a class="btn small primary" href="?s=<?= $service ?>&amp;new=1">+ Add <?= e($cfg['noun']) ?></a><?php endif; ?></h2>
        <?php if (!$rows): ?><p class="muted">Nothing yet.<?= $service === 'ueat' && !$store ? ' Save your restaurant profile first, then add dishes.' : '' ?></p><?php endif; ?>
        <div class="mk-rows">
        <?php foreach ($rows as $r): ?>
            <div class="mk-rowi"><span class="mk-photo-prev" style="<?= mk_img_style($r['photo'], $service, $service === 'ueat' ? ($store['cuisine'] ?? 'Filipino') : $r['category'], '../') ?>"></span>
                <span><b><?= e($r['title']) ?></b><small><?= e($r['category']) ?> · <?= e(mk_price_label($r)) ?><?= $r['time_slots'] ? ' · ' . e(str_replace(',', ', ', $r['time_slots'])) : '' ?></small><?= $r['status'] === 'rejected' && $r['review_note'] ? '<small class="bad-text">' . e($r['review_note']) . '</small>' : '' ?></span>
                <span class="mk-rowi-act"><?= portal_status_pill((string) $r['status']) ?>
                    <a class="btn small" href="?s=<?= $service ?>&amp;edit=<?= (int) $r['id'] ?>">Edit</a>
                    <?php if (in_array($r['status'], ['active', 'paused'], true)): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="action" value="<?= $r['status'] === 'active' ? 'pause' : 'resume' ?>"><button class="btn small" type="submit"><?= $r['status'] === 'active' ? ($service === 'ueat' ? 'Sold out' : 'Pause') : 'Resume' ?></button></form><?php endif; ?>
                </span></div>
        <?php endforeach; ?>
        </div>
    </section>
    </div>
    <div class="stack">
        <?php if ($showForm && ($service !== 'ueat' || $store)): mk_listing_form($edit, $service, 'listings.php?s=' . $service, '../'); ?>
            <?php if ($edit): ?><form method="post" data-confirm="Remove this listing? Past bookings keep their details."><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><button class="btn danger small" type="submit">Remove</button></form><?php endif; ?>
        <?php else: ?>
            <section class="card"><h2>Tips that sell</h2><ul class="mk-tips"><li>Use a bright, real photo (landscape works best).</li><li>Put the price customers pay. Credits payments go to your balance.</li><li><?= in_array($service, ['ugo', 'upass'], true) ? 'Add start times and slots so customers only book what you can run.' : ($service === 'ulocal' ? 'Say where you serve (hotel, anywhere in Boracay) and how long it takes.' : 'Use sections like Best sellers, Mains, Drinks. Tap "Sold out" when a dish runs out.') ?></li><li>Confirm bookings and orders quickly in <a href="orders.php">Orders</a>.</li></ul></section>
        <?php endif; ?>
    </div>
</div>
<?php portal_footer(); ?>
