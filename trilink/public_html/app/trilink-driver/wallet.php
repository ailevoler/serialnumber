<?php
require_once __DIR__ . '/includes/core.php';
driverCheck();
$driver = getDriver();
if (!$driver) driverLogout();

$error   = Session::flash('drv_wallet_error');
$success = Session::flash('drv_wallet_success');
$minBalance = (float)setting('driver_min_wallet_balance', 20.00);

// Handle POST actions (top up or cash out)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && Session::verifyCsrf($_POST[CSRF_TOKEN_NAME] ?? '')) {
    $postAction = $_POST['wallet_action'] ?? 'cashout';

    if ($postAction === 'topup') {
        // Creates a pending request only. Wallet is credited after QR Ph
        // confirms the payment or a Load Center teller confirms the cash.
        require_once __DIR__ . '/../trilink-pay/TopupService.php';
        try {
            $t = Topup::create(Topup::DRIVER, (int)$driver['id'], $_POST['amount'] ?? 0, (string)($_POST['channel'] ?? ''));
            header('Location: ' . APP_URL . '/topup_qr.php?code=' . urlencode($t['topup_code'])); exit;
        } catch (TopupException $e) {
            Session::flash('drv_wallet_error', $e->getMessage());
        } catch (Exception $e) {
            error_log('[TriLink Topup] ' . $e->getMessage());
            Session::flash('drv_wallet_error', 'Top-up failed. Please try again.');
        }
    } else {
        // Cash out
        $amount  = (float)($_POST['amount'] ?? 0);
        $method  = $_POST['method'] ?? 'gcash';
        $account = clean($_POST['account'] ?? '');

        if ($amount < 50) {
            Session::flash('drv_wallet_error', 'Minimum cashout is ₱50.');
        } elseif ((float)$driver['wallet_balance'] < $amount) {
            Session::flash('drv_wallet_error', 'Insufficient balance.');
        } elseif (strlen($account) < 5) {
            Session::flash('drv_wallet_error', 'Enter a valid account number.');
        } else {
            $ok = driverWalletDebit($driver['id'], $amount, 'cashout', 'cashout', 0,
                strtoupper($method) . ' cashout – ' . $account);
            if ($ok) {
                Session::flash('drv_wallet_success', "Cashout of ₱" . number_format($amount, 2) . " submitted! Processing in 1-3 business days.");
            } else {
                Session::flash('drv_wallet_error', 'Cashout failed. Please try again.');
            }
        }
    }
    header('Location: ' . APP_URL . '/wallet.php'); exit;
}

// Recent transactions — trip earnings + wallet transactions (top-ups, commission, cashouts) combined
$transactions = DB::all(
    "(SELECT 'trip' AS kind, b.booking_code AS ref, b.fare_total AS amount, 'credit' AS direction,
             b.completed_at AS created_at, u.full_name AS label
      FROM tl_bookings b JOIN tl_users u ON b.user_id = u.id
      WHERE b.driver_id = ? AND b.status = 'completed')
     UNION ALL
     (SELECT type AS kind, txn_code AS ref, amount, direction,
             created_at, description AS label
      FROM tl_driver_wallet_transactions WHERE driver_id = ?)
     ORDER BY created_at DESC LIMIT 20",
    [$driver['id'], $driver['id']]
);

$balance = (float)$driver['wallet_balance'];

