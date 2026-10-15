<?php
require_once __DIR__.'/referrals.php';
try {
 if(!referral_ready())return;
 $link=rtrim(APP_URL,'/').'/auth/register.php?ref='.referral_code($uid);
 $count=(int)cash_query('SELECT COUNT(*) FROM referral_members WHERE referrer_id=?',[$uid])->fetchColumn();
 $total=(int)cash_query('SELECT COALESCE(SUM(amount_minor),0) FROM referral_rewards WHERE referrer_id=? AND trigger_type="lot_purchase"',[$uid])->fetchColumn();
 $settings=cash_query('SELECT * FROM referral_settings WHERE id=1')->fetch();
 $bonusPlans=!referral_lot_ensure_schema()?[]:cash_query('SELECT name,price_minor,referral_bps FROM cash_lot_plans WHERE enabled=1 ORDER BY price_minor,id')->fetchAll();
}catch(Throwable $e){error_log('[referrals] Dashboard unavailable');echo '<div class="notice">Referral details are temporarily unavailable.</div>';return;}
?>
<section class="panel cash-body"><div class="panel-head"><div><h3>Invite friends. Earn referral bonuses.</h3><p>Share your personal link so new Traders can register through you.</p></div></div>
<label for="referral-link">Your referral link</label><div class="cash-actions"><input id="referral-link" type="url" readonly value="<?= e($link) ?>" style="flex:1;min-width:180px"><button class="button" type="button" id="referral-copy">Copy link</button></div><p id="referral-copy-status" role="status" aria-live="polite"></p>
<p><strong><?= $count ?></strong> registered referrals · <strong><?= cash_money($total) ?></strong> total Lot Referral Bonuses</p>
<p><?php if((int)$settings['enabled'] && $settings['trigger_type']==='lot_purchase'): ?>Lot Referral Bonus on each completed Lot purchase from your referred Trader<?php if($bonusPlans): ?>, depending on the Lot: <?php foreach($bonusPlans as $i=>$bp): ?><?= $i?' · ':'' ?><strong><?= e($bp['name']) ?> <?= referral_lot_label($bp,$settings) ?></strong><?php endforeach ?>.<?php else: ?>: <strong><?= $settings['bonus_type']==='percentage'?e(number_format($settings['value_minor']/100,2)).'% of the USD Lot purchase price':cash_money((int)$settings['value_minor']) ?></strong>.<?php endif ?> Bonuses are credited as withdrawable USD earnings.<?php else: ?>Referral links are active. Bonus payouts are currently disabled by the administrator.<?php endif ?></p><small>Only registrations completed through your link count. The bonus settings in effect when a Lot is purchased apply; past purchases are not recalculated.</small></section>
<script src="/assets/js/referrals.js?v=1" defer></script>
