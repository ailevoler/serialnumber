<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_cms();

if (is_post()) {
    csrf_check();
    $mode = input('home_mode');
    if (in_array($mode, ['landing', 'coming_soon', 'login'], true)) {
        save_setting('home_mode', $mode);
        save_setting('coming_soon_enabled', $mode === 'coming_soon' ? '1' : '0');
        log_activity((int)$me['id'], "CMS: home page set to {$mode}");
        flash('success', 'Home page updated.');
    }
    if (($sec = input('toggle')) !== '' && isset(cms_sections()[$sec])) {
        $key = $sec . '.enabled';
        cms_save($key, cms($key) === '0' ? '1' : '0', (int)$me['id']);
        log_activity((int)$me['id'], "CMS: toggled section {$sec}");
        flash('success', cms_sections()[$sec]['label'] . (cms_all(true)[$key] === '1' ? ' is now visible.' : ' is now hidden.'));
    }
    redirect('admin/cms.php');
}

$mode = home_mode();
$counts = array_column(q_all("SELECT type, COUNT(*) n, SUM(status = 'published') pub FROM cms_items GROUP BY type"), null, 'type');
$leads = q_row("SELECT COUNT(*) total, SUM(created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) week FROM crm_leads WHERE source = 'Website'");
$media = (int)q_val('SELECT COUNT(*) FROM cms_media');
$last = q_row('SELECT c.updated_at, u.first_name, u.last_name FROM cms_content c LEFT JOIN users u ON u.id = c.updated_by ORDER BY c.updated_at DESC LIMIT 1');
$modes = [
    'landing'     => ['Corporate website', 'Full landing page managed by this CMS', 'home.php'],
    'coming_soon' => ['Coming Soon page', 'Countdown and launch sign-up form', 'coming-soon.php'],
    'login'       => ['Member login', 'Visitors go straight to the member login', 'login.php'],
];

page_header('Website CMS', $me, 'Manage the public website content');
?>
<section class="hero-card">
  <div style="z-index:1">
    <span class="hero-badge"><?= $me['role'] === 'cms' ? 'Content Manager' : 'Administrator' ?></span>
    <h2>Website Content Manager</h2>
    <p>Edit texts, images, FAQs and testimonials. Changes are live as soon as you save.<?= $last ? ' Last update: ' . fdatetime($last['updated_at']) . ($last['first_name'] ? ' by ' . e($last['first_name']) : '') . '.' : '' ?></p>
  </div>
  <div class="ref-box" style="display:flex;gap:8px;flex-wrap:wrap;min-width:0">
    <a class="btn btn-gold btn-sm" href="<?= e(url('home.php?preview=1')) ?>" target="_blank" rel="noopener"><?= icon('eye', 14) ?> Preview website</a>
    <a class="btn btn-light btn-sm" href="<?= e(url('admin/cms_section.php')) ?>"><?= icon('layers', 14) ?> Edit sections</a>
  </div>
</section>

<div class="stats">
  <?= stat_card('Home page shows', $modes[$mode][0], 'home', 'dark') ?>
  <?= stat_card('Website inquiries', num($leads['total']), 'message', 'gold', num($leads['week']) . ' in the last 7 days') ?>
  <?= stat_card('Published FAQs', num($counts['faq']['pub'] ?? 0), 'book') ?>
  <?= stat_card('Media files', num($media), 'box', 'gold') ?>
</div>

<form method="post" class="card">
  <?= csrf_field() ?>
  <div class="card-header"><h3><?= icon('home') ?> What visitors see at the home page</h3></div>
  <div class="card-body">
    <div class="mode-grid">
      <?php foreach ($modes as $k => [$label, $desc, $link]): ?>
        <label class="mode-card">
          <input type="radio" name="home_mode" value="<?= $k ?>" <?= $mode === $k ? 'checked' : '' ?> onchange="this.form.submit()">
          <span class="mode-body"><strong><?= e($label) ?></strong><small><?= e($desc) ?></small>
            <a href="<?= e(url($link . ($k === 'landing' ? '?preview=1' : ''))) ?>" target="_blank" rel="noopener" onclick="event.stopPropagation()">Open page ↗</a></span>
        </label>
      <?php endforeach; ?>
    </div>
    <noscript><button class="btn btn-primary mt-2" type="submit">Save</button></noscript>
  </div>
</form>

<div class="card">
  <div class="card-header"><h3><?= icon('layers') ?> Page Sections</h3><div class="actions"><span class="small text-muted">Top to bottom, as they appear on the website</span></div></div>
  <div class="card-body p-0 table-wrap">
    <table class="table stack">
      <thead><tr><th>Section</th><th>Content</th><th>Visibility</th><th></th></tr></thead>
      <tbody>
      <?php foreach (cms_sections() as $key => $s):
          $hasToggle = isset($s['fields'][$key . '.enabled']);
          $itemType = $s['items'] ?? null; ?>
        <tr>
          <td><div class="user-cell"><div class="cms-ic"><?= icon($s['icon'], 18) ?></div><strong><?= e($s['label']) ?></strong></div></td>
          <td class="small text-muted"><?= $itemType ? num($counts[$itemType]['pub'] ?? 0) . ' published · ' . num(($counts[$itemType]['n'] ?? 0) - ($counts[$itemType]['pub'] ?? 0)) . ' draft' : count($s['fields']) . ' field(s)' ?></td>
          <td>
            <?php if ($hasToggle): ?>
              <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="toggle" value="<?= e($key) ?>">
                <button class="toggle-pill <?= cms_on($key) ? 'on' : '' ?>" type="submit" title="Click to <?= cms_on($key) ? 'hide' : 'show' ?>"><span></span><?= cms_on($key) ? 'Visible' : 'Hidden' ?></button></form>
            <?php else: ?><span class="badge badge-muted">Always on</span><?php endif; ?>
          </td>
          <td class="nowrap">
            <a class="btn btn-xs btn-outline" href="<?= e(url('admin/cms_section.php?s=' . $key)) ?>">Edit</a>
            <?php if ($itemType): ?><a class="btn btn-xs btn-light" href="<?= e(url('admin/cms_items.php?type=' . $itemType)) ?>">Items</a><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-body small text-muted">
    The <strong>Products</strong> section lists active products from the product catalog, and <strong>Business opportunity</strong> lists the ways to earn that are switched on in the Compensation Plan — they update automatically.
    Contact-form messages are saved in <?= $me['role'] === 'admin' ? '<a href="' . e(url('admin/crm.php?q=')) . '">CRM</a>' : 'the CRM' ?> as Website leads.
  </div>
</div>
<?php page_footer();
