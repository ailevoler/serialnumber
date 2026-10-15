<?php
require_once __DIR__.'/referrals.php';
require_once __DIR__.'/lot_incentives.php';
function cash_lot_buy(int $uid,int $plan,int $expectedPrice,string $key): int {
 if(!preg_match('/^[a-f0-9-]{36}$/D',$key))throw new DomainException('Invalid purchase request.');
 cash_wallet($uid);
 if(referral_ready())referral_lot_ensure_schema();
 lot_incentive_ensure_schema();
 return cash_atomic(function()use($uid,$plan,$expectedPrice,$key){
  if(!cash_query("SELECT id FROM users WHERE id=? AND role='trader' AND status='active'",[$uid])->fetchColumn())throw new DomainException('Active Trader account required.');
  $w=cash_query('SELECT * FROM cash_wallets WHERE user_id=? FOR UPDATE',[$uid])->fetch();
  $old=cash_query('SELECT * FROM cash_requests WHERE request_key=?',[$key])->fetch();
  if($old){
   $lot=cash_query('SELECT * FROM cash_lots WHERE request_id=?',[$old['id']])->fetch();
   if(!$lot||(int)$lot['user_id']!==$uid||(int)$lot['plan_id']!==$plan||(int)$lot['price_minor']!==$expectedPrice)throw new DomainException('Purchase request already used.');
   return (int)$lot['id'];
  }
  $p=cash_query('SELECT * FROM cash_lot_plans WHERE id=? AND enabled=1 LOCK IN SHARE MODE',[$plan])->fetch();
  if(!$p)throw new DomainException('This Lot is unavailable.');
  $price=(int)$p['price_minor'];
  if($price!==$expectedPrice)throw new DomainException('The Lot price changed. Refresh and review the new price.');
  if($price<100||(int)$w['available']<$price)throw new DomainException('Insufficient available USD balance.');
  $earningsSpent=cash_purchase_earnings($w,$price);
  cash_query("INSERT INTO cash_requests(user_id,request_key,kind,amount,method_id,method_snapshot,reference,status,review_reference,reviewed_at) VALUES(?,?,'lot_purchase',?,0,'USD wallet',?,'paid','Completed Lot purchase',CURRENT_TIMESTAMP)",[$uid,$key,$price,$p['name']]);
  $request=(int)db()->lastInsertId();
  cash_query("INSERT INTO cash_ledger(user_id,request_id,event,available_delta,reserved_delta,earnings_available_delta,earnings_reserved_delta) VALUES(?,?,'lot_purchase',?,0,?,0)",[$uid,$request,-$price,-$earningsSpent]);
  cash_query('UPDATE cash_wallets SET available=available-?,earnings_available=earnings_available-? WHERE user_id=?',[$price,$earningsSpent,$uid]);
  $timeframeDays=max(1,(int)($p['timeframe_days']??30));
  $dailyBps=max(0,(int)($p['daily_earnings_bps']??0));
  cash_query('INSERT INTO cash_lots(user_id,plan_id,plan_name,capacity,price_minor,timeframe_days,daily_earnings_bps,earnings_started_at,earnings_ends_at,credited_hours,request_id) VALUES(?,?,?,?,?,?,?,CURRENT_TIMESTAMP,DATE_ADD(CURRENT_TIMESTAMP,INTERVAL ? DAY),0,?)',[$uid,$plan,$p['name'],$p['capacity'],$price,$timeframeDays,$dailyBps,$timeframeDays,$request]);
  $id=(int)db()->lastInsertId();
  // Snapshot the package's referral percentage so later package edits never change this Lot's bonus.
  // Snapshot the package's completion incentive terms.
  if(array_key_exists('incentive_minor',$p)&&lot_incentive_ensure_schema())cash_query('UPDATE cash_lots SET incentive_minor=?,incentive_invites=? WHERE id=?',[(int)$p['incentive_minor'],(int)$p['incentive_invites'],$id]);
  if(array_key_exists('referral_bps',$p)&&referral_lot_ensure_schema())cash_query('UPDATE cash_lots SET referral_bps=? WHERE id=?',[$p['referral_bps']===null?null:(int)$p['referral_bps'],$id]);
  referral_on_lot_purchase($request);
  return $id;
 });
}
function cash_lot_percentage_bps(string $value): int {
 $value=trim($value);
 if(!preg_match('/^(?:0|[1-9][0-9]{0,2})(?:\.([0-9]{1,2}))?$/D',$value,$m))throw new DomainException('Enter a valid daily earnings percentage with up to two decimal places.');
 $parts=explode('.',$value,2);$bps=((int)$parts[0])*100+(int)str_pad($parts[1]??'',2,'0');
 if($bps<0||$bps>10000)throw new DomainException('Daily earnings percentage must be from 0.00% to 100.00%.');
 return $bps;
}
function cash_lot_plan_save(int $actor,int $id,string $name,string $price,string $capacity,string $timeframe,string $dailyPercent,string $enabled,string $referralPercent='',string $incentive='',string $incentiveInvites=''): void {
 $amount=cash_amount($price);$name=trim($name);$dailyBps=cash_lot_percentage_bps($dailyPercent);$referralBps=referral_lot_bps($referralPercent);$incentiveMinor=lot_incentive_amount($incentive);$invites=lot_incentive_invites($incentiveInvites);
 if($name===''||strlen($name)>100||!ctype_digit($capacity)||(int)$capacity<1||(int)$capacity>1000||!ctype_digit($timeframe)||(int)$timeframe<1||(int)$timeframe>3650||!in_array($enabled,['0','1'],true))throw new DomainException('Enter a name, USD price, 1–1000 product spaces and a 1–3650 day timeframe.');
 $timeframeDays=(int)$timeframe;
 if(!referral_lot_ensure_schema()||!lot_incentive_ensure_schema())throw new RuntimeException('Lot package columns missing.');
 cash_atomic(function()use($actor,$id,$name,$amount,$capacity,$timeframeDays,$dailyBps,$referralBps,$incentiveMinor,$invites,$enabled){
  if(!cash_query("SELECT id FROM users WHERE id=? AND role IN ('finance','super_admin') AND status='active'",[$actor])->fetchColumn())throw new DomainException('Finance access required.');
  if($id){cash_query('UPDATE cash_lot_plans SET name=?,price_minor=?,capacity=?,timeframe_days=?,daily_earnings_bps=?,referral_bps=?,incentive_minor=?,incentive_invites=?,enabled=?,updated_by=? WHERE id=?',[$name,$amount,$capacity,$timeframeDays,$dailyBps,$referralBps,$incentiveMinor,$invites,$enabled,$actor,$id]);}
  else cash_query('INSERT INTO cash_lot_plans(name,price_minor,capacity,timeframe_days,daily_earnings_bps,referral_bps,incentive_minor,incentive_invites,enabled,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?)',[$name,$amount,$capacity,$timeframeDays,$dailyBps,$referralBps,$incentiveMinor,$invites,$enabled,$actor]);
 });
}

