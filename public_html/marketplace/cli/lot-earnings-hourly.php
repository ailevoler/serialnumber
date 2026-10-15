<?php
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/cash_lot_earnings.php';
require_once __DIR__.'/../includes/lot_incentives.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
try{
    $r=cash_lot_credit_all_due();
    echo 'Checked '.$r['lots'].' active Lots; credited '.$r['credited_lots'].' Lots; total '.number_format($r['amount_minor']/100,2).' USD; errors '.$r['errors'].".\n";
    $i=lot_incentive_settle_all_due();
    echo 'Incentives: checked '.$i['lots'].' completed Lots; credited '.$i['credited'].'; missed '.$i['missed'].'; total '.number_format($i['amount_minor']/100,2).' USD; errors '.$i['errors'].".\n";
    exit(($r['errors']||$i['errors'])?2:0);
}catch(Throwable $e){fwrite(STDERR,"Lot earnings job failed. Check the migration and database configuration.\n");exit(1);}
