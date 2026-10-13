<?php
/** Shared queries and render helpers: community posts, events, announcements, section headers. */

const FEED_PAGE = 10;
const REACTIONS = ['love' => ['❤️', 'Love'], 'pray' => ['🙏', 'Pray'], 'amen' => ['🙌', 'Amen']];

/** SQL condition that hides posts between me and members I blocked (or who blocked me). */
function not_blocked_sql(string $col): string
{
    $me = uid();
    return "$col NOT IN (SELECT blocked_id FROM user_blocks WHERE blocker_id = $me UNION SELECT blocker_id FROM user_blocks WHERE blocked_id = $me)";
}

/** Community posts, newest first. Hidden posts are only returned to admins. */
function fetch_posts(string $where = '1=1', array $params = [], int $limit = FEED_PAGE, int $beforeId = 0): array
{
    $me = uid();
    $hidden = is_admin() ? '' : ' AND p.hidden_at IS NULL';
    $before = $beforeId ? ' AND p.id < ' . (int) $beforeId : '';
    return q_all("SELECT p.*, u.first_name, u.last_name, u.title, u.username, u.avatar, u.church_name,
              (SELECT COUNT(*) FROM comments cm WHERE cm.post_id = p.id AND " . not_blocked_sql('cm.user_id') . ") AS comments,
              (SELECT kind FROM post_reactions r WHERE r.post_id = p.id AND r.user_id = $me) AS my_reaction,
              (SELECT COUNT(*) FROM post_reactions r WHERE r.post_id = p.id) AS reactions,
              (SELECT GROUP_CONCAT(DISTINCT r.kind) FROM post_reactions r WHERE r.post_id = p.id) AS reaction_kinds
            FROM posts p JOIN users u ON u.id = p.user_id
            WHERE ($where)$hidden$before AND " . not_blocked_sql('p.user_id') . "
            ORDER BY p.id DESC LIMIT " . (int) $limit, $params);
}

function reaction_summary(array $p): string
{
    $kinds = array_filter(explode(',', (string) $p['reaction_kinds']));
    $emoji = implode('', array_map(fn($k) => REACTIONS[$k][0] ?? '', $kinds));
    return (int) $p['reactions'] ? '<span class="rx-emoji">' . $emoji . '</span> ' . (int) $p['reactions'] : '';
}

function render_post(array $p, bool $full = false): void
{
    $author = ['first_name' => $p['first_name'], 'last_name' => $p['last_name'], 'title' => $p['title'], 'username' => $p['username'], 'avatar' => $p['avatar']];
    $link = url('community_post.php?id=' . $p['id']);
    $wall = url('community_user.php?id=' . $p['user_id']);
    $mine = (int) $p['user_id'] === uid();
    $long = !$full && mb_strlen((string) $p['body']) > 320;
    ?>
    <article class="card post<?= $p['hidden_at'] ? ' is-hidden' : '' ?>" id="post-<?= (int) $p['id'] ?>">
      <?php if ($p['hidden_at']): ?><p class="hidden-note"><?= icon('eye-off') ?> Hidden by an admin — members can't see this post.</p><?php endif; ?>
      <header class="post-head">
        <a href="<?= e($wall) ?>"><?= avatar($author, 'md') ?></a>
        <div class="post-meta">
          <a class="post-author" href="<?= e($wall) ?>"><?= e(display_name($author)) ?></a>
          <small><?= $p['church_name'] ? e($p['church_name']) . ' &bull; ' : '' ?><a href="<?= e($link) ?>" title="<?= e(date('M j, Y g:i A', strtotime($p['created_at']))) ?>"><?= e(time_ago($p['created_at'])) ?></a></small>
        </div>
        <div class="dropdown">
          <button class="icon-btn" data-dropdown aria-label="Post options"><?= icon('dots') ?></button>
          <div class="dropdown-menu">
            <a href="<?= e($link) ?>"><?= icon('external') ?> Open post</a>
            <button type="button" data-copy="<?= e($link) ?>"><?= icon('link') ?> Copy link</button>
            <?php if (!$mine): ?>
              <button type="button" data-open="report-modal" data-report="<?= (int) $p['id'] ?>"><?= icon('shield') ?> Report post</button>
              <form method="post" action="<?= e(url('community_action.php')) ?>" data-confirm="Block <?= e($p['first_name']) ?>? You won't see their posts or comments, and they won't be able to comment on or react to your posts. They won't be told. You can unblock any time in Settings.">
                <?= csrf_field() ?><input type="hidden" name="action" value="block"><input type="hidden" name="user" value="<?= (int) $p['user_id'] ?>">
                <button><?= icon('x') ?> Block <?= e($p['first_name']) ?></button>
              </form>
            <?php endif; ?>
            <?php if (is_admin()): ?>
              <form method="post" action="<?= e(url('community_action.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $p['hidden_at'] ? 'unhide' : 'hide' ?>"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <button><?= icon($p['hidden_at'] ? 'eye' : 'eye-off') ?> <?= $p['hidden_at'] ? 'Show post again' : 'Hide post' ?></button></form>
            <?php endif; ?>
            <?php if ($mine || is_admin()): ?>
              <form method="post" action="<?= e(url('community_action.php')) ?>" data-confirm="Delete this post? Its reactions and comments will be deleted too.">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <button class="danger"><?= icon('trash') ?> Delete post</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </header>
      <?php if ($p['body']): ?>
        <div class="post-body<?= $long ? ' clamp' : '' ?>"><?= rich_text($p['body']) ?></div>
        <?php if ($long): ?><button type="button" class="link see-more" data-see-more>See more</button><?php endif; ?>
      <?php endif; ?>
      <?php if ($p['media_path'] && $p['media_type'] === 'image'): ?>
        <div class="post-media"><a href="<?= e($link) ?>"><img src="<?= e(url($p['media_path'])) ?>" alt="" loading="lazy"></a></div>
      <?php endif; ?>
      <div class="post-stats">
        <span data-rx-summary><?= reaction_summary($p) ?></span>
        <a href="<?= e($link) ?>#comments"><?= (int) $p['comments'] ? (int) $p['comments'] . ' comment' . ((int) $p['comments'] === 1 ? '' : 's') : '' ?></a>
      </div>
      <footer class="post-actions rx-row" data-post="<?= (int) $p['id'] ?>">
        <?php foreach (REACTIONS as $kind => [$emoji, $label]): ?>
          <button type="button" class="rx<?= $p['my_reaction'] === $kind ? ' on' : '' ?>" data-react="<?= $kind ?>" aria-pressed="<?= $p['my_reaction'] === $kind ? 'true' : 'false' ?>"><span><?= $emoji ?></span><?= $label ?></button>
        <?php endforeach; ?>
        <a class="rx" href="<?= e($link) ?>#comments"><?= icon('comment') ?>Comment</a>
      </footer>
    </article>
    <?php
}

/** Report dialog used by every page that shows posts. */
function report_modal(): string
{
    return '<div class="modal" id="report-modal" hidden><div class="modal-card"><div class="modal-head"><h2>Report post</h2>'
        . '<button class="icon-btn" data-close aria-label="Close">' . icon('x') . '</button></div>'
        . '<form method="post" action="' . e(url('community_action.php')) . '" class="form">' . csrf_field()
        . '<input type="hidden" name="action" value="report"><input type="hidden" name="id" value="" data-report-id>'
        . '<p class="muted">Only PCEC administrators see reports. The person who posted won’t be told who reported it.</p>'
        . '<label>What’s wrong with this post? (optional)<textarea name="reason" rows="3" maxlength="500" placeholder="e.g. spam, harassment, false information"></textarea></label>'
        . '<button class="btn btn-gradient btn-block">' . icon('send') . ' Send report</button></form></div></div>';
}

function fetch_events(string $where = 'e.starts_at >= CURDATE()', array $params = [], int $limit = 20, string $order = 'ASC'): array
{
    $me = uid();
    return q_all("SELECT e.*, (SELECT COUNT(*) FROM event_rsvps r WHERE r.event_id = e.id) AS going,
                    EXISTS(SELECT 1 FROM event_rsvps r WHERE r.event_id = e.id AND r.user_id = $me) AS is_going
                  FROM events e WHERE $where ORDER BY e.starts_at " . ($order === 'DESC' ? 'DESC' : 'ASC') . " LIMIT " . (int) $limit, $params);
}

function event_time_label(array $ev): string
{
    $start = strtotime($ev['starts_at']);
    $end = $ev['ends_at'] ? strtotime($ev['ends_at']) : null;
    if ($end && date('Y-m-d', $end) !== date('Y-m-d', $start)) {
        return date('M j', $start) . ' – ' . date('M j', $end) . ', ' . date('g:i A', $start);
    }
    return date('g:i A', $start) . ($end ? ' – ' . date('g:i A', $end) : '');
}

function render_event(array $ev, bool $compact = false): void
{
    $start = strtotime($ev['starts_at']);
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
        <p><?= icon('clock') ?><?= e(event_time_label($ev)) ?></p>
      </div>
      <?= icon('chevron-right', 'event-chevron') ?>
    </a>
    <?php
}

function render_news_card(array $n, bool $lead = false): void
{
    ?>
    <a class="card news-card<?= $lead ? ' is-lead' : '' ?>" href="<?= e(url('news.php?id=' . $n['id'])) ?>">
      <?php if ($n['image']): ?><img class="news-img" src="<?= e(url($n['image'])) ?>" alt="" loading="lazy">
      <?php elseif ($lead): ?><div class="news-img news-ph" aria-hidden="true"><?= pcec_logo() ?></div><?php endif; ?>
      <div class="news-text">
        <h3><?= e($n['title']) ?></h3>
        <?php if ($n['body']): ?><p class="clamp-2"><?= e($n['body']) ?></p><?php endif; ?>
        <div class="news-foot"><span><?= e(date('M j, Y', strtotime($n['created_at']))) ?></span><b>READ MORE</b></div>
      </div>
    </a>
    <?php
}

function section_head(string $title, ?string $href = null, string $linkLabel = 'See All'): void
{
    echo '<div class="section-head"><h2>' . e(t($title)) . '</h2>';
    if ($href) {
        echo '<a href="' . e(url($href)) . '">' . e(t($linkLabel)) . ' ' . icon('chevron-right') . '</a>';
    }
    echo '</div>';
}

function empty_state(string $iconName, string $text, string $sub = ''): void
{
    echo '<div class="empty">' . icon($iconName) . '<p>' . e($text) . '</p>' . ($sub ? '<small>' . e($sub) . '</small>' : '') . '</div>';
}

/** Right-rail content on wide screens. */
function right_rail(): string
{
    ob_start();
    $events = fetch_events('e.starts_at >= CURDATE()', [], 3);
    $news = q_all('SELECT * FROM announcements ORDER BY created_at DESC LIMIT 3');
    ?>
    <div class="card rail-card">
      <?php section_head('Upcoming Events', 'events.php?tab=upcoming'); ?>
      <?php foreach ($events as $ev): render_event($ev, true); endforeach; ?>
      <?php if (!$events) empty_state('calendar', 'No upcoming events.'); ?>
    </div>
    <?php if ($news): ?>
    <div class="card rail-card">
      <?php section_head('Latest News', 'news.php'); ?>
      <?php foreach ($news as $n): ?>
        <a class="rail-news" href="<?= e(url('news.php?id=' . $n['id'])) ?>"><strong><?= e($n['title']) ?></strong><small><?= e(time_ago($n['created_at'])) ?></small></a>
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
