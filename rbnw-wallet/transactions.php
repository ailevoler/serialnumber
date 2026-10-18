<?php
require_once __DIR__ . '/includes/functions.php';
require_login();
$activeNav = 'transactions';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<title>Transactions — <?= APP_NAME ?></title>
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
    <h2>Transactions</h2>
  </div>
  <div class="app-main" style="padding-top:16px;">
    <div class="card" id="txCard">
      <div class="empty-state"><div class="icon"><i class="fa-solid fa-spinner fa-spin"></i></div>Loading transactions...</div>
    </div>
    <button class="btn btn-outline hidden" id="loadMoreBtn">Load More</button>
  </div>
  <?php include __DIR__ . '/includes/bottom-nav.php'; ?>
</div>

<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<script>
let page = 1;
let allItems = [];

async function loadTransactions(append = false) {
  const res = await RBNW.api(`wallet.php?action=transactions&page=${page}&limit=15`);
  const card = document.getElementById('txCard');
  const loadMoreBtn = document.getElementById('loadMoreBtn');

  if (!res.success) {
    card.innerHTML = `<div class="empty-state"><div class="icon"><i class="fa-solid fa-triangle-exclamation"></i></div>Could not load transactions.</div>`;
    return;
  }

  allItems = append ? allItems.concat(res.items) : res.items;

  if (!allItems.length) {
    card.innerHTML = `<div class="empty-state"><div class="icon"><i class="fa-regular fa-clock"></i></div>No transactions yet.</div>`;
    loadMoreBtn.classList.add('hidden');
    return;
  }

  card.innerHTML = allItems.map(tx => `
    <div class="tx-row">
      <div class="tx-icon ${tx.class}"><i class="fa-solid ${tx.sign === '-' ? 'fa-arrow-up' : 'fa-arrow-down'}"></i></div>
      <div class="tx-info">
        <div class="tx-title">${tx.label}${tx.counterparty_name ? ' — ' + tx.counterparty_name : ''}</div>
        <div class="tx-time">${tx.time_ago}</div>
      </div>
      <div class="tx-amount ${tx.class}">${tx.sign}${tx.amount_formatted}</div>
    </div>
  `).join('');

  loadMoreBtn.classList.toggle('hidden', res.items.length < 15);
}

document.getElementById('loadMoreBtn').addEventListener('click', () => {
  page++;
  loadTransactions(true);
});

loadTransactions();
</script>
</body>
</html>
