<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ueat.php';
require_once __DIR__ . '/includes/marketplace.php';
$user = require_auth();
header('Cache-Control: no-store');
if (!mk_ready()) { http_response_code(503); exit('UEat is being set up. Please try again later.'); }
$uid = (int) $user['id'];
$view = in_array($_GET['view'] ?? '', ['cart', 'orders'], true) ? (string) $_GET['view'] : '';
$rid = (int) ($_GET['r'] ?? 0);
$error = '';
$wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    rate_limit_enforce($action === 'place' ? 'ueat_place_order' : 'ueat_cart_write', 'user:' . $uid, $action === 'place' ? 12 : 240, 3600);
    try {
        if ($action === 'add' || $action === 'qty') {
            $cart = mk_cart_set($pdo, $uid, (int) ($_POST['item'] ?? 0), (int) ($_POST['qty'] ?? 1), $action === 'add', !empty($_POST['replace']));
            if ($wantsJson) {
                $sum = mk_cart_summary($pdo, $cart);
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'count' => $sum['count'], 'subtotal' => mk_money($sum['subtotal']), 'qty' => $cart['items'][(int) $_POST['item']] ?? 0]);
                exit;
            }
            redirect($action === 'qty' ? 'ueat.php?view=cart' : 'ueat.php?r=' . (int) ($_POST['r'] ?? 0) . '#item-' . (int) $_POST['item']);
        }
        if ($action === 'clear') { unset($_SESSION['mk_cart'][$uid]); redirect('ueat.php?view=cart'); }
        if ($action === 'place') {
            $_SESSION['ueat_checkout'] = ['pay_source' => ($_POST['pay_source'] ?? '') === 'bcash' ? 'bcash' : 'credits', 'fulfillment' => (string) ($_POST['fulfillment'] ?? 'delivery'), 'address' => mb_substr((string) ($_POST['address'] ?? ''), 0, 500), 'contact_mobile' => mb_substr((string) ($_POST['contact_mobile'] ?? ''), 0, 20)];
            $ref = mk_place_order($pdo, $uid, mk_cart($pdo, $uid), $_POST, (string) ($_POST['request_key'] ?? ''));
            unset($_SESSION['ueat_order_key']);
            redirect('ueat-order.php?reference=' . rawurlencode($ref) . '&new=1');
        }
        throw new InvalidArgumentException('Unknown request.');
    } catch (LogicException $ex) {
        if ($ex->getMessage() !== 'other-restaurant') throw $ex;
        $error = 'other-restaurant';
        if ($wantsJson) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'conflict' => true, 'error' => 'Your basket has food from another restaurant. Start a new basket?']); exit; }
        $rid = (int) ($_POST['r'] ?? 0);
    } catch (InvalidArgumentException $ex) {
        if ($wantsJson) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => $ex->getMessage()]); exit; }
        $error = $ex->getMessage();
        if ($action === 'place') $view = 'cart'; else $rid = (int) ($_POST['r'] ?? 0);
    } catch (Throwable $ex) {
        error_log('UEat failed: ' . get_class($ex) . ' ' . $ex->getMessage());
        $error = 'Something went wrong. No Credits were deducted. Please try again.';
        if ($action === 'place') $view = 'cart';
    }
}
if (empty($_SESSION['ueat_order_key'])) $_SESSION['ueat_order_key'] = bin2hex(random_bytes(32));
$cart = mk_cart($pdo, $uid);
$co = $_SESSION['ueat_checkout'] ?? [];
$fulfillment = in_array($_GET['f'] ?? ($co['fulfillment'] ?? ''), ['delivery', 'pickup'], true) ? (string) ($_GET['f'] ?? $co['fulfillment']) : 'delivery';
$wallet = in_array($_GET['p'] ?? ($co['pay_source'] ?? ''), ['credits', 'bcash'], true) ? (string) ($_GET['p'] ?? $co['pay_source']) : 'credits';
if (!pay_bcash_enabled()) $wallet = 'credits';
$sum = mk_cart_summary($pdo, $cart, $fulfillment, $wallet);
if ($sum['store'] && !(int) $sum['store']['offers_' . ($fulfillment === 'delivery' ? 'delivery' : 'pickup')]) {
    $fulfillment = $fulfillment === 'delivery' ? 'pickup' : 'delivery';
    $sum = mk_cart_summary($pdo, $cart, $fulfillment, $wallet);
}
$store = $rid ? mk_store($pdo, $rid) : null;
if ($rid && (!$store || $store['status'] !== 'active' || $store['merchant_status'] !== 'approved')) { http_response_code(404); $store = null; $rid = 0; $error = $error ?: 'This restaurant is not available.'; }
$cuisine = in_array($_GET['c'] ?? '', mk_cuisines(), true) ? (string) $_GET['c'] : '';
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 60);
$pageTitle = $store ? $store['display_name'] : ($view === 'cart' ? 'Your basket' : ($view === 'orders' ? 'My orders' : 'UEat'));
require __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="assets/css/market.css?v=2">
<?php if ($store):
    $menu = mk_menu($pdo, $rid);
    $open = mk_store_open($store);
    $inCart = $cart['merchant'] === $rid ? $cart['items'] : [];
