  </main>
</div><!-- /.app-main -->

<?php if (!empty($rightRail)): ?>
<aside class="right-rail"><?= $rightRail ?></aside>
<?php endif; ?>

<nav class="bottom-nav" aria-label="Quick navigation">
  <a href="<?= e(url('index.php')) ?>" class="<?= $activeNav === 'home' ? 'active' : '' ?>"><?= icon('home') ?><span><?= e(t('Home')) ?></span></a>
  <a href="<?= e(url('events.php')) ?>" class="<?= $activeNav === 'events' ? 'active' : '' ?>"><?= icon('calendar') ?><span><?= e(t('Events')) ?></span></a>
  <button type="button" data-open="composer-modal"><?= icon('plus-square') ?><span><?= e(t('Post')) ?></span></button>
  <a href="<?= e(url('chat.php')) ?>" class="<?= $activeNav === 'chat' ? 'active' : '' ?>"><?= icon('chat') ?><span><?= e(t('Chat')) ?></span><?php if ($unreadChat): ?><b class="badge"><?= $unreadChat ?></b><?php endif; ?></a>
  <button type="button" data-open="more-sheet" class="<?= in_array($activeNav, ['more', 'churches', 'members', 'resources', 'prayer', 'posts', 'profile', 'notifications', 'give', 'admin'], true) ? 'active' : '' ?>"><?= icon('menu') ?><span><?= e(t('More')) ?></span></button>
</nav>

<!-- Composer modal (used by the bottom-nav "Post" button and the sidebar) -->
<div class="modal" id="composer-modal" hidden>
  <div class="modal-card">
    <div class="modal-head">
      <h2>Create Post</h2>
      <button class="icon-btn" data-close aria-label="Close"><?= icon('x') ?></button>
    </div>
    <?php include __DIR__ . '/composer_form.php'; ?>
  </div>
</div>

<!-- "More" sheet for mobile -->
<div class="modal sheet" id="more-sheet" hidden>
  <div class="modal-card">
    <div class="modal-head">
      <h2><?= e(t('More')) ?></h2>
      <button class="icon-btn" data-close aria-label="Close"><?= icon('x') ?></button>
    </div>
    <div class="more-grid">
      <a href="<?= e(url('churches.php')) ?>"><?= icon('church') ?><?= e(t('Our Churches')) ?></a>
      <a href="<?= e(url('members.php')) ?>"><?= icon('users') ?><?= e(t('Members')) ?></a>
      <a href="<?= e(url('posts.php')) ?>"><?= icon('edit') ?><?= e(t('Posts')) ?></a>
      <a href="<?= e(url('prayer.php')) ?>"><?= icon('pray') ?><?= e(t('Prayer Requests')) ?></a>
      <a href="<?= e(url('resources.php')) ?>"><?= icon('book') ?><?= e(t('Resources')) ?></a>
      <a href="<?= e(url('give.php')) ?>"><?= icon('gift') ?><?= e(t('Give')) ?></a>
      <a href="<?= e(url('my_giving.php')) ?>"><?= icon('receipt') ?>My Giving</a>
      <a href="<?= e(url('notifications.php')) ?>"><?= icon('bell') ?><?= e(t('Notifications')) ?></a>
      <?php if (is_admin()): ?><a href="<?= e(url('admin/index.php')) ?>"><?= icon('settings') ?>Admin</a><?php endif; ?>
      <a href="<?= e(url('profile.php')) ?>"><?= icon('user') ?><?= e(t('Profile')) ?></a>
      <a href="<?= e(url('install.php')) ?>" class="hide-standalone"><?= icon('download') ?>Install App</a>
      <a href="<?= e(url('logout.php')) ?>"><?= icon('logout') ?><?= e(t('Log Out')) ?></a>
    </div>
  </div>
</div>

<div class="toast" id="toast" role="status" aria-live="polite"></div>
<script src="<?= e(asset('assets/js/app.js')) ?>"></script>
<?php if (!empty($pageScript)): ?><script><?= $pageScript ?></script><?php endif; ?>
</body>
</html>
