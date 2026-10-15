<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();

if (is_post()) {
    csrf_check();
    try {
        if (input('action') === 'add') {
            $id = crm_save_lead($_POST, null);
            q('UPDATE crm_leads SET assigned_admin_id = ? WHERE id = ?', [$me['id'], $id]);
            log_activity((int)$me['id'], "Added CRM lead #{$id}");
            flash('success', 'Lead added.');
            redirect('admin/crm_lead.php?id=' . $id);
        } elseif (input('action') === 'move') {
            $st = input('status');
            if (array_key_exists($st, crm_statuses())) {
                q('UPDATE crm_leads SET status = ? WHERE id = ?', [$st, input_int('id')]);
                flash('success', 'Lead moved to ' . crm_statuses()[$st]['label'] . '.');
            }
        } elseif (input('action') === 'settings') {
            save_setting('crm_sources', implode(',', array_filter(array_map('trim', explode(',', input('crm_sources'))))));
            flash('success', 'Lead sources saved.');
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('admin/crm.php?' . http_build_query(array_intersect_key($_GET, array_flip(['view', 'owner', 'q', 'status']))));
}

$view = input('view') === 'list' ? 'list' : 'board';
$owner = in_array(input('owner'), ['company', 'members', 'all'], true) ? input('owner') : 'company';
$search = input('q');
$fstatus = array_key_exists(input('status'), crm_statuses()) ? input('status') : '';

$where = ['1=1'];
$params = [];
if ($owner === 'company') { $where[] = 'l.owner_user_id IS NULL'; }
if ($owner === 'members') { $where[] = 'l.owner_user_id IS NOT NULL'; }
if ($search !== '') { $where[] = '(l.name LIKE ? OR l.mobile LIKE ? OR l.email LIKE ? OR l.location LIKE ?)'; array_push($params, ...array_fill(0, 4, "%$search%")); }
if ($fstatus) { $where[] = 'l.status = ?'; $params[] = $fstatus; }
$w = implode(' AND ', $where);
$base = "FROM crm_leads l LEFT JOIN users o ON o.id = l.owner_user_id WHERE $w";

if (input('export') === 'csv') {
    $rows = q_all("SELECT l.id, l.name, l.mobile, l.email, l.location, l.source, l.interest, l.status, l.next_follow_up, o.username owner, l.created_at $base ORDER BY l.id DESC", $params);
    csv_download('xgen-crm-leads-' . date('Ymd') . '.csv', ['ID', 'Name', 'Mobile', 'Email', 'Location', 'Source', 'Interest', 'Status', 'Next Follow-up', 'Owner', 'Created'], $rows);
}

$stats = q_row("SELECT COUNT(*) total, SUM(l.status = 'converted') converted, SUM(l.status NOT IN ('converted','lost') AND l.next_follow_up <= CURDATE()) due,
                SUM(l.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) recent $base", $params);
$conv = $stats['total'] ? $stats['converted'] / $stats['total'] * 100 : 0;
$due = q_all("SELECT l.*, o.username owner $base AND l.status NOT IN ('converted','lost') AND l.next_follow_up <= CURDATE() ORDER BY l.next_follow_up LIMIT 8", $params);

page_header('CRM Leads & Pipeline', $me, 'Track prospects from first contact to sign-up');
?>
<div class="stats">
  <?= stat_card('Total Leads', num($stats['total']), 'briefcase', '', num($stats['recent']) . ' in the last 30 days') ?>
  <?= stat_card('Converted', num($stats['converted']), 'user-plus', 'gold', num($conv, 1) . '% conversion') ?>
  <?= stat_card('Follow-ups Due', num($stats['due']), 'calendar', $stats['due'] ? 'dark' : '') ?>
</div>

<div class="card">
  <div class="card-body">
    <form class="filters" method="get">
      <input type="hidden" name="view" value="<?= e($view) ?>">
      <select class="input" name="owner">
        <option value="company" <?= $owner === 'company' ? 'selected' : '' ?>>Company leads</option>
        <option value="members" <?= $owner === 'members' ? 'selected' : '' ?>>Members' prospects</option>
        <option value="all" <?= $owner === 'all' ? 'selected' : '' ?>>All</option>
      </select>
      <select class="input" name="status"><option value="">All stages</option><?php foreach (crm_statuses() as $k => $s): ?><option value="<?= $k ?>" <?= $fstatus === $k ? 'selected' : '' ?>><?= e($s['label']) ?></option><?php endforeach; ?></select>
      <input class="input" name="q" value="<?= e($search) ?>" placeholder="Name, mobile, email, location" style="flex:1">
      <button class="btn btn-primary btn-sm"><?= icon('search', 14) ?> Filter</button>
      <a class="btn btn-light btn-sm" href="?<?= e(http_build_query(array_merge($_GET, ['view' => $view === 'board' ? 'list' : 'board']))) ?>"><?= $view === 'board' ? 'List view' : 'Board view' ?></a>
      <a class="btn btn-light btn-sm" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>"><?= icon('download', 14) ?> CSV</a>
      <button type="button" class="btn btn-gold btn-sm" onclick="document.getElementById('add-lead').hidden = !document.getElementById('add-lead').hidden"><?= icon('plus', 14) ?> New Lead</button>
    </form>
  </div>
</div>

<form method="post" class="card" id="add-lead" hidden>
  <?= csrf_field() ?><input type="hidden" name="action" value="add">
  <div class="card-header"><h3><?= icon('plus') ?> New Company Lead</h3></div>
  <div class="card-body">
    <?php $lead = []; require APP_ROOT . '/includes/lead_form.php'; ?>
    <button class="btn btn-primary mt-2" type="submit">Save Lead</button>
  </div>
</form>

<?php if ($due): ?>
<div class="card">
  <div class="card-header"><h3><?= icon('calendar') ?> Follow-ups Due Today</h3></div>
  <div class="card-body"><div class="chip-row">
    <?php foreach ($due as $d): ?><a class="due-chip" href="<?= e(url('admin/crm_lead.php?id=' . $d['id'])) ?>"><strong><?= e($d['name']) ?></strong><small><?= fdate($d['next_follow_up']) ?><?= $d['owner'] ? ' · @' . e($d['owner']) : '' ?></small></a><?php endforeach; ?>
  </div></div>
</div>
<?php endif; ?>

<?php if ($view === 'board'):
    $leads = q_all("SELECT l.*, o.username owner $base ORDER BY l.updated_at DESC LIMIT 600", $params);
    $cols = array_fill_keys(array_keys(crm_statuses()), []);
    foreach ($leads as $l) { $cols[$l['status']][] = $l; } ?>
<div class="kanban">
  <?php foreach (crm_statuses() as $k => $s): ?>
    <div class="kanban-col kanban-<?= $k ?>">
      <div class="kanban-head"><?= e($s['label']) ?> <span><?= count($cols[$k]) ?></span></div>
      <?php foreach ($cols[$k] as $l): ?>
        <div class="kanban-card">
          <a href="<?= e(url('admin/crm_lead.php?id=' . $l['id'])) ?>"><strong><?= e($l['name']) ?></strong></a>
          <small><?= e($l['mobile'] ?: $l['email']) ?></small>
          <div class="kanban-meta">
            <span class="badge badge-muted"><?= e($l['source']) ?></span>
            <?php if ($l['owner']): ?><span class="badge badge-gold">@<?= e($l['owner']) ?></span><?php endif; ?>
            <?php if ($l['next_follow_up'] && !in_array($l['status'], ['converted', 'lost'], true)): ?><span class="badge <?= $l['next_follow_up'] <= date('Y-m-d') ? 'badge-danger' : 'badge-info' ?>"><?= fdate($l['next_follow_up'], 'M d') ?></span><?php endif; ?>
          </div>
          <form method="post" class="kanban-move"><?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <select name="status" onchange="this.form.submit()" aria-label="Move to stage"><?php foreach (crm_statuses() as $k2 => $s2): ?><option value="<?= $k2 ?>" <?= $k2 === $k ? 'selected' : '' ?>><?= e($s2['label']) ?></option><?php endforeach; ?></select>
          </form>
        </div>
      <?php endforeach; ?>
      <?php if (!$cols[$k]): ?><div class="kanban-empty">No leads</div><?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php else:
    $p = paginate("SELECT COUNT(*) $base", $params);
    $leads = q_all("SELECT l.*, o.username owner $base ORDER BY l.updated_at DESC LIMIT {$p['limit']} OFFSET {$p['offset']}", $params); ?>
<div class="card">
  <div class="card-body p-0 table-wrap">
    <table class="table stack">
      <thead><tr><th>Lead</th><th>Source</th><th>Interest</th><th>Owner</th><th>Stage</th><th>Follow-up</th><th>Updated</th></tr></thead>
      <tbody>
      <?php foreach ($leads as $l): ?>
        <tr>
          <td><a href="<?= e(url('admin/crm_lead.php?id=' . $l['id'])) ?>"><strong><?= e($l['name']) ?></strong></a><br><small class="text-muted"><?= e(trim($l['mobile'] . ' ' . $l['email'])) ?></small></td>
          <td><?= e($l['source']) ?></td><td><?= e(ucfirst($l['interest'])) ?></td>
          <td><?= $l['owner'] ? '@' . e($l['owner']) : 'Company' ?></td>
          <td><?= crm_badge($l['status']) ?></td>
          <td class="nowrap"><?= fdate($l['next_follow_up']) ?></td>
          <td class="nowrap"><?= fdate($l['updated_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$leads) echo empty_row(7, 'No leads yet.'); ?>
      </tbody>
    </table>
  </div>
  <?= pagination_links($p) ?>
</div>
<?php endif; ?>

<form method="post" class="card mt-2">
  <?= csrf_field() ?><input type="hidden" name="action" value="settings">
  <div class="card-body filters">
    <div style="flex:1"><label>Lead sources (comma-separated)</label><input class="input" style="width:100%" name="crm_sources" value="<?= e(setting('crm_sources')) ?>"></div>
    <button class="btn btn-light btn-sm" type="submit">Save sources</button>
  </div>
</form>
<?php page_footer();
