<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/market_forms.php';
$admin = admin_require('marketplace.view');
$canManage = admin_can('marketplace.manage');
$view = in_array(q('view'), ['review', 'listings', 'restaurants', 'bookings', 'orders'], true) ? q('view') : 'review';
if (!mk_ready()) {
    admin_header('Marketplace', 'marketplace');
    echo '<div class="flash error" role="status">Import <b>database/marketplace_migration.sql</b> once in phpMyAdmin, then reload this page.</div>';
    admin_footer();
    exit;
}
$self = static fn(array $p = []): string => 'marketplace.php?' . http_build_query(array_filter(['view' => $view] + $p, static fn($v) => $v !== '' && $v !== null));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_post_guard();
    $back = $self(['s' => p('service') ?: q('s'), 'edit' => q('edit'), 'm' => q('m')]);
    try {
        $a = p('action');
        if ($a === 'settings') {
            if (!admin_can('settings')) throw new InvalidArgumentException('Only a Super Admin can change marketplace settings.');
            setting_save($pdo, 'market.credits_checkout', p('credits_checkout') === '1' ? '1' : '0', (int) $admin['id']);
            setting_save($pdo, 'market.review_listings', p('review_listings') === '1' ? '1' : '0', (int) $admin['id']);
            admin_audit('mk_settings', 'settings', 'market', ['credits_checkout' => p('credits_checkout'), 'review_listings' => p('review_listings')]);
            flash('success', 'Marketplace settings saved.');
            redirect('marketplace.php?view=review');
        }
        if (!$canManage) throw new InvalidArgumentException('Your role can view the marketplace but not change it.');
        if ($a === 'save_listing') {
            $id = mk_handle_listing_post($pdo, null, (int) $admin['id']);
            admin_audit('mk_listing_save', 'mk_listing', $id, ['title' => p('title'), 'service' => p('service'), 'status' => p('status')]);
            flash('success', 'Listing saved.');
            redirect($self(['s' => p('service')]));
        } elseif ($a === 'review_listing') {
            mk_listing_review($pdo, (int) p('id'), p('status'), p('note'));
            admin_audit('mk_listing_' . p('status'), 'mk_listing', (int) p('id'), ['note' => p('note')]);
            flash('success', 'Listing updated.');
        } elseif ($a === 'delete_listing') {
            mk_listing_delete($pdo, (int) p('id'), null);
            admin_audit('mk_listing_delete', 'mk_listing', (int) p('id'));
            flash('success', 'Listing removed (or hidden when it has bookings).');
            redirect($self());
        } elseif ($a === 'save_store') {
            $mid = (int) p('merchant_id');
            $cover = mk_store_photo($_FILES['cover_photo'] ?? null, 'The cover photo');
            try { mk_store_save($pdo, $mid, $_POST, $cover, (int) $admin['id'], p('status')); } catch (Throwable $e) { mk_delete_photo($cover); throw $e; }
            admin_audit('mk_store_save', 'merchant', $mid, ['status' => p('status')]);
            flash('success', 'Restaurant saved.');
            redirect('marketplace.php?view=restaurants');
        } elseif ($a === 'review_store') {
            $st = p('status');
            if (!in_array($st, ['active', 'rejected', 'paused'], true)) throw new InvalidArgumentException('Unknown status.');
            if ($st === 'rejected' && mb_strlen(trim(p('note'))) < 5) throw new InvalidArgumentException('Tell the merchant what to fix.');
            $pdo->prepare('UPDATE mk_stores SET status = ?, review_note = ? WHERE merchant_id = ?')->execute([$st, trim(p('note')) ?: null, (int) p('merchant_id')]);
            admin_audit('mk_store_' . $st, 'merchant', (int) p('merchant_id'), ['note' => p('note')]);
            flash('success', 'Restaurant updated.');
        } elseif ($a === 'booking') {
            $r = mk_booking_update($pdo, p('ref'), p('to'), 'admin', (int) $admin['id'], p('note'));
            admin_audit('mk_booking_' . $r['status'], 'mk_booking', p('ref'), ['note' => p('note')]);
            flash('success', p('ref') . ': ' . mk_booking_statuses()[$r['status']] . ($r['refunded'] ? ' (refunded)' : '') . '.');
        } elseif ($a === 'order') {
            $r = mk_order_update($pdo, p('ref'), p('to'), 'admin', (int) $admin['id'], p('note'));
            admin_audit('ueat_order_' . $r['status'], 'ueat_order', p('ref'), ['note' => p('note')]);
            flash('success', p('ref') . ': ' . (mk_order_statuses()[$r['status']] ?? $r['status']) . '.');
        }
    } catch (InvalidArgumentException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect($back);
}