$topupMin = max(20, (float)setting('wallet_topup_min', 50));
$topupMax = (float)setting('wallet_topup_max', 50000);
$pendingTopups = DB::all(
    "SELECT topup_code, amount, channel FROM tl_topups
     WHERE account_type='driver' AND account_id=? AND status='pending'
       AND (expires_at IS NULL OR expires_at > NOW())
     ORDER BY created_at DESC LIMIT 5",
    [$driver['id']]
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>Wallet – TriLink Driver</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/home.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/driver.css">
  <style>
    body { background:#F5F6FA; font-family:'Inter',sans-serif; }
    .app { max-width:480px;margin:0 auto;height:100vh;display:flex;flex-direction:column;background:#F5F6FA;overflow:visible; }
    .wallet-hero { background:linear-gradient(160deg,#0F1A3A,#1A2B5E 50%,#2E7D32);padding:28px 20px 32px;text-align:center; }
    .wh-label { font-size:13px;color:rgba(255,255,255,0.65);margin-bottom:6px; }
    .wh-balance { font-family:'Plus Jakarta Sans',sans-serif;font-size:44px;font-weight:800;color:#fff;margin-bottom:20px; }
    .wh-btn { display:inline-flex;align-items:center;gap:8px;padding:13px 28px;border-radius:14px;background:linear-gradient(135deg,#2E7D32,#43A047);color:#fff;font-weight:800;font-size:15px;border:none;cursor:pointer;box-shadow:0 4px 16px rgba(46,125,50,0.4);font-family:'Inter',sans-serif; }
    .txn-list { flex:1;overflow-y:auto;padding:14px 14px 90px; }
    .txn-card { display:flex;align-items:center;gap:12px;background:#fff;border-radius:12px;padding:13px 14px;margin-bottom:8px;border:1.5px solid #EAECF0;box-shadow:0 1px 4px rgba(0,0,0,0.05); }
    .txn-icon { width:40px;height:40px;border-radius:12px;background:#E8F5E9;display:flex;align-items:center;justify-content:center;font-size:18px;color:#2E7D32;flex-shrink:0; }
    .txn-label { font-size:13px;font-weight:600;color:#202124; }
    .txn-sub { font-size:11px;color:#9AA0A6;margin-top:2px; }
    .txn-amt { font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:800;color:#2E7D32;flex-shrink:0; }
    .modal-overlay { display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:500;align-items:flex-end;justify-content:center; }
    .modal-overlay.open { display:flex; }
    .modal-sheet { background:#fff;border-radius:22px 22px 0 0;width:100%;max-width:480px;padding:0 0 32px;animation:su 0.28s cubic-bezier(.25,.1,.25,1); }
    @keyframes su { from{transform:translateY(100%)} to{transform:translateY(0)} }
    .mhandle { width:40px;height:4px;background:#E0E0E0;border-radius:4px;margin:12px auto 0; }
    .mtitle { font-family:'Plus Jakarta Sans',sans-serif;font-size:18px;font-weight:800;color:#1A2B5E;padding:16px 20px 4px; }
    .f-grp { margin:0 20px 14px; }
    .f-grp label { display:block;font-size:12px;font-weight:700;color:#3C4043;margin-bottom:6px; }
    .f-inp { width:100%;padding:13px 14px;border-radius:12px;border:1.5px solid #EAECF0;font-size:15px;color:#202124;font-family:'Inter',sans-serif;outline:none;background:#fff; }
    .f-inp:focus { border-color:#2E7D32; }
    .f-sel { width:100%;padding:13px 14px;border-radius:12px;border:1.5px solid #EAECF0;font-size:14px;color:#202124;font-family:'Inter',sans-serif;outline:none;background:#fff; }
    .quick-amts { display:flex;gap:6px;margin:0 20px 14px; }
    .qa { flex:1;padding:9px 4px;background:#EEF2FF;border-radius:8px;border:none;font-size:12px;font-weight:700;color:#1A2B5E;cursor:pointer;font-family:'Inter',sans-serif; }
    .submit-btn { display:flex;align-items:center;justify-content:center;gap:10px;width:calc(100% - 40px);margin:0 20px;padding:15px;border-radius:14px;background:linear-gradient(135deg,#2E7D32,#43A047);color:#fff;font-weight:800;font-size:15px;border:none;cursor:pointer;font-family:'Inter',sans-serif;box-shadow:0 4px 14px rgba(46,125,50,0.35); }
  </style>
</head>
<body>
<div class="app">
  <header class="topbar" style="background:linear-gradient(135deg,#0F1A3A,#1A2B5E);">
    <a href="<?= APP_URL ?>/home.php" class="tb-btn" style="color:#fff;text-decoration:none;"><i class="fas fa-arrow-left"></i></a>
    <div class="tb-logo"><div class="tb-logo-icon"><i class="fas fa-motorcycle"></i></div><span><span class="tri">Tri</span><span class="lnk">Link</span></span><span class="driver-tag">Driver</span></div>
    <div class="tb-btn"></div>
  </header>

  <div class="wallet-hero">
    <div class="wh-label">Driver Wallet Balance</div>
    <div class="wh-balance">₱ <?= number_format($balance, 2) ?></div>
    <div style="display:flex;gap:10px;justify-content:center;">
      <button class="wh-btn" onclick="openTopup()" style="background:linear-gradient(135deg,#1A2B5E,#2E4A9E);box-shadow:0 4px 16px rgba(26,43,94,0.4);"><i class="fas fa-plus"></i> Top Up</button>
      <button class="wh-btn" onclick="openCashout()"><i class="fas fa-hand-holding-usd"></i> Cash Out</button>
    </div>
  </div>

  <?php if ($balance < $minBalance): ?>
  <div style="margin:12px 14px 0;padding:12px;background:#FFF3E0;color:#E65100;border-radius:10px;font-size:13px;">
    <i class="fas fa-exclamation-triangle"></i> Mababa ang wallet mo (min. ₱<?= number_format($minBalance, 2) ?> kailangan para makapag-accept ng booking). Mag-Top Up muna.
  </div>
  <?php endif; ?>

  <?php foreach ($pendingTopups as $pt): ?>
  <a href="<?= APP_URL ?>/topup_qr.php?code=<?= urlencode($pt['topup_code']) ?>" style="display:flex;align-items:center;gap:10px;margin:12px 14px 0;padding:12px;background:#FFF3E0;color:#E65100;border-radius:10px;font-size:13px;font-weight:600;text-decoration:none;">
    <i class="fas fa-hourglass-half"></i>
    <span style="flex:1;">Pending top-up ₱<?= number_format((float)$pt['amount'], 2) ?> · <?= $pt['channel'] === 'qrph' ? 'QR Ph' : 'Teller' ?></span>
    <i class="fas fa-chevron-right"></i>
  </a>
  <?php endforeach; ?>

  <?php if ($error): ?><div style="margin:12px 14px 0;padding:12px;background:#FFEBEE;color:#C62828;border-radius:10px;font-size:13px;"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div style="margin:12px 14px 0;padding:12px;background:#E8F5E9;color:#2E7D32;border-radius:10px;font-size:13px;"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div><?php endif; ?>

  <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 14px 8px;"><span style="font-size:15px;font-weight:700;color:#202124;">Transactions</span></div>

  <div class="txn-list">
    <?php
    $txnMeta = [
        'trip'         => ['label' => 'Trip',        'icon' => 'fa-motorcycle',      'bg' => '#E8F5E9', 'fg' => '#2E7D32'],
        'topup'        => ['label' => 'Top Up',       'icon' => 'fa-plus-circle',     'bg' => '#E3F2FD', 'fg' => '#1A2B5E'],
        'commission'   => ['label' => 'Commission',   'icon' => 'fa-percent',         'bg' => '#FFF3E0', 'fg' => '#E65100'],
        'cashout'      => ['label' => 'Cash Out',     'icon' => 'fa-hand-holding-usd','bg' => '#FCE4EC', 'fg' => '#C2185B'],
        'refund'       => ['label' => 'Refund',       'icon' => 'fa-undo',            'bg' => '#E8F5E9', 'fg' => '#2E7D32'],
        'adjustment'   => ['label' => 'Adjustment',   'icon' => 'fa-exchange-alt',    'bg' => '#F3E5F5', 'fg' => '#6A1B9A'],
    ];
    ?>
    <?php if (empty($transactions)): ?>
    <div style="text-align:center;padding:60px 20px;color:#9AA0A6;"><i class="fas fa-wallet" style="font-size:40px;opacity:0.25;display:block;margin-bottom:10px;"></i><p style="font-size:14px;">No transactions yet</p></div>
    <?php else: foreach ($transactions as $t):
        $kind = $t['kind'] ?? 'trip';
        $meta = $txnMeta[$kind] ?? $txnMeta['trip'];
        $isDebit = ($t['direction'] ?? 'credit') === 'debit';
    ?>
    <div class="txn-card">
      <div class="txn-icon" style="background:<?= $meta['bg'] ?>;color:<?= $meta['fg'] ?>;"><i class="fas <?= $meta['icon'] ?>"></i></div>
      <div style="flex:1;min-width:0;">
        <div class="txn-label"><?= $meta['label'] ?> · <?= htmlspecialchars($t['ref']) ?></div>
        <div class="txn-sub"><?= htmlspecialchars($t['label'] ?? '') ?> · <?= date('M d, h:i A', strtotime($t['created_at'] ?? 'now')) ?></div>
      </div>
      <div class="txn-amt" style="color:<?= $isDebit ? '#C62828' : '#2E7D32' ?>;"><?= $isDebit ? '-' : '+' ?>₱<?= number_format((float)$t['amount'], 2) ?></div>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <nav class="bottom-nav">
    <a class="bn" href="<?= APP_URL ?>/home.php"><i class="fas fa-home"></i><span>Home</span></a>
    <a class="bn" href="<?= APP_URL ?>/trips.php"><i class="fas fa-route"></i><span>Trips</span></a>
    <a class="bn" href="<?= APP_URL ?>/earnings.php"><i class="fas fa-chart-line"></i><span>Earnings</span></a>
    <button class="bn active"><i class="fas fa-wallet"></i><span>Wallet</span></button>
    <a class="bn" href="<?= APP_URL ?>/profile.php"><i class="fas fa-user"></i><span>Profile</span></a>
  </nav>
</div>

<!-- CASHOUT MODAL -->
<div class="modal-overlay" id="cashoutModal" onclick="if(event.target===this)closeCashout()">
  <div class="modal-sheet">
    <div class="mhandle"></div>
    <div class="mtitle"><i class="fas fa-hand-holding-usd" style="color:#2E7D32;margin-right:8px;"></i>Cash Out</div>
    <form method="POST" style="margin-top:12px;">
      <?= csrfField() ?>
      <div class="f-grp">
        <label>Amount (Balance: ₱<?= number_format($balance,2) ?>)</label>
        <input type="number" name="amount" id="cashoutAmt" class="f-inp" placeholder="0.00" min="50" max="<?= $balance ?>" step="0.01" style="font-size:28px;font-weight:800;color:#1A2B5E;font-family:'Plus Jakarta Sans',sans-serif;" required>
      </div>
      <div class="quick-amts">
        <?php foreach ([100,200,500,1000] as $a): ?>
        <button type="button" class="qa" onclick="document.getElementById('cashoutAmt').value=<?= $a ?>">₱<?= $a ?></button>
        <?php endforeach; ?>
      </div>
      <div class="f-grp">
        <label>Method</label>
        <select name="method" class="f-sel">
          <option value="gcash">GCash</option>
          <option value="maya">Maya</option>
          <option value="bank">Bank Transfer</option>
        </select>
      </div>
      <div class="f-grp">
        <label>Account Number / Phone</label>
        <input type="text" name="account" class="f-inp" placeholder="09XXXXXXXXX" required>
      </div>
      <button type="submit" class="submit-btn"><i class="fas fa-paper-plane"></i> Submit Cashout</button>
    </form>
  </div>
</div>

<!-- TOP UP MODAL -->
<div class="modal-overlay" id="topupModal" onclick="if(event.target===this)closeTopup()">
  <div class="modal-sheet">
    <div class="mhandle"></div>
    <div class="mtitle"><i class="fas fa-plus-circle" style="color:#1A2B5E;margin-right:8px;"></i>Top Up Wallet</div>
    <form method="POST" style="margin-top:12px;">
      <?= csrfField() ?>
      <input type="hidden" name="wallet_action" value="topup">
      <div class="f-grp">
        <label>Amount (Balance: ₱<?= number_format($balance,2) ?>)</label>
        <input type="number" name="amount" id="topupAmt" class="f-inp" placeholder="0.00" min="<?= $topupMin ?>" max="<?= $topupMax ?>" step="0.01" style="font-size:28px;font-weight:800;color:#1A2B5E;font-family:'Plus Jakarta Sans',sans-serif;" required>
      </div>
      <div class="quick-amts">
        <?php foreach ([100,200,500,1000] as $a): ?>
        <button type="button" class="qa" onclick="document.getElementById('topupAmt').value=<?= $a ?>">₱<?= $a ?></button>
        <?php endforeach; ?>
      </div>
      <div class="f-grp">
        <label>Payment Channel</label>
        <select name="channel" class="f-sel" id="topupChannel" onchange="updateTopupInfo()">
          <option value="qrph">QR Ph — GCash, Maya, o bank app</option>
          <option value="teller">Teller — Cash sa Load Center</option>
        </select>
      </div>
      <div id="topupInfo" style="margin:0 20px 14px;padding:10px 12px;background:#F5F6FA;border-radius:10px;font-size:12px;color:#5F6368;line-height:1.5;"></div>
      <button type="submit" class="submit-btn" style="background:linear-gradient(135deg,#1A2B5E,#2E4A9E);box-shadow:0 4px 14px rgba(26,43,94,0.35);"><i class="fas fa-arrow-right"></i> Magpatuloy</button>
    </form>
  </div>
</div>
<script>
function openCashout() { document.getElementById('cashoutModal').classList.add('open'); }
function closeCashout() { document.getElementById('cashoutModal').classList.remove('open'); }
function openTopup() { document.getElementById('topupModal').classList.add('open'); }
function closeTopup() { document.getElementById('topupModal').classList.remove('open'); }
function updateTopupInfo() {
  var info = {
    qrph:   'Makakakuha ka ng QR Ph code. I-scan gamit ang GCash, Maya o bank app — papasok agad pag kumpirmado na ang bayad.',
    teller: 'Makakakuha ka ng code para sa Load Center / Teller. Papasok lang ang pera pag na-confirm na ng teller ang cash mo.'
  };
  document.getElementById('topupInfo').textContent = info[document.getElementById('topupChannel').value] || '';
}
updateTopupInfo();
<?php if (isset($_GET['topup'])): ?>openTopup();<?php endif; ?>
</script>
</body>
</html>
