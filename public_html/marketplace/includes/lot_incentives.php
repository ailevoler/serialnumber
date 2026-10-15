<?php
require_once __DIR__.'/cash.php';

// Lot completion incentives ("Choose Your Plan and Get Your Bonus").
// A package may pay a fixed USD incentive when a purchased Lot matures, optionally requiring a
// number of invited Traders. Terms are snapshotted onto each purchased Lot.
// An invite counts when the referred Trader registered through the buyer's referral link, is active,
// and completed their first Lot purchase on or before the buyer's Lot end date.
// Evaluated once at maturity: met → credited to Withdrawable Earnings; not met → recorded as missed.
// Client defaults, seeded once when the columns are created (matched by name, then original price):
// Lot 4 (60 days) $60 + 3 invites · Lot 5 (75 days) $200 + 8 invites · Lot 6 (90 days) $480, no invites.
const LOT_INCENTIVE_DEFAULTS=[['Lot 4',48000,6000,3],['Lot 5',100000,20000,8],['Lot 6',180000,48000,0]];

/** Ensure the incentive columns exist even when the SQL migration was missed. Call outside transactions. */
function lot_incentive_ensure_schema(): bool {
    static $ok=null; if($ok!==null)return $ok;
    try {
        $plan=[];foreach(cash_query('SHOW COLUMNS FROM cash_lot_plans')->fetchAll() as $c)$plan[(string)$c['Field']]=true;
        if(empty($plan['incentive_minor'])){
            cash_query('ALTER TABLE cash_lot_plans ADD COLUMN incentive_minor BIGINT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN incentive_invites INT UNSIGNED NOT NULL DEFAULT 0');
            foreach(LOT_INCENTIVE_DEFAULTS as [$name,,$amount,$invites])cash_query('UPDATE cash_lot_plans SET incentive_minor=?,incentive_invites=? WHERE name=? AND incentive_minor=0',[$amount,$invites,$name]);
            foreach(LOT_INCENTIVE_DEFAULTS as [,$price,$amount,$invites])cash_query("UPDATE cash_lot_plans SET incentive_minor=?,incentive_invites=? WHERE price_minor=? AND incentive_minor=0 AND name NOT IN ('Lot 1','Lot 2','Lot 3','Lot 4','Lot 5','Lot 6')",[$amount,$invites,$price]);
        }
        $lot=[];foreach(cash_query('SHOW COLUMNS FROM cash_lots')->fetchAll() as $c)$lot[(string)$c['Field']]=true;
        if(empty($lot['incentive_minor']))cash_query("ALTER TABLE cash_lots ADD COLUMN incentive_minor BIGINT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN incentive_invites INT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN incentive_status VARCHAR(12) NULL DEFAULT NULL, ADD COLUMN incentive_invites_counted INT UNSIGNED NULL DEFAULT NULL, ADD COLUMN incentive_request_id BIGINT UNSIGNED NULL DEFAULT NULL, ADD COLUMN incentive_settled_at DATETIME NULL DEFAULT NULL");
        return $ok=true;
    } catch(PDOException $e) {
        error_log('[lot incentives] Incentive columns missing; import migrations/20261015_lot_incentives.sql');
        return $ok=false;
    }
}

