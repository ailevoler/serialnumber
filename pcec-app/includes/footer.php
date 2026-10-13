  </main>
</div><!-- /.app-main -->

<?php if (!empty($rightRail)): ?>
<aside class="right-rail"><?= $rightRail ?></aside>
<?php endif; ?>

<nav class="bottom-nav" aria-label="Main">
  <?php foreach ($mainTabs as $key => [$href, $ic, $label]): ?>
    <a href="<?= e(url($href)) ?>" class="<?= $activeTab === $key ? 'active' : '' ?>"<?= $activeTab === $key ? ' aria-current="page"' : '' ?>><?= icon($ic) ?><span><?= e(t($label)) ?></span></a>
  <?php endforeach; ?>
</nav>

<!-- "Create post" window for the Community Feed (opened from Home, the feed and the sidebar) -->
<div class="modal" id="composer-modal" hidden>
  <div class="modal-card">
    <div class="modal-head">
      <h2>Create post</h2>
      <button class="icon-btn" data-close aria-label="Close"><?= icon('x') ?></button>
    </div>
    <?php include __DIR__ . '/composer_form.php'; ?>
  </div>
</div>

<div class="toast" id="toast" role="status" aria-live="polite"></div>
<script src="<?= e(asset('assets/js/app.js')) ?>"></script>
<?php if (!empty($pageScript)): ?><script><?= $pageScript ?></script><?php endif; ?>
</body>
</html>
