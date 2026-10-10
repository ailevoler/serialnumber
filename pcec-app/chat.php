<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';
require_login();
$me = uid();

// Start (or reuse) a 1:1 conversation with ?to=<user id>.
if (isset($_GET['to'])) {
    $to = (int) $_GET['to'];
    if ($to !== $me && q_val('SELECT 1 FROM users WHERE id = ?', [$to])) {
        $cid = q_val('SELECT a.conversation_id FROM conversation_members a JOIN conversation_members b
                      ON b.conversation_id = a.conversation_id AND b.user_id = ? WHERE a.user_id = ?
                      AND (SELECT COUNT(*) FROM conversation_members c WHERE c.conversation_id = a.conversation_id) = 2 LIMIT 1', [$to, $me]);
        if (!$cid) {
            q('INSERT INTO conversations () VALUES ()');
            $cid = (int) db()->lastInsertId();
            q('INSERT INTO conversation_members (conversation_id, user_id) VALUES (?,?),(?,?)', [$cid, $me, $cid, $to]);
        }
        redirect('chat.php?c=' . $cid);
    }
}

$conversations = q_all("SELECT c.id, c.updated_at,
    (SELECT body FROM messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_body,
    (SELECT created_at FROM messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_at,
    (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id AND m.user_id <> $me AND m.id > cm.last_read_id) AS unread,
    u.id AS other_id, u.first_name, u.last_name, u.title, u.username, u.avatar, u.last_seen
  FROM conversations c
  JOIN conversation_members cm ON cm.conversation_id = c.id AND cm.user_id = $me
  JOIN conversation_members om ON om.conversation_id = c.id AND om.user_id <> $me
  JOIN users u ON u.id = om.user_id
  ORDER BY COALESCE(last_at, c.updated_at) DESC");

$active = null;
$cid = (int) ($_GET['c'] ?? 0);
foreach ($conversations as $cv) {
    if ((int) $cv['id'] === $cid) $active = $cv;
}
$contacts = q_all('SELECT * FROM users WHERE id <> ? ORDER BY first_name LIMIT 200', [$me]);

$pageTitle = 'Chat';
$activeNav = 'chat';
require __DIR__ . '/includes/header.php';
$online = fn($ts) => $ts && time() - strtotime($ts) < 300;
?>
<div class="chat<?= $active ? ' chat-open' : '' ?>">
  <aside class="chat-list card">
    <div class="chat-list-head">
      <h2>Messages</h2>
      <button class="icon-btn primary" data-open="new-chat" aria-label="New message"><?= icon('edit') ?></button>
    </div>
    <?php foreach ($conversations as $cv): ?>
      <a class="chat-item<?= $active && $cv['id'] == $active['id'] ? ' active' : '' ?>" href="?c=<?= (int) $cv['id'] ?>">
        <span class="presence<?= $online($cv['last_seen']) ? ' on' : '' ?>"><?= avatar($cv, 'md') ?></span>
        <span class="chat-item-text">
          <strong><?= e(display_name($cv)) ?></strong>
          <small><?= e(mb_strimwidth($cv['last_body'] ?? 'Say hello 👋', 0, 48, '…')) ?></small>
        </span>
        <span class="chat-item-meta">
          <?php if ($cv['last_at']): ?><small><?= e(time_ago($cv['last_at'])) ?></small><?php endif; ?>
          <?php if ($cv['unread']): ?><b class="pill"><?= (int) $cv['unread'] ?></b><?php endif; ?>
        </span>
      </a>
    <?php endforeach; ?>
    <?php if (!$conversations) empty_state('chat', 'No conversations yet. Start one!'); ?>
  </aside>

  <section class="chat-window card">
    <?php if ($active): ?>
      <header class="chat-head">
        <a href="<?= e(url('chat.php')) ?>" class="icon-btn chat-back" aria-label="Back"><?= icon('arrow-left') ?></a>
        <span class="presence<?= $online($active['last_seen']) ? ' on' : '' ?>"><?= avatar($active, 'md') ?></span>
        <div><strong><?= e(display_name($active)) ?></strong><small class="muted"><?= $online($active['last_seen']) ? 'Active now' : ($active['last_seen'] ? 'Active ' . e(time_ago($active['last_seen'])) : 'Offline') ?></small></div>
        <a class="icon-btn" href="<?= e(url('profile.php?u=' . $active['username'])) ?>" aria-label="Profile"><?= icon('user') ?></a>
      </header>
      <div class="chat-messages" id="chat-messages" data-conversation="<?= (int) $active['id'] ?>" data-me="<?= $me ?>"></div>
      <form class="chat-input" id="chat-form">
        <input name="body" placeholder="Type a message…" autocomplete="off" maxlength="2000" required>
        <button class="icon-btn primary" aria-label="Send"><?= icon('send') ?></button>
      </form>
    <?php else: ?>
      <div class="chat-empty"><?= icon('chat') ?><h3>Your messages</h3><p class="muted">Select a conversation or start a new one.</p>
        <button class="btn btn-gradient" data-open="new-chat"><?= icon('edit') ?> New Message</button></div>
    <?php endif; ?>
  </section>
</div>

<div class="modal" id="new-chat" hidden>
  <div class="modal-card">
    <div class="modal-head"><h2>New Message</h2><button class="icon-btn" data-close aria-label="Close"><?= icon('x') ?></button></div>
    <label class="search-bar"><?= icon('search') ?><input placeholder="Search people…" data-filter-list="#contact-list"></label>
    <div class="contact-list" id="contact-list">
      <?php foreach ($contacts as $c): ?>
        <a class="person-row" href="?to=<?= (int) $c['id'] ?>" data-name="<?= e(strtolower(display_name($c) . ' ' . $c['username'])) ?>">
          <?= avatar($c, 'sm') ?><span class="person-name"><?= e(display_name($c)) ?><small>@<?= e($c['username']) ?></small></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
