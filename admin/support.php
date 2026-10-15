<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();

$status = input('status') === 'closed' ? 'closed' : 'open';
$threadId = input_int('thread');

if (is_post()) {
    csrf_check();
    $action = input('action');
    if ($action === 'settings') {
        save_setting('chat_enabled', isset($_POST['chat_enabled']) ? '1' : '0');
        save_setting('chat_welcome', input('chat_welcome'));
        save_setting('chat_hours', input('chat_hours'));
        save_setting('chat_quick_replies', str_replace("\r", '', (string)($_POST['chat_quick_replies'] ?? '')));
        flash('success', 'Live chat settings saved.');
        redirect('admin/support.php?settings=1');
    }
    $t = q_row('SELECT * FROM chat_threads WHERE id = ?', [$threadId]);
    if ($t) {
        if ($action === 'close') {
            q("UPDATE chat_threads SET status = 'closed', unread_admin = 0 WHERE id = ?", [$threadId]);
            q("INSERT INTO chat_messages (thread_id, sender_role, body) VALUES (?, 'system', ?)", [$threadId, 'This conversation was closed by support. Send a new message anytime to start a new chat.']);
            flash('success', 'Conversation closed.');
        } elseif ($action === 'reopen') {
            if (q_val("SELECT id FROM chat_threads WHERE user_id = ? AND status = 'open'", [$t['user_id']])) {
                flash('error', 'This member already has another open conversation.');
            } else {
                q("UPDATE chat_threads SET status = 'open' WHERE id = ?", [$threadId]);
                flash('success', 'Conversation reopened.');
            }
        } elseif ($action === 'assign') {
            q('UPDATE chat_threads SET assigned_admin_id = ? WHERE id = ?', [$me['id'], $threadId]);
            flash('success', 'Conversation assigned to you.');
        } elseif ($action === 'note') {
            try {
                crm_add_activity(null, (int)$t['user_id'], (int)$me['id'], 'note', input('note'));
                flash('success', 'CRM note saved to the member profile.');
            } catch (RuntimeException $ex) {
                flash('error', $ex->getMessage());
            }
        }
        log_activity((int)$me['id'], "Chat #{$threadId}: {$action}");
    }
    redirect('admin/support.php?status=' . $status . '&thread=' . $threadId);
}