$merchants = $pdo->query("SELECT id, business_name FROM merchants WHERE status = 'approved' ORDER BY business_name")->fetchAll(PDO::FETCH_KEY_PAIR);
$pendingL = (int) $pdo->query("SELECT COUNT(*) FROM mk_listings WHERE status = 'pending'")->fetchColumn();
$pendingS = (int) $pdo->query("SELECT COUNT(*) FROM mk_stores WHERE status = 'pending'")->fetchColumn();
$openB = (int) $pdo->query("SELECT COUNT(*) FROM mk_bookings WHERE status = 'pending'")->fetchColumn();
$openO = (int) $pdo->query("SELECT COUNT(*) FROM ueat_orders WHERE merchant_id IS NOT NULL AND status IN ('pending','accepted','preparing','on_the_way','ready')")->fetchColumn();
admin_header('Marketplace · UGo, ULocal, UEat', 'marketplace');
?>
<link rel="stylesheet" href="../assets/css/market-admin.css?v=1">
<div class="tabs">
    <a class="<?= $view === 'review' ? 'active' : '' ?>" href="?view=review">To review<span><?= $pendingL + $pendingS ?></span></a>
    <a class="<?= $view === 'listings' ? 'active' : '' ?>" href="?view=listings">Listings</a>
    <a class="<?= $view === 'restaurants' ? 'active' : '' ?>" href="?view=restaurants">UEat restaurants</a>
    <a class="<?= $view === 'bookings' ? 'active' : '' ?>" href="?view=bookings">Bookings<span><?= $openB ?></span></a>
    <a class="<?= $view === 'orders' ? 'active' : '' ?>" href="?view=orders">Food orders<span><?= $openO ?></span></a>
</div>
<?php
$reviewForm = static function (string $action, string $idField, int $id) use ($canManage): string {
    if (!$canManage) return '';
    $base = '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="' . $idField . '" value="' . $id . '">';
    return '<form method="post" class="inline">' . $base . '<input type="hidden" name="status" value="active"><button class="btn small primary" type="submit">Approve</button></form> '
        . '<form method="post" class="inline mk-decline">' . $base . '<input type="hidden" name="status" value="rejected"><input name="note" required minlength="5" maxlength="200" placeholder="What to fix" aria-label="What to fix"><button class="btn small danger" type="submit">Reject</button></form>';
};

/* ------------------------------------------------------------ review */
if ($view === 'review'):
    $ls = $pdo->query("SELECT l.*, m.business_name, m.status merchant_status FROM mk_listings l LEFT JOIN merchants m ON m.id = l.merchant_id WHERE l.status = 'pending' ORDER BY l.updated_at")->fetchAll();
    $ss = $pdo->query("SELECT s.*, m.business_name, m.status merchant_status FROM mk_stores s JOIN merchants m ON m.id = s.merchant_id WHERE s.status = 'pending' ORDER BY s.updated_at")->fetchAll();
