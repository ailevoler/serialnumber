<?php
require __DIR__ . '/_admin.php';

if (is_post()) {
    csrf_check();
    $m = q_one('SELECT * FROM users WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
    $action = (string) ($_POST['action'] ?? '');
    $isSelf = $m && (int) $m['id'] === uid();
    if ($m) {
        switch ($action) {
            case 'let_in':
                q("UPDATE users SET status = 'approved', email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?", [$m['id']]);
                flash('success', display_name($m) . ' can now use the app.');
                break;
            case 'review':
                q('UPDATE users SET reviewed_at = NOW(), reviewed_by = ? WHERE id = ?', [uid(), $m['id']]);
                flash('success', 'Marked as reviewed. The New Member badge is removed.');
                break;
            case 'suspend':
                if ($m['role'] !== 'admin' && !$isSelf) {
                    q("UPDATE users SET status = 'suspended' WHERE id = ?", [$m['id']]);
                    q('DELETE FROM remember_tokens WHERE user_id = ?', [$m['id']]);
                    flash('info', 'Account suspended.');
                }
                break;
            case 'restore':
                q("UPDATE users SET status = 'approved', email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?", [$m['id']]);
                flash('success', 'Account restored.');
                break;
            case 'resend':
                if ($m['status'] === 'pending') {
                    $ok = send_confirmation_email($m);
                    flash($ok ? 'success' : 'error', $ok ? 'Confirmation email sent again.' : 'Could not send the email: ' . ($GLOBALS['mail_error'] ?? 'unknown error'));
                }
                break;
            case 'renewal':
                $d = (string) ($_POST['renewal'] ?? '');
                q('UPDATE users SET membership_renewal_date = ? WHERE id = ?', [preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null, $m['id']]);
                flash('success', 'Renewal date saved.');
                break;
            case 'make_admin':
                if ($m['status'] === 'approved') { q("UPDATE users SET role = 'admin' WHERE id = ?", [$m['id']]); flash('success', display_name($m) . ' is now an administrator.'); }
                break;
            case 'remove_admin':
                if (!$isSelf) { q("UPDATE users SET role = 'member' WHERE id = ?", [$m['id']]); flash('info', 'Administrator access removed.'); }
                break;
        }
    }
    redirect('admin/members.php?' . http_build_query(array_intersect_key($_GET, array_flip(['show', 'q']))));
}

$show = in_array($_GET['show'] ?? '', ['all', 'suspended', 'pending'], true) ? $_GET['show'] : 'new';
$search = trim((string) ($_GET['q'] ?? ''));
$where = match ($show) {
    'new' => "u.status = 'approved' AND u.reviewed_at IS NULL AND u.created_at > NOW() - INTERVAL 30 DAY",
    'suspended' => "u.status = 'suspended'",
    'pending' => "u.status = 'pending'",
    default => '1=1',
};
$params = [];
if ($search !== '') {
    $where .= " AND (CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR u.church_name LIKE ?)";
    array_push($params, ...array_fill(0, 4, '%' . $search . '%'));
}
$rows = q_all("SELECT u.* FROM users u WHERE $where ORDER BY u.created_at DESC LIMIT 200", $params);
$unconfirmed = (int) q_val("SELECT COUNT(*) FROM users WHERE status = 'pending'");
$countries = phone_countries();

$pageTitle = 'Admin';
$activeNav = 'admin';
require __DIR__ . '/../includes/header.php';
admin_tabs('members');
?>
<div class="admin-wrap">
  <header class="dash-header"><div><h2>Member Management</h2></div></header>
  <nav class="tabs-pill" aria-label="Filter members">
    <?php foreach (['new' => 'NEW', 'all' => 'ALL', 'suspended' => 'SUSPENDED', 'pending' => 'UNCONFIRMED'] as $k => $label):
        if ($k === 'pending' && !$unconfirmed && $show !== 'pending') continue; ?>
      <a href="?show=<?= $k ?>" class="<?= $show === $k ? 'on' : '' ?>"><?= $label ?><?= $k === 'pending' && $unconfirmed ? " ($unconfirmed)" : '' ?></a>
    <?php endforeach; ?>
  </nav>
  <form class="search-bar" method="get"><input type="hidden" name="show" value="<?= e($show) ?>"><?= icon('search') ?>
    <input name="q" value="<?= e($search) ?>" placeholder="Search name, email, phone or church" aria-label="Search members"></form>
  <?php if ($show === 'new'): ?><p class="hint"><?= icon('bell') ?> New members can use the app as soon as they confirm their email. Have a look at each one within 30 days and tap “Mark as reviewed”, or “Suspend” if the account is fake. After 30 days they become Official Members automatically.</p><?php endif; ?>
  <?php if ($show === 'pending'): ?><p class="hint"><?= icon('bell') ?> These people signed up but haven't clicked the link in their confirmation email yet. They get in by themselves once they do.</p><?php endif; ?>
  <p class="muted small"><?= count($rows) ?> <?= count($rows) === 1 ? 'person' : 'people' ?></p>
  <div class="admin-list">
    <?php foreach ($rows as $m): $c = $countries[$m['phone_country']] ?? null; $self = (int) $m['id'] === uid(); ?>
      <article class="card admin-item member-admin">
        <div class="member-top">
          <?= avatar($m, 'md') ?>
          <div class="member-info">
            <strong><?= e(display_name($m)) ?></strong>
            <small><?= e($m['email']) ?></small>
            <?php if ($m['phone']): ?><small><?= $c ? $c[2] . ' ' : '' ?><?= e(format_phone($m['phone'], $m['phone_country'])) ?><?= $c ? ' · ' . e($c[0]) : '' ?></small><?php endif; ?>
            <?php if ($m['church_name']): ?><small><?= e($m['church_name']) ?></small><?php endif; ?>
            <small>Joined <?= e(date('M j, Y', strtotime($m['created_at']))) ?><?= $m['reviewed_at'] ? ' · Reviewed ' . e(date('M j, Y', strtotime($m['reviewed_at']))) : '' ?></small>
            <small class="<?= $m['email_verified_at'] ? 'email-ok' : 'email-no' ?>"><?= icon($m['email_verified_at'] ? 'check' : 'clock') ?> <?= $m['email_verified_at'] ? 'Email confirmed' : 'Email not confirmed yet' ?></small>
          </div>
          <div class="member-pills">
            <?php if ($m['role'] === 'admin'): ?><span class="status status-new">Admin</span><?php endif; ?>
            <?= match (true) {
                $m['status'] === 'suspended' => '<span class="status status-failed">Suspended</span>',
                $m['status'] === 'pending' => '<span class="status status-pending">Unconfirmed</span>',
                is_new_member($m) => '<span class="status status-new">New Member</span>',
                default => '<span class="status status-paid">Active</span>',
            } ?>
          </div>
        </div>
        <div class="admin-item-actions">
          <?php $btn = fn($action, $label, $cls, $confirm = '') => '<form method="post"' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field()
              . '<input type="hidden" name="id" value="' . (int) $m['id'] . '"><input type="hidden" name="action" value="' . $action . '"><button class="btn btn-sm ' . $cls . '">' . e($label) . '</button></form>'; ?>
          <?php if ($m['status'] === 'pending'): ?><?= $btn('resend', 'Resend email', 'btn-outline') ?><?= $btn('let_in', 'Let in', 'btn-gradient', 'Let ' . display_name($m) . ' in without confirming their email?') ?><?php endif; ?>
          <?php if ($m['status'] === 'approved' && is_new_member($m)): ?><?= $btn('review', 'Mark as reviewed', 'btn-outline') ?><?php endif; ?>
          <?php if ($m['status'] !== 'suspended' && $m['role'] !== 'admin' && !$self): ?><?= $btn('suspend', 'Suspend', 'btn-danger-outline', 'Suspend ' . display_name($m) . '? They will be signed out of church content until you restore the account.') ?><?php endif; ?>
          <?php if ($m['status'] === 'suspended'): ?><?= $btn('restore', 'Restore', 'btn-gradient') ?><?php endif; ?>
          <?php if ($m['status'] === 'approved' && $m['role'] !== 'admin'): ?><?= $btn('make_admin', 'Make admin', 'btn-outline', 'Give ' . display_name($m) . ' full administrator access?') ?><?php endif; ?>
          <?php if ($m['role'] === 'admin' && !$self): ?><?= $btn('remove_admin', 'Remove admin', 'btn-outline', 'Remove administrator access?') ?><?php endif; ?>
          <?php if ($m['status'] === 'approved'): ?>
            <form method="post" class="renewal-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><input type="hidden" name="action" value="renewal">
              <label>Renewal <input type="date" name="renewal" value="<?= e((string) $m['membership_renewal_date']) ?>"></label><button class="btn btn-sm btn-outline">Save</button></form>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
  <?php if (!$rows) empty_state('users', $search ? 'No matching members' : ['new' => 'No new members to review', 'all' => 'No members yet', 'suspended' => 'No suspended accounts', 'pending' => 'Everyone has confirmed their email'][$show]); ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
