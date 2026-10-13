<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';
$me = require_login();

$posts = q_all('SELECT * FROM ndc_posts ORDER BY created_at DESC LIMIT 3');
$active = $me['status'] === 'approved';
$memberId = 'PCEC-' . date('Y', strtotime($me['created_at'])) . '-' . str_pad((string) $me['id'], 4, '0', STR_PAD_LEFT);
$benefits = ['Access to NDC led ministries and programs.', 'Voting rights in annual conventions.', 'Priority registration for PCEC conferences.'];

$pageTitle = 'Membership & NDC';
$activeNav = 'membership';
require __DIR__ . '/includes/header.php';
?>
<p class="menu-heading">PERSONAL MEMBERSHIP</p>
<section class="membership-card">
  <div class="mc-top"><span class="mc-label">MEMBERSHIP STATUS</span><span class="status <?= $active ? 'status-paid' : 'status-pending' ?>"><?= $active ? 'Active' : 'Pending' ?></span></div>
  <p class="mc-church"><?= e($me['church_name'] ?: 'No church on file') ?></p>
  <p class="mc-id">ID: <?= e($memberId) ?></p>
  <?php if (!$me['church_name']): ?><a class="link mc-link" href="<?= e(url('profile_edit.php')) ?>">Add your church in Edit Profile</a><?php endif; ?>
  <hr>
  <div class="mc-bottom">
    <div><p class="mc-label"><?= $me['membership_renewal_date'] ? 'RENEWAL DATE' : 'MEMBER SINCE' ?></p>
      <p class="mc-date"><?= e(date('F j, Y', strtotime($me['membership_renewal_date'] ?: $me['created_at']))) ?></p></div>
    <a class="btn btn-sm btn-ghost-light" href="<?= e(url('contact.php?subject=' . urlencode('Membership renewal'))) ?>">RENEW NOW</a>
  </div>
</section>

<?php section_head('NDC Promotions', 'ndc.php', 'View All'); ?>
<?php foreach ($posts as $n): ?>
  <article class="card ndc-card">
    <?php if ($n['image']): ?><img class="ndc-img" src="<?= e(url($n['image'])) ?>" alt="" loading="lazy"><?php endif; ?>
    <p class="ndc-date"><?= e(['event' => 'EVENT', 'picture' => 'PICTURES', 'promotion' => 'PROMOTION'][$n['post_type']]) ?> · <?= e(strtoupper(date('M j, Y', strtotime($n['created_at'])))) ?></p>
    <h3><?= e($n['title']) ?></h3>
    <?php if ($n['content']): ?><p class="pre-line"><?= e($n['content']) ?></p><?php endif; ?>
  </article>
<?php endforeach; ?>
<?php if (!$posts) empty_state('bell', 'Nothing posted yet', 'NDC events, pictures and promotions will appear here.'); ?>

<section class="card boxed">
  <p class="mc-label dark">BENEFITS OF MEMBERSHIP</p>
  <ul class="bullets"><?php foreach ($benefits as $b): ?><li><?= e($b) ?></li><?php endforeach; ?></ul>
</section>
<a class="btn btn-outline btn-block" href="<?= e(url('contact.php?subject=Membership')) ?>"><?= icon('mail') ?> CONTACT MEMBERSHIP SECRETARIAT</a>
<?php require __DIR__ . '/includes/footer.php'; ?>
