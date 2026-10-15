<?php
declare(strict_types=1);

/* Live Chat Support — members chat with the support team (admins). Polling-based, works on any PHP host. */

const CHAT_MAX_LEN = 2000;

function chat_enabled(): bool
{
    return setting('chat_enabled', '1') === '1';
}

/** The member's open thread, optionally creating one. */
function chat_member_thread(int $userId, bool $create = false): ?array
{
    $t = q_row("SELECT * FROM chat_threads WHERE user_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1", [$userId]);
    if (!$t && $create) {
        q('INSERT INTO chat_threads (user_id) VALUES (?)', [$userId]);
        $id = (int)db()->lastInsertId();
        q("INSERT INTO chat_messages (thread_id, sender_role, body) VALUES (?, 'system', ?)", [$id, (string)setting('chat_welcome', 'Hi! How can we help you?')]);
        $t = q_row('SELECT * FROM chat_threads WHERE id = ?', [$id]);
    }
    return $t;
}

function chat_send(int $threadId, int $senderId, string $role, string $body): int
{
    $body = trim(str_replace("\r", '', $body));
    if ($body === '') {
        throw new RuntimeException('Message is empty.');
    }
    if (mb_strlen($body) > CHAT_MAX_LEN) {
        throw new RuntimeException('Message is too long (max ' . CHAT_MAX_LEN . ' characters).');
    }
    return tx(function () use ($threadId, $senderId, $role, $body) {
        q('INSERT INTO chat_messages (thread_id, sender_id, sender_role, body) VALUES (?,?,?,?)', [$threadId, $senderId, $role, $body]);
        $id = (int)db()->lastInsertId();
        if ($role === 'admin') {
            q('UPDATE chat_threads SET unread_member = unread_member + 1, unread_admin = 0, last_message_at = NOW(),
               assigned_admin_id = COALESCE(assigned_admin_id, ?) WHERE id = ?', [$senderId, $threadId]);
        } else {
            q("UPDATE chat_threads SET unread_admin = unread_admin + 1, unread_member = 0, last_message_at = NOW(), status = 'open' WHERE id = ?", [$threadId]);
        }
        return $id;
    });
}

function chat_messages(int $threadId, int $afterId = 0, int $limit = 200): array
{
    $rows = q_all("SELECT m.id, m.sender_role, m.body, m.created_at, u.first_name, u.last_name
                   FROM chat_messages m LEFT JOIN users u ON u.id = m.sender_id
                   WHERE m.thread_id = ? AND m.id > ? ORDER BY m.id LIMIT {$limit}", [$threadId, $afterId]);
    return array_map(fn($m) => [
        'id'   => (int)$m['id'],
        'role' => $m['sender_role'],
        'name' => $m['sender_role'] === 'admin' ? ($m['first_name'] . ' · Support') : ($m['sender_role'] === 'system' ? 'XGEN Support' : full_name($m)),
        'body' => $m['body'],
        'time' => date('M d, h:i A', strtotime($m['created_at'])),
    ], $rows);
}

function chat_mark_read(int $threadId, string $role): void
{
    q('UPDATE chat_threads SET ' . ($role === 'admin' ? 'unread_admin' : 'unread_member') . ' = 0 WHERE id = ?', [$threadId]);
}

function chat_unread_threads_admin(): int
{
    return (int)q_val("SELECT COUNT(*) FROM chat_threads WHERE unread_admin > 0");
}

function chat_unread_member(int $userId): int
{
    return (int)q_val('SELECT COALESCE(SUM(unread_member),0) FROM chat_threads WHERE user_id = ?', [$userId]);
}

/** Floating chat widget for member pages. */
function chat_widget(array $user): string
{
    if (!chat_enabled() || $user['role'] !== 'member') {
        return '';
    }
    $unread = chat_unread_member((int)$user['id']);
    ob_start(); ?>
<div class="chat-widget" data-chat-widget data-api="<?= e(url('api/chat.php')) ?>" data-csrf="<?= e(csrf_token()) ?>">
  <button type="button" class="chat-fab" data-chat-toggle aria-label="Open live chat support">
    <?= icon('message', 24) ?><span class="chat-badge" data-chat-badge <?= $unread ? '' : 'hidden' ?>><?= $unread ?></span>
  </button>
  <section class="chat-panel" data-chat-panel hidden aria-label="Live chat support">
    <header class="chat-head">
      <img src="<?= e(asset('img/logo-256.png')) ?>" alt="">
      <div><strong>XGEN Live Support</strong><small><span class="dot"></span> <?= e(setting('chat_hours', '')) ?></small></div>
      <a class="icon-btn" href="<?= e(url('member/support.php')) ?>" title="Open full chat"><?= icon('eye', 18) ?></a>
      <button type="button" class="icon-btn" data-chat-toggle aria-label="Close chat"><?= icon('x', 18) ?></button>
    </header>
    <div class="chat-body" data-chat-messages><div class="chat-empty"><?= e(setting('chat_welcome')) ?></div></div>
    <form class="chat-form" data-chat-form>
      <textarea name="body" rows="1" maxlength="<?= CHAT_MAX_LEN ?>" placeholder="Type your message…" required></textarea>
      <button class="btn btn-primary btn-sm" type="submit" data-no-lock aria-label="Send"><?= icon('send', 16) ?></button>
    </form>
  </section>
</div>
<?php
    return (string)ob_get_clean();
}
