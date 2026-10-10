<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';
require_login();

if (is_post()) {
    csrf_check();
    $action = $_POST['action'] ?? 'create';
    $pr = isset($_POST['id']) ? q_one('SELECT * FROM prayer_requests WHERE id = ?', [(int) $_POST['id']]) : null;
    $owner = $pr && ((int) $pr['user_id'] === uid() || is_admin());
    if ($action === 'answered' && $owner) {
        q('UPDATE prayer_requests SET is_answered = 1 - is_answered WHERE id = ?', [$pr['id']]);
    } elseif ($action === 'delete' && $owner) {
        q('DELETE FROM prayer_requests WHERE id = ?', [$pr['id']]);
        flash('success', 'Prayer request removed.');
    } elseif ($action === 'create') {
        $title = trim($_POST['title'] ?? '');
        $body = trim($_POST['body'] ?? '');
        if ($title === '' || $body === '') {
            flash('error', 'Please add a title and your prayer request.');
        } else {
            q('INSERT INTO prayer_requests (user_id, title, body, is_anonymous) VALUES (?,?,?,?)',
                [uid(), mb_substr($title, 0, 180), mb_substr($body, 0, 3000), !empty($_POST['anonymous']) ? 1 : 0]);
            flash('success', 'Your prayer request has been shared. We are praying with you.');
        }
    }
    redirect('prayer.php' . (isset($_GET['tab']) ? '?tab=' . urlencode($_GET['tab']) : ''));
}

$tab = $_GET['tab'] ?? 'active';
$me = uid();
$where = match ($tab) {
    'answered' => 'p.is_answered = 1',
    'mine' => "p.user_id = $me",
    default => 'p.is_answered = 0',
};
$prayers = q_all("SELECT p.*, u.first_name, u.last_name, u.title AS utitle, u.username, u.avatar,
    (SELECT COUNT(*) FROM prayer_responses r WHERE r.prayer_id = p.id) AS prayed,
    EXISTS(SELECT 1 FROM prayer_responses r WHERE r.prayer_id = p.id AND r.user_id = $me) AS i_prayed
  FROM prayer_requests p JOIN users u ON u.id = p.user_id WHERE $where ORDER BY p.created_at DESC LIMIT 100");

$pageTitle = 'Prayer Requests';
$activeNav = 'prayer';
require __DIR__ . '/includes/header.php';
?>
<section class="card prayer-intro">
  <div class="prayer-intro-ic"><?= icon('pray') ?></div>
  <div>
    <h2>“Pray for one another.”</h2>
    <p class="muted">James 5:16 — Share your burdens and lift up others in prayer.</p>
  </div>
  <button class="btn btn-gradient" data-open="prayer-modal"><?= icon('plus-square') ?> Request Prayer</button>
</section>

<div class="chips">
  <?php foreach (['active' => 'Active', 'answered' => 'Answered', 'mine' => 'My Requests'] as $k => $label): ?>
    <a href="?tab=<?= $k ?>" class="chip<?= $tab === $k ? ' on' : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<?php foreach ($prayers as $p):
    $anon = $p['is_anonymous'] && (int) $p['user_id'] !== $me;
    $author = ['first_name' => $p['first_name'], 'last_name' => $p['last_name'], 'title' => $p['utitle'], 'username' => $p['username'], 'avatar' => $p['avatar']];
?>
  <article class="card prayer<?= $p['is_answered'] ? ' answered' : '' ?>">
    <header class="post-head">
      <?= $anon ? '<span class="avatar avatar-md avatar-anon">' . icon('pray') . '</span>' : avatar($author, 'md') ?>
      <div class="post-meta">
        <span class="post-author"><?= $anon ? 'Anonymous' : e(display_name($author)) ?></span>
        <small><?= e(time_ago($p['created_at'])) ?><?= $p['is_anonymous'] && !$anon ? ' · posted anonymously' : '' ?></small>
      </div>
      <?php if ($p['is_answered']): ?><span class="tag tag-answered"><?= icon('check') ?> Answered</span><?php endif; ?>
    </header>
    <h3 class="prayer-title"><?= e($p['title']) ?></h3>
    <div class="post-body"><?= rich_text($p['body']) ?></div>
    <footer class="post-actions">
      <button class="btn btn-sm <?= $p['i_prayed'] ? 'btn-soft-on' : 'btn-soft' ?>" data-pray="<?= (int) $p['id'] ?>"><?= icon('pray') ?> <span class="pray-label"><?= $p['i_prayed'] ? 'Prayed' : 'I prayed' ?></span> · <span class="pray-count"><?= (int) $p['prayed'] ?></span></button>
      <?php if ((int) $p['user_id'] === $me || is_admin()): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="answered"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <button class="btn btn-sm btn-outline"><?= icon('check') ?> <?= $p['is_answered'] ? 'Mark active' : 'Mark answered' ?></button></form>
        <form method="post" data-confirm="Delete this prayer request?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <button class="icon-btn" aria-label="Delete"><?= icon('trash') ?></button></form>
      <?php endif; ?>
    </footer>
  </article>
<?php endforeach; ?>
<?php if (!$prayers) empty_state('pray', 'No prayer requests here.'); ?>

<div class="modal" id="prayer-modal" hidden>
  <div class="modal-card">
    <div class="modal-head"><h2>Request Prayer</h2><button class="icon-btn" data-close aria-label="Close"><?= icon('x') ?></button></div>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <label>Title<input name="title" required maxlength="180" placeholder="e.g. Healing for my father"></label>
      <label>Prayer request<textarea name="body" rows="5" required maxlength="3000"></textarea></label>
      <label class="check"><input type="checkbox" name="anonymous" value="1"><span></span>Post anonymously</label>
      <button class="btn btn-gradient btn-block"><?= icon('send') ?> Share Request</button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
