<?php
require __DIR__ . '/_admin.php';

if (is_post()) {
    csrf_check();
    $action = $_POST['action'] ?? 'save';
    $id = (int) ($_POST['id'] ?? 0);
    try {
        if ($action === 'toggle') {
            q('UPDATE giving_projects SET is_active = 1 - is_active WHERE id = ?', [$id]);
        } elseif ($action === 'delete') {
            q('DELETE FROM giving_projects WHERE id = ?', [$id]);
            flash('success', 'Project deleted. Past gifts keep their records.');
        } else {
            $title = trim((string) ($_POST['title'] ?? ''));
            if ($title === '') throw new RuntimeException('Project title is required.');
            $goal = (int) round((float) str_replace(',', '', (string) ($_POST['goal'] ?? '0')) * 100);
            $img = handle_upload('image', 'events', ['image']);
            $vals = [mb_substr($title, 0, 160), trim((string) ($_POST['description'] ?? '')) ?: null, max(0, $goal), (int) ($_POST['sort'] ?? 0)];
            if ($id) {
                q('UPDATE giving_projects SET title = ?, description = ?, goal_amount = ?, sort = ? WHERE id = ?', [...$vals, $id]);
                if ($img) q('UPDATE giving_projects SET image = ? WHERE id = ?', [$img[0], $id]);
            } else {
                q('INSERT INTO giving_projects (title, description, goal_amount, sort, image) VALUES (?,?,?,?,?)', [...$vals, $img[0] ?? null]);
            }
            flash('success', 'Project saved.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/projects.php');
}

$projects = q_all("SELECT p.*, (SELECT COALESCE(SUM(pm.amount), 0) FROM donations d JOIN payments pm ON pm.id = d.payment_id WHERE d.project_id = p.id AND pm.status = 'paid') AS raised,
                          (SELECT COUNT(*) FROM donations d JOIN payments pm ON pm.id = d.payment_id WHERE d.project_id = p.id AND pm.status = 'paid') AS gifts
                   FROM giving_projects p ORDER BY p.is_active DESC, p.sort, p.id");
$edit = !empty($_GET['edit']) ? q_one('SELECT * FROM giving_projects WHERE id = ?', [(int) $_GET['edit']]) : null;

$pageTitle = 'Admin';
$activeNav = 'admin';
require __DIR__ . '/../includes/header.php';
admin_tabs('projects');
?>
<div class="admin-wrap">
  <div class="page-actions"><span class="muted"><?= count($projects) ?> project(s)</span><button class="btn btn-gradient" data-open="project-modal"><?= icon('plus-square') ?> New Project</button></div>
  <div class="admin-list">
    <?php foreach ($projects as $p): $pct = $p['goal_amount'] > 0 ? min(100, (int) round($p['raised'] / $p['goal_amount'] * 100)) : 0; ?>
      <article class="card admin-item<?= $p['is_active'] ? '' : ' is-muted' ?>">
        <div class="admin-item-main">
          <div>
            <strong><?= e($p['title']) ?></strong> <?= $p['is_active'] ? '<span class="status status-paid">Active</span>' : '<span class="status status-cancelled">Hidden</span>' ?>
            <?php if ($p['description']): ?><small class="muted"><?= e($p['description']) ?></small><?php endif; ?>
            <span class="progress"><span style="width:<?= $pct ?>%"></span></span>
            <small><b><?= money((int) $p['raised']) ?></b> of <?= $p['goal_amount'] ? money((int) $p['goal_amount']) : 'no goal' ?> · <?= (int) $p['gifts'] ?> gift(s)</small>
          </div>
        </div>
        <div class="admin-item-actions">
          <a class="btn btn-sm btn-outline" href="?edit=<?= (int) $p['id'] ?>"><?= icon('edit') ?> Edit</a>
          <a class="btn btn-sm btn-outline" href="<?= e(url('give.php?project=' . $p['id'])) ?>"><?= icon('external') ?> Give page</a>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button class="btn btn-sm btn-outline"><?= $p['is_active'] ? 'Hide' : 'Show' ?></button></form>
          <form method="post" data-confirm="Delete this project?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button class="icon-btn" aria-label="Delete"><?= icon('trash') ?></button></form>
        </div>
      </article>
    <?php endforeach; ?>
    <?php if (!$projects) empty_state('target', 'No projects yet.'); ?>
  </div>
</div>

<div class="modal" id="project-modal" <?= $edit ? '' : 'hidden' ?>>
  <div class="modal-card">
    <div class="modal-head"><h2><?= $edit ? 'Edit Project' : 'New Project' ?></h2><a class="icon-btn" href="<?= e(url('admin/projects.php')) ?>" data-close aria-label="Close"><?= icon('x') ?></a></div>
    <form method="post" enctype="multipart/form-data" class="form">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
      <label>Title<input name="title" value="<?= e($edit['title'] ?? '') ?>" required maxlength="160"></label>
      <label>Description<textarea name="description" rows="3"><?= e($edit['description'] ?? '') ?></textarea></label>
      <div class="grid-2">
        <label>Goal (₱, optional)<input type="number" name="goal" min="0" step="1" value="<?= $edit && $edit['goal_amount'] ? (int) ($edit['goal_amount'] / 100) : '' ?>"></label>
        <label>Sort order<input type="number" name="sort" value="<?= (int) ($edit['sort'] ?? 0) ?>"></label>
      </div>
      <label>Image (optional)<input type="file" name="image" accept="image/*"></label>
      <button class="btn btn-gradient btn-block"><?= icon('check') ?> Save Project</button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
