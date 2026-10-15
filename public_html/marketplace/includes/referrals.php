<?php
require_once __DIR__.'/cash.php';
function referral_ready(): bool {
    try { cash_query('SELECT id FROM referral_settings WHERE id=1'); return true; }
    catch(PDOException $e) { if(($e->errorInfo[1]??0)===1146)return false; throw $e; }
}
function referral_random_code(): string {
    $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    do {
        $code='';
        for($i=0;$i<12;$i++) $code.=$alphabet[random_int(0,strlen($alphabet)-1)];
        $exists=cash_query('SELECT 1 FROM referral_codes WHERE code=? LIMIT 1',[$code])->fetchColumn();
    } while($exists);
    return $code;
}
function referral_code(int $uid): string {
    $current=(string)(cash_query('SELECT code FROM referral_codes WHERE user_id=?',[$uid])->fetchColumn()?:'');
    if(!preg_match('/^[A-Z0-9]{12}$/D',$current)){
        $code=referral_random_code();
        if($current==='') cash_query('INSERT INTO referral_codes(user_id,code) VALUES(?,?)',[$uid,$code]);
        else cash_query('UPDATE referral_codes SET code=? WHERE user_id=?',[$code,$uid]);
        return $code;
    }
    return $current;
}
function referral_attach(int $uid,string $code): void {
    $code=strtoupper(trim($code));
    if(!preg_match('/^[A-Z0-9]{12}$/D',$code))return;
    $owner=cash_query("SELECT u.id FROM referral_codes c JOIN users u ON u.id=c.user_id WHERE c.code=? AND u.role='trader' AND u.status='active' AND u.id<>?",[$code,$uid])->fetchColumn();
    if($owner)cash_query('INSERT INTO referral_members(user_id,referrer_id) VALUES(?,?)',[$uid,$owner]);
}
function referral_value(string $value,string $type): int {
    if(!in_array($type,['percentage','fixed'],true)||!preg_match('/^(0|[1-9][0-9]{0,5})(?:\.([0-9]{1,2}))?$/D',$value,$m))throw new DomainException('Enter a positive value with at most two decimals.');
    $n=(int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
    if($n<1||$n>($type==='percentage'?10000:1000000))throw new DomainException('Use 0.01–100% or USD 0.01–10,000.00.');
    return $n;
}
function referral_save(int $actor,string $enabled,string $type,string $value,string $trigger): void {
    $n=referral_value($value,$type);
    if(!in_array($enabled,['0','1'],true)||$trigger!=='lot_purchase')throw new DomainException('Invalid referral setting.');
    cash_atomic(function()use($actor,$enabled,$type,$n,$trigger){
        $u=cash_query("SELECT id FROM users WHERE id=? AND role IN ('finance','super_admin') AND status='active'",[$actor])->fetchColumn();
        if(!$u)throw new DomainException('Finance access required.');
        cash_query('UPDATE referral_settings SET enabled=?,bonus_type=?,value_minor=?,trigger_type=?,updated_by=? WHERE id=1',[$enabled,$type,$n,$trigger,$actor]);
        cash_query('INSERT INTO referral_settings_history(actor_id,enabled,bonus_type,value_minor,trigger_type) VALUES(?,?,?,?,?)',[$actor,$enabled,$type,$n,$trigger]);
    });
}
// Per-Lot referral bonus: NULL means the Lot package uses the global setting.
// Client Lot referral percentages, used once to seed packages when the column is created:
// Lot 1=5%, Lot 2=10%, Lot 3=10%, Lot 4=15%, Lot 5=15%, Lot 6=20% (matched by name, then by the original price).
const REFERRAL_LEGACY_PACKAGE_BPS=[['Lot 1',4000,500],['Lot 2',8000,1000],['Lot 3',18000,1000],['Lot 4',48000,1500],['Lot 5',100000,1500],['Lot 6',180000,2000]];
/** Ensure the per-Lot referral columns exist even when the SQL migration was missed. Call outside transactions. */
function referral_lot_ensure_schema(): bool {
    static $ok=null; if($ok!==null)return $ok;
    try {
        $plan=[];foreach(cash_query('SHOW COLUMNS FROM cash_lot_plans')->fetchAll() as $c)$plan[(string)$c['Field']]=true;
        if(empty($plan['referral_bps'])){
            cash_query('ALTER TABLE cash_lot_plans ADD COLUMN referral_bps INT UNSIGNED NULL DEFAULT NULL');
            foreach(REFERRAL_LEGACY_PACKAGE_BPS as [$name,,$bps])cash_query('UPDATE cash_lot_plans SET referral_bps=? WHERE name=? AND referral_bps IS NULL',[$bps,$name]);
            foreach(REFERRAL_LEGACY_PACKAGE_BPS as [,$price,$bps])cash_query('UPDATE cash_lot_plans SET referral_bps=? WHERE price_minor=? AND referral_bps IS NULL',[$bps,$price]);
        }
        $lot=[];foreach(cash_query('SHOW COLUMNS FROM cash_lots')->fetchAll() as $c)$lot[(string)$c['Field']]=true;
        if(empty($lot['referral_bps']))cash_query('ALTER TABLE cash_lots ADD COLUMN referral_bps INT UNSIGNED NULL DEFAULT NULL');
        return $ok=true;
    } catch(PDOException $e) {
        error_log('[referrals] Per-Lot referral columns missing; import migrations/20261015_lot_referral_bonus.sql');
        return $ok=false;
    }
}
/** Optional per-Lot referral percentage: blank means use the global setting. */
function referral_lot_bps(string $value): ?int {
    $value=trim($value);
    if($value==='')return null;
    if(!preg_match('/^(?:0|[1-9][0-9]{0,2})(?:\.([0-9]{1,2}))?$/D',$value,$m))throw new DomainException('Enter a valid referral bonus percentage with up to two decimal places, or leave it blank.');
    $parts=explode('.',$value,2);$bps=((int)$parts[0])*100+(int)str_pad($parts[1]??'',2,'0');
    if($bps>10000)throw new DomainException('Referral bonus percentage must be from 0.00% to 100.00%.');
    return $bps;
}
/** Human-readable referral bonus for a Lot package, given the global settings row. */
function referral_lot_label(array $plan,array $settings): string {
    if(isset($plan['referral_bps'])&&$plan['referral_bps']!==null)return number_format((int)$plan['referral_bps']/100,2).'%';
    return $settings['bonus_type']==='percentage'?number_format((int)$settings['value_minor']/100,2).'%':cash_money((int)$settings['value_minor']);
}
// Compatibility with older deposit callers: deposits no longer award bonuses.
function referral_on_deposit(int $request): void {}
// Called in the transaction that pays for and creates a new Lot.
function referral_on_lot_purchase(int $request): void {
    if(!db()->inTransaction())throw new LogicException('Referral credit requires a Lot purchase transaction.');
    if(!referral_ready())return;
    $lotBps=referral_lot_ensure_schema()?'lot.referral_bps':'NULL';
    $r=cash_query("SELECT r.*,$lotBps AS lot_referral_bps FROM cash_requests r JOIN cash_lots lot ON lot.request_id=r.id AND lot.user_id=r.user_id AND lot.price_minor=r.amount JOIN cash_ledger l ON l.request_id=r.id AND l.user_id=r.user_id AND l.event='lot_purchase' AND l.available_delta=-r.amount WHERE r.id=? AND r.kind='lot_purchase' AND r.status='paid'",[$request])->fetch();
    if(!$r)return;
    $m=cash_query('SELECT * FROM referral_members WHERE user_id=? FOR UPDATE',[$r['user_id']])->fetch();
    if(!$m||(int)$m['referrer_id']===(int)$r['user_id'])return;
    if(cash_query('SELECT id FROM referral_rewards WHERE source_request_id=?',[$request])->fetchColumn())return;
    $s=cash_query('SELECT * FROM referral_settings WHERE id=1 LOCK IN SHARE MODE')->fetch();
    if(!$s||!(int)$s['enabled']||$s['trigger_type']!=='lot_purchase')return;
    if(!cash_query("SELECT id FROM users WHERE id=? AND role='trader' AND status='active'",[$m['referrer_id']])->fetchColumn())return;
    // Each Lot snapshots its package's referral percentage at purchase; packages without one use the global setting.
    $purchaseAmount=(int)$r['amount'];
    if($r['lot_referral_bps']!==null){$bonusType='percentage';$bonusValue=(int)$r['lot_referral_bps'];}
    else{$bonusType=(string)$s['bonus_type'];$bonusValue=(int)$s['value_minor'];}
    $amount=$bonusType==='fixed'?$bonusValue:intdiv($purchaseAmount*$bonusValue,10000);
    cash_query('INSERT INTO referral_rewards(source_request_id,user_id,referrer_id,bonus_type,value_minor,trigger_type,amount_minor) VALUES(?,?,?,?,?,?,?)',[$request,$r['user_id'],$m['referrer_id'],$bonusType,$bonusValue,$s['trigger_type'],$amount]);
    $reward=(int)db()->lastInsertId();
    if($amount===0)return;
    cash_wallet((int)$m['referrer_id']);
    cash_query('SELECT user_id FROM cash_wallets WHERE user_id=? FOR UPDATE',[$m['referrer_id']]);
    cash_query("INSERT INTO cash_requests(user_id,request_key,kind,amount,method_id,method_snapshot,reference,status,review_reference,reviewed_at) VALUES(?,?,'referral',?,0,'Lot Referral Bonus',?,'credited','Completed Lot purchase referral bonus',CURRENT_TIMESTAMP)",[$m['referrer_id'],generate_uuid(),$amount,'Lot referral reward #'.$reward]);
    $bonus=(int)db()->lastInsertId();
    cash_query("INSERT INTO cash_ledger(user_id,request_id,event,available_delta,reserved_delta,earnings_available_delta,earnings_reserved_delta) VALUES(?,?,'referral_bonus',?,0,?,0)",[$m['referrer_id'],$bonus,$amount,$amount]);
    cash_query('UPDATE cash_wallets SET available=available+?,earnings_available=earnings_available+? WHERE user_id=?',[$amount,$amount,$m['referrer_id']]);
    cash_query('UPDATE referral_rewards SET reward_request_id=? WHERE id=?',[$bonus,$reward]);
}
