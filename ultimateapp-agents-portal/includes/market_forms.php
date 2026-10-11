<?php
declare(strict_types=1);

require_once __DIR__ . '/marketplace.php';

/**
 * Listing form shared by the Merchant Portal and Admin. $l = existing listing or null. $base = path to the app root
 * ('../'). $admin = ['merchants' => [id => name], 'merchant_id' => ?int] adds owner, status and featured fields.
 */
function mk_listing_form(?array $l, string $service, string $action, string $base, ?array $admin = null): void
{
    $cfg = mk_services()[$service];
    $v = static fn(string $k, $d = '') => e((string) ($l[$k] ?? $d));
    $price = $l ? rtrim(rtrim(number_format((int) $l['price_centavos'] / 100, 2, '.', ''), '0'), '.') : '';
    $isFood = $service === 'ueat';
    ?>
<form class="card mk-form" method="post" enctype="multipart/form-data" action="<?= e($action) ?>">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_listing"><input type="hidden" name="id" value="<?= (int) ($l['id'] ?? 0) ?>"><input type="hidden" name="service" value="<?= e($service) ?>">
    <h2><?= $l ? 'Edit ' . e($cfg['noun']) : 'Add ' . e($cfg['noun']) ?> <small><?= e($cfg['label']) ?></small></h2>
    <?php if ($l && $l['status'] === 'rejected' && $l['review_note']): ?><p class="alert error">Not approved: <?= e($l['review_note']) ?>. Fix it and save to send it for review again.</p><?php endif; ?>
    <div class="form-grid">
        <?php if ($admin): ?>
        <div class="field"><label for="mk-owner">Run by</label><select id="mk-owner" name="merchant_id"><option value="">Ultimate App (request only, pay on the day)</option><?php foreach ($admin['merchants'] as $mid => $mname): ?><option value="<?= (int) $mid ?>"<?= (int) ($l['merchant_id'] ?? $admin['merchant_id'] ?? 0) === (int) $mid ? ' selected' : '' ?>><?= e($mname) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <div class="field wide"><label for="mk-title"><?= $isFood ? 'Dish name' : 'Name' ?></label><input id="mk-title" name="title" required maxlength="120" value="<?= $v('title') ?>" placeholder="<?= $isFood ? 'e.g. Chicken Inasal Plate' : ($service === 'ugo' ? 'e.g. Boracay Land Tour' : 'e.g. In-room Massage') ?>"></div>
        <?php if ($isFood): ?>
            <div class="field"><label for="mk-cat">Menu section</label><input id="mk-cat" name="category" maxlength="40" list="mk-sections" value="<?= $v('category', 'Best sellers') ?>"><datalist id="mk-sections"><?php foreach (['Best sellers', 'Mains', 'Rice & noodles', 'Grill', 'Soups', 'Sides', 'Desserts', 'Drinks', 'Coffee', 'Bowls'] as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist><small>Dishes with the same section are shown together.</small></div>
        <?php else: ?>
            <div class="field"><label for="mk-cat">Category</label><select id="mk-cat" name="category" required><option value="">Choose…</option><?php foreach ($cfg['categories'] as $c): ?><option<?= ($l['category'] ?? '') === $c ? ' selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <div class="field"><label for="mk-price">Price (₱)</label><input id="mk-price" name="price" required inputmode="decimal" pattern="\d{1,6}(\.\d{1,2})?" value="<?= e($price) ?>" placeholder="850"></div>
        <?php if (!$isFood): ?>
            <div class="field"><label for="mk-unit">Price is</label><select id="mk-unit" name="price_unit"><?php foreach ($cfg['units'] as $u): ?><option value="<?= e($u) ?>"<?= ($l['price_unit'] ?? $cfg['default_unit']) === $u ? ' selected' : '' ?>><?= e(mk_units()[$u]) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="mk-dur">Duration (minutes)</label><input id="mk-dur" name="duration_minutes" type="number" min="5" max="1440" value="<?= $v('duration_minutes') ?>" placeholder="240"></div>
        <?php endif; ?>
        <div class="field wide"><label for="mk-sum">Short description</label><input id="mk-sum" name="summary" maxlength="200" value="<?= $v('summary') ?>" placeholder="<?= $isFood ? 'Grilled chicken, java rice and atchara' : 'One line customers see on the card' ?>"></div>
        <?php if (!$isFood): ?>
            <div class="field wide"><label for="mk-desc">Full description</label><textarea id="mk-desc" name="description" rows="4" maxlength="3000"><?= $v('description') ?></textarea></div>
            <div class="field"><label for="mk-incl">What's included <small>(one per line)</small></label><textarea id="mk-incl" name="includes_text" rows="3" maxlength="500"><?= $v('includes_text') ?></textarea></div>
            <div class="field"><label for="mk-loc">Location / area</label><input id="mk-loc" name="location" maxlength="160" value="<?= $v('location') ?>" placeholder="<?= $service === 'ugo' ? 'Station 3 boat station' : 'Anywhere in Boracay' ?>"></div>
            <?php if ($service === 'ugo'): ?>
                <div class="field"><label for="mk-meet">Meeting point</label><input id="mk-meet" name="meeting_point" maxlength="200" value="<?= $v('meeting_point') ?>" placeholder="Hotel pick-up or exact spot"></div>
                <div class="field"><label for="mk-slots">Start times</label><input id="mk-slots" name="time_slots" maxlength="200" value="<?= e(str_replace(',', ', ', (string) ($l['time_slots'] ?? ''))) ?>" placeholder="08:00, 13:00"><small>Leave empty to let customers pick any time.</small></div>
                <div class="field"><label for="mk-cap">Slots per start time</label><input id="mk-cap" name="capacity" type="number" min="1" max="500" value="<?= $v('capacity') ?>" placeholder="12"><small>Max guests per date and time. Empty = no limit.</small></div>
            <?php endif; ?>
            <div class="field"><label for="mk-min"><?= $service === 'ugo' ? 'Guests' : 'Quantity' ?> min – max</label><span class="inline"><input id="mk-min" name="min_qty" type="number" min="1" max="50" value="<?= $v('min_qty', '1') ?>" style="width:80px"> – <input name="max_qty" type="number" min="1" max="100" value="<?= $v('max_qty', '20') ?>" style="width:80px" aria-label="Maximum"></span></div>
            <div class="field"><label for="mk-cancel">Free cancellation until (hours before)</label><input id="mk-cancel" name="cancel_hours" type="number" min="0" max="168" value="<?= $v('cancel_hours', '24') ?>"></div>
            <div class="field"><label><input type="checkbox" name="instant_confirm" value="1"<?= (int) ($l['instant_confirm'] ?? 0) ? ' checked' : '' ?>> Instant confirmation</label><small>Bookings are confirmed right away. Leave off to confirm each one yourself.</small></div>
        <?php else: ?>
            <div class="field"><label for="mk-max">Max per order</label><input id="mk-max" name="max_qty" type="number" min="1" max="20" value="<?= $v('max_qty', '10') ?>"></div>
        <?php endif; ?>
        <div class="field"><label for="mk-badge">Tag <small>(optional)</small></label><input id="mk-badge" name="badge" maxlength="30" list="mk-badges" value="<?= $v('badge') ?>" placeholder="Best seller"><datalist id="mk-badges"><option value="Best seller"><option value="Popular"><option value="New"><option value="Spicy"><option value="Promo"></datalist></div>
        <div class="field"><label for="mk-sort">Order in list</label><input id="mk-sort" name="sort_order" type="number" min="0" max="9999" value="<?= $v('sort_order', '100') ?>"><small>Lower shows first.</small></div>
        <div class="field wide"><label for="mk-photo">Photo <small>(JPG/PNG, at least 240 px, max 8 MB)</small></label>
            <div class="mk-photo-row"><?php if ($l): ?><span class="mk-photo-prev" style="<?= mk_img_style($l['photo'], $service, $l['category'], $base) ?>"></span><?php endif; ?><input id="mk-photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp"></div>
            <?php if ($l && !$l['photo']): ?><small>No photo yet: customers see a sample picture for the category. A real photo sells much better.</small><?php endif; ?></div>
        <?php if ($admin): ?>
            <div class="field"><label for="mk-status">Status</label><select id="mk-status" name="status"><?php foreach (['active' => 'Live', 'pending' => 'Waiting for review', 'paused' => 'Paused (hidden)', 'rejected' => 'Not approved'] as $k => $lab): ?><option value="<?= $k ?>"<?= ($l['status'] ?? 'active') === $k ? ' selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label><input type="checkbox" name="featured" value="1"<?= (int) ($l['featured'] ?? 0) ? ' checked' : '' ?>> Featured (shown in "Popular now")</label></div>
        <?php endif; ?>
    </div>
    <?php if (!$admin && mk_review_listings()): ?><p class="table-meta">Saving sends it to Ultimate App for a quick review before customers see it.</p><?php endif; ?>
    <div class="form-actions"><button class="btn primary" type="submit">Save</button></div>
</form>
    <?php
}

/** UEat restaurant profile form (Merchant Portal and Admin). */
function mk_store_form(?array $s, array $merchant, string $action, string $base, bool $admin = false): void
{
    $v = static fn(string $k, $d = '') => e((string) ($s[$k] ?? $d));
    $peso = static fn(string $k, string $d) => $s ? rtrim(rtrim(number_format((int) $s[$k] / 100, 2, '.', ''), '0'), '.') : $d;
    ?>
<form class="card mk-form" method="post" enctype="multipart/form-data" action="<?= e($action) ?>">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_store"><input type="hidden" name="merchant_id" value="<?= (int) $merchant['id'] ?>">
    <h2><?= $s ? 'Restaurant profile' : 'Set up your UEat restaurant' ?> <?= $s ? portal_status_pill((string) $s['status']) : '' ?></h2>
    <?php if ($s && $s['status'] === 'rejected' && $s['review_note']): ?><p class="alert error">Not approved: <?= e($s['review_note']) ?></p><?php endif; ?>
    <div class="form-grid">
        <div class="field"><label for="st-name">Restaurant name</label><input id="st-name" name="display_name" required maxlength="120" value="<?= $v('display_name', $merchant['business_name']) ?>"></div>
        <div class="field"><label for="st-cuisine">Cuisine</label><select id="st-cuisine" name="cuisine" required><option value="">Choose…</option><?php foreach (mk_cuisines() as $c): ?><option<?= ($s['cuisine'] ?? '') === $c ? ' selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
        <div class="field wide"><label for="st-tag">Tagline</label><input id="st-tag" name="tagline" maxlength="160" value="<?= $v('tagline') ?>" placeholder="Home-style Filipino plates by the beach"></div>
        <div class="field"><label for="st-prep">Preparation time (minutes)</label><input id="st-prep" name="prep_minutes" type="number" min="5" max="180" value="<?= $v('prep_minutes', '25') ?>"></div>
        <div class="field"><label for="st-fee">Delivery fee (₱)</label><input id="st-fee" name="delivery_fee" inputmode="decimal" value="<?= e($peso('delivery_fee_centavos', '49')) ?>"><small>0 = free delivery. You deliver the food.</small></div>
        <div class="field"><label for="st-min">Minimum order (₱)</label><input id="st-min" name="min_order" inputmode="decimal" value="<?= e($peso('min_order_centavos', '0')) ?>"></div>
        <div class="field"><label>Hours</label><span class="inline"><input name="opens_at" type="time" value="<?= e(substr((string) ($s['opens_at'] ?? '08:00'), 0, 5)) ?>" aria-label="Opens"> to <input name="closes_at" type="time" value="<?= e(substr((string) ($s['closes_at'] ?? '22:00'), 0, 5)) ?>" aria-label="Closes"></span></div>
        <div class="field"><label>Order types</label><span class="mk-checks"><label><input type="checkbox" name="offers_delivery" value="1"<?= (int) ($s['offers_delivery'] ?? 1) ? ' checked' : '' ?>> Delivery</label><label><input type="checkbox" name="offers_pickup" value="1"<?= (int) ($s['offers_pickup'] ?? 1) ? ' checked' : '' ?>> Pick-up</label></span></div>
        <div class="field"><label for="st-promo">Promo banner <small>(optional)</small></label><input id="st-promo" name="promo_text" maxlength="80" value="<?= $v('promo_text') ?>" placeholder="Free delivery over ₱500"></div>
        <div class="field wide"><label for="st-cover">Cover photo</label><div class="mk-photo-row"><?php if ($s): ?><span class="mk-photo-prev wide" style="<?= mk_img_style($s['cover_photo'], 'ueat', $s['cuisine'], $base) ?>"></span><?php endif; ?><input id="st-cover" name="cover_photo" type="file" accept="image/jpeg,image/png,image/webp"></div></div>
        <?php if ($admin): ?><div class="field"><label for="st-status">Status</label><select id="st-status" name="status"><?php foreach (['active' => 'Live', 'pending' => 'Waiting for review', 'paused' => 'Paused (hidden)', 'rejected' => 'Not approved'] as $k => $lab): ?><option value="<?= $k ?>"<?= ($s['status'] ?? 'active') === $k ? ' selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    </div>
    <div class="form-actions"><button class="btn primary" type="submit">Save restaurant</button></div>
</form>
    <?php
}

function portal_status_pill(string $status): string
{
    $map = ['active' => ['good', 'Live'], 'pending' => ['warn', 'Waiting for review'], 'paused' => ['muted', 'Paused'], 'rejected' => ['bad', 'Not approved']];
    [$tone, $label] = $map[$status] ?? ['muted', ucfirst($status)];
    return '<span class="badge ' . $tone . '">' . e($label) . '</span>';
}

/** Saves a posted listing form. $merchantId = owner when a merchant saves; $adminId when Admin saves. */
function mk_handle_listing_post(PDO $pdo, ?int $merchantId, ?int $adminId): int
{
    $service = (string) ($_POST['service'] ?? '');
    $data = mk_validate_listing($_POST, $service);
    $owner = $merchantId;
    $status = null;
    if ($adminId !== null) {
        $owner = ($_POST['merchant_id'] ?? '') !== '' ? (int) $_POST['merchant_id'] : null;
        if ($service === 'ueat' && $owner === null) throw new InvalidArgumentException('A UEat dish must belong to a restaurant (merchant).');
        $status = (string) ($_POST['status'] ?? 'active');
        $data['featured'] = !empty($_POST['featured']) ? 1 : 0;
    }
    $id = (int) ($_POST['id'] ?? 0);
    if ($id) {
        $old = mk_listing($pdo, $id);
        if (!$old || ($adminId === null && (int) $old['merchant_id'] !== $merchantId)) throw new InvalidArgumentException('Listing not found.');
        if ($old['service'] !== $service) throw new InvalidArgumentException('Listing not found.');
    }
    $photo = mk_store_photo($_FILES['photo'] ?? null, 'The photo');
    try {
        return mk_listing_save($pdo, $id ?: null, $data, $owner, $adminId, $photo, $status);
    } catch (Throwable $e) {
        mk_delete_photo($photo);
        throw $e;
    }
}
