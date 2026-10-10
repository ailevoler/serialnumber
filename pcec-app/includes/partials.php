<?php
/** Shared queries + render helpers for posts and events. */

function fetch_posts(string $where = '1=1', array $params = [], int $limit = 20, int $offset = 0): array
{
    $me = uid();
    $sql = "SELECT p.*, u.first_name, u.last_name, u.title, u.username, u.avatar, c.name AS church_name,
              (SELECT COUNT(*) FROM post_likes pl WHERE pl.post_id = p.id) AS likes,
              (SELECT COUNT(*) FROM comments cm WHERE cm.post_id = p.id) AS comments,
              EXISTS(SELECT 1 FROM post_likes pl WHERE pl.post_id = p.id AND pl.user_id = $me) AS liked,
              EXISTS(SELECT 1 FROM bookmarks b WHERE b.post_id = p.id AND b.user_id = $me) AS saved
            FROM posts p
            JOIN users u ON u.id = p.user_id
            LEFT JOIN churches c ON c.id = u.church_id
            WHERE $where
            ORDER BY p.created_at DESC, p.id DESC
            LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset;
    return q_all($sql, $params);
}

function render_post(array $p, bool $full = false): void
{
    $author = ['first_name' => $p['first_name'], 'last_name' => $p['last_name'], 'title' => $p['title'],
        'username' => $p['username'], 'avatar' => $p['avatar']];
    $link = url('post.php?id=' . $p['id']);
    ?>
    <article class="card post" id="post-<?= (int) $p['id'] ?>">
      <header class="post-head">
        <a href="<?= e(url('profile.php?u=' . $p['username'])) ?>"><?= avatar($author, 'md') ?></a>
        <div class="post-meta">
          <a class="post-author" href="<?= e(url('profile.php?u=' . $p['username'])) ?>"><?= e(display_name($author)) ?></a>
          <small><?= $p['church_name'] ? e($p['church_name']) . ' &bull; ' : '' ?><?= e(time_ago($p['created_at'])) ?></small>
        </div>
        <div class="dropdown">
          <button class="icon-btn" data-dropdown aria-label="Post options"><?= icon('dots') ?></button>
          <div class="dropdown-menu">
            <a href="<?= e($link) ?>">Open post</a>
            <button type="button" data-copy="<?= e($link) ?>">Copy link</button>
            <?php if ((int) $p['user_id'] === uid() || is_admin()): ?>
              <form method="post" action="<?= e(url('post_delete.php')) ?>" data-confirm="Delete this post?">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <button class="danger">Delete</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </header>
      <?php if ($p['body']): ?>
        <div class="post-body<?= !$full && mb_strlen($p['body']) > 400 ? ' clamp' : '' ?>"><?= rich_text($p['body']) ?></div>
      <?php endif; ?>
      <?php if ($p['media_path']): ?>
        <div class="post-media">
          <?php if ($p['media_type'] === 'image'): ?>
            <a href="<?= e($link) ?>"><img src="<?= e(url($p['media_path'])) ?>" alt="" loading="lazy"></a>
          <?php elseif ($p['media_type'] === 'video'): ?>
            <video src="<?= e(url($p['media_path'])) ?>" controls preload="metadata"></video>
          <?php else: ?>
            <a class="file-chip" href="<?= e(url($p['media_path'])) ?>" download="<?= e($p['media_name']) ?>"><?= icon('file') ?><span><?= e($p['media_name'] ?: 'Attachment') ?></span><?= icon('download') ?></a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <footer class="post-actions">
        <button class="pa pa-like<?= $p['liked'] ? ' on' : '' ?>" data-like="<?= (int) $p['id'] ?>" aria-label="Like"><?= icon('heart') ?><span><?= (int) $p['likes'] ?></span></button>
        <a class="pa" href="<?= e($link) ?>#comments" aria-label="Comments"><?= icon('comment') ?><span><?= (int) $p['comments'] ?></span></a>
        <button class="pa" data-share="<?= (int) $p['id'] ?>" data-url="<?= e($link) ?>" aria-label="Share"><?= icon('share') ?><span><?= (int) $p['share_count'] ?></span></button>
        <button class="pa pa-save<?= $p['saved'] ? ' on' : '' ?>" data-save="<?= (int) $p['id'] ?>" aria-label="Save"><?= icon('bookmark') ?></button>
      </footer>
    </article>
    <?php
}

