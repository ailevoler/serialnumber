<?php
require_once __DIR__ . '/includes/functions.php';
require_login();
$activeNav = 'home';

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<title>All Services — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>
<div class="app-shell">
  <div class="flow-header">
    <button class="back-btn" onclick="location.href='dashboard.php'" style="background:none;border:none;"><i class="fa-solid fa-arrow-left"></i></button>
    <h2>All Services</h2>
  </div>
  <div class="app-main" style="padding-top:16px;">
    <div class="services-grid">
      <?php render_service_tiles(['send', 'cashin', 'bills', 'loans', 'save', 'request', 'bank', 'donate', 'cashout', 'scan', 'history', 'notif']); ?>
    </div>
  </div>
  <?php include __DIR__ . '/includes/bottom-nav.php'; ?>
</div>
</body>
</html>
