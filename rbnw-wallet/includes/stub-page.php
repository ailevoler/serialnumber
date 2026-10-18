<?php
/**
 * Renders a branded "coming soon" screen for a module that's on the
 * roadmap but not yet wired up. Call render_stub() then exit.
 */
function render_stub(string $title, string $icon, string $description, string $eta = 'Staging phase'): void
{
    require_once __DIR__ . '/functions.php';
    require_login();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($title) ?> — <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    </head>
    <body>
    <div class="app-shell">
      <div class="flow-header">
        <button class="back-btn" onclick="history.back()" style="background:none;border:none;"><i class="fa-solid fa-arrow-left"></i></button>
        <h2><?= htmlspecialchars($title) ?></h2>
      </div>
      <div class="flow-body" style="display:flex;flex-direction:column;align-items:center;text-align:center;padding-top:60px;">
        <div class="icon-tile" style="width:76px;height:76px;border-radius:24px;font-size:30px;margin-bottom:18px;">
          <i class="fa-solid <?= htmlspecialchars($icon) ?>"></i>
        </div>
        <h2 style="margin:0 0 8px;"><?= htmlspecialchars($title) ?></h2>
        <p style="color:var(--text-muted);font-size:13.5px;max-width:300px;"><?= htmlspecialchars($description) ?></p>
        <div class="alert alert-info" style="margin-top:20px;"><i class="fa-solid fa-clock"></i> Coming up next: <?= htmlspecialchars($eta) ?></div>
        <button class="btn btn-primary" style="max-width:220px;margin-top:20px;" onclick="location.href='dashboard.php'">Back to Home</button>
      </div>
      <?php $activeNav = 'home'; include __DIR__ . '/bottom-nav.php'; ?>
    </div>
    </body>
    </html>
    <?php
}
