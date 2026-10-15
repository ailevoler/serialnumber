<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();

if (is_post()) {
    csrf_check();
    $id = input_int('id');
    if (input('action') === 'delete') {
        q('DELETE FROM announcements WHERE id = ?', [$id]);
        flash('success', 'Announcement deleted.');
    } elseif (input('title') === '' || input('body') === '') {
        flash('error', 'Title and message are required.');
    } else {
        $status = input('status') === 'draft' ? 'draft' : 'published';
        if ($id) {
            q('UPDATE announcements SET title = ?, body = ?, status = ? WHERE id = ?', [input('title'), input('body'), $status, $id]);
        } else {
            q('INSERT INTO announcements (title, body, status) VALUES (?,?,?)', [input('title'), input('body'), $status]);
        }
        flash('success', 'Announcement saved.');
    }
    redirect('admin/announcements.php');
}

$edit = input_int('edit') ? q_row('SELECT * FROM announcements WHERE id = ?', [input_int('edit')]) : null;
$rows = q_all('SELECT * FROM announcements ORDER BY id DESC');

page_header('Announcements', $me, 'News shown on member dashboards');
?>
<div class="grid grid-main">
  <div class="card">
    <div class="card-header"><h3><?= icon('megaphone') ?> All Announcements</h3></div>
    <div class="card-body">
      <?php foreach ($rows as $r): ?>
        <div class="announcement">
          <h4><?= e($r['title']) ?> <?= badge($r['status']) ?></h4>
          <p><?= e($r['body']) ?></p>
          <small><?= fdatetime($r['created_at']) ?></small>
          <div class="mt-1">
            <a class="btn btn-xs btn-outline" href="?edit=<?= (int)$r['id'] ?>">Edit</a>
            <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-xs btn-danger" data-confirm="Delete this announcement?">Delete</button></form>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$rows): ?><p class="text-muted">No announcements yet.</p><?php endif; ?>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><h3><?= icon($edit ? 'settings' : 'plus') ?> <?= $edit ? 'Edit' : 'New' ?> Announcement</h3></div>
    <div class="card-body">
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <div class="form-group"><label>Title</label><input class="input" name="title" value="<?= e($edit['title'] ?? '') ?>" required></div>
        <div class="form-group"><label>Message</label><textarea class="input" name="body" rows="6" required><?= e($edit['body'] ?? '') ?></textarea></div>
        <div class="form-group"><label>Status</label><select class="input" name="status"><option>published</option><option <?= ($edit['status'] ?? '') === 'draft' ? 'selected' : '' ?>>draft</option></select></div>
        <button class="btn btn-primary" type="submit">Save</button>
        <?php if ($edit): ?><a class="btn btn-light" href="?">Cancel</a><?php endif; ?>
      </form>
    </div>
  </div>
</div>
<?php page_footer();
