<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';

$pageTitle = 'About PCEC';
$activeNav = 'more';
require __DIR__ . '/includes/header.php';
?>
<section class="banner banner-tall">
  <div class="banner-text">
    <h2>One Body<br>Many Churches<br>Greater Impact</h2>
    <p class="banner-pillars">Unity &middot; Evangelism &middot; Discipleship</p>
  </div>
</section>
<section class="card prose">
  <h2>Philippine Council of Evangelical Churches</h2>
  <p>PCEC is a fellowship of evangelical denominations, churches, and para-church organizations in the Philippines, working together with God to transform the nation through the gospel of Jesus Christ.</p>
  <div class="pillars">
    <div class="pillar"><span class="tile-blue"><?= icon('users') ?></span><h3>Unity</h3><p>Standing together as one body of Christ across denominations and regions.</p></div>
    <div class="pillar"><span class="tile-orange"><?= icon('globe') ?></span><h3>Evangelism</h3><p>Reaching every Filipino with the good news of salvation.</p></div>
    <div class="pillar"><span class="tile-green"><?= icon('book') ?></span><h3>Discipleship</h3><p>Equipping believers and leaders to grow in Christlikeness.</p></div>
  </div>
  <p>This Community Platform connects member churches and leaders to pray, share resources, coordinate events, and encourage one another.</p>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
