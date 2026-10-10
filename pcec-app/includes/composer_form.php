<?php $composerId = $composerId ?? 'm'; ?>
<form class="composer-form" action="<?= e(url('post_create.php')) ?>" method="post" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <div class="composer-row">
    <?= avatar(current_user(), 'md') ?>
    <textarea name="body" rows="3" placeholder="<?= e(t("What's on your mind?")) ?>" maxlength="5000"></textarea>
  </div>
  <div class="composer-preview" hidden></div>
  <input type="file" name="media" id="media-<?= e($composerId) ?>" class="visually-hidden">
  <div class="composer-actions">
    <label for="media-<?= e($composerId) ?>" class="ca ca-photo" data-accept="image/*"><span class="ca-ic"><?= icon('camera') ?></span><?= e(t('Photo')) ?></label>
    <label for="media-<?= e($composerId) ?>" class="ca ca-video" data-accept="video/*"><span class="ca-ic"><?= icon('video') ?></span><?= e(t('Video')) ?></label>
    <a href="<?= e(url('events.php?new=1')) ?>" class="ca ca-event"><span class="ca-ic"><?= icon('calendar') ?></span><?= e(t('Event')) ?></a>
    <label for="media-<?= e($composerId) ?>" class="ca ca-file" data-accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.zip"><span class="ca-ic"><?= icon('file') ?></span><?= e(t('File')) ?></label>
  </div>
  <button class="btn btn-gradient btn-block composer-submit"><?= icon('send') ?> <?= e(t('Post')) ?></button>
</form>
