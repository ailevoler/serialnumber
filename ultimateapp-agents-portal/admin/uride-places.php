<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/uride.php';
require_once __DIR__ . '/../includes/uride_places.php';
$admin = admin_require('rides.view');
$canEdit = admin_can('settings');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_post_guard();
    $id = (int) p('id');
    try {
        if (!$canEdit) throw new InvalidArgumentException('Only a Super Admin can change URide places.');
        if (p('action') === 'delete') {
            $pdo->prepare('DELETE FROM uride_places WHERE id = ?')->execute([$id]);
            admin_audit('uride_place_delete', 'uride_place', $id);
            flash('success', 'Place removed.');
            redirect('uride-places.php');
        }
        $name = trim(preg_replace('/\s+/u', ' ', p('name')) ?? '');
        $area = trim(preg_replace('/\s+/u', ' ', p('area')) ?? '');
        $keywords = trim(preg_replace('/\s+/u', ' ', p('keywords')) ?? '');
        $lat = trim(p('latitude')); $lng = trim(p('longitude'));
        if ($name === '' || mb_strlen($name) > 120) throw new InvalidArgumentException('Enter the place name (up to 120 characters).');
        if (mb_strlen($area) > 120 || mb_strlen($keywords) > 255) throw new InvalidArgumentException('Area or other names are too long.');
        if (!is_numeric($lat) || !is_numeric($lng) || (float) $lat < 4 || (float) $lat > 22 || (float) $lng < 116 || (float) $lng > 127) throw new InvalidArgumentException('Tap the map to pin the exact spot.');
        $row = [$name, $area !== '' ? $area : null, $keywords !== '' ? $keywords : null, round((float) $lat, 7), round((float) $lng, 7), p('verified') === '1' ? 1 : 0, p('active') === '1' ? 1 : 0, max(0, min(9999, (int) p('sort_order', '100')))];
        if ($id) {
            $pdo->prepare('UPDATE uride_places SET name = ?, area = ?, keywords = ?, latitude = ?, longitude = ?, verified = ?, active = ?, sort_order = ? WHERE id = ?')->execute([...$row, $id]);
        } else {
            $pdo->prepare('INSERT INTO uride_places (name, area, keywords, latitude, longitude, verified, active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute($row);
            $id = (int) $pdo->lastInsertId();
        }
        admin_audit('uride_place_save', 'uride_place', $id, ['name' => $name, 'lat' => $lat, 'lng' => $lng, 'verified' => $row[5]]);
        flash('success', 'Saved "' . $name . '".');
        redirect('uride-places.php');
    } catch (InvalidArgumentException $ex) {
        flash('error', $ex->getMessage());
        redirect('uride-places.php' . ($id ? '?id=' . $id : '?new=1'));
    } catch (PDOException $ex) {
        flash('error', $ex->getCode() === '23000' ? 'A place with that name already exists.' : 'Could not save the place.');
        redirect('uride-places.php' . ($id ? '?id=' . $id : '?new=1'));
    }
}

