<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';

const ABOUT_MISSION = 'The Philippine Council of Evangelical Churches (PCEC) is a fellowship of churches committed to the unity of the body of Christ and the proclamation of the Gospel to the nation and the world. We serve as a platform for collaboration, resource sharing, and collective witness among our member denominations.';
const ABOUT_HISTORY = 'Founded on the principles of biblical authority and ecclesiastical cooperation, PCEC has been instrumental in the development of evangelical thought and practice in the Philippines. Through decades of ministry, we have supported the growth of local churches and contributed to social outreach programs that address the spiritual and physical needs of our communities.';
$values = ['Biblical Inerrancy and Authority', 'Evangelism and Discipleship', 'Ecclesiastical Unity and Fellowship', 'Social Responsibility and Outreach'];
$org = setting('about_org_name') ?: 'Philippine Council of Evangelical Churches';
$mission = trim((string) setting('about_text')) ?: ABOUT_MISSION;
$site = trim((string) setting('about_website'));
$year = trim((string) setting('about_established_year'));
$location = trim((string) setting('about_office_location')) ?: 'Manila, Philippines';

$pageTitle = 'About PCEC';
$activeNav = 'about';
$rightRail = right_rail();
require __DIR__ . '/includes/header.php';
?>
<section class="banner banner-tall">
  <div class="banner-text">
    <p class="banner-pillars">About PCEC</p>
    <h2><?= e($org) ?></h2>
    <p class="banner-pillars">Unity &middot; Evangelism &middot; Discipleship</p>
  </div>
</section>
<section class="card about-sec">
  <h2 class="sec-label">Mission</h2>
  <p class="lead pre-line"><?= e($mission) ?></p>
</section>
<section class="card about-sec">
  <h2 class="sec-label">Our History</h2>
  <p class="lead"><?= e(ABOUT_HISTORY) ?></p>
</section>
<section class="card about-sec">
  <h2 class="sec-label">Core Values</h2>
  <div class="pillars">
    <?php foreach ($values as $i => $v): ?>
      <div class="pillar"><span class="<?= ['tile-blue', 'tile-orange', 'tile-green', 'tile-purple'][$i] ?>"><?= icon(['book', 'globe', 'users', 'heart'][$i]) ?></span><h3><?= e($v) ?></h3></div>
    <?php endforeach; ?>
  </div>
</section>
<?php if ($site): ?>
<section class="card about-sec">
  <h2 class="sec-label">Official Website</h2>
  <p class="muted">For the most recent updates, official statements, and detailed resources, please visit our web portal.</p>
  <a class="btn btn-outline btn-block" href="<?= e($site) ?>" target="_blank" rel="noopener external"><?= icon('external') ?> Visit PCEC Portal</a>
</section>
<?php endif; ?>
<footer class="about-foot">
  <?php if ($year): ?><p>ESTABLISHED <?= e($year) ?></p><?php endif; ?>
  <p><?= e(strtoupper($location)) ?></p>
  <a class="link" href="<?= e(url('contact.php')) ?>">Contact the PCEC office <?= icon('chevron-right') ?></a>
</footer>
<?php require __DIR__ . '/includes/footer.php'; ?>
