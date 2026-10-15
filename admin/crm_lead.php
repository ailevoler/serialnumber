<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();
$id = input_int('id');
$lead = q_row('SELECT l.*, o.username owner, o.first_name owner_first, o.last_name owner_last, c.username converted_username, a.first_name admin_first
               FROM crm_leads l LEFT JOIN users o ON o.id = l.owner_user_id LEFT JOIN users c ON c.id = l.converted_user_id
               LEFT JOIN users a ON a.id = l.assigned_admin_id WHERE l.id = ?', [$id]);
if (!$lead) {
    flash('error', 'Lead not found.');
    redirect('admin/crm.php');
}

if (is_post()) {
    csrf_check();
    try {
        switch (input('action')) {
            case 'save':
                crm_save_lead($_POST, null, $id);
                flash('success', 'Lead updated.');
                break;
            case 'activity':
                crm_add_activity($id, null, (int)$me['id'], input('type'), input('body'), input('due_date') ?: null);
                if (input('next_follow_up')) {
                    q('UPDATE crm_leads SET next_follow_up = ? WHERE id = ?', [input('next_follow_up'), $id]);
                }
                flash('success', 'Activity logged.');
                break;
            case 'convert':
                $m = find_member_by_username(strtolower(input('username')));
                if (!$m) {
                    throw new RuntimeException('Member username not found.');
                }
                q("UPDATE crm_leads SET status = 'converted', converted_user_id = ? WHERE id = ?", [$m['id'], $id]);
                crm_add_activity($id, null, (int)$me['id'], 'note', "Converted — registered as @{$m['username']}.");
                flash('success', "Lead converted and linked to @{$m['username']}.");
                break;
            case 'assign':
                q('UPDATE crm_leads SET assigned_admin_id = ? WHERE id = ?', [$me['id'], $id]);
                flash('success', 'Lead assigned to you.');
                break;
            case 'delete':
                q('DELETE FROM crm_leads WHERE id = ?', [$id]);
                flash('success', 'Lead deleted.');
                redirect('admin/crm.php');
        }
        log_activity((int)$me['id'], 'CRM lead #' . $id . ': ' . input('action'));
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('admin/crm_lead.php?id=' . $id);
}

$acts = crm_activities('lead_id', $id);
$tel = preg_replace('/[^0-9+]/', '', (string)$lead['mobile']);

page_header('Lead: ' . $lead['name'], $me, ($lead['owner'] ? 'Prospect of @' . $lead['owner'] : 'Company lead') . ' · ' . crm_statuses()[$lead['status']]['label']);
?>
<div class="grid grid-main">
  <div>
    <div class="card">
      <div class="card-header"><h3><?= icon('briefcase') ?> Lead Details</h3>
        <div class="actions">
          <?php if ($tel): ?><a class="btn btn-light btn-sm" href="tel:<?= e($tel) ?>"><?= icon('phone', 14) ?> Call</a><a class="btn btn-light btn-sm" href="sms:<?= e($tel) ?>">SMS</a><?php endif; ?>
          <?php if ($lead['email']): ?><a class="btn btn-light btn-sm" href="mailto:<?= e($lead['email']) ?>">Email</a><?php endif; ?>
        </div>
      </div>
      <div class="card-body">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save">
          <?php require APP_ROOT . '/includes/lead_form.php'; ?>
          <button class="btn btn-primary mt-2" type="submit">Save Changes</button>
        </form>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><h3><?= icon('list') ?> Activity Timeline</h3></div>
      <div class="card-body">
        <form method="post" class="mb-2"><?= csrf_field() ?><input type="hidden" name="action" value="activity">
          <div class="form-grid">
            <div class="form-group"><label>Type</label><select class="input" name="type"><?php foreach (crm_activity_types() as $k => $t): ?><option value="<?= $k ?>"><?= e($t) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>Set next follow-up</label><input class="input" type="date" name="next_follow_up"></div>
            <div class="form-group full"><label>Details</label><textarea class="input" name="body" rows="2" required placeholder="What happened / what to do next"></textarea></div>
          </div>
          <button class="btn btn-outline btn-sm mt-1" type="submit">Log Activity</button>
        </form>
        <?= crm_timeline($acts) ?>
      </div>
    </div>
  </div>
  <div>
    <div class="card">
      <div class="card-header"><h3><?= icon('shield') ?> Summary</h3></div>
      <div class="card-body">
        <dl class="kv">
          <dt>Stage</dt><dd><?= crm_badge($lead['status']) ?></dd>
          <dt>Owner</dt><dd><?= $lead['owner'] ? '@' . e($lead['owner']) . ' (' . e($lead['owner_first'] . ' ' . $lead['owner_last']) . ')' : 'Company' ?></dd>
          <dt>Assigned to</dt><dd><?= e($lead['admin_first'] ?: '—') ?></dd>
          <dt>Follow-up</dt><dd><?= fdate($lead['next_follow_up']) ?></dd>
          <dt>Created</dt><dd><?= fdatetime($lead['created_at']) ?></dd>
          <dt>Converted</dt><dd><?= $lead['converted_username'] ? '<a href="' . e(url('admin/member_view.php?id=' . $lead['converted_user_id'])) . '">@' . e($lead['converted_username']) . '</a>' : '—' ?></dd>
        </dl>
        <form method="post" class="mt-2"><?= csrf_field() ?><button class="btn btn-light btn-sm btn-block" name="action" value="assign">Assign to me</button></form>
      </div>
    </div>
    <?php if ($lead['status'] !== 'converted'): ?>
    <div class="card">
      <div class="card-header"><h3><?= icon('user-plus') ?> Mark as Converted</h3></div>
      <div class="card-body">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="convert">
          <div class="form-group"><label>Registered username</label><input class="input" name="username" required></div>
          <button class="btn btn-gold btn-block" type="submit">Link Member &amp; Convert</button></form>
      </div>
    </div>
    <?php endif; ?>
    <form method="post" class="card"><div class="card-body"><?= csrf_field() ?><button class="btn btn-danger btn-sm btn-block" name="action" value="delete" data-confirm="Delete this lead and its activities?">Delete Lead</button></div></form>
  </div>
</div>
<?php page_footer();
