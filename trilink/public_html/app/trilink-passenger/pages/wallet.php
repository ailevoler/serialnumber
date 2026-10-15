<?php
require_once __DIR__ . '/../includes/core.php';
Auth::requireLogin();
$user = Auth::user();
if (!$user) Auth::logout();

$action = $_GET['action'] ?? 'home';
$error   = Session::flash('error');
$success = Session::flash('success');

// ── Handle POST actions ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Session::verifyCsrf($_POST[CSRF_TOKEN_NAME] ?? '')) {
        Session::flash('error', 'Invalid request. Please try again.');
        redirect('/pages/wallet.php');
    }

    $postAction = $_POST['wallet_action'] ?? '';

    // TOP UP – creates a pending request; wallet is credited only after
    // QR Ph confirms the payment or a teller confirms the cash.
    if ($postAction === 'topup') {
        require_once __DIR__ . '/../../trilink-pay/TopupService.php';
        try {
            $t = Topup::create(Topup::PASSENGER, (int)$user['id'], $_POST['amount'] ?? 0, (string)($_POST['channel'] ?? ''));
            redirect('/pages/topup.php?code=' . urlencode($t['topup_code']));
        } catch (TopupException $e) {
            Session::flash('error', $e->getMessage());
        } catch (Exception $e) {
            error_log('[TriLink Topup] ' . $e->getMessage());
            Session::flash('error', 'Top-up failed. Please try again.');
        }
        redirect('/pages/wallet.php');
    }

    // SEND MONEY
    if ($postAction === 'send') {
        $toPhone = normalizePhone($_POST['to_phone'] ?? '');
        $amount  = (float)($_POST['amount'] ?? 0);
        $note    = clean($_POST['note'] ?? '');

        if (!isValidPhone($toPhone)) {
            Session::flash('error', 'Enter a valid Philippine phone number.');
        } elseif ($amount < 1) {
            Session::flash('error', 'Minimum send amount is ₱1.00');
        } elseif ((float)$user['wallet_balance'] < $amount) {
            Session::flash('error', 'Insufficient wallet balance.');
        } else {
            $recipient = DB::row("SELECT id,full_name FROM tl_users WHERE phone=? AND status='active'", [$toPhone]);
            if (!$recipient) {
                Session::flash('error', 'Recipient not found or account inactive.');
            } elseif ($recipient['id'] === $user['id']) {
                Session::flash('error', 'You cannot send money to yourself.');
            } else {
                $pdo = DB::get();
                $pdo->beginTransaction();
                try {
                    walletDebit($user['id'], $amount, 'send', 'transfer', $recipient['id'],
                        ($note ?: "Sent to {$recipient['full_name']}"));
                    walletCredit($recipient['id'], $amount, 'receive', 'transfer', $user['id'],
                        "Received from {$user['full_name']}");
                    $pdo->commit();
                    Session::flash('success', "₱" . number_format($amount, 2) . " sent to {$recipient['full_name']}!");
                } catch (Exception $e) {
                    $pdo->rollBack();
                    Session::flash('error', 'Transfer failed. Please try again.');
                }
            }
        }
        redirect('/pages/wallet.php');
    }
}

// ── Fetch transaction history ────────────────────────────────
$typeFilter = $_GET['type'] ?? 'all';
$page       = max(1, (int)($_GET['page'] ?? 1));
$limit      = 15;
$offset     = ($page - 1) * $limit;

$allowedTypes = ['all', 'topup', 'ride_payment', 'delivery_payment', 'send', 'receive', 'refund', 'incentive'];
if (!in_array($typeFilter, $allowedTypes)) $typeFilter = 'all';

$where  = $typeFilter !== 'all' ? "AND type=?" : '';
$params = $typeFilter !== 'all'
    ? [$user['id'], $typeFilter, $limit, $offset]
    : [$user['id'], $limit, $offset];