$threads = q_all("SELECT t.*, u.username, u.first_name, u.last_name,
                  (SELECT body FROM chat_messages m WHERE m.thread_id = t.id ORDER BY m.id DESC LIMIT 1) last_body
                  FROM chat_threads t JOIN users u ON u.id = t.user_id WHERE t.status = ? ORDER BY t.last_message_at DESC LIMIT 100", [$status]);
$thread = $threadId ? q_row('SELECT t.*, u.username, u.first_name, u.last_name, u.mobile, u.email, u.wallet_balance, u.created_at joined, p.name package_name, a.first_name admin_first
                             FROM chat_threads t JOIN users u ON u.id = t.user_id LEFT JOIN packages p ON p.id = u.package_id LEFT JOIN users a ON a.id = t.assigned_admin_id WHERE t.id = ?', [$threadId]) : null;
$counts = array_column(q_all('SELECT status, COUNT(*) n FROM chat_threads GROUP BY status'), 'n', 'status');
$quick = array_values(array_filter(array_map('trim', explode("\n", (string)setting('chat_quick_replies', '')))));
$showSettings = input('settings') === '1';

page_header('Live Chat Support', $me, chat_unread_threads_admin() . ' conversation(s) waiting for a reply');
?>
<div class="tabs">
  <a href="?status=open" class="<?= $status === 'open' && !$showSettings ? 'active' : '' ?>">Open (<?= (int)($counts['open'] ?? 0) ?>)</a>
  <a href="?status=closed" class="<?= $status === 'closed' && !$showSettings ? 'active' : '' ?>">Closed (<?= (int)($counts['closed'] ?? 0) ?>)</a>
  <a href="?settings=1" class="<?= $showSettings ? 'active' : '' ?>"><?= icon('settings', 14) ?> Chat Settings</a>
</div>

<?php if ($showSettings): ?>
<form method="post" class="card">
  <?= csrf_field() ?><input type="hidden" name="action" value="settings">
  <div class="card-header"><h3><?= icon('settings') ?> Live Chat Settings</h3></div>
  <div class="card-body">
    <label class="toggle-card mb-2" style="max-width:380px"><input type="checkbox" name="chat_enabled" value="1" <?= chat_enabled() ? 'checked' : '' ?>><span class="toggle-ui"></span><span><strong>Enable Live Chat</strong><small>Show the chat bubble to members</small></span></label>
    <div class="form-grid">
      <div class="form-group full"><label>Welcome message</label><input class="input" name="chat_welcome" value="<?= e(setting('chat_welcome')) ?>"></div>
      <div class="form-group full"><label>Support hours (shown to members)</label><input class="input" name="chat_hours" value="<?= e(setting('chat_hours')) ?>"></div>
      <div class="form-group full"><label>Quick replies (one per line)</label><textarea class="input" name="chat_quick_replies" rows="6"><?= e(setting('chat_quick_replies')) ?></textarea></div>
    </div>
    <button class="btn btn-primary mt-2" type="submit">Save</button>
  </div>
</form>
<?php else: ?>
<div class="chat-admin">
  <div class="card chat-threads">
    <div class="card-header"><h3><?= icon('message') ?> Conversations</h3></div>
    <div class="thread-list" data-chat-inbox data-api="<?= e(url('api/chat.php')) ?>" data-status="<?= e($status) ?>" data-current="<?= (int)$threadId ?>">
      <?php foreach ($threads as $t): ?>
        <a class="thread-item <?= (int)$t['id'] === $threadId ? 'active' : '' ?> <?= $t['unread_admin'] ? 'unread' : '' ?>" href="?status=<?= $status ?>&thread=<?= (int)$t['id'] ?>">
          <div class="thread-top"><strong><?= e(full_name($t)) ?></strong><small><?= date('M d, h:i A', strtotime($t['last_message_at'])) ?></small></div>
          <div class="thread-bottom"><span>@<?= e($t['username']) ?> · <?= e(mb_substr((string)$t['last_body'], 0, 80)) ?></span><?php if ($t['unread_admin']): ?><em class="nav-count"><?= (int)$t['unread_admin'] ?></em><?php endif; ?></div>
        </a>
      <?php endforeach; ?>
      <?php if (!$threads): ?><p class="text-muted chat-empty">No conversations.</p><?php endif; ?>
    </div>
  </div>

  <div class="card chat-card">
    <?php if ($thread): ?>
      <div class="card-header">
        <div class="user-cell"><div class="avatar"><?= e(initials($thread)) ?></div><div><strong><?= e(full_name($thread)) ?></strong><small>@<?= e($thread['username']) ?> · <span data-chat-status><?= ucfirst($thread['status']) ?></span><?= $thread['admin_first'] ? ' · Assigned: ' . e($thread['admin_first']) : '' ?></small></div></div>
        <div class="actions">
          <form method="post" class="inline-form"><?= csrf_field() ?>
            <?php if (!$thread['assigned_admin_id'] || (int)$thread['assigned_admin_id'] !== (int)$me['id']): ?><button class="btn btn-light btn-sm" name="action" value="assign">Assign to me</button><?php endif; ?>
            <?php if ($thread['status'] === 'open'): ?><button class="btn btn-outline btn-sm" name="action" value="close" data-confirm="Close this conversation?">Close</button>
            <?php else: ?><button class="btn btn-outline btn-sm" name="action" value="reopen">Reopen</button><?php endif; ?>
          </form>
        </div>
      </div>
      <div data-chat-page data-viewer="admin" data-thread="<?= (int)$thread['id'] ?>" data-api="<?= e(url('api/chat.php')) ?>" data-csrf="<?= e(csrf_token()) ?>">
        <div class="chat-body chat-body-lg" data-chat-messages></div>
        <?php if ($quick): ?>
          <div class="quick-replies"><?php foreach ($quick as $qr): ?><button type="button" class="btn btn-xs btn-light" data-quick-reply="<?= e($qr) ?>"><?= e(mb_strimwidth($qr, 0, 42, '…')) ?></button><?php endforeach; ?></div>
        <?php endif; ?>
        <form class="chat-form" data-chat-form>
          <textarea name="body" rows="1" maxlength="<?= CHAT_MAX_LEN ?>" placeholder="Reply to <?= e($thread['first_name']) ?>… (Enter to send)" required></textarea>
          <button class="btn btn-primary" type="submit" data-no-lock><?= icon('send', 16) ?> Send</button>
        </form>
      </div>
    <?php else: ?>
      <div class="card-body chat-placeholder"><?= icon('message', 40) ?><p>Select a conversation to start replying.</p></div>
    <?php endif; ?>
  </div>

  <?php if ($thread): $acts = crm_activities('member_id', (int)$thread['user_id'], 5); ?>
  <div class="card chat-side">
    <div class="card-header"><h3><?= icon('user') ?> Member 360</h3></div>
    <div class="card-body">
      <dl class="kv small">
        <dt>Package</dt><dd><?= e($thread['package_name'] ?? '—') ?></dd>
        <dt>Mobile</dt><dd><?= e($thread['mobile'] ?: '—') ?></dd>
        <dt>Email</dt><dd><?= e($thread['email']) ?></dd>
        <dt>Wallet</dt><dd><?= money($thread['wallet_balance']) ?></dd>
        <dt>Joined</dt><dd><?= fdate($thread['joined']) ?></dd>
      </dl>
      <a class="btn btn-light btn-sm btn-block mt-1" href="<?= e(url('admin/member_view.php?id=' . $thread['user_id'])) ?>">Open member profile</a>
      <div class="divider"></div>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="note">
        <label>Add CRM note</label><textarea class="input" name="note" rows="2" required></textarea>
        <button class="btn btn-outline btn-sm btn-block mt-1" type="submit">Save note</button></form>
      <div class="divider"></div>
      <?= crm_timeline($acts) ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php page_footer();
