<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/ubarangay.php';
$admin = admin_require('barangay.settings');
$parseReqs = static function (string $text, array $existing = []): string {
    $out = [];
    $known = [];
    foreach ($existing as $r) $known[mb_strtolower($r['label'])] = $r['key'];
    foreach (preg_split('/\R/', trim($text)) ?: [] as $line) {
        if (trim($line) === '') continue;
        $parts = array_map('trim', explode('|', $line));
        $label = $parts[0];
        $required = strtolower($parts[1] ?? 'required') !== 'optional';
        if (mb_strlen($label) < 3 || mb_strlen($label) > 120) throw new InvalidArgumentException('Each requirement needs a label (3–120 characters).');
        // Keep the original key when the label is unchanged so earlier uploads still match.
        $key = $known[mb_strtolower($label)] ?? trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($label)) ?? '', '_');
        $key = substr($key, 0, 40) ?: 'req';
        while (isset($out[$key])) $key = substr($key, 0, 36) . '_' . random_int(10, 99);
        $out[$key] = ['key' => $key, 'label' => $label, 'required' => $required];
    }
    if (!$out) throw new InvalidArgumentException('Add at least one requirement.');
    if (count($out) > 12) throw new InvalidArgumentException('Up to 12 requirements.');
    return json_encode(array_values($out), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_post_guard();
    try {
        if (p('action') === 'module') {
            if (!admin_can('settings')) throw new InvalidArgumentException('Only a Super Admin can turn UBarangay on or off.');
            $on = p('enabled') === '1';
            setting_save($pdo, 'ubarangay.enabled', $on ? '1' : '0', (int) $admin['id']);
            admin_audit('ubarangay_' . ($on ? 'enabled' : 'disabled'), 'settings', 'ubarangay.enabled');
            flash('success', $on ? 'UBarangay is on: customers can apply and pay again.' : 'UBarangay is off: the tile is hidden and new applications and payments are paused.');
            redirect('brgy-types.php');
        } elseif (p('action') === 'officials') {
            $pdo->beginTransaction();
            foreach (brgy_barangays() as $name => $b) foreach (['captain', 'secretary', 'address', 'contact'] as $f) {
                $val = p($b['slug'] . '_' . $f);
                if (trim($val) === '') setting_clear($pdo, "brgy.{$b['slug']}.$f"); else setting_save($pdo, "brgy.{$b['slug']}.$f", $val, (int) $admin['id']);
            }
            $pdo->commit();
            admin_audit('brgy_officials_updated', 'settings', 'ubarangay');
            flash('success', 'Barangay officials saved. New certificates will show these names.');
        } else {
            $name = trim(p('name')); $desc = mb_substr(trim(p('description')), 0, 500); $text = trim(p('certificate_text'));
            if (mb_strlen($name) < 3 || mb_strlen($name) > 120) throw new InvalidArgumentException('Enter the permit name.');
            if (!preg_match('/^\d{1,6}(\.\d{1,2})?$/D', trim(p('fee')))) throw new InvalidArgumentException('Fee must be a peso amount (0 for free).');
            [$w, $f] = array_pad(explode('.', trim(p('fee')), 2), 2, '');
            $fee = (int) $w * 100 + (int) str_pad($f, 2, '0');
            $valid = (int) p('validity_days');
            if ($valid < 1 || $valid > 1095) throw new InvalidArgumentException('Validity must be 1–1095 days.');
            if (mb_strlen($text) < 20) throw new InvalidArgumentException('Write the certificate text (use {name}, {address}, {purpose}…).');
            $current = p('id') !== '' ? brgy_type($pdo, (int) p('id')) : null;
            $reqs = $parseReqs(p('requirements'), $current ? brgy_requirements($current) : []);
            $vals = [$name, $desc, $fee, $valid, p('needs_business') === '1' ? 1 : 0, $reqs, $text, p('active') === '1' ? 1 : 0, (int) p('sort_order')];
            if (p('id') !== '') {
                $pdo->prepare('UPDATE brgy_permit_types SET name = ?, description = ?, fee_centavos = ?, validity_days = ?, needs_business = ?, requirements = ?, certificate_text = ?, active = ?, sort_order = ? WHERE id = ?')->execute([...$vals, (int) p('id')]);
                admin_audit('brgy_type_updated', 'brgy_type', p('id'), ['name' => $name, 'fee' => $fee]);
            } else {
                $code = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($name)) ?? '', '_') . '_' . random_int(100, 999);
                $pdo->prepare('INSERT INTO brgy_permit_types (name, description, fee_centavos, validity_days, needs_business, requirements, certificate_text, active, sort_order, code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([...$vals, $code]);
                admin_audit('brgy_type_created', 'brgy_type', $pdo->lastInsertId(), $name);
            }
            flash('success', 'Permit type saved. New applications use the new fee; existing ones keep theirs.');
        }
    } catch (InvalidArgumentException $ex) { if ($pdo->inTransaction()) $pdo->rollBack(); flash('error', $ex->getMessage()); }
    redirect('brgy-types.php');
}
$types = brgy_types($pdo, false);
$reqText = static fn(array $t): string => implode("\n", array_map(static fn($r) => $r['label'] . ' | ' . (!empty($r['required']) ? 'required' : 'optional'), brgy_requirements($t)));
$typeForm = static function (?array $t) use ($reqText): void { ?>
    <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= e((string) ($t['id'] ?? '')) ?>">
        <div class="form-grid">
            <div class="field"><label>Name</label><input type="text" name="name" required value="<?= e($t['name'] ?? '') ?>"></div>
            <div class="field"><label>Fee (PHP, 0 = free)</label><input type="text" name="fee" inputmode="decimal" required value="<?= e(centavos_to_decimal((int) ($t['fee_centavos'] ?? 0))) ?>"></div>
            <div class="field"><label>Valid for (days)</label><input type="number" name="validity_days" min="1" max="1095" value="<?= (int) ($t['validity_days'] ?? 180) ?>"></div>
            <div class="field"><label>Order</label><input type="number" name="sort_order" value="<?= (int) ($t['sort_order'] ?? 100) ?>"></div>
        </div>
        <div class="field mt"><label>Short description</label><input type="text" name="description" maxlength="500" value="<?= e($t['description'] ?? '') ?>"></div>
        <div class="field mt"><label>Requirements <small>— one per line: <code>Label | required</code> or <code>Label | optional</code></small></label><textarea name="requirements" rows="4" required><?= e($t ? $reqText($t) : "Valid government ID | required") ?></textarea></div>
        <div class="field mt"><label>Certificate text <small>— placeholders: {name} {address} {barangay} {purpose} {civil_status} {years_residency} {birthdate} {business_name} {business_address} {business_nature}</small></label><textarea name="certificate_text" rows="5" required><?= e($t['certificate_text'] ?? 'This is to certify that {name}, a resident of {address}, Barangay {barangay}, Malay, Aklan, ...\n\nThis certification is issued for {purpose}.') ?></textarea></div>
        <div class="form-actions"><label><input type="checkbox" name="active" value="1"<?= !$t || (int) $t['active'] === 1 ? ' checked' : '' ?>> Available to residents</label><label><input type="checkbox" name="needs_business" value="1"<?= (int) ($t['needs_business'] ?? 0) === 1 ? ' checked' : '' ?>> Asks for business details</label><button class="btn primary" type="submit">Save</button></div>
    </form>
<?php };
admin_header('Permit types & officials', 'brgy-types');
?>
<form class="card" method="post" style="margin-bottom:16px" data-confirm="<?= ubarangay_enabled() ? 'Turn UBarangay off for customers?' : 'Turn UBarangay on for customers?' ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="module"><input type="hidden" name="enabled" value="<?= ubarangay_enabled() ? '0' : '1' ?>">
    <h2>UBarangay for customers <?= status_badge(ubarangay_enabled() ? 'active' : 'disabled') ?></h2>
    <p class="muted"><?= ubarangay_enabled() ? 'Customers see the UBarangay tile and can apply and pay online.' : 'Hidden from customers. New applications and payments are paused. Issued certificates can still be printed and verified, and officers can still finish applications already submitted here.' ?></p>
    <?php if (admin_can('settings')): ?><button class="btn <?= ubarangay_enabled() ? 'danger' : 'primary' ?>" type="submit"><?= ubarangay_enabled() ? 'Turn off UBarangay' : 'Turn on UBarangay' ?></button><?php else: ?><p class="table-meta">Only a Super Admin can change this.</p><?php endif; ?>
