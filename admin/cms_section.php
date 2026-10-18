<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_cms();

$sections = cms_sections();
$key = array_key_exists(input('s'), $sections) ? input('s') : array_key_first($sections);
$section = $sections[$key];

if (is_post()) {
    csrf_check();
    try {
        $vals = (array)($_POST['f'] ?? []);
        tx(function () use ($section, $vals, $me) {
            foreach ($section['fields'] as $fk => [$label, $type]) {
                if ($type === 'toggle') {
                    $v = isset($vals[$fk]) ? '1' : '0';
                } elseif ($type === 'image') {
                    $v = cms_upload('img', $fk, (int)$me['id']) ?? (string)($vals[$fk] ?? '');
                } else {
                    $v = str_replace("\r", '', trim((string)($vals[$fk] ?? '')));
                    if ($type === 'url' && $v !== '' && preg_match('#^javascript:#i', $v)) {
                        throw new RuntimeException("Invalid link for \"{$label}\".");
                    }
                    if (mb_strlen($v) > 5000) {
                        throw new RuntimeException("\"{$label}\" is too long.");
                    }
                }
                cms_save($fk, $v, (int)$me['id']);
            }
        });
        log_activity((int)$me['id'], 'CMS: updated section ' . $key);
        flash('success', $section['label'] . ' saved. Changes are now live.');
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('admin/cms_section.php?s=' . $key);
}

$anchors = ['about' => '#about', 'products' => '#products', 'features' => '#why', 'opportunity' => '#opportunity', 'testimonials' => '#testimonials', 'faq' => '#faq', 'contact' => '#contact', 'footer' => '#contact'];

page_header('Page Sections', $me, 'Edit website texts and images');
?>
<div class="cms-layout">
  <nav class="card cms-nav" aria-label="Sections">
    <?php foreach ($sections as $k => $s): ?>
      <a href="?s=<?= $k ?>" class="<?= $k === $key ? 'active' : '' ?>"><?= icon($s['icon'], 16) ?> <?= e($s['label']) ?>
        <?php if (isset($s['fields'][$k . '.enabled']) && !cms_on($k)): ?><em class="badge badge-muted">Hidden</em><?php endif; ?></a>
    <?php endforeach; ?>
  </nav>

  <form method="post" enctype="multipart/form-data" class="card">
    <?= csrf_field() ?>
    <div class="card-header">
      <h3><?= icon($section['icon']) ?> <?= e($section['label']) ?></h3>
      <div class="actions">
        <?php if (!empty($section['items'])): ?><a class="btn btn-light btn-sm" href="<?= e(url('admin/cms_items.php?type=' . $section['items'])) ?>"><?= icon('list', 14) ?> Manage <?= e(cms_item_types()[$section['items']]['label']) ?></a><?php endif; ?>
        <a class="btn btn-light btn-sm" href="<?= e(url('home.php?preview=1' . ($anchors[$key] ?? ''))) ?>" target="_blank" rel="noopener"><?= icon('eye', 14) ?> Preview</a>
      </div>
    </div>
    <div class="card-body">
      <?php foreach ($section['fields'] as $fk => [$label, $type, $default]): $val = cms($fk); $name = 'f[' . $fk . ']'; ?>
        <div class="form-group">
          <?php if ($type === 'toggle'): ?>
            <label class="toggle-card" style="max-width:420px"><input type="checkbox" name="<?= e($name) ?>" value="1" <?= $val !== '0' ? 'checked' : '' ?>><span class="toggle-ui"></span><span><strong><?= e($label) ?></strong></span></label>
          <?php else: ?>
            <label><?= e($label) ?></label>
            <?php if ($type === 'textarea' || $type === 'lines'): ?>
              <textarea class="input" name="<?= e($name) ?>" rows="<?= $type === 'lines' ? 4 : (mb_strlen($val) > 220 ? 6 : 3) ?>"><?= e($val) ?></textarea>
              <?php if ($type === 'lines'): ?><div class="help">One item per line.</div><?php endif; ?>
            <?php elseif ($type === 'image'): ?>
              <?= cms_image_field($name, $fk, $val) ?>
            <?php else: ?>
              <input class="input" name="<?= e($name) ?>" value="<?= e($val) ?>" <?= $type === 'url' ? 'placeholder="https://…, #section or page.php"' : '' ?>>
              <?php if ($type === 'url'): ?><div class="help">Use a full link (https://…), a page (register.php) or a section (#contact).</div><?php endif; ?>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <div class="sticky-save"><button class="btn btn-primary" type="submit"><?= icon('settings', 15) ?> Save &amp; Publish</button></div>
    </div>
  </form>
</div>
<?php page_footer();
