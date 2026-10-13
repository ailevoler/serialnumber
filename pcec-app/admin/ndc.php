<?php
require __DIR__ . '/_admin.php';
$types = ['event' => 'Event', 'picture' => 'Pictures', 'promotion' => 'Promotion'];

if (is_post()) {
    csrf_check();
    $id = (int) ($_POST['id'] ?? 0);
    $row = $id ? q_one('SELECT * FROM ndc_posts WHERE id = ?', [$id]) : null;
    try {
        if (($_POST['action'] ?? '') === 'delete') {
            q('DELETE FROM ndc_posts WHERE id = ?', [$id]);
            flash('info', 'Deleted.');
        } else {
            $title = trim((string) ($_POST['title'] ?? ''));
            if ($title === '') throw new RuntimeException('Please enter a title.');
            $type = array_key_exists($_POST['post_type'] ?? '', $types) ? $_POST['post_type'] : 'promotion';
            $vals = [mb_substr($title, 0, 200), $type, mb_substr(trim((string) ($_POST['content'] ?? '')), 0, 8000) ?: null, admin_image('image', $row['image'] ?? null)];
            if ($row) {
                q('UPDATE ndc_posts SET title = ?, post_type = ?, content = ?, image = ? WHERE id = ?', [...$vals, $id]);
            } else {
                q('INSERT INTO ndc_posts (title, post_type, content, image, created_by) VALUES (?,?,?,?,?)', [...$vals, uid()]);
                if (!empty($_POST['notify'])) notify_all_members('ndc', 'NDC update: ' . $title, 'ndc.php');
            }
            flash('success', 'Saved.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/ndc.php');
}
$rows = q_all('SELECT * FROM ndc_posts ORDER BY created_at DESC');
$edit = !empty($_GET['edit']) ? q_one('SELECT * FROM ndc_posts WHERE id = ?', [(int) $_GET['edit']]) : null;

$pageTitle = 'Admin';
$activeNav = 'admin';
require __DIR__ . '/../includes/header.php';
admin_tabs('ndc');
?>
<div class="admin-wrap">
  <div class="page-actions">
    <header class="dash-header"><div><h2>NDC Posts</h2><p>Events, pictures and promotions on Membership &amp; NDC</p></div></header>
    <button class="btn btn-gradient" data-open="ndc-modal"><?= icon('plus-square') ?> New NDC post</button>
  </div>
  <div class="admin-list">
    <?php foreach ($rows as $n): ?>
      <article class="card admin-item row-card">
        <?php if ($n['image']): ?><img class="row-img" src="<?= e(url($n['image'])) ?>" alt=""><?php endif; ?>
        <div class="row-main"><strong><?= e($n['title']) ?></strong><small class="muted"><?= e($types[$n['post_type']]) ?> · <?= e(date('M j, Y', strtotime($n['created_at']))) ?></small>
          <?php if ($n['content']): ?><p class="clamp-2"><?= e($n['content']) ?></p><?php endif; ?></div>
        <div class="admin-item-actions">
          <a class="btn btn-sm btn-outline" href="?edit=<?= (int) $n['id'] ?>"><?= icon('edit') ?> Edit</a>
          <form method="post" data-confirm="Delete “<?= e($n['title']) ?>”?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $n['id'] ?>"><button class="icon-btn" aria-label="Delete"><?= icon('trash') ?></button></form>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
  <?php if (!$rows) empty_state('shield', 'No NDC posts yet'); ?>
</div>
<div class="modal" id="ndc-modal" <?= $edit ? '' : 'hidden' ?>>
  <div class="modal-card">
    <div class="modal-head"><h2><?= $edit ? 'Edit NDC post' : 'New NDC post' ?></h2><a class="icon-btn" href="<?= e(url('admin/ndc.php')) ?>" data-close aria-label="Close"><?= icon('x') ?></a></div>
    <form method="post" enctype="multipart/form-data" class="form">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
      <label>Title<input name="title" value="<?= e($edit['title'] ?? '') ?>" required maxlength="200"></label>
      <label>Type<select name="post_type"><?php foreach ($types as $k => $l): ?><option value="<?= $k ?>" <?= ($edit['post_type'] ?? 'promotion') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
      <label>Text<textarea name="content" rows="6" maxlength="8000"><?= e($edit['content'] ?? '') ?></textarea></label>
      <label>Picture (optional)<input type="file" name="image" accept="image/*"></label>
      <?php if (!empty($edit['image'])): ?><label class="check"><input type="checkbox" name="remove_image" value="1"><span></span>Remove current picture</label><?php endif; ?>
      <?php if (!$edit): ?><label class="check"><input type="checkbox" name="notify" value="1" checked><span></span>Notify all members</label><?php endif; ?>
      <button class="btn btn-gradient btn-block"><?= icon('check') ?> Save</button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