function fetch_events(string $where = 'e.starts_at >= CURDATE()', array $params = [], int $limit = 20): array
{
    $me = uid();
    return q_all("SELECT e.*, (SELECT COUNT(*) FROM event_rsvps r WHERE r.event_id = e.id) AS going,
                    EXISTS(SELECT 1 FROM event_rsvps r WHERE r.event_id = e.id AND r.user_id = $me) AS is_going
                  FROM events e WHERE $where ORDER BY e.starts_at ASC LIMIT " . (int) $limit, $params);
}

function render_event(array $ev, bool $compact = false): void
{
    $start = strtotime($ev['starts_at']);
    $end = $ev['ends_at'] ? strtotime($ev['ends_at']) : null;
    $time = date('g:i A', $start) . ($end ? ' – ' . date('g:i A', $end) : '');
    if ($end && date('Y-m-d', $end) !== date('Y-m-d', $start)) {
        $time = date('M j', $start) . ' – ' . date('M j', $end) . ', ' . date('g:i A', $start);
    }
    ?>
    <a class="card event<?= $compact ? ' event-compact' : '' ?>" href="<?= e(url('event.php?id=' . $ev['id'])) ?>">
      <div class="event-date">
        <span><?= strtoupper(date('M', $start)) ?></span>
        <strong><?= date('j', $start) ?></strong>
        <span><?= date('Y', $start) ?></span>
      </div>
      <div class="event-thumb"<?= $ev['image'] ? ' style="background-image:url(\'' . e(url($ev['image'])) . '\')"' : '' ?>></div>
      <div class="event-info">
        <span class="tag tag-<?= e(strtolower($ev['category'])) ?>"><?= e(strtoupper($ev['category'])) ?></span>
        <h3><?= e($ev['title']) ?></h3>
        <?php if ($ev['location']): ?><p><?= icon('pin') ?><?= e($ev['location']) ?></p><?php endif; ?>
        <p><?= icon('clock') ?><?= e($time) ?></p>
      </div>
      <?= icon('chevron-right', 'event-chevron') ?>
    </a>
    <?php
}

function section_head(string $title, ?string $href = null): void
{
    echo '<div class="section-head"><h2>' . e(t($title)) . '</h2>';
    if ($href) {
        echo '<a href="' . e(url($href)) . '">' . e(t('See All')) . ' ' . icon('chevron-right') . '</a>';
    }
    echo '</div>';
}

function empty_state(string $iconName, string $text): void
{
    echo '<div class="empty">' . icon($iconName) . '<p>' . e($text) . '</p></div>';
}

/** Right-rail content shared by the desktop layout. */
function right_rail(): string
{
    ob_start();
    $events = fetch_events('e.starts_at >= CURDATE()', [], 3);
    $suggest = q_all('SELECT u.* FROM users u WHERE u.id <> ? AND u.id NOT IN (SELECT following_id FROM follows WHERE follower_id = ?)
                      ORDER BY RAND() LIMIT 4', [uid(), uid()]);
    ?>
    <div class="card rail-card">
      <?php section_head('Upcoming Events', 'events.php'); ?>
      <?php foreach ($events as $ev): render_event($ev, true); endforeach; ?>
      <?php if (!$events) empty_state('calendar', 'No upcoming events.'); ?>
    </div>
    <?php if ($suggest): ?>
    <div class="card rail-card">
      <div class="section-head"><h2>People to follow</h2></div>
      <?php foreach ($suggest as $s): ?>
        <div class="person-row">
          <a href="<?= e(url('profile.php?u=' . $s['username'])) ?>"><?= avatar($s, 'sm') ?></a>
          <a class="person-name" href="<?= e(url('profile.php?u=' . $s['username'])) ?>"><?= e(display_name($s)) ?><small>@<?= e($s['username']) ?></small></a>
          <button class="btn btn-sm btn-outline" data-follow="<?= (int) $s['id'] ?>"><?= e(t('Follow')) ?></button>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="rail-verse">
      <p>“Behold, how good and how pleasant it is for brethren to dwell together in unity!”</p>
      <small>Psalm 133:1</small>
    </div>
    <?php
    return ob_get_clean();
}