?>
<section class="screen ue-screen ue-store">
    <header class="page-head mk-float"><a href="ueat.php" aria-label="Back"><span data-icon="arrow-left"></span></a><h1 class="sr-only"><?= e($store['display_name']) ?></h1><a href="ueat.php?view=cart" aria-label="Basket"><span data-icon="shopping-cart"></span></a></header>
    <div class="mk-cover ue-cover" style="<?= mk_img_style($store['cover_photo'], 'ueat', $store['cuisine']) ?>" role="img" aria-label="<?= e($store['display_name']) ?>"></div>
    <div class="ue-info">
        <h2><?= e($store['display_name']) ?></h2>
        <p><?= e($store['cuisine']) ?><?= $store['tagline'] ? ' · ' . e($store['tagline']) : '' ?></p>
        <ul class="ue-meta">
            <li><span data-icon="clock"></span><?= (int) $store['prep_minutes'] ?>–<?= (int) $store['prep_minutes'] + 15 ?> min</li>
            <?php if ((int) $store['offers_delivery']): ?><li><span data-icon="bike"></span>Delivery <?= (int) $store['delivery_fee_centavos'] ? e(mk_money((int) $store['delivery_fee_centavos'])) : 'free' ?></li><?php endif; ?>
            <?php if ((int) $store['offers_pickup']): ?><li><span data-icon="shopping-bag"></span>Pick-up</li><?php endif; ?>
            <?php if ((int) $store['min_order_centavos']): ?><li>Min. <?= e(mk_money((int) $store['min_order_centavos'])) ?></li><?php endif; ?>
        </ul>
        <?php if ($store['promo_text']): ?><p class="ue-promo"><span data-icon="badge-percent"></span><?= e($store['promo_text']) ?></p><?php endif; ?>
        <p class="ue-hours <?= $open ? 'open' : 'closed' ?>"><?= $open ? 'Open now' : 'Closed now' ?> · <?= e(date('g:i A', strtotime($store['opens_at']))) ?> – <?= e(date('g:i A', strtotime($store['closes_at']))) ?><?= $store['address'] ? ' · ' . e($store['address']) : '' ?></p>
    </div>
    <?php if ($error === 'other-restaurant'): ?>
        <form method="post" class="alert ue-conflict"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add"><input type="hidden" name="item" value="<?= (int) ($_POST['item'] ?? 0) ?>"><input type="hidden" name="r" value="<?= $rid ?>"><input type="hidden" name="replace" value="1">
            Your basket has food from another restaurant. <button type="submit">Start a new basket</button></form>
    <?php elseif ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>
    <nav class="ue-tabs" aria-label="Menu sections" data-ue-tabs><?php foreach (array_keys($menu) as $i => $sec): ?><a href="#sec-<?= $i ?>" class="<?= $i === 0 ? 'on' : '' ?>"><?= e($sec) ?></a><?php endforeach; ?></nav>
    <?php foreach (array_values($menu) as $i => $items): $sec = array_keys($menu)[$i]; ?>
        <section class="ue-sec" id="sec-<?= $i ?>"><h3><?= e($sec) ?></h3>
        <?php foreach ($items as $it): $n = $inCart[(int) $it['id']] ?? 0; ?>
            <article class="ue-item" id="item-<?= (int) $it['id'] ?>">
                <div><b><?= e($it['title']) ?></b><?php if ($it['badge']): ?> <em class="ue-badge"><?= e($it['badge']) ?></em><?php endif; ?><?php if ($it['summary']): ?><p><?= e($it['summary']) ?></p><?php endif; ?><strong><?= e(mk_money((int) $it['price_centavos'])) ?></strong></div>
                <div class="ue-item-side">
                    <?php if ($it['photo']): ?><span class="ue-item-img" style="<?= mk_img_style($it['photo'], 'ueat', $store['cuisine']) ?>"></span><?php endif; ?>
                    <form method="post" data-ue-add><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add"><input type="hidden" name="item" value="<?= (int) $it['id'] ?>"><input type="hidden" name="r" value="<?= $rid ?>"><input type="hidden" name="qty" value="1">
                        <button type="submit" class="ue-add<?= $n ? ' has' : '' ?>" aria-label="Add <?= e($it['title']) ?>"<?= $open ? '' : ' disabled' ?>><?= $n ? '<span data-n>' . $n . '</span>' : '+' ?></button></form>
                </div>
            </article>
        <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
    <?php if (!$open): ?><p class="mk-note">This restaurant is closed right now. You can look at the menu and order when it opens.</p><?php endif; ?>
    <a class="ue-basket" href="ueat.php?view=cart" data-ue-basket<?= $cart['merchant'] === $rid && $sum['count'] ? '' : ' hidden' ?>><span><b data-ue-count><?= $sum['count'] ?></b> Basket</span><strong data-ue-subtotal><?= e(mk_money($sum['subtotal'])) ?></strong></a>
