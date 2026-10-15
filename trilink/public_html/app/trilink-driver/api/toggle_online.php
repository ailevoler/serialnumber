<?php
require_once __DIR__ . '/../includes/core.php';
driverCheck();
if(!Session::verifyCsrf($_POST[CSRF_TOKEN_NAME]??'')) { jsonResponse(false,'Invalid token'); }
$id=(int)$_SESSION['tl_driver_id']; $on=(int)($_POST['is_online']??0);
DB::run("UPDATE tl_drivers SET is_online=?,last_online=NOW() WHERE id=?",[$on,$id]);
if (!$on) {
    // Going offline takes the driver out of the terminal pila
    require_once __DIR__ . '/../../trilink-dispatch/lib/Dispatch.php';
    Dispatch::leave($id);
}
jsonResponse(true,$on?'Online':'Offline',['is_online'=>$on]);
