<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout_auth.php';
require __DIR__ . '/includes/legal.php';
$office = implode(' · ', array_filter([setting('about_office_email'), setting('about_office_phone'), setting('about_office_location')])) ?: 'the PCEC office (see the About page)';
legal_open('Privacy Notice');
?>
<p>This notice explains how the Philippine Council of Evangelical Churches, Inc. (PCEC) handles your personal information in PCEC Connect, as required by the Data Privacy Act of 2012 (Republic Act No. 10173) and its rules.</p>
<h2>Who is responsible</h2>
<p>PCEC is the personal information controller. Data Protection Officer (DPO): [name to be appointed by PCEC]. Contact: <?= e($office) ?>.</p>
<h2>What we collect</h2>
<ul>
  <li>Account details: name, email, password (stored only in scrambled form), mobile number and its country.</li>
  <li>Member details you add: church name, address, profile photo, membership dates.</li>
  <li>Sensitive personal information: because PCEC Connect is a church membership app, being a member reveals your religious affiliation. The law treats this as sensitive, and we only process it with your consent.</li>
  <li>What you share: Community Feed posts, photos, comments, reactions, reports and blocks; Media uploads; chat messages; event registrations; Contact Us messages.</li>
  <li>Payments: the amount, reference number, date and status of your gifts and event fees. Your bank or e-wallet details stay with PayMongo and your bank.</li>
  <li>Technical basics needed to keep you signed in and the app working. We don’t use advertising or tracking cookies, and we don’t sell or rent your information.</li>
</ul>
<h2>Why we use it</h2>
<ul>
  <li>To recognise you as a member and run your account (including security, and preventing spam and abuse).</li>
  <li>To show you church news, events and NDC updates, and to let you take part in the Community Feed and Chat.</li>
  <li>To process and record your gifts and event registrations.</li>
  <li>To reply to your messages and contact you about your membership.</li>
  <li>To meet legal obligations.</li>
</ul>
<h2>Our legal basis</h2>
<p>Your consent, given when you create your account (needed for sensitive personal information); the membership relationship between you and PCEC; and PCEC’s legitimate interest in keeping the app safe.</p>
<h2>Who can see it</h2>
<ul>
  <li>Your contact details (email, phone, address) are visible only to you and PCEC administrators.</li>
  <li>Your name, church, posts, comments and reactions are visible to other members inside the app.</li>
  <li>Our service providers process data for us under contract: our web host (database and file storage), PayMongo (payments) and our email provider. Your information is protected in transit (HTTPS).</li>
  <li>We share information with authorities only when the law requires it.</li>
</ul>
<h2>How long we keep it</h2>
<p>Account information is kept while your account exists. Deleting your account removes your profile, posts, photos, comments and messages. Payment records are kept for accounting and legal requirements without your profile. Copies may remain in backups until their retention period ends.</p>
<h2>Your rights</h2>
<p>Under the Data Privacy Act you may: be informed; access your information; object to processing; correct it (most details can be changed in Edit Profile); have it erased or blocked (Settings → Delete my account, or the <a href="<?= e(url('delete_account.php')) ?>">Delete account</a> page); get a copy in a common format (data portability); claim damages; and file a complaint with the National Privacy Commission (privacy.gov.ph). To use a right, contact our DPO.</p>
<h2>Security and breaches</h2>
<p>We use encryption in transit, access rules, and limited administrator access. For a personal-data breach that requires notification under the law, we will notify the National Privacy Commission and affected people within 72 hours of learning or reasonably believing that it happened.</p>
<h2>Children</h2>
<p>Members under 18 need a parent or guardian’s consent to use the app.</p>
<h2>Changes</h2>
<p>We’ll post any change here and tell members in the app.</p>
<?php legal_close(); ?>