admin_header('URide places', 'uride-places');
if (!uride_places_ready($pdo)) {
    echo '<div class="flash error" role="status">Import <b>database/uride_places_migration.sql</b> once in phpMyAdmin, then reload this page.</div>';
    admin_footer();
    exit;
}
$places = $pdo->query('SELECT * FROM uride_places ORDER BY sort_order, name')->fetchAll();
$edit = null;
if (q('id') !== '') { $s = $pdo->prepare('SELECT * FROM uride_places WHERE id = ?'); $s->execute([(int) q('id')]); $edit = $s->fetch() ?: null; }
$showForm = $canEdit && ($edit || q('new') === '1');
$unverified = count(array_filter($places, static fn($p) => !(int) $p['verified']));
$merchantPins = (int) $pdo->query("SELECT COUNT(*) FROM merchants WHERE status = 'approved' AND latitude IS NOT NULL")->fetchColumn();
?>
<p class="muted">Customers see these places as they type in URide <b>Pick-up</b> and <b>Drop-off</b>, together with <?= $merchantPins ?> approved merchant(s) that have a map pin and their own recent places. This works even when Google place search is not enabled for your key.</p>
<?php if ($unverified): ?><div class="flash error" role="status"><?= $unverified ?> place(s) have an <b>approximate</b> pin. Open each one, drag the pin to the exact spot (use Satellite view in Google Maps to compare), tick <b>Pin checked</b> and save.</div><?php endif; ?>
<div class="grid two" <?= google_maps_attrs() ?>>
    <section class="card"><h2>Places <small><?= count($places) ?></small><?php if ($canEdit): ?> <a class="btn small" href="?new=1">Add place</a><?php endif; ?></h2>
        <div class="table-wrap"><table><thead><tr><th>Place</th><th>Pin</th><th></th></tr></thead><tbody>
        <?php foreach ($places as $p): ?><tr>
            <td><b><?= e($p['name']) ?></b><small><?= e($p['area'] ?? '') ?><?= (int) $p['active'] ? '' : ' · hidden' ?></small></td>
            <td><?= (int) $p['verified'] ? '<span class="badge good">Checked</span>' : '<span class="badge warn">Approximate</span>' ?><small><?= e(number_format((float) $p['latitude'], 6) . ', ' . number_format((float) $p['longitude'], 6)) ?></small></td>
            <td><?php if ($canEdit): ?><a href="?id=<?= (int) $p['id'] ?>">Edit</a><?php endif; ?> <a href="https://www.google.com/maps/search/?api=1&amp;query=<?= e($p['latitude'] . ',' . $p['longitude']) ?>" target="_blank" rel="noopener">Google Maps</a></td>
        </tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
    <?php if ($showForm): ?>
    <form class="card" method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
        <h2><?= $edit ? 'Edit place' : 'Add place' ?></h2>
        <div class="form-grid">
            <div class="field"><label for="name">Name</label><input id="name" name="name" required maxlength="120" value="<?= e($edit['name'] ?? '') ?>" placeholder="e.g. D'Mall Boracay"></div>
            <div class="field"><label for="area">Area</label><input id="area" name="area" maxlength="120" value="<?= e($edit['area'] ?? '') ?>" placeholder="e.g. Station 2, Balabag"></div>
            <div class="field"><label for="keywords">Other names people type</label><input id="keywords" name="keywords" maxlength="255" value="<?= e($edit['keywords'] ?? '') ?>" placeholder="dmall, d mall, station 2"><small>Comma separated. Helps when customers type a nickname.</small></div>
            <div class="field"><label for="sort_order">Order</label><input id="sort_order" name="sort_order" type="number" min="0" max="9999" value="<?= (int) ($edit['sort_order'] ?? 100) ?>"><small>Lower shows first when the field is empty.</small></div>
        </div>
        <input type="hidden" id="latitude" name="latitude" value="<?= e((string) ($edit['latitude'] ?? '')) ?>"><input type="hidden" id="longitude" name="longitude" value="<?= e((string) ($edit['longitude'] ?? '')) ?>">
        <div class="map-box tall" data-map-picker data-lat-input="latitude" data-lng-input="longitude">Loading map…</div>
        <div class="map-tools"><p data-map-status></p><button class="btn small" type="button" data-map-locate>Use my current location</button></div>
        <label><input type="checkbox" name="verified" value="1"<?= (int) ($edit['verified'] ?? 0) ? ' checked' : '' ?>> Pin checked: it is on the exact spot</label><br>
        <label><input type="checkbox" name="active" value="1"<?= !$edit || (int) $edit['active'] ? ' checked' : '' ?>> Show to customers</label>
        <div class="form-actions"><button class="btn primary" type="submit">Save</button><a class="btn" href="uride-places.php">Cancel</a></div>
    </form>
    <?php if ($edit): ?><form method="post" class="mt" data-confirm="Remove this place?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><button class="btn danger small" type="submit">Remove place</button></form><?php endif; ?>
    <?php else: ?>
    <section class="card"><h2>Map</h2>
        <div class="map-box tall" data-map-view data-markers="<?= e(json_encode(array_map(static fn($p) => ['lat' => (float) $p['latitude'], 'lng' => (float) $p['longitude'], 'title' => $p['name'], 'sub' => ((int) $p['verified'] ? 'Pin checked' : 'Approximate pin') . ($p['area'] ? ' · ' . $p['area'] : '')], $places))) ?>">Loading map…</div>
        <p class="table-meta" data-map-status></p>
    </section>
    <?php endif; ?>
</div>
<?php admin_footer(['../assets/js/gmaps.js?v=2']); ?>
