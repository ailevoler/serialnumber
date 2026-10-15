<?php
/** Shared lead/prospect fields. Expects $lead (array of current values, may be empty). */
$lv = fn($k, $d = '') => e($lead[$k] ?? $d);
?>
<div class="form-grid">
  <div class="form-group"><label>Full Name</label><input class="input" name="name" value="<?= $lv('name') ?>" required></div>
  <div class="form-group"><label>Mobile</label><input class="input" name="mobile" value="<?= $lv('mobile') ?>" placeholder="09XXXXXXXXX"></div>
  <div class="form-group"><label>Email</label><input class="input" type="email" name="email" value="<?= $lv('email') ?>"></div>
  <div class="form-group"><label>Location</label><input class="input" name="location" value="<?= $lv('location') ?>" placeholder="City / Province"></div>
  <div class="form-group"><label>Source</label>
    <select class="input" name="source"><?php foreach (crm_sources() as $s): ?><option <?= ($lead['source'] ?? '') === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
  <div class="form-group"><label>Interested in</label>
    <select class="input" name="interest"><?php foreach (['both' => 'Products & Business', 'product' => 'Products only', 'business' => 'Business opportunity'] as $k => $l): ?><option value="<?= $k ?>" <?= ($lead['interest'] ?? 'both') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div class="form-group"><label>Stage</label>
    <select class="input" name="status"><?php foreach (crm_statuses() as $k => $s): ?><option value="<?= $k ?>" <?= ($lead['status'] ?? 'new') === $k ? 'selected' : '' ?>><?= e($s['label']) ?></option><?php endforeach; ?></select></div>
  <div class="form-group"><label>Next Follow-up</label><input class="input" type="date" name="next_follow_up" value="<?= $lv('next_follow_up') ?>"></div>
  <div class="form-group full"><label>Notes</label><textarea class="input" name="notes" rows="3"><?= $lv('notes') ?></textarea></div>
</div>
