<?php
require_once __DIR__.'/cash_insights.php';
require_once __DIR__.'/lot_incentives.php';
if(!$usdLotsReady)return;
$spaceCounts=[];$earnedByLot=[];$spacesReady=true;$earningsReady=true;
try{foreach(cash_query('SELECT s.lot_id,COUNT(*) AS occupied FROM cash_lot_slots s JOIN cash_lots l ON l.id=s.lot_id WHERE l.user_id=? GROUP BY s.lot_id',[$uid])->fetchAll() as $count)$spaceCounts[(int)$count['lot_id']]=(int)$count['occupied'];}catch(Throwable $e){$spacesReady=false;}
try{foreach(cash_query('SELECT lot_id,COALESCE(SUM(amount_minor),0) earned FROM cash_lot_earnings WHERE user_id=? GROUP BY lot_id',[$uid])->fetchAll() as $row)$earnedByLot[(int)$row['lot_id']]=(int)$row['earned'];}catch(Throwable $e){$earningsReady=false;}
/** Trader-facing incentive status (safe HTML) for one purchased Lot, or '' when the Lot has no incentive. */
function nex_lot_incentive_note(array $p,int $uid): string {
 $amount=(int)($p['incentive_minor']??0);if($amount<=0)return '';
 $status=(string)($p['incentive_status']??'');$required=(int)($p['incentive_invites']??0);
 if($status==='credited')return cash_money($amount).' incentive credited to Withdrawable Earnings';
 if($status==='missed')return cash_money($amount).' incentive not earned ('.(int)$p['incentive_invites_counted'].' / '.$required.' invites)';
 if($required<=0)return cash_money($amount).' incentive on completion';
 static $counts=[];$until=min(time(),(int)strtotime((string)($p['earnings_ends_at']??'')));$key=date('Y-m-d H:i:s',$until);
 try{$counts[$key]??=lot_incentive_invite_count($uid,$key);}catch(Throwable $e){return cash_money($amount).' incentive · invite '.$required;}
 return cash_money($amount).' incentive · '.min($required,$counts[$key]).' / '.$required.' invites';
}
$activeLots=[];$completedLots=[];
foreach($purchasedLots as $p){
 $maxHours=max(0,(int)($p['timeframe_days']??0)*24);$credited=min($maxHours,max(0,(int)($p['credited_hours']??0)));
 $matured=$maxHours>0&&$credited>=$maxHours&&!empty($p['earnings_ends_at'])&&strtotime((string)$p['earnings_ends_at'])<=time();
 if($matured){ if(empty($p['earnings_transferred_at'])) $completedLots[]=$p; } else $activeLots[]=$p;
}
function nex_lot_rows(array $rows,array $spaceCounts,array $earnedByLot,bool $spacesReady,bool $earningsReady,bool $completed=false): void { global $uid; foreach($rows as $p):
 $bps=(int)($p['daily_earnings_bps']??0);$hourly=(int)round(((int)$p['price_minor']*$bps/10000)/24);$maxHours=(int)($p['timeframe_days']??0)*24;$credited=min($maxHours,(int)($p['credited_hours']??0));$earned=(int)($earnedByLot[(int)$p['id']]??0);$transferred=!empty($p['earnings_transferred_at']); ?>
<tr><td>#<?= (int)$p['id'] ?> <?= e($p['plan_name']) ?><small class="table-subtext"><?= $completed?'Completed':'Active' ?></small><?php $incentiveNote=nex_lot_incentive_note($p,(int)$uid); if($incentiveNote!==''): ?><small class="table-subtext"><?= $incentiveNote ?></small><?php endif ?></td><td><?php if($spacesReady): ?><a class="space-count-link" href="/trader/lot-spaces.php?id=<?= (int)$p['id'] ?>&amp;view=products"><strong><?= (int)($spaceCounts[(int)$p['id']]??0) ?> / <?= (int)$p['capacity'] ?></strong> filled</a><?php else: ?><?= (int)$p['capacity'] ?> spaces<?php endif ?></td><td><?= cash_money((int)$p['price_minor']) ?></td><td><strong><?= (int)($p['timeframe_days']??0) ?> days</strong><small class="table-subtext"><?= $credited ?> / <?= $maxHours ?> hours</small></td><td><strong><?= number_format($bps/100,2) ?>%</strong><small class="table-subtext">per day</small></td><td><?= cash_money($hourly) ?></td><td><strong><?= $earningsReady?cash_money($earned):'Pending' ?></strong><small class="table-subtext"><?= $completed?($transferred?'Transferred':'Ready for transfer'):'Accumulating in this Lot' ?></small></td><td><?= e($p['created_at']) ?><small class="table-subtext">Ends: <?= e((string)($p['earnings_ends_at']??'—')) ?></small></td><td><?php if($completed): ?><?php if($transferred): ?><span class="chip">Transferred</span><small class="table-subtext"><?= e((string)$p['earnings_transferred_at']) ?></small><?php else: ?><form method="post" action="/trader/transfer-lot-earnings.php" class="completed-lot-transfer"><?= csrf_field() ?><input type="hidden" name="lot_id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="request_key" value="<?= e(generate_uuid()) ?>"><button class="button" type="submit" <?= !$earningsReady||$earned<=0?'disabled':'' ?>>Transfer to Withdrawable Earnings</button></form><?php endif ?><?php else: ?><a class="button secondary" href="/trader/lot-spaces.php?id=<?= (int)$p['id'] ?>">Manage Spaces →</a><?php endif ?></td></tr>
<?php endforeach; }
?>
<section class="panel cash-body"><div class="panel-head"><div><h3>Active Lot Plans</h3><p>Daily earnings accumulate inside each Lot. They do not enter Withdrawable Earnings while the Lot is active.</p></div><a class="button" href="/trader/buy-lot.php">Buy Lot</a></div><div class="table-scroll"><table><thead><tr><th>Lot</th><th>Product spaces</th><th>Purchase price</th><th>Timeframe</th><th>Daily %</th><th>Hourly earning</th><th>Accumulated earnings</th><th>Date / End</th><th>Actions</th></tr></thead><tbody><?php nex_lot_rows($activeLots,$spaceCounts,$earnedByLot,$spacesReady,$earningsReady,false); ?><?php if(!$activeLots): ?><tr><td colspan="9">No active Lot Plans.</td></tr><?php endif ?></tbody></table></div></section>

<section class="panel cash-body" id="completed-lots"><div class="panel-head"><div><h3>Completed Lot Plans</h3><p>Matured Lot Plans waiting for transfer appear here. After you transfer the earnings to Withdrawable Earnings, the Lot is removed from this table.</p></div></div><div class="table-scroll"><table><thead><tr><th>Lot</th><th>Product spaces</th><th>Purchase price</th><th>Timeframe</th><th>Daily %</th><th>Hourly earning</th><th>Completed earnings</th><th>Date / End</th><th>Transfer</th></tr></thead><tbody><?php nex_lot_rows($completedLots,$spaceCounts,$earnedByLot,$spacesReady,$earningsReady,true); ?><?php if(!$completedLots): ?><tr><td colspan="9">No completed Lot Plans yet.</td></tr><?php endif ?></tbody></table></div><p class="fine-print">Only earnings from a completed Lot Plan that you explicitly transfer are added to Withdrawable Earnings. The Admin minimum withdrawal amount still applies when requesting a payout.</p></section>
