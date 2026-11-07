<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
try {
$method=$_SERVER['REQUEST_METHOD']; $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH); $b=body_json();
if ($path==='/api/status' && $method==='GET') {
    $checks=[
      'supabase_url'=>filter_var(envv('SUPABASE_URL'),FILTER_VALIDATE_URL)!==false,
      'supabase_anon_key'=>envv('SUPABASE_ANON_KEY')!=='',
      'supabase_service_role_key'=>envv('SUPABASE_SERVICE_ROLE_KEY')!=='',
      'app_url'=>filter_var(envv('APP_URL'),FILTER_VALIDATE_URL)!==false
    ];
    if (!$checks['supabase_url'] || !$checks['supabase_service_role_key']) {
      json_response(['ok'=>false,'checks'=>$checks,'message'=>'Render is missing SUPABASE_URL or SUPABASE_SERVICE_ROLE_KEY. Add the values in the Render environment settings.'],503);
    }
    try {
      supabase_request('GET','/rest/v1/categories?select=id&limit=1',null,true);
      $checks['database_api']=true;
      json_response(['ok'=>true,'checks'=>$checks,'message'=>'The marketplace database API is reachable.']);
    } catch (Throwable $e) {
      $checks['database_api']=false;
      error_log('[marketplace-database-check] '.$e->getMessage());
      json_response(['ok'=>false,'checks'=>$checks,'message'=>'Supabase rejected the server database request: '.$e->getMessage()],503);
    }
}
if ($path==='/api/auth/login' && $method==='POST') rate_limit('login',strtolower(trim((string)($b['email']??''))),8,900);
if ($path==='/api/auth/register' && $method==='POST') rate_limit('register',strtolower(trim((string)($b['email']??''))),4,3600);
if ($path==='/api/messages' && $method==='POST') rate_limit('message',(string)(current_user()['id']??session_id()),20,300);
if (preg_match('#^/api/messages/[0-9a-f-]+/?$#',$path) && $method==='POST') rate_limit('message_reply',(string)(current_user()['id']??session_id()),30,300);
if ($path==='/api/auth/recover' && $method==='POST') rate_limit('recover',strtolower(trim((string)($b['email']??''))),4,3600);
if ($path==='/api/reports' && $method==='POST') rate_limit('report',(string)(current_user()['id']??session_id()),10,3600);
if ($path==='/api/promotions/bids' && $method==='POST') rate_limit('promotion_bid',(string)(current_user()['id']??session_id()),5,3600);
if (in_array($method,['POST','PATCH','PUT','DELETE'],true) && $path!=='/api/owner/setup') { $csrf=$_SERVER['HTTP_X_CSRF_TOKEN']??''; if(!$csrf||!hash_equals($_SESSION['csrf_token']??'', $csrf)) json_response(['error'=>'Security token expired. Refresh the page and try again.'],419); }
if ($path==='/api/owner/setup' && $method==='POST') {
    $setupToken=envv('OWNER_SETUP_TOKEN');
    $ownerEmail=strtolower(trim(envv('OWNER_EMAIL')));
    $providedToken=(string)($b['setup_token']??'');
    $email=strtolower(trim((string)($b['email']??'')));
    $password=(string)($b['password']??'');
    $name=trim((string)($b['full_name']??''));
    $phone=trim((string)($b['phone']??''));
    if (strlen($setupToken)<32) json_response(['error'=>'Owner setup is not configured. Add a strong OWNER_SETUP_TOKEN (at least 32 characters) in Render.'],503);
    if (!hash_equals($setupToken,$providedToken)) json_response(['error'=>'Invalid owner setup code.'],403);
    if ($ownerEmail==='' || !filter_var($ownerEmail,FILTER_VALIDATE_EMAIL)) json_response(['error'=>'Set a valid OWNER_EMAIL in Render first.'],503);
    if ($email!==$ownerEmail) json_response(['error'=>'Use the owner email configured in Render.'],403);
    if ($name==='' || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password)<12) json_response(['error'=>'Enter the owner name and email, and use a password of at least 12 characters.'],422);
    $existingOwner=supabase_request('GET',"/rest/v1/profiles?role=eq.owner&select=id&limit=1",null,true);
    if ($existingOwner) json_response(['error'=>'The website owner account has already been created. Use the normal login page.'],409);
    $created=supabase_request('POST','/auth/v1/admin/users',['email'=>$email,'password'=>$password,'email_confirm'=>true,'user_metadata'=>['full_name'=>$name,'phone'=>$phone]],true);
    $id=$created['id']??$created['user']['id']??'';
    if (!$id) throw new RuntimeException('Supabase did not return the owner account ID.');
    $profile=supabase_request('GET','/rest/v1/profiles?id=eq.'.urlencode($id).'&select=id',null,true);
    if ($profile) supabase_request('PATCH','/rest/v1/profiles?id=eq.'.urlencode($id),['full_name'=>$name,'phone'=>$phone,'role'=>'owner'],true);
    else supabase_request('POST','/rest/v1/profiles',['id'=>$id,'full_name'=>$name,'phone'=>$phone,'role'=>'owner'],true);
    json_response(['ok'=>true,'message'=>'Separate website owner account created. Sign in using the owner email and password.']);
}
if ($path==='/api/auth/recover' && $method==='POST') {
    $email=strtolower(trim((string)($b['email']??'')));
    if(!filter_var($email,FILTER_VALIDATE_EMAIL))json_response(['error'=>'Enter a valid email address.'],422);
    $redirect=rtrim(envv('APP_URL'),'/').'/reset-password';
    supabase_request('POST','/auth/v1/recover?redirect_to='.urlencode($redirect),['email'=>$email]);
    json_response(['ok'=>true,'message'=>'If this email belongs to an account, a password reset link will be sent. Check your inbox and spam folder.']);
}
if ($path==='/api/auth/reset' && $method==='POST') {
    $token=trim((string)($b['access_token']??''));$password=(string)($b['password']??'');
    if($token===''||strlen($password)<12)json_response(['error'=>'Use the reset link and enter a new password of at least 12 characters.'],422);
    supabase_request('PUT','/auth/v1/user',['password'=>$password],false,$token);
    json_response(['ok'=>true,'message'=>'Password updated. Return to the marketplace and log in.']);
}
if ($path==='/api/auth/register' && $method==='POST') {
    foreach (['full_name','email','phone','password'] as $f) if (!isset($b[$f]) || trim((string)$b[$f])==='') json_response(['error'=>"Missing $f"],422);
    $name=trim((string)$b['full_name']);
    $email=strtolower(trim((string)$b['email']));
    $phone=trim((string)$b['phone']);
    $password=(string)$b['password'];
    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) json_response(['error'=>'Enter a valid email address.'],422);
    if (strlen($password)<8) json_response(['error'=>'Password must be at least 8 characters.'],422);
    // Create marketplace accounts through the server-side Admin API. This confirms the address
    // for immediate marketplace access without sending a confirmation email on every attempt.
    // Seller advert payments remain enforced separately by the advert checkout endpoint.
    $created=supabase_request('POST','/auth/v1/admin/users',['email'=>$email,'password'=>$password,'email_confirm'=>true,'user_metadata'=>['full_name'=>$name,'phone'=>$phone]],true);
    $id=$created['id']??$created['user']['id']??'';
    if (!$id) throw new RuntimeException('Supabase did not return the new account details.');
    $login=supabase_request('POST','/auth/v1/token?grant_type=password',['email'=>$email,'password'=>$password]);
    $profiles=supabase_request('GET','/rest/v1/profiles?id=eq.'.urlencode($id).'&select=id',null,true);
    if ($profiles) supabase_request('PATCH','/rest/v1/profiles?id=eq.'.urlencode($id),['full_name'=>$name,'phone'=>$phone],true);
    else supabase_request('POST','/rest/v1/profiles',['id'=>$id,'full_name'=>$name,'phone'=>$phone,'role'=>'user'],true);
    session_regenerate_id(true);
    $_SESSION['user']=['id'=>$id,'email'=>$email,'full_name'=>$name,'role'=>'user','access_token'=>$login['access_token']];
    json_response(['ok'=>true,'message'=>'Account created. You are now logged in.','logged_in'=>true]);
}
if ($path==='/api/auth/login' && $method==='POST') {
    $email=trim((string)($b['email']??''));
    $password=(string)($b['password']??'');
    try {
        $r=supabase_request('POST','/auth/v1/token?grant_type=password',['email'=>$email,'password'=>$password]);
    } catch (Throwable $loginError) {
        // Existing accounts created while email confirmation was enabled can still use the marketplace.
        if (stripos($loginError->getMessage(),'email not confirmed')===false) throw $loginError;
        $directory=supabase_request('GET','/auth/v1/admin/users?page=1&per_page=1000',null,true);
        $account=null;
        foreach (($directory['users']??[]) as $candidate) {
            if (strcasecmp((string)($candidate['email']??''),$email)===0) { $account=$candidate; break; }
        }
        if (!$account || empty($account['id'])) throw $loginError;
        supabase_request('PUT','/auth/v1/admin/users/'.rawurlencode($account['id']),['email_confirm'=>true],true);
        // Supabase still verifies the password before a session is created.
        $r=supabase_request('POST','/auth/v1/token?grant_type=password',['email'=>$email,'password'=>$password]);
    }
    session_regenerate_id(true);
    $id=$r['user']['id']; $p=supabase_request('GET','/rest/v1/profiles?id=eq.'.urlencode($id).'&select=role,full_name',null,true);
    $profile=$p[0]??[]; $_SESSION['user']=['id'=>$id,'email'=>$r['user']['email'],'full_name'=>$profile['full_name']??($r['user']['user_metadata']['full_name']??'Member'),'role'=>$profile['role']??'user','access_token'=>$r['access_token']];
    json_response(['ok'=>true,'user'=>['name'=>$_SESSION['user']['full_name'],'role'=>$_SESSION['user']['role']]]);
}
if ($path==='/api/auth/logout' && $method==='POST') { session_destroy(); json_response(['ok'=>true]); }
if ($path==='/api/me') { $u=current_user(); json_response(['user'=>$u?['id'=>$u['id'],'email'=>$u['email'],'name'=>$u['full_name'],'role'=>$u['role']]:null]); }
if ($path==='/api/listings') {
    $q=trim($_GET['q']??''); $cat=$_GET['category']??'';
    $filter='status=eq.active&expires_at=gt.'.urlencode(gmdate('c')).'&select=id,title,description,price,location,district,category_id,created_at,expires_at,image_url,seller_id&order=created_at.desc&limit=60';
    if ($cat!=='') $filter.='&category_id=eq.'.urlencode($cat);
    if ($q!=='') $filter.='&or=(title.ilike.*'.urlencode($q). '*,description.ilike.*'.urlencode($q). '*,location.ilike.*'.urlencode($q).'*)';
    $rows=supabase_request('GET','/rest/v1/advertisements?'.$filter,null,true);
    $cats=supabase_request('GET','/rest/v1/categories?active=eq.true&select=id,name&order=name',null,true);
    json_response(['listings'=>$rows,'categories'=>$cats]);
}
if ($path==='/api/promotions' && $method==='GET') {
    $now=gmdate('c');
    $campaigns=supabase_request('GET','/rest/v1/promotion_campaigns?active=eq.true&starts_at=lte.'.urlencode($now).'&or=(ends_at.is.null,ends_at.gt.'.urlencode($now).')&select=id,title,description,image_url,target_url,created_at&order=created_at.desc&limit=30',null,true);
    $bids=supabase_request('GET','/rest/v1/promotion_bids?status=eq.approved&starts_at=lte.'.urlencode($now).'&or=(ends_at.is.null,ends_at.gt.'.urlencode($now).')&select=id,bid_ugx,advertisement_id,advertisements!inner(id,title,description,image_url,location,price,status,expires_at)&advertisements.status=eq.active&advertisements.expires_at=gt.'.urlencode($now).'&order=bid_ugx.desc&limit=50',null,true);
    $items=[];
    foreach($campaigns as $c)$items[]=['id'=>$c['id'],'title'=>$c['title'],'description'=>$c['description'],'image_url'=>$c['image_url'],'target_url'=>$c['target_url']?:'/marketplace','label'=>'Marketplace feature','kind'=>'admin'];
    foreach($bids as $bid){$ad=$bid['advertisements']??[];$items[]=['id'=>$bid['id'],'title'=>$ad['title']??'Featured advert','description'=>$ad['description']??'','image_url'=>$ad['image_url']??null,'target_url'=>'/#advert-'.($ad['id']??$bid['advertisement_id']),'label'=>'Sponsored seller · UGX '.number_format((int)$bid['bid_ugx']),'kind'=>'seller','bid_ugx'=>(int)$bid['bid_ugx']];}
    usort($items,fn($x,$y)=>(($y['kind']==='seller')?1:0)<=>(($x['kind']==='seller')?1:0) ?: (($y['bid_ugx']??0)<=>($x['bid_ugx']??0)));
    json_response(['promotions'=>$items]);
}
if ($path==='/api/promotions/my-bids' && $method==='GET') {
    $u=require_user();
    $rows=supabase_request('GET','/rest/v1/promotion_bids?seller_id=eq.'.$u['id'].'&select=id,bid_ugx,status,created_at,advertisements(title)&order=created_at.desc&limit=100',null,true);
    json_response(['bids'=>$rows]);
}
if ($path==='/api/promotions/bids' && $method==='POST') {
    $u=require_user();$adId=(string)($b['advertisement_id']??'');$bid=(int)($b['bid_ugx']??0);
    if($bid<1000||$bid>100000000)json_response(['error'=>'Enter a bid between UGX 1,000 and UGX 100,000,000.'],422);
    $ad=supabase_request('GET','/rest/v1/advertisements?id=eq.'.urlencode($adId).'&seller_id=eq.'.$u['id'].'&status=eq.active&expires_at=gt.'.urlencode(gmdate('c')).'&select=id,title',null,true);
    if(!$ad)json_response(['error'=>'Choose one of your own active adverts to promote.'],422);
    $existing=supabase_request('GET','/rest/v1/promotion_bids?advertisement_id=eq.'.urlencode($adId).'&seller_id=eq.'.$u['id'].'&select=id',null,true);
    if($existing) {
      $bidId=$existing[0]['id'];
      supabase_request('PATCH','/rest/v1/promotion_bids?id=eq.'.$bidId,['bid_ugx'=>$bid,'status'=>'pending_payment','starts_at'=>null,'ends_at'=>null,'updated_at'=>gmdate('c')],true);
      $oldPay=[];
    } else {
      $created=supabase_request('POST','/rest/v1/promotion_bids?select=id',['advertisement_id'=>$adId,'seller_id'=>$u['id'],'bid_ugx'=>$bid,'status'=>'pending_payment'],true);
      $bidId=$created[0]['id']??'';
      $oldPay=[];
    }
    if(!$bidId)throw new RuntimeException('Could not create promotion bid.');
    $orderRef='NUMC-PROMO-'.strtoupper(bin2hex(random_bytes(6)));
    $payRow=['promotion_bid_id'=>$bidId,'seller_id'=>$u['id'],'order_reference'=>$orderRef,'amount_ugx'=>$bid,'currency'=>envv('PESAPAL_CURRENCY','UGX'),'status'=>'initiated'];
    supabase_request('POST','/rest/v1/promotion_bid_payments',$payRow,true);
    $token=pesapal_token();
    $ipnId=pesapal_notification_id();
    if(!$ipnId){$ipn=pesapal_call('POST','/api/URLSetup/RegisterIPN',['url'=>rtrim(envv('APP_URL'),'/').'/pesapal/ipn','ipn_notification_type'=>'POST'],$token);$ipnId=$ipn['ipn_id']??'';}
    if(!$ipnId)throw new RuntimeException('Pesapal IPN is not configured. Set PESAPAL_NOTIFICATION_ID in Render.');
    $order=pesapal_call('POST','/api/Transactions/SubmitOrderRequest',[
      'id'=>$orderRef,'currency'=>envv('PESAPAL_CURRENCY','UGX'),'amount'=>$bid,
      'description'=>'Promotion bid: '.$ad[0]['title'],
      'callback_url'=>envv('PESAPAL_CALLBACK_URL',rtrim(envv('APP_URL'),'/').'/payment/return'),
      'notification_id'=>$ipnId,
      'billing_address'=>['email_address'=>$u['email'],'phone_number'=>'','country_code'=>'UG','first_name'=>substr($u['full_name'],0,40),'last_name'=>'Seller']
    ],$token);
    if(empty($order['redirect_url']))throw new RuntimeException('Pesapal did not return a promotion checkout URL.');
    supabase_request('PATCH','/rest/v1/promotion_bid_payments?order_reference=eq.'.urlencode($orderRef),['pesapal_order_tracking_id'=>$order['order_tracking_id']??null,'checkout_url'=>$order['redirect_url'],'updated_at'=>gmdate('c')],true);
    json_response(['ok'=>true,'checkout_url'=>$order['redirect_url'],'order_reference'=>$orderRef,'message'=>'Complete the Pesapal payment. The bid will be reviewed only after payment verification.']);
}
if ($path==='/api/pricing') {
    $rows=supabase_request('GET','/rest/v1/advertisement_durations?active=eq.true&select=id,label,days,price_ugx&order=days',null,true);
    json_response(['durations'=>$rows]);
}
if ($path==='/api/advert' && $method==='POST') {
    $u=require_user();
    foreach (['title','description','category_id','location','duration_id'] as $f) if (empty($b[$f])) json_response(['error'=>"Missing $f"],422);
    $d=supabase_request('GET','/rest/v1/advertisement_durations?id=eq.'.urlencode($b['duration_id']).'&active=eq.true&select=*',null,true);
    if (!$d) json_response(['error'=>'Selected advert duration is unavailable.'],422);
    $duration=$d[0];
    // Administrator/website-owner adverts are free and publish immediately.
    $isOwnerAdmin=in_array(($u['role']??''),['admin','owner'],true);
    $base=(int)$duration['price_ugx']; $fee=$isOwnerAdmin?0:fee_for($base); $total=$isOwnerAdmin?0:$base+$fee;
    $imageUrl=null;
    if (!empty($_FILES['image']['tmp_name'])) {
        $f=$_FILES['image']; if ($f['size']>5*1024*1024) json_response(['error'=>'Image must be 5MB or smaller.'],422);
        $mime=mime_content_type($f['tmp_name']); if (!in_array($mime,['image/jpeg','image/png','image/webp'],true)) json_response(['error'=>'Upload JPG, PNG or WebP images only.'],422);
        $ext=match($mime){'image/png'=>'png','image/webp'=>'webp',default=>'jpg'};
        $imageUrl=storage_upload(envv('SUPABASE_STORAGE_BUCKET','advert-images'),$u['id'].'/'.bin2hex(random_bytes(12)).'.'.$ext,$f['tmp_name'],$mime);
    }
    $created=supabase_request('POST','/rest/v1/advertisements?select=id,title',[
      'seller_id'=>$u['id'],'title'=>trim($b['title']),'description'=>trim($b['description']),'category_id'=>$b['category_id'],
      'price'=>isset($b['price'])&&$b['price']!==''?(float)$b['price']:null,'location'=>trim($b['location']),
      'district'=>trim($b['district']??''),'duration_id'=>$duration['id'],
      'status'=>$isOwnerAdmin?'active':'pending_payment','image_url'=>$imageUrl,
      ...($isOwnerAdmin?['published_at'=>gmdate('c'),'expires_at'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+'.max(1,(int)$duration['days']).' days')->format('c')]:[])
    ],true);
    $ad=$created[0]??null; if (!$ad) throw new RuntimeException('Could not create advert.');
    if ($isOwnerAdmin) {
        json_response(['ok'=>true,'published'=>true,'message'=>'Your advert has been published free of charge.']);
    }
    $orderRef='NUMC-'.strtoupper(bin2hex(random_bytes(6)));
    supabase_request('POST','/rest/v1/advertisement_payments',[
      'advertisement_id'=>$ad['id'],'seller_id'=>$u['id'],'order_reference'=>$orderRef,'advert_fee_ugx'=>$base,
      'pesapal_fee_ugx'=>$fee,'total_ugx'=>$total,'currency'=>'UGX','status'=>'initiated'
    ],true);
    $token=pesapal_token();
    $ipnId=pesapal_notification_id();
    if (!$ipnId) {
        $ipn=pesapal_call('POST','/api/URLSetup/RegisterIPN',['url'=>rtrim(envv('APP_URL'),'/').'/pesapal/ipn','ipn_notification_type'=>'POST'],$token);
        $ipnId=$ipn['ipn_id']??''; if (!$ipnId) throw new RuntimeException('Pesapal IPN registration failed. Set PESAPAL_IPN_ID after registering the notification URL.');
    }
    $payload=['id'=>$orderRef,'currency'=>envv('PESAPAL_CURRENCY','UGX'),'amount'=>$total,
      'description'=>'Advertisement: '.$ad['title'],'callback_url'=>envv('PESAPAL_CALLBACK_URL',rtrim(envv('APP_URL'),'/').'/payment/return'),
      'notification_id'=>$ipnId,'billing_address'=>['email_address'=>$u['email'],'phone_number'=>'','country_code'=>'UG','first_name'=>substr($u['full_name'],0,40),'last_name'=>'Customer']];
    $order=pesapal_call('POST','/api/Transactions/SubmitOrderRequest',$payload,$token);
    if (empty($order['redirect_url'])) throw new RuntimeException('Pesapal did not return a checkout URL.');
    supabase_request('PATCH','/rest/v1/advertisement_payments?order_reference=eq.'.urlencode($orderRef),['pesapal_order_tracking_id'=>$order['order_tracking_id']??null,'checkout_url'=>$order['redirect_url']],true);
    json_response(['ok'=>true,'checkout_url'=>$order['redirect_url'],'order_reference'=>$orderRef]);
}
if ($path==='/api/admin/pricing' && $method==='GET') {
    require_admin(); $rows=supabase_request('GET','/rest/v1/advertisement_durations?select=*&order=days',null,true); json_response(['durations'=>$rows]);
}
if ($path==='/api/admin/pricing' && $method==='POST') {
    require_admin();
    $row=['label'=>trim($b['label']??''),'days'=>(int)($b['days']??0),'price_ugx'=>(int)($b['price_ugx']??0),'active'=>(bool)($b['active']??true)];
    if ($row['label']===''||$row['days']<1||$row['price_ugx']<0) json_response(['error'=>'Enter a label, positive number of days and valid price.'],422);
    if (!empty($b['id'])) supabase_request('PATCH','/rest/v1/advertisement_durations?id=eq.'.urlencode($b['id']),$row,true);
    else supabase_request('POST','/rest/v1/advertisement_durations',$row,true);
    json_response(['ok'=>true]);
}

if ($path==='/api/messages' && $method==='GET') {
    $u=require_user();
    $conv=supabase_request('GET','/rest/v1/conversations?or=(buyer_id.eq.'.$u['id'].',seller_id.eq.'.$u['id'].')&select=id,buyer_id,seller_id,advertisement_id,created_at,advertisements(title,image_url),buyer:profiles!conversations_buyer_id_fkey(full_name),seller:profiles!conversations_seller_id_fkey(full_name)&order=created_at.desc',null,true);
    foreach ($conv as &$c) {
        $last=supabase_request('GET','/rest/v1/messages?conversation_id=eq.'.$c['id'].'&select=body,created_at,sender_id,read_at&order=created_at.desc&limit=1',null,true);
        $c['last_message']=$last[0]??null;
        $unread=supabase_request('GET','/rest/v1/messages?conversation_id=eq.'.$c['id'].'&sender_id=neq.'.$u['id'].'&read_at=is.null&select=id',null,true);
        $c['unread_count']=count($unread);
        $c['other_party']=($c['buyer_id']===$u['id'])?($c['seller']['full_name']??'Seller'):($c['buyer']['full_name']??'Buyer');
    }
    unset($c);
    json_response(['conversations'=>$conv]);
}
if ($path==='/api/messages' && $method==='POST') {
    $u=require_user(); $message=trim((string)($b['message']??''));
    if ($message==='' || mb_strlen($message)>5000) json_response(['error'=>'Message must be between 1 and 5,000 characters.'],422);
    $ad=supabase_request('GET','/rest/v1/advertisements?id=eq.'.urlencode($b['advertisement_id']??'').'&status=eq.active&select=id,seller_id',null,true);
    if (!$ad) json_response(['error'=>'Advert not found or inactive.'],404);
    if ($ad[0]['seller_id']===$u['id']) json_response(['error'=>'You cannot message yourself.'],422);
    $existing=supabase_request('GET','/rest/v1/conversations?advertisement_id=eq.'.urlencode($ad[0]['id']).'&buyer_id=eq.'.$u['id'].'&seller_id=eq.'.$ad[0]['seller_id'].'&select=id',null,true);
    $cid=$existing[0]['id']??null;
    if (!$cid) { $new=supabase_request('POST','/rest/v1/conversations?select=id',['advertisement_id'=>$ad[0]['id'],'buyer_id'=>$u['id'],'seller_id'=>$ad[0]['seller_id']],true); $cid=$new[0]['id']; }
    supabase_request('POST','/rest/v1/messages',['conversation_id'=>$cid,'sender_id'=>$u['id'],'body'=>$message],true);
    json_response(['ok'=>true,'conversation_id'=>$cid]);
}
if (preg_match('#^/api/messages/([0-9a-f-]+)/?$#',$path,$m) && $method==='GET') {
    $u=require_user(); $cid=$m[1];
    $c=supabase_request('GET','/rest/v1/conversations?id=eq.'.$cid.'&or=(buyer_id.eq.'.$u['id'].',seller_id.eq.'.$u['id'].')&select=id,buyer_id,seller_id,advertisement_id,advertisements(title)',null,true);
    if (!$c) json_response(['error'=>'Conversation not found.'],404);
    $msgs=supabase_request('GET','/rest/v1/messages?conversation_id=eq.'.$cid.'&select=id,body,sender_id,created_at,read_at,sender:profiles!messages_sender_id_fkey(full_name)&order=created_at.asc&limit=300',null,true);
    supabase_request('PATCH','/rest/v1/messages?conversation_id=eq.'.$cid.'&sender_id=neq.'.$u['id'].'&read_at=is.null',['read_at'=>gmdate('c')],true);
    json_response(['messages'=>$msgs,'conversation'=>$c[0]]);
}
if (preg_match('#^/api/messages/([0-9a-f-]+)/?$#',$path,$m) && $method==='POST') {
    $u=require_user(); $cid=$m[1]; $message=trim((string)($b['message']??''));
    if ($message==='' || mb_strlen($message)>5000) json_response(['error'=>'Message must be between 1 and 5,000 characters.'],422);
    $c=supabase_request('GET','/rest/v1/conversations?id=eq.'.$cid.'&or=(buyer_id.eq.'.$u['id'].',seller_id.eq.'.$u['id'].')&select=id',null,true);
    if (!$c) json_response(['error'=>'Conversation not found.'],404);
    supabase_request('POST','/rest/v1/messages',['conversation_id'=>$cid,'sender_id'=>$u['id'],'body'=>$message],true);
    json_response(['ok'=>true]);
}
if ($path==='/api/my-adverts' && $method==='GET') {
    $u=require_user();
    $rows=supabase_request('GET','/rest/v1/advertisements?seller_id=eq.'.$u['id'].'&select=*,categories(name),advertisement_durations(label,days)&order=created_at.desc',null,true);
    json_response(['adverts'=>$rows]);
}
if ($path==='/api/reports' && $method==='POST') {
    $u=require_user(); $adId=$b['advertisement_id']??''; $reason=trim((string)($b['reason']??'')); $details=trim((string)($b['details']??''));
    if (!$adId || !in_array($reason,['Fraud or scam','Prohibited item','Misleading information','Harassment or abuse','Other'],true)) json_response(['error'=>'Choose a valid report reason.'],422);
    $ad=supabase_request('GET','/rest/v1/advertisements?id=eq.'.urlencode($adId).'&select=id',null,true);
    if (!$ad) json_response(['error'=>'Advert not found.'],404);
    supabase_request('POST','/rest/v1/advertisement_reports',['advertisement_id'=>$adId,'reporter_id'=>$u['id'],'reason'=>$reason,'details'=>$details],true);
    json_response(['ok'=>true,'message'=>'Thank you. Our moderation team will review this report.']);
}
if ($path==='/api/admin/overview' && $method==='GET') {
    require_admin();
    $ads=supabase_request('GET','/rest/v1/advertisements?select=id,status',null,true);
    $users=supabase_request('GET','/rest/v1/profiles?select=id',null,true);
    $payments=supabase_request('GET','/rest/v1/advertisement_payments?select=id,status,total_ugx,created_at&order=created_at.desc&limit=200',null,true);
    $reports=supabase_request('GET','/rest/v1/advertisement_reports?status=eq.open&select=id',null,true);
    $revenue=0; foreach($payments as $pay) if(($pay['status']??'')==='completed') $revenue+=(int)$pay['total_ugx'];
    json_response(['stats'=>['users'=>count($users),'adverts'=>count($ads),'active'=>count(array_filter($ads,fn($a)=>$a['status']==='active')),'pending'=>count(array_filter($ads,fn($a)=>$a['status']==='pending_review')),'reports'=>count($reports),'payments'=>count($payments),'gross_ugx'=>$revenue]]);
}
if ($path==='/api/admin/promotions' && $method==='GET') {
    require_admin();
    $campaigns=supabase_request('GET','/rest/v1/promotion_campaigns?select=*&order=created_at.desc&limit=100',null,true);
    $bids=supabase_request('GET','/rest/v1/promotion_bids?select=*,advertisements(title,image_url,status,location),profiles!promotion_bids_seller_id_fkey(full_name)&order=bid_ugx.desc&limit=150',null,true);
    json_response(['campaigns'=>$campaigns,'bids'=>$bids]);
}
if ($path==='/api/admin/promotions' && $method==='POST') {
    $admin=require_admin();$title=trim((string)($b['title']??''));$description=trim((string)($b['description']??''));
    $image=trim((string)($b['image_url']??''));$target=trim((string)($b['target_url']??''));
    if($title===''||mb_strlen($title)>120)json_response(['error'=>'Enter a promotion title up to 120 characters.'],422);
    if($image!==''&&!filter_var($image,FILTER_VALIDATE_URL))json_response(['error'=>'Enter a valid image URL.'],422);
    if($target!==''&&!str_starts_with($target,'/')&&!filter_var($target,FILTER_VALIDATE_URL))json_response(['error'=>'Enter a valid destination URL.'],422);
    if($target!==''&&filter_var($target,FILTER_VALIDATE_URL)&&!in_array(strtolower((string)parse_url($target,PHP_URL_SCHEME)),['http','https'],true))json_response(['error'=>'Destination must use HTTP or HTTPS.'],422);
    supabase_request('POST','/rest/v1/promotion_campaigns',['title'=>$title,'description'=>$description,'image_url'=>$image?:null,'target_url'=>$target?:'/marketplace','active'=>(bool)($b['active']??true),'created_by'=>$admin['id']],true);
    json_response(['ok'=>true]);
}
if (preg_match('#^/api/admin/promotions/bids/([0-9a-f-]+)/?$#',$path,$m) && $method==='PATCH') {
    require_admin();$status=$b['status']??'';
    if(!in_array($status,['approved','rejected','paused'],true))json_response(['error'=>'Invalid bid decision.'],422);
    if($status==='approved'){
      $paid=supabase_request('GET','/rest/v1/promotion_bid_payments?promotion_bid_id=eq.'.$m[1].'&select=id,status&order=created_at.desc&limit=1',null,true);
      if(!$paid || ($paid[0]['status']??'')!=='completed')json_response(['error'=>'A promotion cannot be approved until its Pesapal payment is verified.'],422);
    }
    $patch=['status'=>$status,'updated_at'=>gmdate('c')];
    if($status==='approved'){$patch['starts_at']=gmdate('c');$patch['ends_at']=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+7 days')->format('c');}
    supabase_request('PATCH','/rest/v1/promotion_bids?id=eq.'.$m[1],$patch,true);json_response(['ok'=>true]);
}
if (preg_match('#^/api/admin/promotions/campaigns/([0-9a-f-]+)/?$#',$path,$m) && $method==='PATCH') {
    require_admin();supabase_request('PATCH','/rest/v1/promotion_campaigns?id=eq.'.$m[1],['active'=>(bool)($b['active']??false)],true);json_response(['ok'=>true]);
}
if ($path==='/api/admin/adverts' && $method==='GET') {
    require_admin(); $status=$_GET['status']??'all';
    $filter='select=*,profiles!advertisements_seller_id_fkey(full_name),categories(name),advertisement_durations(label,days)&order=created_at.desc&limit=250';
    if ($status!=='all') $filter.='&status=eq.'.urlencode($status);
    $rows=supabase_request('GET','/rest/v1/advertisements?'.$filter,null,true); json_response(['adverts'=>$rows]);
}
if (preg_match('#^/api/admin/adverts/([0-9a-f-]+)/?$#',$path,$m) && $method==='PATCH') {
    require_admin(); $id=$m[1]; $status=$b['status']??'';
    if (!in_array($status,['active','rejected','suspended','pending_review'],true)) json_response(['error'=>'Invalid moderation action.'],422);
    $patch=['status'=>$status];
    if ($status==='active') {
      $ad=supabase_request('GET','/rest/v1/advertisements?id=eq.'.$id.'&select=duration_id',null,true);
      if(!$ad)json_response(['error'=>'Advert not found.'],404);
      $paid=supabase_request('GET','/rest/v1/advertisement_payments?advertisement_id=eq.'.$id.'&status=eq.completed&select=id&limit=1',null,true);
      if(!$paid)json_response(['error'=>'This advert cannot be approved because no completed Pesapal payment was verified.'],422);
      $d=supabase_request('GET','/rest/v1/advertisement_durations?id=eq.'.$ad[0]['duration_id'].'&select=days',null,true);
      $patch['published_at']=gmdate('c'); $patch['expires_at']=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+'.max(1,(int)($d[0]['days']??30)).' days')->format('c');
    }
    supabase_request('PATCH','/rest/v1/advertisements?id=eq.'.$id,$patch,true); json_response(['ok'=>true]);
}
if ($path==='/api/admin/reports' && $method==='GET') {
    require_admin(); $rows=supabase_request('GET','/rest/v1/advertisement_reports?select=*,advertisements(title,status),profiles!advertisement_reports_reporter_id_fkey(full_name)&order=created_at.desc&limit=250',null,true); json_response(['reports'=>$rows]);
}
if (preg_match('#^/api/admin/reports/([0-9a-f-]+)/?$#',$path,$m) && $method==='PATCH') {
    require_admin(); $status=$b['status']??'';
    if(!in_array($status,['reviewed','dismissed'],true))json_response(['error'=>'Invalid report status.'],422);
    supabase_request('PATCH','/rest/v1/advertisement_reports?id=eq.'.$m[1],['status'=>$status,'reviewed_by'=>current_user()['id'],'reviewed_at'=>gmdate('c')],true); json_response(['ok'=>true]);
}
if ($path==='/api/admin/users' && $method==='GET') {
    require_admin(); $rows=supabase_request('GET','/rest/v1/profiles?select=id,full_name,phone,role,account_status,suspension_reason,created_at&order=created_at.desc&limit=300',null,true); json_response(['users'=>$rows]);
}
if (preg_match('#^/api/admin/users/([0-9a-f-]+)/?$#',$path,$m) && $method==='PATCH') {
    $admin=require_admin(); if($m[1]===$admin['id'])json_response(['error'=>'You cannot change your own administrator role.'],422);
    $role=$b['role']??''; if(!in_array($role,['user','admin'],true))json_response(['error'=>'Invalid role.'],422);
    supabase_request('PATCH','/rest/v1/profiles?id=eq.'.$m[1],['role'=>$role],true); json_response(['ok'=>true]);
}
if ($path==='/api/admin/audit-logs' && $method==='GET') {
    require_admin();$rows=supabase_request('GET','/rest/v1/admin_audit_logs?select=*&order=created_at.desc&limit=200',null,true);json_response(['logs'=>$rows]);
}
if (preg_match('#^/api/admin/users/([0-9a-f-]+)/status/?$#',$path,$m) && $method==='PATCH') {
    $admin=require_admin();if($m[1]===$admin['id'])json_response(['error'=>'You cannot suspend your own account.'],422);
    $status=$b['account_status']??'';if(!in_array($status,['active','suspended','closed'],true))json_response(['error'=>'Invalid account status.'],422);
    $target=supabase_request('GET','/rest/v1/profiles?id=eq.'.$m[1].'&select=role',null,true);if(!$target)json_response(['error'=>'User not found.'],404);if(($target[0]['role']??'')==='owner')json_response(['error'=>'The protected website owner account cannot be suspended or closed.'],403);
    $reason=trim((string)($b['reason']??''));if($status==='suspended'&&$reason==='')json_response(['error'=>'Provide a reason for suspension.'],422);
    supabase_request('PATCH','/rest/v1/profiles?id=eq.'.$m[1],['account_status'=>$status,'suspended_at'=>$status==='suspended'?gmdate('c'):null,'suspension_reason'=>$status==='suspended'?$reason:null],true);
    json_response(['ok'=>true]);
}
if ($path==='/api/support' && $method==='GET') {
    $u=require_user(true);$rows=supabase_request('GET','/rest/v1/support_tickets?requester_id=eq.'.$u['id'].'&select=*,support_messages(id,sender_id,body,internal_note,created_at)&order=created_at.desc&limit=100',null,true);json_response(['tickets'=>$rows]);
}
if ($path==='/api/support' && $method==='POST') {
    $u=require_user(true);$subject=trim((string)($b['subject']??''));$category=$b['category']??'general';$message=trim((string)($b['message']??''));
    if(mb_strlen($subject)<3||mb_strlen($subject)>160||mb_strlen($message)<5||mb_strlen($message)>5000)json_response(['error'=>'Enter a subject and a message (5–5,000 characters).'],422);
    $t=supabase_request('POST','/rest/v1/support_tickets?select=id',['requester_id'=>$u['id'],'subject'=>$subject,'category'=>$category],true);$id=$t[0]['id']??'';
    if(!$id)throw new RuntimeException('Could not open support ticket.');
    supabase_request('POST','/rest/v1/support_messages',['ticket_id'=>$id,'sender_id'=>$u['id'],'body'=>$message],true);json_response(['ok'=>true,'ticket_id'=>$id]);
}
if (preg_match('#^/api/support/([0-9a-f-]+)/?$#',$path,$m) && $method==='POST') {
    $u=require_user(true);$ticket=supabase_request('GET','/rest/v1/support_tickets?id=eq.'.$m[1].'&requester_id=eq.'.$u['id'].'&select=id,status',null,true);
    if(!$ticket)json_response(['error'=>'Support ticket not found.'],404);
    $message=trim((string)($b['message']??''));if($message===''||mb_strlen($message)>5000)json_response(['error'=>'Enter a message up to 5,000 characters.'],422);
    supabase_request('POST','/rest/v1/support_messages',['ticket_id'=>$m[1],'sender_id'=>$u['id'],'body'=>$message],true);
    supabase_request('PATCH','/rest/v1/support_tickets?id=eq.'.$m[1],['status'=>'open','updated_at'=>gmdate('c')],true);json_response(['ok'=>true]);
}
if ($path==='/api/disputes' && $method==='GET') {
    $u=require_user(true);$rows=supabase_request('GET','/rest/v1/dispute_cases?opened_by=eq.'.$u['id'].'&select=*&order=created_at.desc&limit=100',null,true);json_response(['disputes'=>$rows]);
}
if ($path==='/api/admin/support' && $method==='GET') {
    require_admin();
    try {
      // Fetch base rows and related records separately. This avoids depending on
      // PostgREST relationship-cache names for nested profile/message joins.
      $rows=supabase_request('GET','/rest/v1/support_tickets?select=*&order=updated_at.desc&limit=200',null,true);
      if (!$rows) json_response(['tickets'=>[]]);
      $requesterIds=array_values(array_unique(array_filter(array_column($rows,'requester_id'))));
      $ticketIds=array_values(array_unique(array_filter(array_column($rows,'id'))));
      $profiles=[];
      $messages=[];
      if ($requesterIds) {
        $in='('.implode(',',array_map(static fn($id)=>rawurlencode((string)$id),$requesterIds)).')';
        $people=supabase_request('GET','/rest/v1/profiles?id=in.'.$in.'&select=id,full_name',null,true);
        foreach($people as $person) $profiles[$person['id']]=$person;
      }
      if ($ticketIds) {
        $in='('.implode(',',array_map(static fn($id)=>rawurlencode((string)$id),$ticketIds)).')';
        $items=supabase_request('GET','/rest/v1/support_messages?ticket_id=in.'.$in.'&select=id,ticket_id,sender_id,body,internal_note,created_at&order=created_at.asc',null,true);
        foreach($items as $item) $messages[$item['ticket_id']][]=$item;
      }
      foreach($rows as &$ticket) {
        $ticket['profiles']=$profiles[$ticket['requester_id']??'']??['full_name'=>'Customer'];
        $ticket['support_messages']=$messages[$ticket['id']??'']??[];
      }
      unset($ticket);
      json_response(['tickets'=>$rows]);
    } catch(Throwable $e) {
      error_log('[admin-support] '.$e->getMessage());
      json_response(['error'=>'Customer support data could not be loaded. Confirm that database/upgrade_v3.sql has been applied to the marketplace Supabase project.'],503);
    }
}
if (preg_match('#^/api/admin/support/([0-9a-f-]+)/reply/?$#',$path,$m) && $method==='POST') {
    $admin=require_admin();$message=trim((string)($b['message']??''));if($message===''||mb_strlen($message)>5000)json_response(['error'=>'Enter a reply up to 5,000 characters.'],422);
    $ticket=supabase_request('GET','/rest/v1/support_tickets?id=eq.'.$m[1].'&select=id',null,true);if(!$ticket)json_response(['error'=>'Support ticket not found.'],404);
    supabase_request('POST','/rest/v1/support_messages',['ticket_id'=>$m[1],'sender_id'=>$admin['id'],'body'=>$message],true);json_response(['ok'=>true]);
}
if (preg_match('#^/api/admin/support/([0-9a-f-]+)/?$#',$path,$m) && $method==='PATCH') {
    require_admin();$status=$b['status']??'';if(!in_array($status,['open','in_progress','waiting_customer','resolved','closed'],true))json_response(['error'=>'Invalid support status.'],422);
    supabase_request('PATCH','/rest/v1/support_tickets?id=eq.'.$m[1],['status'=>$status,'assigned_to'=>current_user()['id'],'updated_at'=>gmdate('c')],true);json_response(['ok'=>true]);
}
if ($path==='/api/disputes' && $method==='POST') {
    $u=require_user(true);$reason=trim((string)($b['reason']??''));$description=trim((string)($b['description']??''));
    if(mb_strlen($reason)<3||mb_strlen($reason)>160||$description===''||mb_strlen($description)>5000)json_response(['error'=>'Enter a dispute reason and description.'],422);
    $row=['opened_by'=>$u['id'],'reason'=>$reason,'description'=>$description,'advertisement_id'=>$b['advertisement_id']??null,'payment_order_reference'=>$b['payment_order_reference']??null];
    $created=supabase_request('POST','/rest/v1/dispute_cases?select=id',$row,true);json_response(['ok'=>true,'dispute_id'=>$created[0]['id']??null]);
}
if ($path==='/api/admin/disputes' && $method==='GET') {
    require_admin();$rows=supabase_request('GET','/rest/v1/dispute_cases?select=*,profiles!dispute_cases_opened_by_fkey(full_name)&order=created_at.desc&limit=200',null,true);json_response(['disputes'=>$rows]);
}
if (preg_match('#^/api/admin/disputes/([0-9a-f-]+)/?$#',$path,$m) && $method==='PATCH') {
    require_admin();$status=$b['status']??'';if(!in_array($status,['open','investigating','resolved_buyer','resolved_seller','closed'],true))json_response(['error'=>'Invalid dispute status.'],422);
    supabase_request('PATCH','/rest/v1/dispute_cases?id=eq.'.$m[1],['status'=>$status,'resolution'=>trim((string)($b['resolution']??'')),'resolved_by'=>in_array($status,['resolved_buyer','resolved_seller','closed'],true)?current_user()['id']:null,'resolved_at'=>in_array($status,['resolved_buyer','resolved_seller','closed'],true)?gmdate('c'):null],true);json_response(['ok'=>true]);
}
if ($path==='/api/admin/categories' && $method==='GET') {
    require_admin(); $rows=supabase_request('GET','/rest/v1/categories?select=*&order=name',null,true); json_response(['categories'=>$rows]);
}
if ($path==='/api/admin/categories' && $method==='POST') {
    require_admin(); $name=trim((string)($b['name']??'')); if($name===''||mb_strlen($name)>80)json_response(['error'=>'Enter a category name up to 80 characters.'],422);
    supabase_request('POST','/rest/v1/categories',['name'=>$name,'active'=>true],true); json_response(['ok'=>true]);
}
if (preg_match('#^/api/admin/categories/([0-9a-f-]+)/?$#',$path,$m) && $method==='PATCH') {
    require_admin(); $name=trim((string)($b['name']??'')); if($name===''||mb_strlen($name)>80)json_response(['error'=>'Enter a category name up to 80 characters.'],422);
    supabase_request('PATCH','/rest/v1/categories?id=eq.'.$m[1],['name'=>$name,'active'=>(bool)($b['active']??true)],true); json_response(['ok'=>true]);
}
if ($path==='/api/admin/payments' && $method==='GET') {
    require_admin(); $rows=supabase_request('GET','/rest/v1/advertisement_payments?select=*,advertisements(title),profiles!advertisement_payments_seller_id_fkey(full_name)&order=created_at.desc&limit=300',null,true); $promo=supabase_request('GET','/rest/v1/promotion_bid_payments?select=*,promotion_bids(advertisement_id,advertisements(title)),profiles!promotion_bid_payments_seller_id_fkey(full_name)&order=created_at.desc&limit=300',null,true); foreach($promo as &$p){$p['payment_type']='promotion';$p['advertisements']=$p['promotion_bids']['advertisements']??['title'=>'Promotion bid'];$p['total_ugx']=$p['amount_ugx'];} unset($p); foreach($rows as &$r)$r['payment_type']='advert'; unset($r); json_response(['payments'=>array_merge($rows,$promo)]);
}
if ($path==='/api/admin/pricing' && $method==='PATCH') {
    require_admin(); $id=$b['id']??''; if(!$id)json_response(['error'=>'Pricing ID required.'],422);
    $row=['label'=>trim($b['label']??''),'days'=>(int)($b['days']??0),'price_ugx'=>(int)($b['price_ugx']??-1),'active'=>(bool)($b['active']??true)];
    if($row['label']===''||$row['days']<1||$row['price_ugx']<0)json_response(['error'=>'Invalid pricing details.'],422);
    supabase_request('PATCH','/rest/v1/advertisement_durations?id=eq.'.urlencode($id),$row,true); json_response(['ok'=>true]);
}
if ($path==='/api/admin/settings' && $method==='GET') {
    require_admin(); $rows=supabase_request('GET','/rest/v1/platform_settings?select=key,value',null,true); $v=[]; foreach($rows as $r)$v[$r['key']]=$r['value'];
    json_response(['settings'=>['fee_mode'=>$v['payment_fee_mode']??envv('PESAPAL_FEE_MODE','none'),'fee_fixed_ugx'=>(int)($v['payment_fee_fixed_ugx']??envv('PESAPAL_FEE_FIXED_UGX','0')),'fee_percent'=>(float)($v['payment_fee_percent']??envv('PESAPAL_FEE_PERCENT','0')),'pesapal_env'=>pesapal_environment()]]);
}
if ($path==='/api/admin/settings' && $method==='PATCH') {
    require_admin(); $mode=$b['fee_mode']??'none'; $fixed=(int)($b['fee_fixed_ugx']??0); $pct=(float)($b['fee_percent']??0);
    if(!in_array($mode,['none','fixed','percentage','fixed_plus_percentage'],true)||$fixed<0||$pct<0||$pct>100)json_response(['error'=>'Invalid payment fee settings.'],422);
    foreach(['payment_fee_mode'=>$mode,'payment_fee_fixed_ugx'=>(string)$fixed,'payment_fee_percent'=>(string)$pct] as $key=>$value) {
      $exists=supabase_request('GET','/rest/v1/platform_settings?key=eq.'.urlencode($key).'&select=key',null,true);
      if($exists) supabase_request('PATCH','/rest/v1/platform_settings?key=eq.'.urlencode($key),['value'=>$value,'updated_at'=>gmdate('c')],true);
      else supabase_request('POST','/rest/v1/platform_settings',['key'=>$key,'value'=>$value],true);
    }
    json_response(['ok'=>true]);
}
if ($path==='/api/admin/payments/test' && $method==='POST') {
    require_admin();
    $checks=['supabase'=>false,'pesapal_token'=>false];
    $sup=supabase_request('GET','/rest/v1/categories?select=id&limit=1',null,true); $checks['supabase']=true;
    try { pesapal_token(); $checks['pesapal_token']=true; } catch(Throwable $e) { $checks['pesapal_error']=$e->getMessage(); }
    json_response(['checks'=>$checks,'message'=>($checks['supabase']&&$checks['pesapal_token'])?'Connections successful. Complete a real sandbox checkout to test callback and IPN.':'One or more checks failed.'],200);
}
json_response(['error'=>'Endpoint not found.'],404);
} catch(Throwable $e) { api_error($e); }