</form>
<div class="grid two">
<div class="stack">
    <section class="card"><h2>Permit types <small>Fees are placeholders until you set your ordinance rates</small></h2>
        <?php foreach ($types as $t): ?><details class="kv-details"><summary><b><?= e($t['name']) ?></b> <span class="muted">· <?= (int) $t['fee_centavos'] === 0 ? 'Free' : '₱' . peso((int) $t['fee_centavos']) ?> · <?= count(brgy_requirements($t)) ?> requirements · <?= (int) $t['validity_days'] ?> days</span> <?= (int) $t['active'] ? '' : '<span class="badge muted">Hidden</span>' ?></summary><?php $typeForm($t); ?></details><?php endforeach; ?>
    </section>
    <section class="card"><h2>Add a permit type</h2><?php $typeForm(null); ?></section>
</div>
<form class="card" method="post"><h2>Barangay officials <small>Printed on certificates</small></h2>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="officials">
    <?php foreach (brgy_barangays() as $name => $b): $o = brgy_officials($name); ?>
        <p class="section-title">Barangay <?= e($name) ?></p>
        <div class="field"><label>Punong Barangay</label><input type="text" name="<?= $b['slug'] ?>_captain" maxlength="120" value="<?= e($o['captain']) ?>" placeholder="Hon. Juan Dela Cruz"></div>
        <div class="field mt"><label>Barangay Secretary</label><input type="text" name="<?= $b['slug'] ?>_secretary" maxlength="120" value="<?= e($o['secretary']) ?>"></div>
        <div class="field mt"><label>Barangay hall address</label><input type="text" name="<?= $b['slug'] ?>_address" maxlength="200" value="<?= e((string) setting("brgy.{$b['slug']}.address", '')) ?>" placeholder="<?= e($o['address']) ?>"></div>
        <div class="field mt"><label>Contact number</label><input type="text" name="<?= $b['slug'] ?>_contact" maxlength="80" value="<?= e($o['contact']) ?>"></div>
    <?php endforeach; ?>
    <div class="form-actions"><button class="btn primary" type="submit">Save officials</button></div>
</form>
</div>
<?php admin_footer();
