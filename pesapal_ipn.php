<?php
declare(strict_types=1);
try {
    $tracking=$_GET['OrderTrackingId']??$_POST['OrderTrackingId']??'';
    $ref=$_GET['OrderMerchantReference']??$_POST['OrderMerchantReference']??'';
    if ($tracking && $ref) verify_and_publish($ref,$tracking);
    json_response(['orderNotificationType'=>$_GET['OrderNotificationType']??'IPNCHANGE','orderTrackingId'=>$tracking,'orderMerchantReference'=>$ref,'status'=>200]);
} catch(Throwable $e) {
    error_log('Pesapal IPN: '.$e->getMessage());
    json_response(['status'=>500,'error'=>'Verification failed'],500);
}
