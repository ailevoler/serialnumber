<?php
/** Shared shell for the Terms of Service and Privacy Notice pages (drafts for PCEC's lawyer / DPO to review). */
const LEGAL_UPDATED = '13 October 2026';

function legal_open(string $title): void
{
    auth_head($title, 'legal-body'); ?>
<div class="auth-wrap">
  <div class="auth-card legal-card">
    <div class="auth-top auth-top-sm"><div class="auth-top-bar"><a href="javascript:history.length>1?history.back():location.href='<?= e(url('login.php')) ?>'" class="icon-btn light" aria-label="Back"><?= icon('arrow-left') ?></a><span></span></div><?php auth_brand(true); ?></div>
    <div class="auth-panel prose legal">
      <h1><?= e($title) ?></h1>
      <p class="muted small">Last updated <?= LEGAL_UPDATED ?>. Draft: to be reviewed by PCEC's lawyer and Data Protection Officer before public launch.</p>
<?php }

function legal_close(): void
{ ?>
      <p class="legal-links"><a href="<?= e(url('terms.php')) ?>">Terms of Service</a> · <a href="<?= e(url('privacy.php')) ?>">Privacy Notice</a> · <a href="<?= e(url('delete_account.php')) ?>">Delete account</a></p>
    </div>
  </div>
</div>
<?php auth_foot();
}
