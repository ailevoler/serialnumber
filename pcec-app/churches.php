<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';
require_login();

if (is_post()) {
    csrf_check();
    if (!is_leader()) {
        flash('error', 'Only leaders and admins can add churches.');
        redirect('churches.php');
    }
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        flash('error', 'Church name is required.');
    } else {
        $f = fn($k, $len = 255) => mb_substr(trim($_POST[$k] ?? ''), 0, $len) ?: null;
        q('INSERT INTO churches (name, denomination, city, region, address, pastor, contact, description) VALUES (?,?,?,?,?,?,?,?)',
            [mb_substr($name, 0, 160), $f('denomination', 120), $f('city', 100), $f('region', 100), $f('address'), $f('pastor', 120), $f('contact', 120), $f('description', 2000)]);
        flash('success', 'Church added to the directory.');
    }
    redirect('churches.php');
}

$search = trim($_GET['q'] ?? '');
$region = trim($_GET['region'] ?? '');
$where = '1=1';
$params = [];
if ($search !== '') {
    $where .= ' AND (c.name LIKE ? OR c.city LIKE ? OR c.denomination LIKE ? OR c.pastor LIKE ?)';
    array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
}
if ($region !== '') {
    $where .= ' AND c.region = ?';
    $params[] = $region;
}
$churches = q_all("SELECT c.*, (SELECT COUNT(*) FROM users u WHERE u.church_id = c.id) AS members FROM churches c WHERE $where ORDER BY c.name", $params);
$regions = q_all('SELECT DISTINCT region FROM churches WHERE region IS NOT NULL ORDER BY region');

$pageTitle = 'Our Churches';
$activeNav = 'churches';
require __DIR__ . '/includes/header.php';
?>
<form class="search-bar" method="get">
  <?= icon('search') ?>
  <input name="q" value="<?= e($search) ?>" placeholder="Search churches, cities, pastors…">
  <select name="region" onchange="this.form.submit()">
    <option value="">All regions</option>
    <?php foreach ($regions as $r): ?><option <?= $region === $r['region'] ? 'selected' : '' ?>><?= e($r['region']) ?></option><?php endforeach; ?>
  </select>
</form>
<?php if (is_leader()): ?>
  <div class="page-actions"><span class="muted"><?= count($churches) ?> churches</span><button class="btn btn-gradient" data-open="church-modal"><?= icon('plus-square') ?> Add Church</button></div>
<?php endif; ?>

<div class="grid-cards">
  <?php foreach ($churches as $c): ?>
    <article class="card church-card">
      <div class="church-icon"><?= icon('church') ?></div>
      <div>
        <h3><?= e($c['name']) ?></h3>
        <?php if ($c['denomination']): ?><span class="tag tag-soft"><?= e($c['denomination']) ?></span><?php endif; ?>
        <ul class="meta-list small">
          <?php if ($c['city'] || $c['region']): ?><li><?= icon('pin') ?><?= e(trim(($c['address'] ? $c['address'] . ', ' : '') . $c['city'] . ($c['region'] ? ' · ' . $c['region'] : ''), ', ')) ?></li><?php endif; ?>
          <?php if ($c['pastor']): ?><li><?= icon('user') ?><?= e($c['pastor']) ?></li><?php endif; ?>
          <?php if ($c['contact']): ?><li><?= icon('phone') ?><?= e($c['contact']) ?></li><?php endif; ?>
          <li><?= icon('users') ?><?= (int) $c['members'] ?> member<?= $c['members'] == 1 ? '' : 's' ?> on PCEC</li>
        </ul>
        <?php if ($c['description']): ?><p class="muted"><?= e($c['description']) ?></p><?php endif; ?>
        <a class="link" href="<?= e(url('members.php?church=' . $c['id'])) ?>">View members <?= icon('chevron-right') ?></a>
      </div>
    </article>
  <?php endforeach; ?>
</div>
<?php if (!$churches) empty_state('church', 'No churches match your search.'); ?>

<?php if (is_leader()): ?>
<div class="modal" id="church-modal" hidden>
  <div class="modal-card">
    <div class="modal-head"><h2>Add Church</h2><button class="icon-btn" data-close aria-label="Close"><?= icon('x') ?></button></div>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <label>Church name<input name="name" required maxlength="160"></label>
      <div class="grid-2">
        <label>Denomination<input name="denomination"></label>
        <label>Senior pastor<input name="pastor"></label>
      </div>
      <div class="grid-2">
        <label>City<input name="city"></label>
        <label>Region<input name="region" list="region-list"></label>
      </div>
      <datalist id="region-list"><?php foreach (['NCR','CAR','Ilocos Region','Cagayan Valley','Central Luzon','CALABARZON','MIMAROPA','Bicol Region','Western Visayas','Central Visayas','Eastern Visayas','Zamboanga Peninsula','Northern Mindanao','Davao Region','SOCCSKSARGEN','Caraga','BARMM'] as $r): ?><option value="<?= e($r) ?>"><?php endforeach; ?></datalist>
      <label>Address<input name="address"></label>
      <label>Contact<input name="contact"></label>
      <label>Description<textarea name="description" rows="3"></textarea></label>
      <button class="btn btn-gradient btn-block"><?= icon('check') ?> Save</button>
    </form>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
