<?php
require __DIR__ . '/_admin.php';

if (is_post()) {
    csrf_check();
    $id = (int) ($_POST['id'] ?? 0);
    match ($_POST['action'] ?? '') {
        'read' => q('UPDATE contact_messages SET is_read = 1 WHERE id = ?', [$id]),
        'unread' => q('UPDATE contact_messages SET is_read = 0 WHERE id = ?', [$id]),
        'delete' => q('DELETE FROM contact_messages WHERE id = ?', [$id]),
        'read_all' => q('UPDATE contact_messages SET is_read = 1'),
        default => null,
    };
    redirect('admin/inbox.php');
}
$rows = q_all('SELECT * FROM contact_messages ORDER BY is_read, created_at DESC LIMIT 300');
$unread = count(array_filter($rows, fn($r) => !$r['is_read']));

$pageTitle = 'Admin';
$activeNav = 'admin';
require __DIR__ . '/../includes/header.php';
admin_tabs('inbox');
?>
<div class="admin-wrap">
  <div class="page-actions">
    <header class="dash-header"><div><h2>Inbox</h2><p><?= $unread ?> unread · Contact Us messages</p></div></header>
    <?php if ($unread): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="read_all"><button class="btn btn-sm btn-outline"><?= icon('check') ?> Mark all as read</button></form><?php endif; ?>
  </div>
  <div class="admin-list">
    <?php foreach ($rows as $r): ?>
      <article class="card admin-item<?= $r['is_read'] ? ' is-muted' : ' is-unread' ?>">
        <div class="admin-item-main">
          <div>
            <strong><?= e($r['subject'] ?: '(no subject)') ?></strong>
            <small class="muted"><?= e($r['name']) ?> · <a class="link" href="mailto:<?= e($r['email']) ?>?subject=<?= rawurlencode('Re: ' . ($r['subject'] ?: 'Your message to PCEC')) ?>"><?= e($r['email']) ?></a> · <?= e(date('M j, Y g:i A', strtotime($r['created_at']))) ?></small>
            <p class="pre-line inbox-msg"><?= e($r['message']) ?></p>
          </div>
          <?php if (!$r['is_read']): ?><span class="status status-new">New</span><?php endif; ?>
        </div>
        <div class="admin-item-actions">
          <a class="btn btn-sm btn-gradient" href="mailto:<?= e($r['email']) ?>?subject=<?= rawurlencode('Re: ' . ($r['subject'] ?: 'Your message to PCEC')) ?>"><?= icon('mail') ?> Reply by email</a>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="action" value="<?= $r['is_read'] ? 'unread' : 'read' ?>"><button class="btn btn-sm btn-outline"><?= $r['is_read'] ? 'Mark unread' : 'Mark read' ?></button></form>
          <form method="post" data-confirm="Delete this message?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="action" value="delete"><button class="icon-btn" aria-label="Delete"><?= icon('trash') ?></button></form>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
  <?php if (!$rows) empty_state('mail', 'No messages yet', 'Messages sent from Contact Us appear here.'); ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
