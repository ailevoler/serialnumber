<?php
require_once __DIR__ . '/includes/functions.php';
require_login();
$activeNav = 'notifications';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Notifications — <?= APP_NAME ?></title>
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
    <h2>Notifications</h2>
  </div>
  <div class="app-main" style="padding-top:16px;">
    <div class="card" id="notifCard">
      <div class="empty-state"><div class="icon"><i class="fa-solid fa-spinner fa-spin"></i></div>Loading...</div>
    </div>
  </div>
  <?php include __DIR__ . '/includes/bottom-nav.php'; ?>
</div>

<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<script>
async function loadNotifications() {
  const res = await RBNW.api('notifications.php?action=list');
  const card = document.getElementById('notifCard');

  if (!res.success || !res.items.length) {
    card.innerHTML = `<div class="empty-state"><div class="icon"><i class="fa-regular fa-bell"></i></div>No notifications yet.</div>`;
    return;
  }

  card.innerHTML = res.items.map(n => `
    <div class="tx-row">
      <div class="tx-icon tx-in"><i class="fa-solid fa-bell"></i></div>
      <div class="tx-info">
        <div class="tx-title">${n.title}${n.is_read ? '' : ' <span style="color:var(--danger);">●</span>'}</div>
        <div class="tx-time">${n.message}</div>
        <div class="tx-time" style="margin-top:2px;">${n.time_ago}</div>
      </div>
    </div>
  `).join('');

  RBNW.api('notifications.php?action=mark_read', 'POST', {});
}
loadNotifications();
</script>
</body>
</html>
