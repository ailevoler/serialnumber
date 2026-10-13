<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';
$me = require_login();

if (is_post()) {
    csrf_check();
    if (($_POST['action'] ?? '') === 'delete') {
        $m = q_one('SELECT * FROM media WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
        if ($m && ((int) $m['user_id'] === (int) $me['id'] || is_admin())) {
            q('DELETE FROM media WHERE id = ?', [$m['id']]);
            if (str_starts_with($m['path'], 'uploads/') && is_file(__DIR__ . '/' . $m['path'])) @unlink(__DIR__ . '/' . $m['path']);
            flash('success', 'Deleted.');
        }
    } else {
        try {
            if (!empty($_FILES['photo']['size']) && $_FILES['photo']['size'] > 5 * 1024 * 1024) throw new RuntimeException('That photo is over 5 MB.');
            $f = handle_upload('photo', 'media', ['image']);
            if (!$f) throw new RuntimeException('Please choose a photo first.');
            q('INSERT INTO media (user_id, path, caption) VALUES (?,?,?)', [$me['id'], $f[0], mb_substr(trim((string) ($_POST['caption'] ?? '')), 0, 300) ?: null]);
            flash('success', 'Uploaded. Thanks for sharing!');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
    }
    redirect('media.php');
}

$items = q_all('SELECT m.*, u.first_name, u.last_name, u.title FROM media m JOIN users u ON u.id = m.user_id
                WHERE ' . not_blocked_sql('m.user_id') . ' ORDER BY m.created_at DESC LIMIT 90');

$pageTitle = 'Media';
$activeNav = 'media';
require __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
  <header class="page-intro"><h2>Media</h2><p>Pictures from church life</p></header>
  <button class="btn btn-gradient" data-open="upload-modal"><?= icon('camera') ?> Share a photo</button>
</div>
<div class="media-grid">
  <?php foreach ($items as $m): ?>
    <figure class="media-tile">
      <button type="button" class="media-open" data-open="m-<?= (int) $m['id'] ?>" aria-label="<?= e($m['caption'] ?: 'Open photo') ?>"><img src="<?= e(url($m['path'])) ?>" alt="<?= e($m['caption'] ?: '') ?>" loading="lazy"></button>
    </figure>
    <div class="modal" id="m-<?= (int) $m['id'] ?>" hidden>
      <div class="modal-card media-view">
        <div class="modal-head"><h2><?= e($m['caption'] ?: 'Photo') ?></h2><button class="icon-btn" data-close aria-label="Close"><?= icon('x') ?></button></div>
        <img src="<?= e(url($m['path'])) ?>" alt="<?= e($m['caption'] ?: '') ?>">
        <p class="muted small">Shared by <?= e(trim(($m['title'] ? $m['title'] . ' ' : '') . $m['first_name'] . ' ' . $m['last_name'])) ?> · <?= e(time_ago($m['created_at'])) ?></p>
        <?php if ((int) $m['user_id'] === (int) $me['id'] || is_admin()): ?>
          <form method="post" data-confirm="Delete this photo? It will be removed for everyone."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
            <button class="btn btn-sm btn-danger-outline"><?= icon('trash') ?> Delete</button></form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php if (!$items) empty_state('camera', 'No photos yet', 'Be the first to share a moment from church life.'); ?>

<div class="modal" id="upload-modal" hidden>
  <div class="modal-card">
    <div class="modal-head"><h2>Share a photo</h2><button class="icon-btn" data-close aria-label="Close"><?= icon('x') ?></button></div>
    <form method="post" enctype="multipart/form-data" class="form">
      <?= csrf_field() ?>
      <label>Photo<input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required></label>
      <p class="hint">JPG, PNG or WebP. Up to 5 MB. Only share photos of people who agreed to be shown.</p>
      <label>Caption<input name="caption" maxlength="300" placeholder="Say something about it (optional)"></label>
      <button class="btn btn-gradient btn-block"><?= icon('send') ?> Upload</button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
