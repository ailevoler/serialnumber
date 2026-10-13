<?php
require __DIR__ . '/_admin.php';

$fields = [
    'about_org_name' => ['Organization Name', 200, 'text'], 'about_text' => ['Mission / About Text', 4000, 'textarea'],
    'about_established_year' => ['Year Established', 4, 'number'], 'about_office_location' => ['Office Address', 300, 'text'],
    'about_office_phone' => ['Office Phone', 60, 'tel'], 'about_office_email' => ['Office Email', 200, 'email'],
    'about_website' => ['Official Website (https://…)', 200, 'url'],
];
if (is_post()) {
    csrf_check();
    $site = trim((string) ($_POST['about_website'] ?? ''));
    $mail = trim((string) ($_POST['about_office_email'] ?? ''));
    if ($site !== '' && !preg_match('~^https?://~i', $site)) {
        flash('error', 'The website must start with https://');
    } elseif ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Please enter a valid office email.');
    } else {
        foreach ($fields as $k => [, $len]) setting_set($k, mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $len));
        flash('success', 'Saved.');
    }
    redirect('admin/about.php');
}

$pageTitle = 'Admin';
$activeNav = 'admin';
require __DIR__ . '/../includes/header.php';
admin_tabs('about');
?>
<div class="admin-wrap">
  <header class="dash-header"><div><h2>About &amp; Office Info</h2><p>Shown on About, Contact Us and in the Privacy Notice</p></div></header>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <?php foreach ($fields as $k => [$label, $len, $type]): ?>
      <?php if ($type === 'textarea'): ?>
        <label><?= e($label) ?><textarea name="<?= $k ?>" rows="6" maxlength="<?= $len ?>" placeholder="Leave empty to use the default mission statement"><?= e(setting($k)) ?></textarea></label>
      <?php else: ?>
        <label><?= e($label) ?><input type="<?= $type ?>" name="<?= $k ?>" value="<?= e(setting($k)) ?>" maxlength="<?= $len ?>"></label>
      <?php endif; ?>
    <?php endforeach; ?>
    <button class="btn btn-gradient btn-block"><?= icon('check') ?> Save</button>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
