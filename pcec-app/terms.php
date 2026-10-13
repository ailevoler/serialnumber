<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout_auth.php';
require __DIR__ . '/includes/legal.php';
legal_open('Terms of Service');
?>
<p>PCEC Connect is the member app of the Philippine Council of Evangelical Churches, Inc. (PCEC). By creating an account or using the app you agree to these terms, to the Community Rules below, and to how we handle information as explained in our <a href="<?= e(url('privacy.php')) ?>">Privacy Notice</a>.</p>
<h2>1. Your account</h2>
<ul>
  <li>Use your real name and an email address and phone number you control.</li>
  <li>Keep your password private. You are responsible for what is done with your account.</li>
  <li>If you are under 18, you need a parent or guardian’s permission to use PCEC Connect.</li>
  <li>You can delete your account at any time in Settings, or on the <a href="<?= e(url('delete_account.php')) ?>">Delete account</a> page.</li>
</ul>
<h2 id="community-rules">2. Community Rules</h2>
<p>The Community Feed is for encouraging one another: prayer requests, testimonies, church news and fellowship. When you post, comment or react, please:</p>
<ul>
  <li>Be kind and respectful, even when you disagree.</li>
  <li>Share only what you have the right to share, and only photos of people who agreed to be shown.</li>
  <li>Keep other people’s private information private (phone numbers, addresses, health or family matters) unless they agreed.</li>
</ul>
<p>These are not allowed and will be removed:</p>
<ul>
  <li>Hate speech, harassment, bullying, threats or personal attacks</li>
  <li>Sexual or sexually suggestive content, nudity, and anything that endangers children</li>
  <li>Violence, self-harm encouragement, or illegal activity</li>
  <li>Spam, scams, chain messages, fundraising or selling that PCEC has not approved</li>
  <li>False information meant to mislead, and pretending to be someone else</li>
  <li>Content that infringes someone else’s copyright or privacy</li>
</ul>
<h2>3. Reporting, blocking and moderation</h2>
<ul>
  <li>Report a post with ••• → Report post. Only PCEC administrators see reports, and the person who posted is not told who reported it.</li>
  <li>Block a member with ••• → Block. You won’t see their posts or comments and they can’t comment on or react to yours. Unblock any time in Settings.</li>
  <li>PCEC administrators review reports and may hide or delete content, and suspend accounts, that break these rules. We aim to review reports within 48 hours.</li>
  <li>There are posting limits to prevent spam (for example, 5 posts per 10 minutes).</li>
</ul>
<h2>4. Your content</h2>
<p>You keep ownership of what you post. You allow PCEC to show it to other members inside the app for as long as it stays posted. Delete it any time; deleting your account deletes all of it.</p>
<h2>5. Giving</h2>
<p>Gifts and event fees are paid with QR Ph through PayMongo, a licensed payment provider. PCEC Connect never sees your bank or e-wallet login. A processing fee may be added to the amount so that PCEC receives your full gift; it is always shown before you pay. You receive a reference number and receipt for every payment. Please contact the PCEC office about refunds or if you are unsure about a payment.</p>
<h2>6. Changes and contact</h2>
<p>We may update these terms; we will tell members in the app when we do. Questions: contact the PCEC office through Contact Us.</p>
<?php legal_close(); ?>
