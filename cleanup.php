<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$summary=['expired_adverts'=>0,'abandoned_adverts'=>0,'expired_promotion_bids'=>0,'removed_rate_limit_events'=>0];
try {
  $now=gmdate('c');
  $expired=supabase_request('GET','/rest/v1/advertisements?status=eq.active&expires_at=lt.'.urlencode($now).'&select=id',null,true);
  foreach($expired as $row) supabase_request('PATCH','/rest/v1/advertisements?id=eq.'.$row['id'].'&status=eq.active',['status'=>'expired'],true);
  $summary['expired_adverts']=count($expired);

  $stale=supabase_request('GET','/rest/v1/advertisement_payments?status=in.(initiated,pending)&created_at=lt.'.urlencode(gmdate('c',time()-86400)).'&select=id,advertisement_id,order_reference',null,true);
  foreach($stale as $payment){
    supabase_request('PATCH','/rest/v1/advertisement_payments?id=eq.'.$payment['id'].'&status=in.(initiated,pending)',['status'=>'abandoned','updated_at'=>$now],true);
    supabase_request('PATCH','/rest/v1/advertisements?id=eq.'.$payment['advertisement_id'].'&status=eq.pending_payment',['status'=>'expired'],true);
  }
  $summary['abandoned_adverts']=count($stale);

  $staleBids=supabase_request('GET','/rest/v1/promotion_bid_payments?status=in.(initiated,pending)&created_at=lt.'.urlencode(gmdate('c',time()-86400)).'&select=id,promotion_bid_id',null,true);
  foreach($staleBids as $payment){
    supabase_request('PATCH','/rest/v1/promotion_bid_payments?id=eq.'.$payment['id'].'&status=in.(initiated,pending)',['status'=>'abandoned','updated_at'=>$now],true);
    supabase_request('PATCH','/rest/v1/promotion_bids?id=eq.'.$payment['promotion_bid_id'].'&status=eq.pending_payment',['status'=>'rejected','updated_at'=>$now],true);
  }
  $summary['expired_promotion_bids']=count($staleBids);

  $cutoff=gmdate('c',time()-7*86400);
  $old=supabase_request('GET','/rest/v1/rate_limit_events?created_at=lt.'.urlencode($cutoff).'&select=id&limit=1000',null,true);
  if($old){$ids=array_column($old,'id');supabase_request('DELETE','/rest/v1/rate_limit_events?id=in.('.implode(',',$ids).')',null,true);}
  $summary['removed_rate_limit_events']=count($old);
  error_log('[marketplace-cleanup] '.json_encode($summary));
  echo json_encode(['ok'=>true,'summary'=>$summary],JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch(Throwable $e) {
  error_log('[marketplace-cleanup] '.$e->getMessage());
  fwrite(STDERR,"Cleanup failed: ".$e->getMessage().PHP_EOL);
  exit(1);
}
