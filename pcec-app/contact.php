<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';
$me = require_login();
$errors = [];

if (is_post()) {
    csrf_check();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $message = trim($_POST['message'] ?? '');
    if ($name === '') $errors[] = 'Please enter your name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    if ($message === '') $errors[] = 'Please write your message.';
    if ((int) q_val('SELECT COUNT(*) FROM contact_messages WHERE user_id = ? AND created_at > NOW() - INTERVAL 1 HOUR', [$me['id']]) >= 5) {
        $errors[] = 'You have sent several messages recently. Please wait a while before sending another.';
    }
    if (!$errors) {
        q('INSERT INTO contact_messages (user_id, name, email, subject, message) VALUES (?,?,?,?,?)',
            [$me['id'], mb_substr($name, 0, 160), mb_substr($email, 0, 200), mb_substr(trim($_POST['subject'] ?? ''), 0, 160) ?: null, mb_substr($message, 0, 4000)]);
        foreach (q_all("SELECT id FROM users WHERE role = 'admin'") as $a) {
            notify((int) $a['id'], (int) $me['id'], 'message', 'New Contact Us message from ' . $name . '.', 'admin/inbox.php');
        }
        flash('success', 'Thank you! Your message was sent to the PCEC office.');
        redirect('contact.php');
    }
}
$office = [
    ['pin', 'Headquarters', setting('about_office_location'), null],
    ['phone', 'Phone Number', setting('about_office_phone'), setting('about_office_phone') ? 'tel:' . preg_replace('/[^\d+]/', '', setting('about_office_phone')) : null],
    ['mail', 'Email Address', setting('about_office_email'), setting('about_office_email') ? 'mailto:' . setting('about_office_email') : null],
];
$office = array_filter($office, fn($r) => trim((string) $r[2]) !== '');

$pageTitle = 'Contact Us';
$activeNav = 'contact';
require __DIR__ . '/includes/header.php';
?>
<header class="page-intro"><h2>Contact Us</h2><p>Reach out to the Philippine Council of Evangelical Churches for inquiries, support, or partnership opportunities.</p></header>
<section class="card">
  <p class="mc-label dark">OFFICE INFORMATION</p>
  <?php foreach ($office as [$ic, $label, $value, $href]): ?>
    <div class="info-row"><span class="info-icon"><?= icon($ic) ?></span><div><small><?= e($label) ?></small>
      <?php if ($href): ?><a href="<?= e($href) ?>"><?= e($value) ?></a><?php else: ?><p><?= e($value) ?></p><?php endif; ?></div></div>
  <?php endforeach; ?>
  <?php if (!$office): ?><p class="muted small">Office details will appear here once the PCEC office adds them.</p><?php endif; ?>
</section>
<section class="card">
  <p class="mc-label dark">SEND US A MESSAGE</p>
  <?php foreach ($errors as $m): ?><div class="alert alert-error"><?= e($m) ?></div><?php endforeach; ?>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <label>Full Name<input name="name" value="<?= e($_POST['name'] ?? display_name($me)) ?>" maxlength="160" autocomplete="name" required></label>
    <label>Email Address<input type="email" name="email" value="<?= e($_POST['email'] ?? $me['email']) ?>" maxlength="200" autocomplete="email" required></label>
    <label>Subject<input name="subject" value="<?= e($_POST['subject'] ?? ($_GET['subject'] ?? '')) ?>" maxlength="160" placeholder="What is this regarding?"></label>
    <label>Message<textarea name="message" rows="5" maxlength="4000" placeholder="Type your message here..." required><?= e($_POST['message'] ?? '') ?></textarea></label>
    <button class="btn btn-gradient btn-lg btn-block"><?= icon('send') ?> Submit Inquiry</button>
  </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
