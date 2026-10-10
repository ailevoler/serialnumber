<?php
/** Shell for onboarding / login / register / password pages. */
function auth_head(string $title, string $bodyClass = ''): void
{ ?>
<!doctype html>
<html lang="<?= lang() === 'fil' ? 'fil' : 'en' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0b2a6b">
<title><?= e($title) ?> · PCEC</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
<link rel="icon" href="<?= e(url('assets/img/favicon.svg')) ?>" type="image/svg+xml">
</head>
<body class="auth-body <?= e($bodyClass) ?>">
<?php }

function auth_brand(bool $compact = false): void
{ ?>
  <div class="brand-block<?= $compact ? ' compact' : '' ?>">
    <?= pcec_logo() ?>
    <div class="brand-name">Philippine Council of<br>Evangelical Churches</div>
    <div class="brand-tag"><?= e(APP_TAGLINE) ?></div>
  </div>
<?php }

function auth_flashes(): void
{
    foreach (flashes() as $f) {
        echo '<div class="alert alert-' . e($f['type']) . '">' . e($f['msg']) . '</div>';
    }
}

function auth_foot(): void
{ ?>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
<?php }
