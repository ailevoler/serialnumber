<?php $composerId = $composerId ?? 'm'; ?>
<form class="composer-form" action="<?= e(url('community_action.php')) ?>" method="post" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="create">
  <div class="composer-row">
    <?= avatar(current_user(), 'md') ?>
    <textarea name="body" rows="3" placeholder="Share a prayer request, a testimony or an encouraging word…" maxlength="2000"></textarea>
  </div>
  <div class="composer-preview" hidden></div>
  <input type="file" name="media" id="media-<?= e($composerId) ?>" class="visually-hidden" accept="image/jpeg,image/png,image/webp,image/gif">
  <div class="composer-tools">
    <label for="media-<?= e($composerId) ?>" class="composer-tool"><?= icon('camera') ?> Add photo</label>
    <button class="btn btn-gradient composer-submit"><?= icon('send') ?> Post</button>
  </div>
  <p class="composer-rules">Please follow the <a href="<?= e(url('terms.php#community-rules')) ?>" target="_blank" rel="noopener">Community Rules</a>.</p>
</form>
