<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_cms();

if (is_post()) {
    csrf_check();
    try {
        if (input('action') === 'upload') {
            $_FILES['img'] = isset($_FILES['file']) ? array_map(fn($v) => ['upload' => $v], $_FILES['file']) : null;
            $path = cms_upload('img', 'upload', (int)$me['id']);
            if (!$path) {
                throw new RuntimeException('Please choose an image to upload.');
            }
            q('UPDATE cms_media SET alt = ? WHERE path = ?', [mb_substr(input('alt'), 0, 160) ?: null, $path]);
            flash('success', 'Image uploaded.');
        } elseif (input('action') === 'delete') {
            $m = q_row('SELECT * FROM cms_media WHERE id = ?', [input_int('id')]);
            if ($m) {
                $used = (int)q_val('SELECT COUNT(*) FROM cms_content WHERE cvalue = ?', [$m['path']]) + (int)q_val('SELECT COUNT(*) FROM cms_items WHERE image = ?', [$m['path']]);
                if ($used) {
                    throw new RuntimeException('This image is used on the website. Replace it there first.');
                }
                q('DELETE FROM cms_media WHERE id = ?', [$m['id']]);
                $file = realpath(UPLOAD_DIR . '/' . $m['path']);
                if ($file && str_starts_with($file, realpath(UPLOAD_DIR . '/cms'))) {
                    @unlink($file);
                }
                flash('success', 'Image deleted.');
            }
        }
        log_activity((int)$me['id'], 'CMS media: ' . input('action'));
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('admin/cms_media.php');
}

$media = q_all('SELECT m.*, u.first_name FROM cms_media m LEFT JOIN users u ON u.id = m.uploaded_by ORDER BY m.id DESC');
$used = array_flip(array_merge(array_column(q_all('SELECT cvalue FROM cms_content'), 'cvalue'), array_column(q_all('SELECT image FROM cms_items WHERE image IS NOT NULL'), 'image')));

page_header('Media Library', $me, 'Images used on the website');
?>
<form method="post" enctype="multipart/form-data" class="card">
  <?= csrf_field() ?><input type="hidden" name="action" value="upload">
  <div class="card-body filters">
    <div style="flex:1;min-width:220px"><label>Image</label><input class="input" style="width:100%" type="file" name="file" accept="image/*" required></div>
    <div style="flex:1;min-width:200px"><label>Description (alt text)</label><input class="input" style="width:100%" name="alt" placeholder="e.g. Barley product photo"></div>
    <button class="btn btn-primary" type="submit"><?= icon('plus', 15) ?> Upload</button>
  </div>
</form>
<div class="media-grid">
  <?php foreach ($media as $m): $url = cms_img($m['path']); ?>
    <figure class="media-item card">
      <a href="<?= e($url) ?>" target="_blank" rel="noopener"><img src="<?= e($url) ?>" alt="<?= e($m['alt']) ?>" loading="lazy"></a>
      <figcaption>
        <strong title="<?= e($m['path']) ?>"><?= e($m['alt'] ?: basename($m['path'])) ?></strong>
        <small><?= fdate($m['created_at']) ?><?= $m['first_name'] ? ' · ' . e($m['first_name']) : '' ?> <?= isset($used[$m['path']]) ? '<span class="badge badge-success">In use</span>' : '' ?></small>
        <div class="filters mt-1">
          <input class="input small mono" id="m<?= (int)$m['id'] ?>" value="<?= e(absolute_url('uploads/' . $m['path'])) ?>" readonly style="flex:1;min-width:0">
          <button type="button" class="btn btn-xs btn-light" data-copy="#m<?= (int)$m['id'] ?>"><?= icon('copy', 12) ?></button>
          <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn btn-xs btn-light" data-confirm="Delete this image?" aria-label="Delete"><?= icon('x', 12) ?></button></form>
        </div>
      </figcaption>
    </figure>
  <?php endforeach; ?>
</div>
<?php if (!$media): ?><div class="card"><div class="card-body text-center text-muted">No images yet. Upload your first image above.</div></div><?php endif; ?>
<?php page_footer();