/** USD amount (blank or 0 = no incentive) to minor units. */
function lot_incentive_amount(string $value): int {
    $value=trim($value);
    if($value===''||preg_match('/^0+(?:\.0{1,2})?$/D',$value))return 0;
    if(!preg_match('/^(0|[1-9][0-9]{0,6})(?:\.([0-9]{1,2}))?$/D',$value,$m))throw new DomainException('Enter a valid incentive amount in USD with up to two decimals, or leave it blank.');
    return (int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
}

function lot_incentive_invites(string $value): int {
    $value=trim($value);
    if($value==='')return 0;
    if(!ctype_digit($value)||(int)$value>1000)throw new DomainException('Required invites must be a whole number from 0 to 1000.');
    return (int)$value;
}

/** Number of qualifying invites for a referrer, counting first Lot purchases up to $until (Y-m-d H:i:s). */
function lot_incentive_invite_count(int $referrerId,string $until): int {
    return (int)cash_query("SELECT COUNT(*) FROM referral_members m JOIN users u ON u.id=m.user_id AND u.role='trader' AND u.status='active' WHERE m.referrer_id=? AND m.user_id<>m.referrer_id AND (SELECT MIN(l.created_at) FROM cash_lots l WHERE l.user_id=m.user_id)<=?",[$referrerId,$until])->fetchColumn();
}

/** Settle one matured Lot's incentive exactly once. Returns the credited amount (0 if missed or not due). */
function lot_incentive_settle(int $lotId,?int $now=null): int {
    $now??=time();
    return cash_atomic(function()use($lotId,$now){
        $lot=cash_query('SELECT * FROM cash_lots WHERE id=? FOR UPDATE',[$lotId])->fetch();
        if(!$lot||(int)$lot['incentive_minor']<=0||$lot['incentive_status']!==null)return 0;
        $ends=strtotime((string)($lot['earnings_ends_at']??''));
        if(!$ends||$ends>$now)return 0;
        $uid=(int)$lot['user_id'];$amount=(int)$lot['incentive_minor'];$required=(int)$lot['incentive_invites'];
        $counted=$required>0?lot_incentive_invite_count($uid,(string)$lot['earnings_ends_at']):0;
        $active=cash_query("SELECT id FROM users WHERE id=? AND role='trader' AND status='active'",[$uid])->fetchColumn();
        if(!$active||$counted<$required){
            cash_query("UPDATE cash_lots SET incentive_status='missed',incentive_invites_counted=?,incentive_settled_at=CURRENT_TIMESTAMP WHERE id=?",[$counted,$lotId]);
            return 0;
        }
        cash_wallet($uid);
        cash_query('SELECT user_id FROM cash_wallets WHERE user_id=? FOR UPDATE',[$uid]);
        // Deterministic key: a Lot can never receive its incentive twice.
        $key='li-'.substr(hash('sha256','lot-incentive:'.$lotId),0,33);
        cash_query("INSERT INTO cash_requests(user_id,request_key,kind,amount,method_id,method_snapshot,reference,status,review_reference,reviewed_at) VALUES(?,?,'incentive',?,0,'Lot Completion Incentive',?,'credited','Completed Lot incentive',CURRENT_TIMESTAMP)",[$uid,$key,$amount,'Lot #'.$lotId.' '.$lot['plan_name'].' incentive']);
        $request=(int)db()->lastInsertId();
        cash_query("INSERT INTO cash_ledger(user_id,request_id,event,available_delta,reserved_delta,earnings_available_delta,earnings_reserved_delta) VALUES(?,?,'lot_incentive',?,0,?,0)",[$uid,$request,$amount,$amount]);
        cash_query('UPDATE cash_wallets SET available=available+?,earnings_available=earnings_available+? WHERE user_id=?',[$amount,$amount,$uid]);
        cash_query("UPDATE cash_lots SET incentive_status='credited',incentive_invites_counted=?,incentive_request_id=?,incentive_settled_at=CURRENT_TIMESTAMP WHERE id=?",[$counted,$request,$lotId]);
        return $amount;
    });
}

function lot_incentive_settle_all_due(?int $now=null): array {
    $now??=time();$lots=[];$credited=0;$missed=0;$amount=0;$errors=0;
    if(!lot_incentive_ensure_schema())return ['lots'=>0,'credited'=>0,'missed'=>0,'amount_minor'=>0,'errors'=>1];
    try{$lots=cash_query('SELECT id FROM cash_lots WHERE incentive_minor>0 AND incentive_status IS NULL AND earnings_ends_at<=FROM_UNIXTIME(?) ORDER BY id',[$now])->fetchAll();}
    catch(Throwable $e){return ['lots'=>0,'credited'=>0,'missed'=>0,'amount_minor'=>0,'errors'=>1];}
    foreach($lots as $row){
        try{$n=lot_incentive_settle((int)$row['id'],$now);if($n>0){$credited++;$amount+=$n;}else $missed++;}
        catch(Throwable $e){$errors++;error_log('[lot incentives] Lot #'.(int)$row['id'].' failed: '.$e->getMessage());}
    }
    return ['lots'=>count($lots),'credited'=>$credited,'missed'=>$missed,'amount_minor'=>$amount,'errors'=>$errors];
}

/** Short trader-facing description (safe HTML) of a package's incentive terms, or '' when it has none. */
function lot_incentive_terms(array $plan): string {
    $amount=(int)($plan['incentive_minor']??0);if($amount<=0)return '';
    $invites=(int)($plan['incentive_invites']??0);
    return cash_money($amount).' incentive after '.(int)($plan['timeframe_days']??0).' days'.($invites>0?' · invite '.$invites.' '.($invites===1?'person':'people'):' · no invites needed');
}
