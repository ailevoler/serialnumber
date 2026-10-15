<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_member();
$uid = (int)$me['id'];

$view = input_int('thread');
$past = q_all("SELECT t.*, (SELECT COUNT(*) FROM chat_messages m WHERE m.thread_id = t.id) msgs FROM chat_threads t WHERE t.user_id = ? ORDER BY t.id DESC LIMIT 20", [$uid]);
$open = chat_member_thread($uid, false);
$closedView = $view ? q_row("SELECT * FROM chat_threads WHERE id = ? AND user_id = ? AND status = 'closed'", [$view, $uid]) : null;

page_header('Live Chat Support', $me, setting('chat_hours', ''));
?>
<?php if (!chat_enabled()): ?>
  <div class="alert alert-warning">Live chat is currently unavailable. Please try again later.</div>
<?php else: ?>
<div class="grid grid-main">
  <div class="card chat-card">
    <div class="card-header">
      <img src="<?= e(asset('img/logo-256.png')) ?>" alt="" class="chat-logo">
      <h3>XGEN Live Support <?php if ($closedView): ?><span class="badge badge-muted">Closed conversation #<?= (int)$closedView['id'] ?></span><?php endif; ?></h3>
      <?php if ($closedView): ?><div class="actions"><a class="btn btn-light btn-sm" href="?">Back to live chat</a></div><?php endif; ?>
    </div>
    <?php if ($closedView): ?>
      <div class="chat-body chat-body-lg">
        <?php foreach (chat_messages((int)$closedView['id']) as $m): $side = $m['role'] === 'system' ? 'system' : ($m['role'] === 'member' ? 'me' : 'them'); ?>
          <div class="msg msg-<?= $side ?>"><?php if ($side === 'them'): ?><span class="msg-name"><?= e($m['name']) ?></span><?php endif; ?><div class="msg-text"><?= e($m['body']) ?></div><span class="msg-time"><?= e($m['time']) ?></span></div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div data-chat-page data-viewer="member" data-api="<?= e(url('api/chat.php')) ?>" data-csrf="<?= e(csrf_token()) ?>">
        <div class="chat-body chat-body-lg" data-chat-messages><div class="chat-empty"><?= e(setting('chat_welcome')) ?></div></div>
        <form class="chat-form" data-chat-form>
          <textarea name="body" rows="1" maxlength="<?= CHAT_MAX_LEN ?>" placeholder="Type your message… (Enter to send, Shift+Enter for new line)" required></textarea>
          <button class="btn btn-primary" type="submit" data-no-lock><?= icon('send', 16) ?> Send</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
  <div class="card">
    <div class="card-header"><h3><?= icon('list') ?> My Conversations</h3></div>
    <div class="card-body">
      <ul class="list-plain">
        <?php foreach ($past as $t): ?>
          <li>
            <div><strong>#<?= (int)$t['id'] ?></strong> <?= badge($t['status'] === 'open' ? 'active' : 'inactive') ?><br><small class="text-muted"><?= (int)$t['msgs'] ?> message(s) · <?= fdatetime($t['last_message_at']) ?></small></div>
            <?php if ($t['status'] === 'closed'): ?><a class="btn btn-xs btn-light" style="margin-left:auto" href="?thread=<?= (int)$t['id'] ?>">View</a><?php else: ?><span class="badge badge-success" style="margin-left:auto">Live</span><?php endif; ?>
          </li>
        <?php endforeach; ?>
        <?php if (!$past): ?><li class="text-muted">No conversations yet. Send us a message!</li><?php endif; ?>
      </ul>
      <div class="divider"></div>
      <p class="small text-muted mb-0">Support hours: <?= e(setting('chat_hours')) ?>. Messages sent outside these hours will be answered on the next business day.</p>
    </div>
  </div>
</div>
<?php endif; ?>
<?php page_footer();