</section>

<?php elseif ($view === 'cart'):
    $st = $sum['store'];
    $open = $st && mk_store_open($st);
?>
<section class="screen ue-screen">
    <header class="page-head"><a href="<?= $st ? 'ueat.php?r=' . (int) $st['merchant_id'] : 'ueat.php' ?>" aria-label="Back"><span data-icon="arrow-left"></span></a><h1>Your basket</h1><span></span></header>
    <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>
    <?php if (!$sum['lines']): ?>
        <div class="mk-empty ue-empty"><span data-icon="shopping-cart"></span><p>Your basket is empty.</p></div><a class="mk-cta" href="ueat.php">Find food</a>
    <?php else: ?>
        <div class="ue-cart-head"><span class="mk-thumb" style="<?= mk_img_style($st['cover_photo'], 'ueat', $st['cuisine']) ?>"></span><span><b><?= e($st['display_name']) ?></b><small><?= (int) $st['prep_minutes'] ?>–<?= (int) $st['prep_minutes'] + 15 ?> min · <?= $open ? 'Open now' : 'Closed now' ?></small></span></div>
        <div class="ue-lines">
        <?php foreach ($sum['lines'] as $l): ?>
            <div class="ue-line"><form method="post" class="mk-stepper sm"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="qty"><input type="hidden" name="item" value="<?= $l['id'] ?>">
                <button name="qty" value="<?= $l['qty'] - 1 ?>" aria-label="One less"><?= $l['qty'] === 1 ? '🗑' : '−' ?></button><span><?= $l['qty'] ?></span><button name="qty" value="<?= $l['qty'] + 1 ?>" aria-label="One more">+</button></form>
                <b><?= e($l['name']) ?></b><strong><?= e(mk_money($l['total'])) ?></strong></div>
        <?php endforeach; ?>
        </div>
        <a class="ue-more" href="ueat.php?r=<?= (int) $st['merchant_id'] ?>">+ Add more items</a>
        <form method="post" class="ue-checkout" data-ue-checkout>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="place"><input type="hidden" name="request_key" value="<?= e($_SESSION['ueat_order_key']) ?>">
            <div class="ue-seg" role="radiogroup" aria-label="Delivery or pick-up">
                <?php if ((int) $st['offers_delivery']): ?><a href="ueat.php?view=cart&amp;f=delivery&amp;p=<?= e($wallet) ?>" class="<?= $fulfillment === 'delivery' ? 'on' : '' ?>" role="radio" aria-checked="<?= $fulfillment === 'delivery' ? 'true' : 'false' ?>">Delivery</a><?php endif; ?>
                <?php if ((int) $st['offers_pickup']): ?><a href="ueat.php?view=cart&amp;f=pickup&amp;p=<?= e($wallet) ?>" class="<?= $fulfillment === 'pickup' ? 'on' : '' ?>" role="radio" aria-checked="<?= $fulfillment === 'pickup' ? 'true' : 'false' ?>">Pick-up</a><?php endif; ?>
            </div>
            <input type="hidden" name="fulfillment" value="<?= e($fulfillment) ?>"><input type="hidden" name="pay_source" value="<?= e($wallet) ?>">
            <?php if ($fulfillment === 'delivery'): ?>
                <label><span><span data-icon="map-pin"></span> Deliver to</span><input name="address" required maxlength="500" placeholder="Hotel and room, street or landmark" autocomplete="street-address" value="<?= e((string) ($co['address'] ?? '')) ?>"></label>
            <?php else: ?>
                <p class="mk-note"><b>Pick up at:</b> <?= e($st['display_name']) ?><?= $st['address'] ? ', ' . e($st['address']) : '' ?></p>
            <?php endif; ?>
            <label>Mobile number<input type="tel" name="contact_mobile" required maxlength="13" placeholder="09XXXXXXXXX" autocomplete="tel" value="<?= e((string) ($co['contact_mobile'] ?? ($user['mobile'] ?? ''))) ?>"></label>
            <label>Note to restaurant <small>(optional)</small><textarea name="notes" rows="2" maxlength="500" placeholder="No onions, extra rice, gate code…"></textarea></label>
            <?php if (pay_bcash_enabled()): ?><div class="ue-seg ue-pay" role="radiogroup" aria-label="Pay with"><a href="ueat.php?view=cart&amp;f=<?= e($fulfillment) ?>&amp;p=credits" class="<?= $wallet === 'credits' ? 'on' : '' ?>" role="radio" aria-checked="<?= $wallet === 'credits' ? 'true' : 'false' ?>">Credits</a><a href="ueat.php?view=cart&amp;f=<?= e($fulfillment) ?>&amp;p=bcash" class="<?= $wallet === 'bcash' ? 'on' : '' ?>" role="radio" aria-checked="<?= $wallet === 'bcash' ? 'true' : 'false' ?>">BCash</a></div><?php endif; ?>
            <div class="ue-sum">
                <div><span>Subtotal</span><span><?= e(mk_money($sum['subtotal'])) ?></span></div>
                <?php if ($fulfillment === 'delivery'): ?><div><span>Delivery fee</span><span><?= $sum['delivery_fee'] ? e(mk_money($sum['delivery_fee'])) : 'Free' ?></span></div><?php endif; ?>
                <?php if ($sum['fee']): ?><div><span>Payment charge</span><span><?= e(mk_money($sum['fee'])) ?></span></div><?php endif; ?>
                <div class="tot"><span>Total</span><span><?= e(mk_money($sum['total'])) ?></span></div>
                <div class="muted"><span>Pay with <?= e(wallet_label($wallet)) ?> · balance</span><span>₱<?= peso(wallet_balance($pdo, $uid, $wallet)) ?></span></div>
            </div>
            <?php if ((int) $st['min_order_centavos'] > $sum['subtotal']): ?><p class="mk-note">Minimum order is <?= e(mk_money((int) $st['min_order_centavos'])) ?>. Add <?= e(mk_money((int) $st['min_order_centavos'] - $sum['subtotal'])) ?> more.</p><?php endif; ?>
            <button class="mk-cta" type="submit"<?= $open && mk_credits_checkout() ? '' : ' disabled' ?>><?= !$open ? 'Restaurant is closed' : (!mk_credits_checkout() ? 'Ordering is paused' : 'Place order · ' . e(mk_money($sum['total']))) ?></button>
            <p class="mk-fine">The restaurant gets your order right away. If they decline, or you cancel before they accept, your <?= e(wallet_label($wallet)) ?> comes back in full.</p>
        </form>
        <form method="post" class="ue-clear"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="clear"><button type="submit">Clear basket</button></form>
    <?php endif; ?>
