<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_member();
$uid = (int)$me['id'];

$own = fn(int $id) => q_row('SELECT * FROM crm_leads WHERE id = ? AND owner_user_id = ?', [$id, $uid]);

if (is_post()) {
    csrf_check();
    try {
        $action = input('action');
        $id = input_int('id');
        if ($action === 'save') {
            if ($id && !$own($id)) {
                throw new RuntimeException('Prospect not found.');
            }
            crm_save_lead($_POST, $uid, $id);
            flash('success', 'Prospect saved.');
        } elseif ($action === 'activity' && $own($id)) {
            crm_add_activity($id, null, $uid, input('type'), input('body'));
            if (input('next_follow_up')) {
                q('UPDATE crm_leads SET next_follow_up = ? WHERE id = ?', [input('next_follow_up'), $id]);
            }
            if (array_key_exists(input('status'), crm_statuses())) {
                q('UPDATE crm_leads SET status = ? WHERE id = ?', [input('status'), $id]);
            }
            flash('success', 'Activity logged.');
            redirect('member/prospects.php?view=' . $id);
        } elseif ($action === 'delete' && $own($id)) {
            q('DELETE FROM crm_leads WHERE id = ?', [$id]);
            flash('success', 'Prospect deleted.');
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('member/prospects.php');
}

$status = array_key_exists(input('status'), crm_statuses()) ? input('status') : '';
$search = input('q');
$where = ['owner_user_id = ?'];
$params = [$uid];
if ($status) { $where[] = 'status = ?'; $params[] = $status; }
if ($search !== '') { $where[] = '(name LIKE ? OR mobile LIKE ? OR location LIKE ?)'; array_push($params, "%$search%", "%$search%", "%$search%"); }
$w = implode(' AND ', $where);
$p = paginate("SELECT COUNT(*) FROM crm_leads WHERE $w", $params);
$rows = q_all("SELECT * FROM crm_leads WHERE $w ORDER BY (next_follow_up IS NULL), next_follow_up, updated_at DESC LIMIT {$p['limit']} OFFSET {$p['offset']}", $params);
$counts = array_column(q_all('SELECT status, COUNT(*) n FROM crm_leads WHERE owner_user_id = ? GROUP BY status', [$uid]), 'n', 'status');
$dueCount = (int)q_val("SELECT COUNT(*) FROM crm_leads WHERE owner_user_id = ? AND next_follow_up <= CURDATE() AND status NOT IN ('converted','lost')", [$uid]);
$edit = input_int('edit') ? $own(input_int('edit')) : null;
$view = input_int('view') ? $own(input_int('view')) : null;
$refLink = absolute_url('register.php?ref=' . urlencode($me['username']));

page_header('My Prospects', $me, 'Your personal CRM for inviting and following up');
?>
<div class="stats">
  <?= stat_card('Prospects', num(array_sum($counts)), 'briefcase') ?>
  <?= stat_card('Follow-ups Due', num($dueCount), 'calendar', $dueCount ? 'dark' : 'gold') ?>
  <?= stat_card('Converted', num($counts['converted'] ?? 0), 'user-plus', 'gold') ?>
</div>

<div class="tabs">
  <a href="?" class="<?= $status === '' ? 'active' : '' ?>">All</a>
  <?php foreach (crm_statuses() as $k => $s): ?><a href="?status=<?= $k ?>" class="<?= $status === $k ? 'active' : '' ?>"><?= e($s['label']) ?> (<?= (int)($counts[$k] ?? 0) ?>)</a><?php endforeach; ?>
</div>

<div class="grid grid-main">
  <div>
    <?php if ($view): $tel = preg_replace('/[^0-9+]/', '', (string)$view['mobile']); $invite = rawurlencode("Hi {$view['name']}! Join me at XGEN LIFE Intl — Better Health, Brighter Future. Register here: {$refLink}"); ?>
    <div class="card">
      <div class="card-header"><h3><?= icon('user') ?> <?= e($view['name']) ?> <?= crm_badge($view['status']) ?></h3>
        <div class="actions">
          <?php if ($tel): ?><a class="btn btn-light btn-sm" href="tel:<?= e($tel) ?>"><?= icon('phone', 14) ?> Call</a><a class="btn btn-light btn-sm" href="sms:<?= e($tel) ?>?body=<?= $invite ?>">Send invite (SMS)</a><?php endif; ?>
          <a class="btn btn-outline btn-sm" href="?edit=<?= (int)$view['id'] ?>">Edit</a><a class="btn btn-light btn-sm" href="?">Close</a>
        </div></div>
      <div class="card-body">
        <dl class="kv mb-2"><dt>Mobile</dt><dd><?= e($view['mobile'] ?: '—') ?></dd><dt>Email</dt><dd><?= e($view['email'] ?: '—') ?></dd><dt>Location</dt><dd><?= e($view['location'] ?: '—') ?></dd><dt>Follow-up</dt><dd><?= fdate($view['next_follow_up']) ?></dd><dt>Notes</dt><dd><?= nl2br(e($view['notes'] ?: '—')) ?></dd></dl>
        <form method="post" class="mb-2"><?= csrf_field() ?><input type="hidden" name="action" value="activity"><input type="hidden" name="id" value="<?= (int)$view['id'] ?>">
          <div class="form-grid">
            <div class="form-group"><label>Activity</label><select class="input" name="type"><?php foreach (crm_activity_types() as $k => $t): ?><option value="<?= $k ?>"><?= e($t) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>Move to stage</label><select class="input" name="status"><?php foreach (crm_statuses() as $k => $s): ?><option value="<?= $k ?>" <?= $view['status'] === $k ? 'selected' : '' ?>><?= e($s['label']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>Next follow-up</label><input class="input" type="date" name="next_follow_up"></div>
            <div class="form-group full"><label>Details</label><textarea class="input" name="body" rows="2" required></textarea></div>
          </div>
          <button class="btn btn-primary btn-sm mt-1" type="submit">Log Activity</button>
        </form>
        <?= crm_timeline(crm_activities('lead_id', (int)$view['id'])) ?>
      </div>
    </div>
    <?php endif; ?>
    <div class="card">
      <div class="card-header"><h3><?= icon('briefcase') ?> Prospect List</h3>
        <div class="actions"><form class="filters" method="get"><input type="hidden" name="status" value="<?= e($status) ?>"><input class="input" name="q" value="<?= e($search) ?>" placeholder="Search"><button class="btn btn-primary btn-sm">Search</button></form></div></div>
      <div class="card-body p-0 table-wrap">
        <table class="table stack">
          <thead><tr><th>Name</th><th>Contact</th><th>Interest</th><th>Stage</th><th>Follow-up</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): $overdue = $r['next_follow_up'] && $r['next_follow_up'] <= date('Y-m-d') && !in_array($r['status'], ['converted', 'lost'], true); ?>
            <tr>
              <td><a href="?view=<?= (int)$r['id'] ?>"><strong><?= e($r['name']) ?></strong></a><br><small class="text-muted"><?= e($r['source']) ?><?= $r['location'] ? ' · ' . e($r['location']) : '' ?></small></td>
              <td><?= e($r['mobile'] ?: $r['email']) ?></td>
              <td><?= e(ucfirst($r['interest'])) ?></td>
              <td><?= crm_badge($r['status']) ?></td>
              <td class="nowrap <?= $overdue ? 'text-danger' : '' ?>"><?= fdate($r['next_follow_up']) ?></td>
              <td class="nowrap"><a class="btn btn-xs btn-outline" href="?view=<?= (int)$r['id'] ?>">Open</a>
                <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-xs btn-light" data-confirm="Delete this prospect?">Delete</button></form></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows) echo empty_row(6, 'No prospects yet. Add the people you plan to invite!'); ?>
          </tbody>
        </table>
      </div>
      <?= pagination_links($p) ?>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><h3><?= icon($edit ? 'settings' : 'plus') ?> <?= $edit ? 'Edit Prospect' : 'Add Prospect' ?></h3></div>
    <div class="card-body">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <?php $lead = $edit ?? []; require APP_ROOT . '/includes/lead_form.php'; ?>
        <button class="btn btn-primary mt-2" type="submit">Save Prospect</button>
        <?php if ($edit): ?><a class="btn btn-light mt-2" href="?">Cancel</a><?php endif; ?>
      </form>
    </div>
  </div>
</div>
<?php page_footer();
