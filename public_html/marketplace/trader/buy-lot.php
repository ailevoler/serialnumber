<?php
require_once __DIR__.'/../includes/bootstrap.php';require_once __DIR__.'/../includes/cash_lots.php';require_once __DIR__.'/../includes/cash_layout.php';
$user=require_role(ROLE_TRADER);$uid=(int)$user['id'];
if(is_post()){
 csrf_verify();try{
  if(input('confirm')!=='1')throw new DomainException('Confirm the Lot purchase.');
  $lot=cash_lot_buy($uid,(int)input('plan'),(int)input('price'),input('request_key'));
  flash_set('success','Lot #'.$lot.' purchased successfully.');redirect('/trader/lots.php');
 }catch(DomainException $e){flash_set('error',$e->getMessage());}catch(Throwable $e){error_log('[Lot purchase] Transaction failed: '.$e->getMessage());flash_set('error','Purchase could not complete. Check My Lots before trying again.');}
 redirect('/trader/buy-lot.php');
}
cash_header($user,'Buy a Lot');
try{lot_incentive_ensure_schema();$plans=cash_query('SELECT * FROM cash_lot_plans WHERE enabled=1 ORDER BY id')->fetchAll();$w=cash_wallet($uid);}catch(Throwable $e){echo '<div class="notice">Lot purchases are not configured yet. The Lot timeframe migration may still need to be imported.</div>';cash_footer();exit;}
?>
<section class="panel cash-body"><h3>Choose your Lot</h3><p>Available balance: <?= cash_money((int)$w['available']) ?>. Each purchase snapshots its listed timeframe and daily earnings percentage. Earnings are credited after each completed hour, up to the Lot end date.</p><a href="/trader/lots.php">My Lots</a></section>
<div class="dashboard-grid"><?php foreach($plans as $p): $daily=(int)$p['price_minor']*(int)($p['daily_earnings_bps']??0)/10000;$hourly=$daily/24; ?><section class="panel cash-body"><h3><?= e($p['name']) ?></h3><p><?= (int)$p['capacity'] ?> product spaces</p><h3><?= cash_money((int)$p['price_minor']) ?></h3><div class="stats-grid"><article class="stat purple"><span>Timeframe</span><strong><?= (int)($p['timeframe_days']??30) ?> days</strong><small>Starts on purchase</small></article><article class="stat blue"><span>Daily earnings</span><strong><?= number_format(((int)($p['daily_earnings_bps']??0))/100,2) ?>%</strong><small>≈ <?= cash_money((int)round($daily)) ?>/day</small></article><article class="stat orchid"><span>Hourly earning</span><strong><?= cash_money((int)round($hourly)) ?></strong><small>Daily amount ÷ 24</small></article></div><?php $incentiveTerms=lot_incentive_terms($p); if($incentiveTerms!==''): ?><p class="chip">Bonus: <?= $incentiveTerms ?></p><?php endif ?><form method="post"><?= csrf_field() ?><input type="hidden" name="request_key" value="<?= generate_uuid() ?>"><input type="hidden" name="plan" value="<?= (int)$p['id'] ?>"><input type="hidden" name="price" value="<?= (int)$p['price_minor'] ?>"><label><input type="checkbox" name="confirm" value="1" required style="width:auto"> I confirm this purchase at the listed USD price, timeframe and earnings terms.</label><button class="button" <?= (int)$w['available']<(int)$p['price_minor']?'disabled':'' ?>>Buy Lot</button></form></section><?php endforeach ?></div>
<?php if(!$plans): ?><section class="panel cash-body">No Lot plans are currently enabled.</section><?php endif ?>
<p class="fine-print">Configured earnings are credited by the platform according to the Lot terms. Displayed estimates can vary slightly by cent-level rounding across hourly credits.</p>
<?php cash_footer(); ?>
