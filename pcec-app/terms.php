<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout_auth.php';
auth_head('Terms & Privacy');
?>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-top auth-top-sm"><div class="auth-top-bar"><a href="javascript:history.back()" class="icon-btn light" aria-label="Back"><?= icon('arrow-left') ?></a><span></span></div><?php auth_brand(true); ?></div>
    <div class="auth-panel prose">
      <h1>Terms of Service</h1>
      <p>The PCEC Community Platform is provided for member churches, leaders and partners of the Philippine Council of Evangelical Churches. By using it you agree to post respectfully, honor others' privacy, and refrain from sharing content that is unlawful, abusive or misleading.</p>
      <p>Administrators may remove content or accounts that violate these terms.</p>
      <h2 id="privacy">Privacy Policy</h2>
      <p>We store the information you provide (name, email, church, posts, messages and uploads) only to operate the platform. Passwords are stored as one-way hashes. We do not sell your data. You may ask an administrator to delete your account at any time.</p>
      <p class="auth-switch"><a href="<?= e(url('register.php')) ?>"><?= icon('arrow-left') ?> Back</a></p>
    </div>
  </div>
</div>
<?php auth_foot(); ?>