$txns = DB::all(
    "SELECT * FROM tl_wallet_transactions WHERE user_id=? {$where} ORDER BY created_at DESC LIMIT ? OFFSET ?",
    $params
);
$total      = (int)DB::val("SELECT COUNT(*) FROM tl_wallet_transactions WHERE user_id=?" . ($typeFilter !== 'all' ? " AND type=?" : ""),
    $typeFilter !== 'all' ? [$user['id'], $typeFilter] : [$user['id']]);
$totalPages = (int)ceil($total / $limit);

// Summary stats
$totalIn  = (float)DB::val("SELECT COALESCE(SUM(amount),0) FROM tl_wallet_transactions WHERE user_id=? AND direction='credit' AND MONTH(created_at)=MONTH(NOW())", [$user['id']]);
$totalOut = (float)DB::val("SELECT COALESCE(SUM(amount),0) FROM tl_wallet_transactions WHERE user_id=? AND direction='debit' AND MONTH(created_at)=MONTH(NOW())", [$user['id']]);

// Top-up limits + open (unpaid) top-up requests
$topupMin = max(20, (float)setting('wallet_topup_min', 50));
$topupMax = (float)setting('wallet_topup_max', 50000);
$pendingTopups = DB::all(
    "SELECT topup_code, amount, channel FROM tl_topups
     WHERE account_type='passenger' AND account_id=? AND status='pending'
       AND (expires_at IS NULL OR expires_at > NOW())
     ORDER BY created_at DESC LIMIT 5",
    [$user['id']]
);
$openTopup = $action === 'topup' || isset($_GET['topup']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>Wallet – TriLink</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/home.css?v=<?= filemtime(__DIR__.'/../assets/css/home.css') ?>">
  <style>
    body { background: #F5F6FA; font-family: 'Inter', sans-serif; }

    /* ── WALLET HERO ── */
    .wallet-hero {
      background: linear-gradient(145deg, #0F1A3A 0%, #1A2B5E 55%, #2D4A8A 100%);
      padding: 20px 20px 28px;
      position: relative;
    }
    .wallet-hero::before {
      content: ''; position: absolute;
      width: 200px; height: 200px; border-radius: 50%;
      background: rgba(255,255,255,0.04);
      top: -60px; right: -40px;
    }
    .wallet-hero::after {
      content: ''; position: absolute;
      width: 120px; height: 120px; border-radius: 50%;
      background: rgba(0,200,83,0.08);
      bottom: -30px; left: 20px;
    }
    .wh-label { font-size: 12px; color: rgba(255,255,255,0.65); letter-spacing: 0.5px; margin-bottom: 4px; position: relative; z-index: 1; }
    .wh-bal   { font-family: 'Plus Jakarta Sans',sans-serif; font-size: 40px; font-weight: 800; color: #fff; margin-bottom: 18px; position: relative; z-index: 1; }
    .wh-month { display: flex; gap: 14px; position: relative; z-index: 1; }
    .wh-stat  { background: rgba(255,255,255,0.1); border-radius: 10px; padding: 10px 14px; flex: 1; }
    .wh-stat-label { font-size: 11px; color: rgba(255,255,255,0.6); margin-bottom: 3px; display: flex; align-items: center; gap: 5px; }
    .wh-stat-val   { font-size: 17px; font-weight: 800; color: #fff; font-family: 'Plus Jakarta Sans',sans-serif; }
    .wh-stat-val.green { color: #00C853; }
    .wh-stat-val.red   { color: #FF5252; }

    /* ── QUICK ACTIONS ── */
    .wallet-actions {
      display: grid; grid-template-columns: repeat(4,1fr);
      gap: 10px; padding: 16px 16px 0;
    }
    .wa-btn {
      display: flex; flex-direction: column; align-items: center; gap: 8px;
      padding: 14px 6px; background: #fff; border-radius: 14px;
      border: 1.5px solid #EAECF0; cursor: pointer;
      font-size: 12px; font-weight: 700; color: #202124;
      transition: all 0.18s; text-decoration: none;
    }
    .wa-btn:hover { border-color: #1A2B5E; transform: translateY(-2px); }
    .wa-btn i { font-size: 22px; width: 44px; height: 44px; border-radius: 12px;
                display: flex; align-items: center; justify-content: center; }
    .wa-topup   i { background: #E8F5E9; color: #2E7D32; }
    .wa-send    i { background: #E3F2FD; color: #1565C0; }
    .wa-receive i { background: #F3E5F5; color: #6A1B9A; }
    .wa-history i { background: #FFF3E0; color: #E65100; }

    /* ── MODAL SHEET ── */
    .modal-overlay {
      display: none; position: fixed; inset: 0;
      background: rgba(0,0,0,0.5); z-index: 500;
      align-items: flex-end; justify-content: center;
    }
    .modal-overlay.open { display: flex; }
    .modal-sheet {
      background: #fff; border-radius: 22px 22px 0 0;
      width: 100%; max-width: 480px;
      padding: 0 0 32px;
      animation: sheetUp 0.28s cubic-bezier(.25,.1,.25,1);
      max-height: 90vh; overflow-y: auto;
    }
    @keyframes sheetUp { from{transform:translateY(100%)} to{transform:translateY(0)} }
    .modal-handle { width: 40px; height: 4px; background: #E0E0E0; border-radius: 4px; margin: 12px auto 0; }
    .modal-title  { font-family: 'Plus Jakarta Sans',sans-serif; font-size: 18px; font-weight: 800; color: #1A2B5E; padding: 16px 20px 12px; }
    .modal-body   { padding: 0 20px 8px; }

    /* Amount input */
    .amt-input-wrap {
      background: #F5F6FA; border-radius: 14px;
      padding: 16px; margin-bottom: 14px;
      border: 2px solid #EAECF0; transition: border-color 0.2s;
    }
    .amt-input-wrap:focus-within { border-color: #2E7D32; background: #fff; }
    .amt-label { font-size: 11px; font-weight: 700; color: #9AA0A6; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
    .amt-row   { display: flex; align-items: center; gap: 4px; }
    .amt-peso  { font-size: 24px; font-weight: 800; color: #1A2B5E; }
    .amt-field { flex: 1; font-size: 32px; font-weight: 800; color: #1A2B5E; border: none; background: transparent; outline: none; font-family: 'Plus Jakarta Sans',sans-serif; min-width: 0; }

    /* Quick amounts */
    .quick-amts { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
    .qamt { padding: 7px 14px; background: #EEF2FF; border-radius: 20px; font-size: 13px; font-weight: 700; color: #1A2B5E; cursor: pointer; border: none; transition: all 0.15s; }
    .qamt:hover { background: #1A2B5E; color: #fff; }

    /* Channel selector */
    .channel-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 10px; margin-bottom: 14px; }
    .ch-card {
      display: flex; flex-direction: column; align-items: center; gap: 6px;
      padding: 14px 8px; border-radius: 12px; border: 2px solid #EAECF0;
      cursor: pointer; text-align: center; font-size: 12px; font-weight: 700;
      color: #202124; transition: all 0.18s;
    }
    .ch-card input { display: none; }
    .ch-card i { font-size: 24px; }
    .ch-card:has(input:checked) { border-color: #2E7D32; background: #E8F5E9; }
    .channel-grid.two { grid-template-columns: repeat(2,1fr); }
    .ch-card small { font-size: 10.5px; font-weight: 500; color: #9AA0A6; }
    .ch-qrph   i { color: #1A2B5E; }
    .ch-teller i { color: #E65100; }
    .pending-topup { display:flex; align-items:center; gap:10px; margin:12px 16px 0; padding:12px 14px; background:#FFF3E0; border-radius:12px; color:#E65100; font-size:13px; font-weight:600; text-decoration:none; }
    .pending-topup span { flex:1; }
    .ch-info { font-size: 11px; color: #9AA0A6; text-align: center; padding: 8px 12px; background: #F5F6FA; border-radius: 10px; margin-bottom: 14px; line-height: 1.5; }
    .ch-info strong { color: #1A2B5E; }

    /* Form fields */
    .f-group { margin-bottom: 14px; }
    .f-group label { display: block; font-size: 12px; font-weight: 700; color: #3C4043; margin-bottom: 6px; }
    .f-group input, .f-group textarea {
      width: 100%; padding: 13px 14px; border-radius: 12px;
      border: 1.5px solid #EAECF0; font-size: 15px; color: #202124;
      background: #fff; font-family: 'Inter',sans-serif; outline: none;
    }
    .f-group input:focus, .f-group textarea:focus { border-color: #2E7D32; }
    .f-group textarea { resize: none; height: 70px; font-size: 14px; }

    /* Balance display */
    .bal-display {
      display: flex; align-items: center; justify-content: space-between;
      padding: 12px 14px; background: #E8F5E9; border-radius: 10px; margin-bottom: 14px;
    }
    .bal-display span { font-size: 13px; color: #2E7D32; font-weight: 600; }
    .bal-display strong { font-size: 16px; font-weight: 800; color: #2E7D32; font-family: 'Plus Jakarta Sans',sans-serif; }

    /* Submit button */
    .modal-submit {
      width: 100%; padding: 16px; border-radius: 14px;
      background: linear-gradient(135deg, #2E7D32, #43A047);
      color: #fff; font-weight: 800; font-size: 16px;
      border: none; cursor: pointer; font-family: inherit;
      box-shadow: 0 4px 14px rgba(46,125,50,0.35);
      transition: all 0.18s; display: flex; align-items: center; justify-content: center; gap: 10px;
    }
    .modal-submit:hover { transform: translateY(-1px); }
    .modal-submit:disabled { opacity: 0.65; transform: none; cursor: not-allowed; }

    /* ── FILTER TABS ── */
    .filter-tabs {
      display: flex; gap: 6px; padding: 14px 16px 4px;
      overflow-x: auto; scrollbar-width: none;
    }
    .filter-tabs::-webkit-scrollbar { display: none; }
    .ftab {
      padding: 6px 14px; border-radius: 20px; white-space: nowrap;
      font-size: 12px; font-weight: 700; text-decoration: none; transition: all 0.18s;
      background: #F5F6FA; color: #9AA0A6; border: 1.5px solid #EAECF0;
    }
    .ftab.active { background: #1A2B5E; color: #fff; border-color: #1A2B5E; }

    /* ── TRANSACTION LIST ── */
    .txn-group-date { padding: 10px 16px 4px; font-size: 11px; font-weight: 700; color: #9AA0A6; text-transform: uppercase; letter-spacing: 0.5px; }
    .txn-item {
      display: flex; align-items: center; gap: 12px;
      padding: 13px 16px; background: #fff;
      border-bottom: 1px solid #F1F3F4;
      transition: background 0.12s;
    }
    .txn-item:last-child { border-bottom: none; }
    .txn-item:hover { background: #FAFAFA; }
    .txn-icon { width: 44px; height: 44px; border-radius: 13px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
    .ti-topup    { background: #E8F5E9; color: #2E7D32; }
    .ti-ride     { background: #E3F2FD; color: #1565C0; }
    .ti-delivery { background: #FFF3E0; color: #E65100; }
    .ti-send     { background: #FCE4EC; color: #C62828; }
    .ti-receive  { background: #E8F5E9; color: #2E7D32; }
    .ti-refund   { background: #F3E5F5; color: #6A1B9A; }
    .ti-incentive{ background: #FFF9C4; color: #F57F17; }
    .ti-default  { background: #F1F3F4; color: #9AA0A6; }
    .txn-info { flex: 1; min-width: 0; }
    .txn-desc { font-size: 14px; font-weight: 600; color: #202124; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .txn-time { font-size: 11px; color: #9AA0A6; margin-top: 2px; }
    .txn-right { text-align: right; flex-shrink: 0; }
    .txn-amt  { font-size: 15px; font-weight: 800; font-family: 'Plus Jakarta Sans',sans-serif; }
    .txn-amt.credit { color: #2E7D32; }
    .txn-amt.debit  { color: #202124; }
    .txn-bal  { font-size: 11px; color: #9AA0A6; margin-top: 2px; }

    /* Empty */
    .txn-empty { display: flex; flex-direction: column; align-items: center; gap: 12px; padding: 60px 20px; text-align: center; color: #9AA0A6; }
    .txn-empty i { font-size: 44px; opacity: 0.3; }

    /* Pagination */
    .pagination { display: flex; justify-content: center; gap: 6px; padding: 16px 16px 90px; }
    .pg-btn { width: 34px; height: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; text-decoration: none; transition: all 0.15s; }
    .pg-btn.active { background: #1A2B5E; color: #fff; }
    .pg-btn:not(.active) { background: #F1F3F4; color: #3C4043; }

    /* Alert */
    .page-alert { display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; border-radius: 12px; font-size: 13px; margin: 12px 16px 0; }
    .page-alert.success { background: #E8F5E9; color: #2E7D32; }
    .page-alert.error   { background: #FFEBEE; color: #C62828; }
  </style>
</head>
<body>
<div class="app" style="max-width:480px;margin:0 auto;display:flex;flex-direction:column;height:100vh;background:#F5F6FA;box-shadow:0 0 40px rgba(0,0,0,0.12);overflow:visible;">

  <!-- TOPBAR -->
  <header class="topbar">
    <a href="<?= APP_URL ?>/pages/home.php" class="tb-btn" style="text-decoration:none;color:#1A2B5E;">
      <i class="fas fa-arrow-left"></i>
    </a>
    <div class="tb-logo">
      <div class="tb-logo-icon"><i class="fas fa-motorcycle"></i></div>
      <span><span class="tri">Tri</span><span class="lnk">Link</span></span>
    </div>
    <button class="tb-btn" onclick="openModal('topupModal')">
      <i class="fas fa-plus-circle" style="color:#2E7D32;font-size:20px;"></i>
    </button>
  </header>

  <div style="flex:1;overflow-y:auto;">

    <!-- ALERT -->
    <?php if ($success): ?>
    <div class="page-alert success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="page-alert error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php foreach ($pendingTopups as $pt): ?>
    <a class="pending-topup" href="<?= APP_URL ?>/pages/topup.php?code=<?= urlencode($pt['topup_code']) ?>">
      <i class="fas fa-hourglass-half"></i>
      <span>Pending top-up ₱<?= number_format((float)$pt['amount'], 2) ?> · <?= $pt['channel'] === 'qrph' ? 'QR Ph' : 'Teller' ?></span>
      <i class="fas fa-chevron-right"></i>
    </a>
    <?php endforeach; ?>

    <!-- WALLET HERO -->
    <div class="wallet-hero">
      <div class="wh-label">TriLink Wallet Balance</div>
      <div class="wh-bal">₱ <?= number_format((float)$user['wallet_balance'], 2) ?></div>
      <div class="wh-month">
        <div class="wh-stat">
          <div class="wh-stat-label"><i class="fas fa-arrow-down"></i> Money In</div>
          <div class="wh-stat-val green">₱<?= number_format($totalIn, 2) ?></div>
        </div>
        <div class="wh-stat">
          <div class="wh-stat-label"><i class="fas fa-arrow-up"></i> Money Out</div>
          <div class="wh-stat-val red">₱<?= number_format($totalOut, 2) ?></div>
        </div>
      </div>
    </div>

    <!-- QUICK ACTIONS -->
    <div class="wallet-actions">
      <button class="wa-btn wa-topup" onclick="openModal('topupModal')">
        <i class="fas fa-plus"></i> Top Up
      </button>
      <button class="wa-btn wa-send" onclick="openModal('sendModal')">
        <i class="fas fa-paper-plane"></i> Send
      </button>
      <button class="wa-btn wa-receive" onclick="openModal('receiveModal')">
        <i class="fas fa-download"></i> Receive
      </button>
      <a class="wa-btn wa-history" href="<?= APP_URL ?>/pages/bookings.php">
        <i class="fas fa-calendar-check"></i> Bookings
      </a>
    </div>

    <!-- FILTER TABS -->
    <div class="filter-tabs">
      <?php
      $tabs = ['all'=>'All','topup'=>'Top Up','ride_payment'=>'Rides','delivery_payment'=>'Delivery','send'=>'Sent','receive'=>'Received','refund'=>'Refunds'];
      foreach ($tabs as $val => $lbl):
        $isActive = $typeFilter === $val;
      ?>
      <a href="?type=<?= $val ?>" class="ftab <?= $isActive ? 'active' : '' ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>

    <!-- TRANSACTION LIST -->
    <?php if (empty($txns)): ?>
    <div class="txn-empty">
      <i class="fas fa-receipt"></i>
      <p>No transactions yet.</p>
      <small>Your wallet activity will appear here.</small>
    </div>
    <?php else:
      $lastDate = '';
      $typeIconMap = [
        'topup'             => ['ti-topup',    'fa-plus-circle'],
        'ride_payment'      => ['ti-ride',     'fa-motorcycle'],
        'delivery_payment'  => ['ti-delivery', 'fa-shipping-fast'],
        'send'              => ['ti-send',     'fa-paper-plane'],
        'receive'           => ['ti-receive',  'fa-download'],
        'refund'            => ['ti-refund',   'fa-undo'],
        'incentive'         => ['ti-incentive','fa-star'],
        'tip'               => ['ti-incentive','fa-heart'],
      ];
      foreach ($txns as $t):
        $txnDate = date('F d, Y', strtotime($t['created_at']));
        $isCredit = $t['direction'] === 'credit';
        [$iconClass, $iconFa] = $typeIconMap[$t['type']] ?? ['ti-default','fa-exchange-alt'];
    ?>
    <?php if ($txnDate !== $lastDate): $lastDate = $txnDate; ?>
    <div class="txn-group-date"><?= $txnDate ?></div>
    <?php endif; ?>
    <div class="txn-item">
      <div class="txn-icon <?= $iconClass ?>"><i class="fas <?= $iconFa ?>"></i></div>
      <div class="txn-info">
        <div class="txn-desc"><?= htmlspecialchars($t['description'] ?? ucfirst(str_replace('_',' ',$t['type']))) ?></div>
        <div class="txn-time"><?= date('h:i A', strtotime($t['created_at'])) ?> · <?= ucfirst($t['status']) ?></div>
      </div>
      <div class="txn-right">
        <div class="txn-amt <?= $isCredit ? 'credit' : 'debit' ?>">
          <?= $isCredit ? '+' : '–' ?>₱<?= number_format((float)$t['amount'], 2) ?>
        </div>
        <div class="txn-bal">Bal: ₱<?= number_format((float)$t['balance_after'], 2) ?></div>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- PAGINATION -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <?php for ($p = 1; $p <= $totalPages; $p++): ?>
      <a href="?type=<?= $typeFilter ?>&page=<?= $p ?>" class="pg-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
    <?php else: ?>
    <div style="height:80px;"></div>
    <?php endif; ?>
    <?php endif; ?>

  </div><!-- end scroll -->

  <!-- BOTTOM NAV -->
  <nav class="bottom-nav">
    <a href="<?= APP_URL ?>/pages/home.php" class="bn"><i class="fas fa-home"></i><span>Home</span></a>
    <a href="<?= APP_URL ?>/pages/bookings.php" class="bn"><i class="fas fa-calendar-check"></i><span>Bookings</span></a>
    <a href="<?= APP_URL ?>/pages/orders.php" class="bn"><i class="fas fa-box"></i><span>Orders</span></a>
    <a href="<?= APP_URL ?>/pages/wallet.php" class="bn active"><i class="fas fa-wallet"></i><span>Wallet</span></a>
    <a href="<?= APP_URL ?>/pages/profile.php" class="bn"><i class="fas fa-user"></i><span>Account</span></a>
  </nav>

</div><!-- /app -->

<!-- ══ TOP UP MODAL ══════════════════════════════════════════ -->
<div class="modal-overlay" id="topupModal" onclick="closeOnOverlay(event,'topupModal')">
  <div class="modal-sheet">
    <div class="modal-handle"></div>
    <div class="modal-title"><i class="fas fa-plus-circle" style="color:#2E7D32;margin-right:8px;"></i>Top Up Wallet</div>
    <div class="modal-body">
      <form method="POST" action="" onsubmit="handleSubmit(this,'topupBtn')">
        <?= csrfField() ?>
        <input type="hidden" name="wallet_action" value="topup">

        <div class="amt-input-wrap">
          <div class="amt-label">Enter Amount</div>
          <div class="amt-row">
            <span class="amt-peso">₱</span>
            <input type="number" name="amount" id="topupAmount" class="amt-field"
                   placeholder="0.00" min="<?= $topupMin ?>" max="<?= $topupMax ?>" step="0.01" required>
          </div>
        </div>

        <div class="quick-amts">
          <?php foreach ([100,200,500,1000,2000,5000] as $qa): if ($qa < $topupMin || $qa > $topupMax) continue; ?>
          <button type="button" class="qamt" onclick="document.getElementById('topupAmount').value=<?= $qa ?>">
            ₱<?= number_format($qa) ?>
          </button>
          <?php endforeach; ?>
        </div>

        <label style="font-size:12px;font-weight:700;color:#3C4043;display:block;margin-bottom:10px;">Paraan ng Pagbabayad</label>
        <div class="channel-grid two">
          <label class="ch-card ch-qrph">
            <input type="radio" name="channel" value="qrph" required checked>
            <i class="fas fa-qrcode"></i> QR Ph
            <small>GCash · Maya · Bank</small>
          </label>
          <label class="ch-card ch-teller">
            <input type="radio" name="channel" value="teller">
            <i class="fas fa-store"></i> Teller
            <small>Cash sa Load Center</small>
          </label>
        </div>

        <div class="ch-info" id="channelInfo"></div>

        <button type="submit" class="modal-submit" id="topupBtn">
          <i class="fas fa-arrow-right"></i> Magpatuloy
        </button>
      </form>
    </div>
  </div>
</div>

<!-- ══ SEND MONEY MODAL ══════════════════════════════════════ -->
<div class="modal-overlay" id="sendModal" onclick="closeOnOverlay(event,'sendModal')">
  <div class="modal-sheet">
    <div class="modal-handle"></div>
    <div class="modal-title"><i class="fas fa-paper-plane" style="color:#1565C0;margin-right:8px;"></i>Send Money</div>
    <div class="modal-body">
      <form method="POST" action="" onsubmit="handleSubmit(this,'sendBtn')">
        <?= csrfField() ?>
        <input type="hidden" name="wallet_action" value="send">

        <div class="bal-display">
          <span><i class="fas fa-wallet"></i> Available Balance</span>
          <strong>₱ <?= number_format((float)$user['wallet_balance'], 2) ?></strong>
        </div>

        <div class="f-group">
          <label>Recipient Phone Number</label>
          <input type="tel" name="to_phone" placeholder="09XXXXXXXXX" maxlength="11" pattern="09[0-9]{9}" required>
        </div>

        <div class="amt-input-wrap">
          <div class="amt-label">Amount to Send</div>
          <div class="amt-row">
            <span class="amt-peso">₱</span>
            <input type="number" name="amount" id="sendAmount" class="amt-field"
                   placeholder="0.00" min="1" step="0.01" required>
          </div>
        </div>

        <div class="quick-amts">
          <?php foreach ([50,100,200,500,1000] as $qa): ?>
          <button type="button" class="qamt" onclick="document.getElementById('sendAmount').value=<?= $qa ?>">
            ₱<?= number_format($qa) ?>
          </button>
          <?php endforeach; ?>
        </div>

        <div class="f-group">
          <label>Note <span style="color:#9AA0A6;font-weight:400;">(optional)</span></label>
          <textarea name="note" placeholder="For food, payment, etc."></textarea>
        </div>

        <button type="submit" class="modal-submit" id="sendBtn">
          <i class="fas fa-paper-plane"></i> Send Money
        </button>
      </form>
    </div>
  </div>
</div>

<!-- ══ RECEIVE MODAL ════════════════════════════════════════ -->
<div class="modal-overlay" id="receiveModal" onclick="closeOnOverlay(event,'receiveModal')">
  <div class="modal-sheet">
    <div class="modal-handle"></div>
    <div class="modal-title"><i class="fas fa-download" style="color:#6A1B9A;margin-right:8px;"></i>Receive Money</div>
    <div class="modal-body">
      <div style="text-align:center;padding:10px 0 20px;">
        <!-- QR placeholder -->
        <div style="width:160px;height:160px;border-radius:16px;border:3px solid #1A2B5E;
                    display:flex;flex-direction:column;align-items:center;justify-content:center;
                    margin:0 auto 16px;background:#EEF2FF;gap:8px;">
          <i class="fas fa-qrcode" style="font-size:64px;color:#1A2B5E;opacity:0.7;"></i>
          <span style="font-size:11px;color:#1A2B5E;font-weight:700;">Your QR Code</span>
        </div>
        <div style="font-size:16px;font-weight:800;color:#1A2B5E;margin-bottom:4px;">
          <?= htmlspecialchars($user['full_name']) ?>
        </div>
        <div style="font-size:14px;color:#9AA0A6;margin-bottom:16px;">
          <?= htmlspecialchars($user['phone']) ?>
        </div>
        <div style="background:#F5F6FA;border-radius:12px;padding:12px;margin-bottom:16px;font-size:13px;color:#3C4043;line-height:1.6;">
          Share your phone number or QR code<br>for others to send you money.
        </div>
        <button onclick="shareMyInfo()" style="display:flex;align-items:center;justify-content:center;gap:8px;
          width:100%;padding:15px;border-radius:14px;background:linear-gradient(135deg,#1A2B5E,#2D4A8A);
          color:#fff;font-weight:800;font-size:15px;border:none;cursor:pointer;">
          <i class="fas fa-share-alt"></i> Share My Info
        </button>
      </div>
    </div>
  </div>
</div>

<script>
const APP_URL = '<?= APP_URL ?>';

function openModal(id) {
  document.getElementById(id).classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeModal(id) {
  document.getElementById(id).classList.remove('open');
  document.body.style.overflow = '';
}
function closeOnOverlay(e, id) {
  if (e.target === document.getElementById(id)) closeModal(id);
}

function handleSubmit(form, btnId) {
  const btn = document.getElementById(btnId);
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing…';
  }
}

// Channel info display
const CHANNEL_INFO = {
  qrph:   'Makakakuha ka ng <strong>QR Ph</strong> code. I-scan gamit ang GCash, Maya o kahit anong bank app. Papasok agad sa wallet pag kumpirmado na ang bayad.',
  teller: 'Makakakuha ka ng code na ipapakita sa <strong>TriLink Load Center / Teller</strong>. Papasok lang ang pera pag na-confirm na ng teller ang cash payment mo.',
};
function updateChannelInfo() {
  const sel = document.querySelector('input[name="channel"]:checked');
  document.getElementById('channelInfo').innerHTML = sel ? CHANNEL_INFO[sel.value] : '';
}
document.querySelectorAll('input[name="channel"]').forEach(r => r.addEventListener('change', updateChannelInfo));
updateChannelInfo();
<?php if ($openTopup): ?>openModal('topupModal');<?php endif; ?>

function shareMyInfo() {
  const text = 'Send me money via TriLink Wallet!\nName: <?= htmlspecialchars($user['full_name']) ?>\nPhone: <?= htmlspecialchars($user['phone']) ?>';
  if (navigator.share) {
    navigator.share({ title: 'TriLink Wallet', text });
  } else {
    navigator.clipboard?.writeText(text);
    alert('Info copied to clipboard!');
  }
}
</script>
</body>
</html>