?>
    <p class="muted">Merchants' new or edited listings and restaurants wait here when <b>Review merchant listings</b> is on (settings below). Check the photo, price and wording, then approve.</p>
    <?php if (!$ls && !$ss): ?><div class="card empty"><h2>All caught up</h2><p class="muted">Nothing waiting for review.</p></div><?php endif; ?>
    <?php if ($ss): ?><section class="card"><h2>Restaurants</h2><div class="mk-rows"><?php foreach ($ss as $s): ?>
        <div class="mk-rowi"><span class="mk-photo-prev" style="<?= mk_img_style($s['cover_photo'], 'ueat', $s['cuisine'], '../') ?>"></span><span><b><?= e($s['display_name']) ?></b><small><?= e($s['cuisine']) ?> · <?= e($s['business_name']) ?> (<?= e($s['merchant_status']) ?>) · delivery ₱<?= e(number_format((int) $s['delivery_fee_centavos'] / 100, 2)) ?> · <?= e(substr($s['opens_at'], 0, 5)) ?>–<?= e(substr($s['closes_at'], 0, 5)) ?></small></span>
            <span class="mk-rowi-act"><?= $reviewForm('review_store', 'merchant_id', (int) $s['merchant_id']) ?></span></div>
    <?php endforeach; ?></div></section><?php endif; ?>
    <?php if ($ls): ?><section class="card mt"><h2>Listings</h2><div class="mk-rows"><?php foreach ($ls as $l): ?>
        <div class="mk-rowi"><span class="mk-photo-prev" style="<?= mk_img_style($l['photo'], $l['service'], $l['category'], '../') ?>"></span>
            <span><b><?= e($l['title']) ?></b> <span class="badge muted"><?= e(mk_services()[$l['service']]['label']) ?></span><small><?= e($l['category']) ?> · <?= e(mk_price_label($l)) ?> · <?= e($l['business_name'] ?: 'Ultimate App') ?><?= $l['merchant_status'] && $l['merchant_status'] !== 'approved' ? ' (merchant ' . e($l['merchant_status']) . ')' : '' ?></small><?php if ($l['summary']): ?><small><?= e($l['summary']) ?></small><?php endif; ?></span>
            <span class="mk-rowi-act"><a class="btn small" href="?view=listings&amp;edit=<?= (int) $l['id'] ?>">Open</a> <?= $reviewForm('review_listing', 'id', (int) $l['id']) ?></span></div>
    <?php endforeach; ?></div></section><?php endif; ?>
    <?php if (admin_can('settings')): ?>
    <form class="card mt" method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="settings"><h2>Marketplace settings</h2>
        <label><input type="checkbox" name="review_listings" value="1"<?= mk_review_listings() ? ' checked' : '' ?>> <b>Review merchant listings</b>: new and edited listings and restaurants wait for approval before customers see them</label><br>
        <label><input type="checkbox" name="credits_checkout" value="1"<?= mk_credits_checkout() ? ' checked' : '' ?>> <b>Pay with Credits</b>: customers pay merchants when booking (UGo, ULocal) and ordering (UEat). Off = bookings are pay on the day and UEat ordering pauses</label>
        <p class="table-meta">Payment charges follow Settings › Payments › pay a merchant with Credits (same as QR payments).</p>
        <div class="form-actions"><button class="btn primary" type="submit">Save settings</button></div></form>
    <?php endif; ?>

<?php /* ------------------------------------------------------------ listings */
elseif ($view === 'listings'):
    $svc = in_array(q('s'), MK_SERVICES, true) ? q('s') : '';
    $st = in_array(q('st'), ['active', 'pending', 'paused', 'rejected'], true) ? q('st') : '';
    $edit = q('edit') !== '' ? mk_listing($pdo, (int) q('edit')) : null;
    $newSvc = in_array(q('new'), MK_SERVICES, true) ? q('new') : '';
    $where = ['1=1']; $args = [];
    if ($svc) { $where[] = 'l.service = ?'; $args[] = $svc; }
    if ($st) { $where[] = 'l.status = ?'; $args[] = $st; }
    if (q('q') !== '') { $where[] = 'CONCAT_WS(" ", l.title, l.category, m.business_name) LIKE ?'; $args[] = '%' . q('q') . '%'; }
    $s = $pdo->prepare('SELECT l.*, m.business_name, (SELECT COUNT(*) FROM mk_bookings b WHERE b.listing_id = l.id) bookings FROM mk_listings l LEFT JOIN merchants m ON m.id = l.merchant_id WHERE ' . implode(' AND ', $where) . ' ORDER BY l.service, l.status <> "active", l.sort_order, l.id DESC LIMIT 300');
    $s->execute($args); $rows = $s->fetchAll();
