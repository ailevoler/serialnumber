<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout_auth.php';

if (current_user()) redirect('index.php');
setcookie('pcec_onboarded', '1', ['expires' => time() + 365 * 86400, 'path' => '/', 'samesite' => 'Lax']);

$slides = [
    ['One Body<br>Many Churches<br>Greater Impact', 'A unified community of evangelical churches, working together to glorify God and reach the Philippines for Christ.', 'unity'],
    ['Pray<br>Together', 'Share prayer requests and lift up one another, our churches and our nation.', 'prayer'],
    ['Connect &amp;<br>Grow', 'Discover events, resources and leaders across Luzon, Visayas and Mindanao.', 'connect'],
];
auth_head('Welcome', 'onboarding-body');
?>
<div class="onboarding">
  <a class="skip-link" href="<?= e(url('login.php')) ?>"><?= e(t('Skip')) ?></a>
  <div class="onboarding-brand"><?php auth_brand(); ?></div>
  <div class="slides" data-slider>
    <?php foreach ($slides as $i => [$title, $text, $art]): ?>
      <section class="slide slide-<?= $art ?><?= $i === 0 ? ' active' : '' ?>" aria-hidden="<?= $i === 0 ? 'false' : 'true' ?>">
        <h1><?= $title ?></h1>
        <p><?= e(html_entity_decode($text)) ?></p>
      </section>
    <?php endforeach; ?>
  </div>
  <div class="dots" data-dots>
    <?php foreach ($slides as $i => $_): ?><button class="<?= $i === 0 ? 'on' : '' ?>" aria-label="Slide <?= $i + 1 ?>"></button><?php endforeach; ?>
  </div>
  <a class="btn btn-primary btn-lg btn-block" data-next href="<?= e(url('login.php')) ?>"><?= e(t('Get Started')) ?> <?= icon('arrow-right') ?></a>
</div>
<?php auth_foot(); ?>
