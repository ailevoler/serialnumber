<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/billers.php';
require_login();

// Local Aklan/Boracay billers (seeded in rbnw_billers). Names are stored as
// "SHORT - Full Legal Name"; show the short name big and the rest below.
$local = db()->query("SELECT id, name, category, logo FROM rbnw_billers WHERE is_active = 1 ORDER BY sort_order, name")->fetchAll();
$local = array_map(function (array $b): array {
    [$short, $full] = array_pad(array_map('trim', explode(' - ', $b['name'], 2)), 2, '');
    $b['short'] = $short;
    $b['full']  = $full ?: $b['category'];
    return $b;
}, $local);

$national = national_billers();
$categories = array_values(array_unique(array_column($national, 1)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<title>Pay Bills — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
.bills-search {
  position: sticky; top: 0; z-index: 9;
  padding: 12px 16px 10px; background: var(--bg);
}
.bills-search .input-wrap { background: #fff; }
.bills-tabs { display: flex; gap: 8px; margin-top: 10px; overflow-x: auto; scrollbar-width: none; }
.bills-tabs::-webkit-scrollbar { display: none; }
.bills-tabs button {
  flex-shrink: 0; height: 34px; padding: 0 14px; border-radius: 999px;
  border: 1.5px solid var(--border); background: #fff;
  font-size: 13px; font-weight: 600; color: var(--text-body);
}
.bills-tabs button.active { background: var(--rbnw-blue); border-color: var(--rbnw-blue); color: #fff; }

.bills-body { padding: 4px 16px calc(110px + env(safe-area-inset-bottom)); }
.bills-section-title {
  display: flex; align-items: baseline; justify-content: space-between;
  margin: 18px 2px 10px; font-size: 15px; font-weight: 700;
}
.bills-section-title small { font-size: 12px; font-weight: 500; color: var(--text-muted); }

.biller-logo {
  width: 48px; height: 48px; flex-shrink: 0;
  border-radius: 14px; background: linear-gradient(160deg, #fff 0%, var(--rbnw-blue-50) 100%);
  border: 1px solid #dbe6f5;
  display: flex; align-items: center; justify-content: center;
  font-size: 15px; font-weight: 800; color: var(--rbnw-blue); letter-spacing: 0.3px;
  overflow: hidden;
}
.biller-logo.has-img { background: #fff; padding: 6px; }
.biller-logo img { width: 100%; height: 100%; object-fit: contain; }

/* Local billers: logo grid */
.local-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px 8px; }
.local-tile {
  display: flex; flex-direction: column; align-items: center; gap: 7px; text-align: center;
  background: none; border: none; padding: 0; color: inherit;
}
.local-tile .biller-logo { width: 60px; height: 60px; border-radius: 18px; font-size: 17px; box-shadow: var(--shadow-soft); }
.local-tile span.name { font-size: 11.5px; font-weight: 600; line-height: 1.25; color: var(--text-dark); }

/* National directory list */
.biller-list { background: #fff; border-radius: var(--radius-md); box-shadow: var(--shadow-soft); overflow: hidden; }
.biller-row {
  display: flex; align-items: center; gap: 12px; width: 100%;
  padding: 12px 14px; background: none; border: none; text-align: left; color: inherit;
  border-bottom: 1px solid var(--border);
}
.biller-row:last-child { border-bottom: none; }
.biller-row:active { background: var(--rbnw-blue-50); }
.biller-row .info { flex: 1; min-width: 0; }
.biller-row .info strong { display: block; font-size: 14px; font-weight: 700; }
.biller-row .info small { font-size: 12px; color: var(--text-muted); }
.biller-row .chev { color: var(--text-faint); font-size: 13px; }
.via { display: flex; gap: 4px; margin-top: 4px; }
.via span {
  font-size: 10px; font-weight: 700; letter-spacing: 0.3px; padding: 2px 7px; border-radius: 999px;
  background: var(--rbnw-blue-50); color: var(--rbnw-blue);
}
.via span.bayad { background: #e7f6ec; color: #1b7a3b; }
.cat-head {
  padding: 10px 14px 6px; font-size: 11.5px; font-weight: 700; letter-spacing: 0.6px;
  text-transform: uppercase; color: var(--text-muted); background: #fafbfd;
  border-bottom: 1px solid var(--border);
}
.no-results { text-align: center; padding: 30px 16px; color: var(--text-muted); font-size: 13.5px; }
.no-results i { display: block; font-size: 28px; margin-bottom: 8px; opacity: .5; }
</style>
</head>
<body>
<div class="app-shell">
  <div class="flow-header">
    <button class="back-btn" onclick="RBNW.goBack('dashboard.php')" style="background:none;border:none;" aria-label="Back"><i class="fa-solid fa-arrow-left"></i></button>
    <h2>Pay Bills</h2>
  </div>

  <div class="bills-search">
    <div class="input-wrap">
      <i class="icon fa-solid fa-magnifying-glass"></i>
      <input type="search" id="billerSearch" placeholder="Search billers (e.g. Meralco, SSS)" autocomplete="off">
    </div>
    <div class="bills-tabs" role="tablist">
      <button type="button" class="active" data-net="all">All</button>
      <button type="button" data-net="ECPay">ECPay</button>
      <button type="button" data-net="Bayad">Bayad</button>
    </div>
  </div>

  <div class="bills-body">
    <section id="localSection">
      <div class="bills-section-title">Aklan &amp; Boracay billers <small>Partner billers</small></div>
      <div class="local-grid">
        <?php foreach ($local as $b): ?>
          <button type="button" class="local-tile biller-pick"
                  data-name="<?= htmlspecialchars($b['short']) ?>"
                  data-search="<?= htmlspecialchars(strtolower($b['name'] . ' ' . $b['category'])) ?>">
            <?= biller_logo_html($b['short'], $b['logo']) ?>
            <span class="name"><?= htmlspecialchars($b['short']) ?></span>
          </button>
        <?php endforeach; ?>
      </div>
    </section>

    <section>
      <div class="bills-section-title">All billers <small id="billerCount"><?= count($national) ?> billers</small></div>
      <div class="biller-list" id="billerList">
        <?php foreach ($categories as $cat): ?>
          <div class="cat-head" data-cat="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></div>
          <?php foreach ($national as [$name, $c, $via]): if ($c !== $cat) continue; ?>
            <button type="button" class="biller-row biller-pick"
                    data-name="<?= htmlspecialchars($name) ?>" data-cat="<?= htmlspecialchars($cat) ?>"
                    data-via="<?= htmlspecialchars(implode(',', $via)) ?>"
                    data-search="<?= htmlspecialchars(strtolower("$name $cat " . implode(' ', $via))) ?>">
              <?= biller_logo_html($name) ?>
              <span class="info">
                <strong><?= htmlspecialchars($name) ?></strong>
                <span class="via">
                  <?php foreach ($via as $v): ?><span class="<?= strtolower($v) ?>"><?= $v ?></span><?php endforeach; ?>
                </span>
              </span>
              <i class="fa-solid fa-chevron-right chev"></i>
            </button>
          <?php endforeach; ?>
        <?php endforeach; ?>
        <div class="no-results hidden" id="noResults"><i class="fa-solid fa-magnifying-glass"></i>No billers match your search.</div>
      </div>
    </section>
  </div>

  <?php $activeNav = 'home'; include __DIR__ . '/includes/bottom-nav.php'; ?>
</div>

<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<script>
(() => {
  const search = document.getElementById('billerSearch');
  const rows = [...document.querySelectorAll('#billerList .biller-row')];
  const heads = [...document.querySelectorAll('#billerList .cat-head')];
  const tiles = [...document.querySelectorAll('#localSection .local-tile')];
  const localSection = document.getElementById('localSection');
  const count = document.getElementById('billerCount');
  const empty = document.getElementById('noResults');
  let network = 'all';

  function apply() {
    const q = search.value.trim().toLowerCase();
    let shown = 0;
    rows.forEach(r => {
      const ok = (!q || r.dataset.search.includes(q)) && (network === 'all' || r.dataset.via.split(',').includes(network));
      r.classList.toggle('hidden', !ok);
      if (ok) shown++;
    });
    heads.forEach(h => {
      h.classList.toggle('hidden', !rows.some(r => r.dataset.cat === h.dataset.cat && !r.classList.contains('hidden')));
    });
    let localShown = 0;
    tiles.forEach(t => {
      const ok = !q || t.dataset.search.includes(q);
      t.classList.toggle('hidden', !ok);
      if (ok) localShown++;
    });
    // local billers are paid directly through RBNW, not ECPay/Bayad
    localSection.classList.toggle('hidden', network !== 'all' || localShown === 0);
    count.textContent = shown + (shown === 1 ? ' biller' : ' billers');
    empty.classList.toggle('hidden', shown > 0);
  }

  search.addEventListener('input', apply);
  document.querySelectorAll('.bills-tabs button').forEach(b => b.addEventListener('click', () => {
    document.querySelectorAll('.bills-tabs button').forEach(x => x.classList.toggle('active', x === b));
    network = b.dataset.net;
    apply();
  }));

  document.querySelectorAll('.biller-pick').forEach(el => el.addEventListener('click', () => {
    RBNW.toast(`Bill payment for ${el.dataset.name} is coming soon.`, 'info');
  }));
})();
</script>
</body>
</html>
