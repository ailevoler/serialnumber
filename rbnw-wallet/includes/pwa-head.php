<?php /* PWA + icon tags, included in every page's <head> */ ?>
<link rel="manifest" href="<?= app_url('manifest.webmanifest') ?>">
<link rel="icon" type="image/png" sizes="32x32" href="<?= asset_url('assets/img/icons/favicon-32.png') ?>">
<link rel="apple-touch-icon" href="<?= asset_url('assets/img/icons/apple-touch-icon.png') ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="RBNW Wallet">
<meta name="application-name" content="RBNW Wallet">
<meta name="theme-color" content="<?= htmlspecialchars($pwaThemeColor ?? '#ffffff') ?>">
