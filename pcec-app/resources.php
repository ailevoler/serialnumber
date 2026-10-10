<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';
require_login();

$categories = ['Documents', 'Discipleship', 'Prayer', 'Sermons', 'Worship', 'Training', 'General'];

if (is_post()) {
    csrf_check();
    $action = $_POST['action'] ?? 'create';
    if ($action === 'delete') {
        $r = q_one('SELECT * FROM resources WHERE id = ?', [(int) $_POST['id']]);
        if ($r && ((int) $r['user_id'] === uid() || is_admin())) {
            q('DELETE FROM resources WHERE id = ?', [$r['id']]);
            if ($r['file_path'] && is_file(__DIR__ . '/' . $r['file_path'])) unlink(__DIR__ . '/' . $r['file_path']);
            flash('success', 'Resource removed.');
        }
        redirect('resources.php');
    }
    $title = trim($_POST['title'] ?? '');
    $link = trim($_POST['url'] ?? '');
    if ($link !== '' && !preg_match('~^https?://~i', $link)) $link = '';
    try {
        $file = handle_upload('file', 'resources');
        if ($title === '' || (!$file && $link === '')) {
            throw new RuntimeException('Add a title and either a file or a link.');
        }
        q('INSERT INTO resources (user_id, title, category, description, file_path, file_name, url) VALUES (?,?,?,?,?,?,?)',
            [uid(), mb_substr($title, 0, 180), in_array($_POST['category'] ?? '', $categories, true) ? $_POST['category'] : 'General',
             trim($_POST['description'] ?? '') ?: null, $file[0] ?? null, $file[2] ?? null, $link ?: null]);
        flash('success', 'Resource shared.');
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('resources.php');
}

if (isset($_GET['download'])) {
    $r = q_one('SELECT * FROM resources WHERE id = ?', [(int) $_GET['download']]);
    if ($r) {
        q('UPDATE resources SET downloads = downloads + 1 WHERE id = ?', [$r['id']]);
        if ($r['file_path']) {
            header('Location: ' . url($r['file_path']));
            exit;
        }
        if ($r['url']) {
            header('Location: ' . $r['url']);
            exit;
        }
    }
    redirect('resources.php');
}

$cat = in_array($_GET['cat'] ?? '', $categories, true) ? $_GET['cat'] : '';
$search = trim($_GET['q'] ?? '');
$where = '1=1';
$params = [];
if ($cat) { $where .= ' AND r.category = ?'; $params[] = $cat; }
if ($search !== '') { $where .= ' AND (r.title LIKE ? OR r.description LIKE ?)'; array_push($params, "%$search%", "%$search%"); }
$resources = q_all("SELECT r.*, u.first_name, u.last_name, u.title AS utitle, u.username FROM resources r JOIN users u ON u.id = r.user_id WHERE $where ORDER BY r.created_at DESC", $params);

$pageTitle = 'Resources';
$activeNav = 'resources';
require __DIR__ . '/includes/header.php';
?>
<form class="search-bar" method="get">
  <?= icon('search') ?><input name="q" value="<?= e($search) ?>" placeholder="Search resources…">
  <?php if ($cat): ?><input type="hidden" name="cat" value="<?= e($cat) ?>"><?php endif; ?>
</form>
<div class="page-actions">
  <div class="chips chips-scroll">
    <a href="?" class="chip chip-sm<?= $cat === '' ? ' on' : '' ?>">All</a>
    <?php foreach ($categories as $c): ?><a href="?cat=<?= e($c) ?>" class="chip chip-sm<?= $cat === $c ? ' on' : '' ?>"><?= e($c) ?></a><?php endforeach; ?>
  </div>
  <button class="btn btn-gradient" data-open="res-modal"><?= icon('plus-square') ?> Share</button>
</div>

<div class="list">
  <?php foreach ($resources as $r): $ext = $r['file_name'] ? strtoupper(pathinfo($r['file_name'], PATHINFO_EXTENSION)) : 'LINK'; ?>
    <article class="card resource">
      <div class="resource-ic res-<?= e(strtolower($ext)) ?>"><?= $r['file_path'] ? icon('file') : icon('link') ?><small><?= e($ext ?: 'FILE') ?></small></div>
      <div class="resource-info">
        <h3><?= e($r['title']) ?></h3>
        <?php if ($r['description']): ?><p class="muted"><?= e($r['description']) ?></p><?php endif; ?>
        <small class="muted"><span class="tag tag-soft"><?= e($r['category']) ?></span> by <?= e(trim($r['utitle'] . ' ' . $r['first_name'] . ' ' . $r['last_name'])) ?> · <?= (int) $r['downloads'] ?> opens · <?= e(time_ago($r['created_at'])) ?></small>
      </div>
      <div class="resource-actions">
        <?php if ($r['file_path'] || $r['url']): ?>
          <a class="icon-btn primary" href="?download=<?= (int) $r['id'] ?>" target="_blank" rel="noopener" aria-label="Open"><?= icon($r['file_path'] ? 'download' : 'link') ?></a>
        <?php endif; ?>
        <?php if ((int) $r['user_id'] === uid() || is_admin()): ?>
          <form method="post" data-confirm="Remove this resource?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button class="icon-btn" aria-label="Delete"><?= icon('trash') ?></button></form>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
</div>
<?php if (!$resources) empty_state('book', 'No resources found.'); ?>

<div class="modal" id="res-modal" hidden>
  <div class="modal-card">
    <div class="modal-head"><h2>Share a Resource</h2><button class="icon-btn" data-close aria-label="Close"><?= icon('x') ?></button></div>
    <form method="post" enctype="multipart/form-data" class="form">
      <?= csrf_field() ?>
      <label>Title<input name="title" required maxlength="180"></label>
      <label>Category<select name="category"><?php foreach ($categories as $c): ?><option><?= e($c) ?></option><?php endforeach; ?></select></label>
      <label>Description<textarea name="description" rows="3"></textarea></label>
      <label>Upload file (PDF, Word, PowerPoint, Excel, image, video — max 20 MB)<input type="file" name="file"></label>
      <label>…or link (https://)<input type="url" name="url" placeholder="https://"></label>
      <button class="btn btn-gradient btn-block"><?= icon('check') ?> Share</button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
