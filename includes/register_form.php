<?php
/**
 * Shared registration form fields.
 * Expects: $form (array of old values), $lockSponsor (bool), $codeOptions (array of codes or null)
 */
$v = fn($k, $d = '') => e($form[$k] ?? $d);
?>
<div class="section-title">Activation Code</div>
<div class="form-grid">
  <div class="form-group">
    <label for="code">Activation Code</label>
    <?php if (!empty($codeOptions)): ?>
      <select class="input" id="code" name="code" required>
        <option value="">— Select one of your codes —</option>
        <?php foreach ($codeOptions as $c): ?>
          <option value="<?= e($c['code']) ?>" <?= ($form['code'] ?? '') === $c['code'] ? 'selected' : '' ?>><?= e($c['code']) ?> · <?= e($c['package_name']) ?><?= $c['code_type'] === 'free' ? ' (FREE)' : '' ?></option>
        <?php endforeach; ?>
      </select>
      <div class="help">The PIN is filled automatically for your own codes.</div>
    <?php else: ?>
      <input class="input mono" id="code" name="code" value="<?= $v('code') ?>" placeholder="XGXXXXXXXX" required>
    <?php endif; ?>
  </div>
  <?php if (empty($codeOptions)): ?>
  <div class="form-group">
    <label for="pin">PIN</label>
    <input class="input mono" id="pin" name="pin" value="<?= $v('pin') ?>" inputmode="numeric" required>
  </div>
  <?php endif; ?>
</div>

<div class="section-title">Sponsor &amp; Placement</div>
<div class="form-grid">
  <div class="form-group">
    <label for="sponsor">Sponsor Username</label>
    <input class="input" id="sponsor" name="sponsor" value="<?= $v('sponsor') ?>" <?= !empty($lockSponsor) ? 'readonly' : '' ?> required>
  </div>
  <div class="form-group">
    <label for="placement">Placement Username <span class="text-muted">(optional)</span></label>
    <input class="input" id="placement" name="placement" value="<?= $v('placement') ?>" placeholder="Auto-place under sponsor">
  </div>
  <div class="form-group full">
    <label>Position</label>
    <div class="radio-row">
      <?php foreach (['' => 'Auto', 'L' => 'Left', 'R' => 'Right'] as $val => $lab): ?>
        <label class="radio-pill"><input type="radio" name="position" value="<?= $val ?>" <?= ($form['position'] ?? '') === $val ? 'checked' : '' ?>><span><?= $lab ?></span></label>
      <?php endforeach; ?>
    </div>
    <div class="help">Auto fills the first open slot. Left/Right without placement goes to the outermost slot of that leg.</div>
  </div>
</div>

<div class="section-title">Personal Information</div>
<div class="form-grid">
  <div class="form-group"><label for="first_name">First Name</label><input class="input" id="first_name" name="first_name" value="<?= $v('first_name') ?>" required></div>
  <div class="form-group"><label for="last_name">Last Name</label><input class="input" id="last_name" name="last_name" value="<?= $v('last_name') ?>" required></div>
  <div class="form-group"><label for="email">Email</label><input class="input" id="email" type="email" name="email" value="<?= $v('email') ?>" required></div>
  <div class="form-group"><label for="mobile">Mobile Number</label><input class="input" id="mobile" name="mobile" value="<?= $v('mobile') ?>" placeholder="09XXXXXXXXX"></div>
  <div class="form-group full"><label for="address">Address</label><input class="input" id="address" name="address" value="<?= $v('address') ?>"></div>
</div>

<div class="section-title">Login Details</div>
<div class="form-grid">
  <div class="form-group"><label for="reg_username">Username</label><input class="input" id="reg_username" name="username" value="<?= $v('username') ?>" pattern="[A-Za-z0-9_.]{4,30}" required></div>
  <div class="form-group"><label for="reg_password">Password</label><input class="input" id="reg_password" type="password" name="password" minlength="8" required><div class="help">At least 8 characters with letters and numbers.</div></div>
</div>
