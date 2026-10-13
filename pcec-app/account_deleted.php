<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout_auth.php';
auth_head('Account deleted');
?>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-top auth-top-sm"><div class="auth-top-bar"><span></span><span></span></div><?php auth_brand(true); ?></div>
    <div class="auth-panel notice-panel">
      <div class="notice-ic"><?= icon('check') ?></div>
      <h1>Your account has been deleted</h1>
      <p class="auth-sub">Thank you for being part of PCEC Connect. Your profile, posts and photos have been removed.</p>
      <a class="btn btn-gradient btn-block" href="<?= e(url('login.php')) ?>">Back to the start</a>
    </div>
  </div>
</div>
<?php auth_foot(); ?>
