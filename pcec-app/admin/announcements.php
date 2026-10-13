<?php
require __DIR__ . '/_admin.php';

if (is_post()) {
    csrf_check();
    $id = (int) ($_POST['id'] ?? 0);
    $row = $id ? q_one('SELECT * FROM announcements WHERE id = ?', [$id]) : null;
    try {
        if (($_POST['action'] ?? '') === 'delete') {
            q('DELETE FROM announcements WHERE id = ?', [$id]);
            flash('info', 'Deleted.');
        } else {
            $title = trim((string) ($_POST['title'] ?? ''));
            if ($title === '') throw new RuntimeException('Please enter a title.');
            $image = admin_image('image', $row['image'] ?? null);
            $vals = [mb_substr($title, 0, 200), mb_substr(trim((string) ($_POST['body'] ?? '')), 0, 8000) ?: null, $image];
            if ($row) {
                q('UPDATE announcements SET title = ?, body = ?, image = ? WHERE id = ?', [...$vals, $id]);
                flash('success', 'Announcement updated.');
            } else {
                q('INSERT INTO announcements (title, body, image, created_by) VALUES (?,?,?,?)', [...$vals, uid()]);
                $newId = (int) db()->lastInsertId();
                if (!empty($_POST['notify'])) notify_all_members('announcement', 'New announcement: ' . $title, 'news.php?id=' . $newId);
                flash('success', 'Announcement posted.');
            }
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/announcements.php');
}
$rows = q_all('SELECT * FROM announcements ORDER BY created_at DESC');
$edit = !empty($_GET['edit']) ? q_one('SELECT * FROM announcements WHERE id = ?', [(int) $_GET['edit']]) : null;

$pageTitle = 'Admin';
$activeNav = 'admin';
require __DIR__ . '/../includes/header.php';
admin_tabs('announcements');
?>
<div class="admin-wrap">
  <div class="page-actions">
    <header class="dash-header"><div><h2>Announcements</h2><p>Shown in Latest News and on Home</p></div></header>
    <button class="btn btn-gradient" data-open="ann-modal"><?= icon('plus-square') ?> New announcement</button>
  </div>
  <div class="admin-list">
    <?php foreach ($rows as $a): ?>
      <article class="card admin-item row-card">
        <?php if ($a['image']): ?><img class="row-img" src="<?= e(url($a['image'])) ?>" alt=""><?php endif; ?>
        <div class="row-main"><strong><?= e($a['title']) ?></strong><small class="muted"><?= e(date('M j, Y g:i A', strtotime($a['created_at']))) ?></small>
          <?php if ($a['body']): ?><p class="clamp-2"><?= e($a['body']) ?></p><?php endif; ?></div>
        <div class="admin-item-actions">
          <a class="btn btn-sm btn-outline" href="?edit=<?= (int) $a['id'] ?>"><?= icon('edit') ?> Edit</a>
          <form method="post" data-confirm="Delete “<?= e($a['title']) ?>”? It will be removed from the app."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button class="icon-btn" aria-label="Delete"><?= icon('trash') ?></button></form>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
  <?php if (!$rows) empty_state('bell', 'No announcements yet'); ?>
</div>
<div class="modal" id="ann-modal" <?= $edit ? '' : 'hidden' ?>>
  <div class="modal-card">
    <div class="modal-head"><h2><?= $edit ? 'Edit announcement' : 'New announcement' ?></h2><a class="icon-btn" href="<?= e(url('admin/announcements.php')) ?>" data-close aria-label="Close"><?= icon('x') ?></a></div>
    <form method="post" enctype="multipart/form-data" class="form">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
      <label>Title<input name="title" value="<?= e($edit['title'] ?? '') ?>" required maxlength="200"></label>
      <label>Message<textarea name="body" rows="8" maxlength="8000"><?= e($edit['body'] ?? '') ?></textarea></label>
      <label>Picture (optional)<input type="file" name="image" accept="image/*"></label>
      <?php if (!empty($edit['image'])): ?><label class="check"><input type="checkbox" name="remove_image" value="1"><span></span>Remove current picture</label><?php endif; ?>
      <?php if (!$edit): ?><label class="check"><input type="checkbox" name="notify" value="1" checked><span></span>Notify all members</label><?php endif; ?>
      <button class="btn btn-gradient btn-block"><?= icon('check') ?> <?= $edit ? 'Save' : 'Post announcement' ?></button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
