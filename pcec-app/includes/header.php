<?php
/**
 * App shell header. Before including set:
 *   $pageTitle (string), $activeNav (home|events|post|chat|more|churches|members|resources|prayer|posts|notifications|profile)
 *   $hero (bool) — show the big welcome hero (home page only)
 */
$authUser = require_login();
$pageTitle = $pageTitle ?? 'Home';
$activeNav = $activeNav ?? '';
$hero = $hero ?? false;
$unreadNotif = (int) q_val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [uid()]);
$unreadChat = (int) q_val(
    'SELECT COUNT(*) FROM messages m JOIN conversation_members cm ON cm.conversation_id = m.conversation_id AND cm.user_id = ?
     WHERE m.user_id <> ? AND m.id > cm.last_read_id', [uid(), uid()]);

$sideNav = [
    'home' => ['index.php', 'home', 'Home'],
    'posts' => ['posts.php', 'edit', 'Posts'],
    'events' => ['events.php', 'calendar', 'Events'],
    'churches' => ['churches.php', 'church', 'Our Churches'],
    'members' => ['members.php', 'users', 'Members'],
    'prayer' => ['prayer.php', 'pray', 'Prayer Requests'],
    'resources' => ['resources.php', 'book', 'Resources'],
    'chat' => ['chat.php', 'chat', 'Chat'],
    'notifications' => ['notifications.php', 'bell', 'Notifications'],
];
?>
<!doctype html>
<html lang="<?= lang() === 'fil' ? 'fil' : 'en' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0b2a6b">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url" content="<?= e(BASE_URL) ?>">
<title><?= e(t($pageTitle)) ?> · PCEC</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
<link rel="icon" href="<?= e(url('assets/img/favicon.svg')) ?>" type="image/svg+xml">
</head>
<body class="app-body<?= $hero ? ' has-hero' : '' ?>">

<aside class="sidebar" aria-label="Main navigation">
  <a class="sidebar-brand" href="<?= e(url('index.php')) ?>">
    <?= pcec_logo() ?>
    <span>Philippine Council of<br>Evangelical Churches</span>
  </a>
  <nav class="side-nav">
    <?php foreach ($sideNav as $key => [$href, $ic, $label]): ?>
      <a href="<?= e(url($href)) ?>" class="<?= $activeNav === $key ? 'active' : '' ?>">
        <?= icon($ic) ?><span><?= e(t($label)) ?></span>
        <?php if ($key === 'chat' && $unreadChat): ?><b class="pill"><?= $unreadChat ?></b><?php endif; ?>
        <?php if ($key === 'notifications' && $unreadNotif): ?><b class="pill"><?= $unreadNotif ?></b><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>
  <button class="btn btn-gradient btn-block" data-open="composer-modal"><?= icon('plus-square') ?> <?= e(t('Post')) ?></button>
  <div class="sidebar-user">
    <a href="<?= e(url('profile.php')) ?>" class="sidebar-user-link">
      <?= avatar($authUser, 'sm') ?>
      <span><strong><?= e(display_name($authUser)) ?></strong><small>@<?= e($authUser['username']) ?></small></span>
    </a>
    <a href="<?= e(url('logout.php')) ?>" class="icon-btn" title="<?= e(t('Log Out')) ?>"><?= icon('logout') ?></a>
  </div>
</aside>

<div class="app-main">
<?php if ($hero): ?>
  <header class="hero">
    <div class="hero-top">
      <a class="hero-brand" href="<?= e(url('index.php')) ?>">
        <?= pcec_logo() ?>
        <span><strong>Philippine Council of<br>Evangelical Churches</strong><small><?= e(APP_TAGLINE) ?></small></span>
      </a>
      <div class="hero-actions">
        <?= lang_switch() ?>
        <a href="<?= e(url('notifications.php')) ?>" class="bell" aria-label="<?= e(t('Notifications')) ?>">
          <?= icon('bell') ?><?php if ($unreadNotif): ?><b class="badge"><?= $unreadNotif ?></b><?php endif; ?>
        </a>
        <a href="<?= e(url('profile.php')) ?>" class="hero-avatar"><?= avatar($authUser, 'lg') ?></a>
      </div>
    </div>
    <div class="hero-text">
      <h1><?= e(t('Welcome Back,')) ?><br><span><?= e($authUser['first_name'] . ' ' . $authUser['last_name']) ?>!</span></h1>
      <p><?= e(t('Together in Christ for a Greater Philippines.')) ?></p>
    </div>
  </header>
<?php else: ?>
  <header class="topbar">
    <a href="javascript:history.length>1?history.back():location.href='<?= e(url('index.php')) ?>'" class="icon-btn back-btn" aria-label="Back"><?= icon('arrow-left') ?></a>
    <h1><?= e(t($pageTitle)) ?></h1>
    <div class="topbar-actions">
      <?= lang_switch() ?>
      <a href="<?= e(url('notifications.php')) ?>" class="bell" aria-label="<?= e(t('Notifications')) ?>">
        <?= icon('bell') ?><?php if ($unreadNotif): ?><b class="badge"><?= $unreadNotif ?></b><?php endif; ?>
      </a>
      <a href="<?= e(url('profile.php')) ?>"><?= avatar($authUser, 'sm') ?></a>
    </div>
  </header>
<?php endif; ?>

  <main class="content<?= $hero ? ' content-hero' : '' ?>">
    <?php foreach (flashes() as $f): ?>
      <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
    <?php endforeach; ?>