</section>

<?php elseif ($view === 'orders'):
    $s = $pdo->prepare("SELECT o.*, st.display_name, st.cuisine, st.cover_photo FROM ueat_orders o LEFT JOIN mk_stores st ON st.merchant_id = o.merchant_id WHERE o.user_id = ? ORDER BY o.id DESC LIMIT 40");
    $s->execute([$uid]); $orders = $s->fetchAll();
    $catalog = ueat_catalog();
?>
<section class="screen ue-screen">
    <header class="page-head"><a href="ueat.php" aria-label="Back"><span data-icon="arrow-left"></span></a><h1>My orders</h1><span></span></header>
    <?php if (!$orders): ?><div class="mk-empty ue-empty"><span data-icon="receipt"></span><p>No orders yet.</p></div><?php endif; ?>
    <div class="mk-list">
    <?php foreach ($orders as $o): $name = $o['display_name'] ?: ($catalog[$o['restaurant_id']]['name'] ?? $o['restaurant_id']); ?>
        <a href="ueat-order.php?reference=<?= e(rawurlencode($o['reference'])) ?>"><span class="mk-thumb" style="<?= mk_img_style($o['cover_photo'], 'ueat', (string) ($o['cuisine'] ?? 'Filipino')) ?>"></span>
            <span><b><?= e($name) ?></b><small><?= e(date('M j, g:i A', strtotime($o['created_at']))) ?> · <?= e(mk_money((int) round((float) $o['total_credits'] * 100))) ?></small></span>
            <span class="mk-status s-<?= e($o['status']) ?>"><?= e(mk_order_statuses()[$o['status']] ?? ucfirst($o['status'])) ?></span></a>
    <?php endforeach; ?>
    </div>