/** Ensure the completed-Lot transfer fields exist even when the SQL migration was missed. */
function cash_lot_ensure_transfer_schema(): void {
 static $done=false; if($done)return;
 $columns=[];
 foreach(cash_query('SHOW COLUMNS FROM cash_lots')->fetchAll() as $c)$columns[(string)$c['Field']]=true;
 if(empty($columns['earnings_wallet_credited'])) cash_query('ALTER TABLE cash_lots ADD COLUMN earnings_wallet_credited TINYINT(1) NOT NULL DEFAULT 0 AFTER credited_hours');
 if(empty($columns['earnings_transferred_at'])) cash_query('ALTER TABLE cash_lots ADD COLUMN earnings_transferred_at DATETIME NULL AFTER earnings_wallet_credited');
 if(empty($columns['earnings_transfer_request_id'])) cash_query('ALTER TABLE cash_lots ADD COLUMN earnings_transfer_request_id BIGINT UNSIGNED NULL AFTER earnings_transferred_at');
 $done=true;
}

/** Move one matured Lot's accumulated earnings into withdrawable earnings exactly once. */
function cash_lot_transfer_completed_earnings(int $uid,int $lotId,string $key): int {
 cash_lot_ensure_transfer_schema();
 if(!preg_match('/^[a-f0-9-]{36}$/D',$key))throw new DomainException('Invalid transfer request.');
 cash_wallet($uid);
 return cash_atomic(function()use($uid,$lotId,$key){
  $lot=cash_query('SELECT * FROM cash_lots WHERE id=? AND user_id=? FOR UPDATE',[$lotId,$uid])->fetch();
  if(!$lot)throw new DomainException('Lot Plan not found.');
  if(!empty($lot['earnings_transferred_at']))throw new DomainException('This completed Lot Plan was already transferred.');
  $maxHours=max(0,(int)$lot['timeframe_days']*24);
  if($maxHours<1||(int)$lot['credited_hours']<$maxHours||empty($lot['earnings_ends_at'])||strtotime((string)$lot['earnings_ends_at'])>time())throw new DomainException('This Lot Plan has not matured yet.');
  $amount=(int)cash_query('SELECT COALESCE(SUM(amount_minor),0) FROM cash_lot_earnings WHERE lot_id=? AND user_id=?',[$lotId,$uid])->fetchColumn();
  if($amount<=0)throw new DomainException('This completed Lot Plan has no earnings to transfer.');
  $legacy=(int)($lot['earnings_wallet_credited']??0)===1;
  $old=cash_query('SELECT id FROM cash_requests WHERE request_key=?',[$key])->fetchColumn();
  if($old)return $amount;
  cash_query("INSERT INTO cash_requests(user_id,request_key,kind,amount,method_id,method_snapshot,reference,status,review_reference,reviewed_at) VALUES(?,?,'lot_transfer',?,0,'Completed Lot Plan',?,'paid','Transferred to withdrawable earnings',CURRENT_TIMESTAMP)",[$uid,$key,$amount,'Lot #'.$lotId.' '.$lot['plan_name']]);
  $request=(int)db()->lastInsertId();
  if(!$legacy){
   cash_query('UPDATE cash_wallets SET available=available+?,earnings_available=earnings_available+? WHERE user_id=?',[$amount,$amount,$uid]);
   cash_query("INSERT INTO cash_ledger(user_id,request_id,event,available_delta,reserved_delta,earnings_available_delta,earnings_reserved_delta) VALUES(?,?,'lot_transfer',?,0,?,0)",[$uid,$request,$amount,$amount]);
  }
  cash_query('UPDATE cash_lots SET earnings_transferred_at=CURRENT_TIMESTAMP, earnings_transfer_request_id=? WHERE id=?',[$request,$lotId]);
  return $amount;
 });
}
