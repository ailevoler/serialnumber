<?php
require_once __DIR__ . '/includes/functions.php';
require_login();
$user = get_user(current_user_id());
$activeNav = 'profile';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<title>Profile — <?= APP_NAME ?></title>
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
    <h2>Profile</h2>
  </div>

  <div class="app-main" style="padding-top:16px;">
    <div class="card" style="text-align:center;">
      <div class="avatar" style="width:72px;height:72px;font-size:24px;margin:0 auto 12px;">
        <?= strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)) ?>
      </div>
      <h3 style="margin:0;"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></h3>
      <p style="color:var(--text-muted);font-size:12.5px;margin:4px 0;"><?= htmlspecialchars($user['mobile_number']) ?></p>
      <p style="color:var(--rbnw-blue);font-size:12.5px;font-weight:700;margin:0;"><?= htmlspecialchars($user['wallet_code']) ?></p>
      <span class="wallet-level-chip" style="background:var(--rbnw-blue);color:#fff;display:inline-block;margin-top:10px;">
        <?= ['verified' => 'Verified Account', 'pending' => 'Verification under review', 'rejected' => 'Verification not approved — tap to retry'][$user['kyc_status']] ?? 'Unverified — tap to verify' ?>
      </span>
    </div>

    <div class="card" style="padding:4px 16px;">
      <a href="kyc.php" class="tx-row" style="text-decoration:none;color:inherit;">
        <div class="tx-icon tx-in"><i class="fa-solid fa-id-card"></i></div>
        <div class="tx-info"><div class="tx-title">Verify Your ID (KYC)</div></div>
        <i class="fa-solid fa-chevron-right" style="color:var(--text-muted);"></i>
      </a>
      <a href="bank-wallet.php" class="tx-row" style="text-decoration:none;color:inherit;">
        <div class="tx-icon tx-in"><i class="fa-solid fa-building-columns"></i></div>
        <div class="tx-info"><div class="tx-title">Linked Bank Accounts</div></div>
        <i class="fa-solid fa-chevron-right" style="color:var(--text-muted);"></i>
      </a>
      <a href="security.php" class="tx-row" style="text-decoration:none;color:inherit;">
        <div class="tx-icon tx-in"><i class="fa-solid fa-shield-halved"></i></div>
        <div class="tx-info"><div class="tx-title">Security &amp; MPIN</div></div>
        <i class="fa-solid fa-chevron-right" style="color:var(--text-muted);"></i>
      </a>
      <a href="transactions.php" class="tx-row" style="text-decoration:none;color:inherit;">
        <div class="tx-icon tx-in"><i class="fa-solid fa-clock-rotate-left"></i></div>
        <div class="tx-info"><div class="tx-title">Transaction History</div></div>
        <i class="fa-solid fa-chevron-right" style="color:var(--text-muted);"></i>
      </a>
      <a href="#" class="tx-row" style="text-decoration:none;color:inherit;" onclick="return false;">
        <div class="tx-icon tx-in"><i class="fa-solid fa-headset"></i></div>
        <div class="tx-info"><div class="tx-title">Help &amp; Support</div></div>
        <i class="fa-solid fa-chevron-right" style="color:var(--text-muted);"></i>
      </a>
    </div>

    <button class="btn btn-outline" id="logoutBtn" style="color:var(--danger);border-color:#f4c9c7;">
      <i class="fa-solid fa-right-from-bracket"></i> Log Out
    </button>
  </div>

  <?php include __DIR__ . '/includes/bottom-nav.php'; ?>
</div>

<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<script>
document.getElementById('logoutBtn').addEventListener('click', async () => {
  await RBNW.api('auth.php?action=logout', 'POST', {});
  window.location.href = 'login.php';
});
</script>
</body>
</html>