</section>

<?php else:
    $all = mk_restaurants($pdo);
    $list = $cuisine !== '' || $q !== '' ? mk_restaurants($pdo, $cuisine, $q) : $all;
    $popular = array_slice(array_values(array_filter($all, static fn($r) => $r['open_now'])), 0, 8);
    $promos = array_values(array_filter($all, static fn($r) => $r['promo_text'] && $r['open_now']));
    $have = array_unique(array_column($all, 'cuisine'));
    $active = 'home';
?>
<section class="screen ue-screen ue-home">
    <header class="page-head ue-top"><a href="dashboard.php" aria-label="Back"><span data-icon="arrow-left"></span></a>
        <div class="ue-deliver"><small>Deliver to</small><b><?= e(mb_strimwidth((string) ($co['address'] ?? '') ?: 'Boracay Island', 0, 32, '…')) ?></b></div>
        <a href="ueat.php?view=cart" aria-label="Basket, <?= $sum['count'] ?> items" class="ueat-cart-link"><span data-icon="shopping-cart"></span><?php if ($sum['count']): ?><b><?= $sum['count'] ?></b><?php endif; ?></a></header>
    <form class="mk-search ue-search" method="get" role="search"><span data-icon="search"></span><input type="search" name="q" value="<?= e($q) ?>" placeholder="What are you craving?" aria-label="Search food or restaurants"><?php if ($cuisine): ?><input type="hidden" name="c" value="<?= e($cuisine) ?>"><?php endif; ?></form>
    <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>
    <?php if ($cuisine === '' && $q === ''): ?>
        <div class="ue-banner"><div><small>UEAT · BORACAY</small><b><?= $promos ? e($promos[0]['promo_text']) : 'Island food, delivered' ?></b><span><?= $promos ? e($promos[0]['display_name']) : 'Order from local restaurants and pay with Credits' ?></span></div><span class="ue-banner-img" style="<?= mk_img_style($promos[0]['cover_photo'] ?? null, 'ueat', $promos[0]['cuisine'] ?? 'Desserts & Drinks') ?>"></span><?php if ($promos): ?><a href="ueat.php?r=<?= (int) $promos[0]['merchant_id'] ?>" aria-label="Open <?= e($promos[0]['display_name']) ?>"></a><?php endif; ?></div>
    <?php endif; ?>
    <nav class="ue-cuisines" aria-label="Cuisines">
        <?php foreach (mk_cuisines() as $c): if (!in_array($c, $have, true)) continue; ?>
            <a href="ueat.php?c=<?= rawurlencode($c) ?>" class="<?= $cuisine === $c ? 'on' : '' ?>"><span style="<?= mk_img_style(null, 'ueat', $c) ?>"></span><?= e($c) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php if ($cuisine === '' && $q === '' && $popular): ?>
        <h3 class="mk-section">Popular near you</h3>
        <div class="mk-rail"><?php foreach ($popular as $r): ?><a class="mk-feature ue-feature" href="ueat.php?r=<?= (int) $r['merchant_id'] ?>"><span class="mk-img" style="<?= mk_img_style($r['cover_photo'], 'ueat', $r['cuisine']) ?>"><?php if ($r['promo_text']): ?><em><?= e($r['promo_text']) ?></em><?php endif; ?></span><b><?= e($r['display_name']) ?></b><small><?= (int) $r['prep_minutes'] ?>–<?= (int) $r['prep_minutes'] + 15 ?> min · <?= (int) $r['delivery_fee_centavos'] ? e(mk_money((int) $r['delivery_fee_centavos'])) : 'Free delivery' ?></small></a><?php endforeach; ?></div>
    <?php endif; ?>
    <h3 class="mk-section"><?= $cuisine !== '' ? e($cuisine) : ($q !== '' ? 'Results for “' . e($q) . '”' : 'All restaurants') ?> <small><?= count($list) ?></small><?php if ($cuisine || $q): ?> <a href="ueat.php">Clear</a><?php endif; ?></h3>
    <?php if (!$list): ?><div class="mk-empty ue-empty"><span data-icon="utensils"></span><p><?= $all ? 'No match. Try another word or cuisine.' : 'Restaurants are joining UEat soon.' ?></p></div><?php endif; ?>
    <div class="ue-list">
    <?php foreach ($list as $r): ?>
        <a class="ue-rest<?= $r['open_now'] ? '' : ' closed' ?>" href="ueat.php?r=<?= (int) $r['merchant_id'] ?>">
            <span class="mk-img" style="<?= mk_img_style($r['cover_photo'], 'ueat', $r['cuisine']) ?>"><?php if (!$r['open_now']): ?><i>Closed · opens <?= e(date('g:i A', strtotime($r['opens_at']))) ?></i><?php elseif ($r['promo_text']): ?><em><?= e($r['promo_text']) ?></em><?php endif; ?></span>
            <span class="ue-rest-body"><b><?= e($r['display_name']) ?></b><small><?= e($r['cuisine']) ?><?= $r['tagline'] ? ' · ' . e($r['tagline']) : '' ?></small>
                <small class="ue-rest-meta"><span data-icon="clock"></span><?= (int) $r['prep_minutes'] ?>–<?= (int) $r['prep_minutes'] + 15 ?> min · <span data-icon="bike"></span><?= (int) $r['offers_delivery'] ? ((int) $r['delivery_fee_centavos'] ? e(mk_money((int) $r['delivery_fee_centavos'])) : 'Free') : 'Pick-up only' ?><?= (int) $r['orders_done'] ? ' · ' . (int) $r['orders_done'] . '+ orders' : '' ?></small></span>
        </a>
    <?php endforeach; ?>
    </div>
    <p class="mk-join"><a href="ueat.php?view=orders">My orders</a> · Own a restaurant? <a href="merchant-portal/register.php">Join UEat</a></p>
    <?php if ($sum['count']): ?><a class="ue-basket" href="ueat.php?view=cart"><span><b><?= $sum['count'] ?></b> Basket · <?= e($sum['store']['display_name'] ?? '') ?></span><strong><?= e(mk_money($sum['subtotal'])) ?></strong></a><?php endif; ?>
    <?php require __DIR__ . '/includes/nav.php'; ?>
</section>
<?php endif; ?>
<script defer src="assets/js/market.js?v=2"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