?>
    <form class="toolbar" method="get"><input type="hidden" name="view" value="listings">
        <select name="s" data-autosubmit aria-label="Service"><option value="">All services</option><?php foreach (mk_services() as $k => $c): ?><option value="<?= $k ?>"<?= $svc === $k ? ' selected' : '' ?>><?= e($c['label']) ?></option><?php endforeach; ?></select>
        <select name="st" data-autosubmit aria-label="Status"><option value="">Any status</option><?php foreach (['active' => 'Live', 'pending' => 'To review', 'paused' => 'Paused', 'rejected' => 'Not approved'] as $k => $lab): ?><option value="<?= $k ?>"<?= $st === $k ? ' selected' : '' ?>><?= $lab ?></option><?php endforeach; ?></select>
        <input type="search" name="q" value="<?= e(q('q')) ?>" placeholder="Search name or merchant">
        <?php if ($canManage): ?><span class="spacer"></span><a class="btn small primary" href="?view=listings&amp;new=upass">+ UPass</a><a class="btn small primary" href="?view=listings&amp;new=ugo">+ UGo activity</a><a class="btn small primary" href="?view=listings&amp;new=ulocal">+ ULocal service</a><a class="btn small primary" href="?view=listings&amp;new=ueat">+ UEat dish</a><?php endif; ?>
    </form>
    <div class="grid two">
        <section class="card"><h2>Listings <small><?= count($rows) ?></small></h2><div class="mk-rows">
        <?php if (!$rows): ?><p class="muted">No listings match.</p><?php endif; ?>
        <?php foreach ($rows as $l): ?>
            <div class="mk-rowi"><span class="mk-photo-prev" style="<?= mk_img_style($l['photo'], $l['service'], $l['category'], '../') ?>"></span>
                <span><b><?= e($l['title']) ?></b><small><?= e(mk_services()[$l['service']]['label']) ?> · <?= e($l['category']) ?> · <?= e(mk_price_label($l)) ?> · <?= e($l['business_name'] ?: 'Ultimate App') ?><?= (int) $l['featured'] ? ' · ★ featured' : '' ?><?= (int) $l['bookings'] ? ' · ' . (int) $l['bookings'] . ' booking(s)' : '' ?></small></span>
                <span class="mk-rowi-act"><?= portal_status_pill((string) $l['status']) ?><?php if ($canManage): ?> <a class="btn small" href="<?= e($self(['s' => $svc, 'st' => $st, 'edit' => $l['id']])) ?>">Edit</a><?php endif; ?></span></div>
        <?php endforeach; ?></div></section>
        <div class="stack">
        <?php if ($canManage && ($edit || $newSvc)):
            mk_listing_form($edit, $edit['service'] ?? $newSvc, $self(['s' => $svc, 'edit' => $edit['id'] ?? null]), '../', ['merchants' => $merchants, 'merchant_id' => $edit['merchant_id'] ?? null]);
            if ($edit): ?><form method="post" data-confirm="Remove this listing? If it has bookings it is hidden instead."><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_listing"><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><button class="btn danger small" type="submit">Remove listing</button></form><?php endif;
        else: ?>
            <section class="card"><h2>How it works</h2><ul class="mk-tips"><li><b>Merchant listings</b>: customers pay with Credits; the money goes to the merchant's balance (QR payment charges apply) and is paid out in Settlements.</li><li><b>Ultimate App listings</b> (no merchant): booked as requests and paid on the day. Your team confirms them in Bookings.</li><li>UEat dishes always belong to a merchant restaurant.</li><li>Featured listings show in "Popular now".</li></ul></section>
        <?php endif; ?>
        </div>
    </div>

