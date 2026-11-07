<?php
declare(strict_types=1);
$result='checking';
$redirectToSellerDashboard=false;
try {
    $tracking=$_GET['OrderTrackingId']??$_GET['orderTrackingId']??'';
    $merchantRef=$_GET['OrderMerchantReference']??$_GET['orderMerchantReference']??'';
    if ($tracking && $merchantRef) {
        verify_and_publish($merchantRef,$tracking);
        // A browser return is successful only after the server has confirmed the
        // payment row and the related advert is actually active.
        $payments=supabase_request('GET','/rest/v1/advertisement_payments?order_reference=eq.'.urlencode($merchantRef).'&select=advertisement_id,seller_id,status&limit=1',null,true);
        if ($payments && ($payments[0]['status']??'')==='completed') {
            $ad=supabase_request('GET','/rest/v1/advertisements?id=eq.'.urlencode($payments[0]['advertisement_id']).'&select=id,status&limit=1',null,true);
            if ($ad && ($ad[0]['status']??'')==='active') {
                $result='received';
                $user=current_user();
                $redirectToSellerDashboard=$user && ($user['id']??'')===($payments[0]['seller_id']??'');
            }
        }
    }
} catch(Throwable $e) {
    error_log('Payment return verification: '.$e->getMessage());
    $result='check_failed';
}
$destination='/?payment='.rawurlencode($result);
if ($redirectToSellerDashboard) $destination.='&dashboard=seller&section=adverts';
header('Location: '.$destination, true, 303);
exit;