<?php /* ------------------------------------------------------------ restaurants */
elseif ($view === 'restaurants'):
    $rows = $pdo->query("SELECT s.*, m.business_name, m.status merchant_status, (SELECT COUNT(*) FROM mk_listings l WHERE l.merchant_id = s.merchant_id AND l.service = 'ueat' AND l.status = 'active') items,
        (SELECT COUNT(*) FROM ueat_orders o WHERE o.merchant_id = s.merchant_id AND o.status = 'completed') done FROM mk_stores s JOIN merchants m ON m.id = s.merchant_id ORDER BY s.status <> 'active', s.display_name")->fetchAll();
    $editM = (int) q('m');
    $em = $editM ? $pdo->query('SELECT * FROM merchants WHERE id = ' . $editM)->fetch() : null;
?>
    <div class="grid two">
        <section class="card"><h2>UEat restaurants <small><?= count($rows) ?></small></h2>
            <?php if ($canManage): ?><form class="toolbar" method="get"><input type="hidden" name="view" value="restaurants"><select name="m" aria-label="Merchant" required><option value="">Set up a restaurant for…</option><?php foreach ($merchants as $mid => $mn): ?><option value="<?= (int) $mid ?>"><?= e($mn) ?></option><?php endforeach; ?></select><button class="btn small" type="submit">Open</button></form><?php endif; ?>
            <div class="mk-rows"><?php foreach ($rows as $r): $open = mk_store_open($r); ?>
                <div class="mk-rowi"><span class="mk-photo-prev" style="<?= mk_img_style($r['cover_photo'], 'ueat', $r['cuisine'], '../') ?>"></span>
                    <span><b><?= e($r['display_name']) ?></b><small><?= e($r['cuisine']) ?> · <?= (int) $r['items'] ?> live dishes · <?= (int) $r['done'] ?> completed orders · <?= $open ? 'open now' : 'closed now' ?><?= $r['merchant_status'] !== 'approved' ? ' · merchant ' . e($r['merchant_status']) : '' ?></small></span>
                    <span class="mk-rowi-act"><?= portal_status_pill((string) $r['status']) ?><?php if ($canManage): ?> <a class="btn small" href="?view=restaurants&amp;m=<?= (int) $r['merchant_id'] ?>">Edit</a> <a class="btn small" href="?view=listings&amp;s=ueat&amp;q=<?= rawurlencode($r['business_name']) ?>">Menu</a><?php endif; ?></span></div>
            <?php endforeach; ?></div>
        </section>
        <div class="stack"><?php if ($em && $canManage): mk_store_form(mk_store($pdo, $editM), $em, 'marketplace.php?view=restaurants&m=' . $editM, '../', true); endif; ?></div>
    </div>

<?php /* ------------------------------------------------------------ bookings */
elseif ($view === 'bookings'):
    $st = in_array(q('st'), array_keys(mk_booking_statuses()), true) ? q('st') : '';
    [$page, $per, $offset] = paging();
    $where = $st ? 'WHERE b.status = ' . $pdo->quote($st) : '';
    $total = (int) $pdo->query("SELECT COUNT(*) FROM mk_bookings b $where")->fetchColumn();
    $rows = $pdo->query("SELECT b.*, u.full_name, m.business_name FROM mk_bookings b JOIN users u ON u.id = b.user_id LEFT JOIN merchants m ON m.id = b.merchant_id $where ORDER BY b.status = 'pending' DESC, b.id DESC LIMIT $per OFFSET $offset")->fetchAll();
    $act = static function (string $ref, string $to, string $label, string $cls = '') use ($canManage): string {
        if (!$canManage) return '';
        return '<form method="post" class="inline"' . ($to === 'cancel' || $to === 'decline' ? ' data-confirm="' . e($label) . ' ' . e($ref) . '? Paid Credits are refunded."' : '') . '><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="booking"><input type="hidden" name="ref" value="' . e($ref) . '"><input type="hidden" name="to" value="' . $to . '"><button class="btn small ' . $cls . '" type="submit">' . e($label) . '</button></form>';
    };
?>
    <form class="toolbar" method="get"><input type="hidden" name="view" value="bookings"><select name="st" data-autosubmit aria-label="Status"><option value="">All bookings</option><?php foreach (mk_booking_statuses() as $k => $lab): ?><option value="<?= $k ?>"<?= $st === $k ? ' selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?></select></form>
    <div class="table-wrap"><table><thead><tr><th>Booking</th><th>Customer</th><th>Run by</th><th>When</th><th class="num">Total</th><th>Status</th><th></th></tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="7" class="muted">No bookings.</td></tr><?php endif; ?>
    <?php foreach ($rows as $b): ?><tr>
        <td><b><?= e($b['title']) ?></b><small><?= e($b['reference']) ?> · <?= e(mk_services()[$b['service']]['label']) ?> · <?= (int) $b['quantity'] ?>×</small></td>
        <td><a class="row-link" href="customer.php?id=<?= (int) $b['user_id'] ?>"><?= e($b['full_name']) ?></a><small><?= e((string) $b['contact_mobile']) ?></small></td>
        <td><?= e($b['business_name'] ?: 'Ultimate App') ?></td>
        <td><?= e(date('M j, Y', strtotime($b['booking_date']))) ?><small><?= $b['booking_time'] ? e(date('g:i A', strtotime($b['booking_time']))) : '' ?></small></td>
        <td class="num">₱<?= e(number_format((int) $b['total_centavos'] / 100, 2)) ?><small><?= $b['pay_method'] === 'credits' ? 'Credits · ' . e($b['payment_status']) : 'On the day' ?></small></td>
        <td><?= status_badge($b['status']) ?></td>
        <td class="mk-acts"><?php if ($b['status'] === 'pending'): ?><?= $act($b['reference'], 'confirm', 'Confirm', 'primary') ?><?= $act($b['reference'], 'decline', 'Decline') ?><?php elseif ($b['status'] === 'confirmed'): ?><?= $b['booking_date'] <= date('Y-m-d') ? $act($b['reference'], 'complete', 'Complete', 'primary') : '' ?><?= $act($b['reference'], 'cancel', 'Cancel', 'danger') ?><?php endif; ?></td>
    </tr><?php endforeach; ?>
    </tbody></table></div>
    <?= pager($page, $per, $total) ?>

<?php /* ------------------------------------------------------------ orders */
else:
    $st = in_array(q('st'), array_keys(mk_order_statuses()), true) ? q('st') : '';
    [$page, $per, $offset] = paging();
    $where = 'WHERE o.merchant_id IS NOT NULL' . ($st ? ' AND o.status = ' . $pdo->quote($st) : '');
    $total = (int) $pdo->query("SELECT COUNT(*) FROM ueat_orders o $where")->fetchColumn();
    $rows = $pdo->query("SELECT o.*, u.full_name, s.display_name FROM ueat_orders o JOIN users u ON u.id = o.user_id LEFT JOIN mk_stores s ON s.merchant_id = o.merchant_id $where ORDER BY o.id DESC LIMIT $per OFFSET $offset")->fetchAll();
?>
    <form class="toolbar" method="get"><input type="hidden" name="view" value="orders"><select name="st" data-autosubmit aria-label="Status"><option value="">All orders</option><?php foreach (mk_order_statuses() as $k => $lab): if ($k === 'sample') continue; ?><option value="<?= $k ?>"<?= $st === $k ? ' selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?></select></form>
    <div class="table-wrap"><table><thead><tr><th>Order</th><th>Restaurant</th><th>Customer</th><th>Type</th><th class="num">Total</th><th>Status</th><th></th></tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="7" class="muted">No orders.</td></tr><?php endif; ?>
    <?php foreach ($rows as $o): $openO = !in_array($o['status'], ['completed', 'cancelled', 'declined'], true); ?><tr>
        <td><b><?= e($o['reference']) ?></b><small><?= e(date('M j, g:i A', strtotime($o['created_at']))) ?></small></td>
        <td><?= e((string) $o['display_name']) ?></td>
        <td><a class="row-link" href="customer.php?id=<?= (int) $o['user_id'] ?>"><?= e($o['full_name']) ?></a><small><?= e((string) $o['contact_mobile']) ?></small></td>
        <td><?= e(ucfirst($o['fulfillment'])) ?><small><?= e((string) $o['delivery_address']) ?></small></td>
        <td class="num">₱<?= e(number_format((float) $o['total_credits'], 2)) ?><small><?= e($o['payment_status']) ?></small></td>
        <td><?= status_badge($o['status']) ?><?= $o['status_note'] ? '<small>' . e($o['status_note']) . '</small>' : '' ?></td>
        <td class="mk-acts"><?php if ($openO && $canManage): ?>
            <form method="post" class="inline" data-confirm="Cancel <?= e($o['reference']) ?> and refund the customer?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="order"><input type="hidden" name="ref" value="<?= e($o['reference']) ?>"><input type="hidden" name="to" value="cancelled"><input type="hidden" name="note" value="Cancelled by Ultimate App support"><button class="btn small danger" type="submit">Cancel &amp; refund</button></form>
            <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="order"><input type="hidden" name="ref" value="<?= e($o['reference']) ?>"><input type="hidden" name="to" value="completed"><button class="btn small" type="submit">Mark completed</button></form>
        <?php endif; ?></td>
    </tr><?php endforeach; ?>
    </tbody></table></div>
    <?= pager($page, $per, $total) ?>
<?php endif; ?>
<?php admin_footer(); ?>
