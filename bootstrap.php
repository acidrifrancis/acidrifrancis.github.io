<?php
declare(strict_types=1);

function load_env_file(): void {
    $file = dirname(__DIR__) . '/.env';
    if (!is_file($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $v = trim($v);
        if (strlen($v) >= 2 && (($v[0] === '"' && $v[-1] === '"') || ($v[0] === "'" && $v[-1] === "'"))) $v = substr($v, 1, -1);
        $_ENV[trim($k)] = $v;
    }
}
load_env_file();
function envv(string $key, string $default = ''): string { return (string)($_ENV[$key] ?? getenv($key) ?: $default); }
define('APP_NAME', envv('APP_NAME', 'NOTHERN UGANDA MARKET CONNECT'));
date_default_timezone_set(envv('APP_TIMEZONE', 'Africa/Kampala'));
$isSecure = (($_SERVER['HTTPS'] ?? '') === 'on') || envv('SESSION_SECURE', 'false') === 'true';
ini_set('session.use_strict_mode','1');
ini_set('session.use_only_cookies','1');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$isSecure,'httponly'=>true,'samesite'=>'Lax']);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));

function json_response(array $data, int $status=200): never {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES); exit;
}
function body_json(): array {
    $raw = file_get_contents('php://input');
    if (str_contains(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data')) return $_POST;
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}
function supabase_request(string $method, string $path, ?array $payload=null, bool $admin=false, ?string $jwt=null): array {
    $base = rtrim(envv('SUPABASE_URL'), '/');
    $key = $admin ? envv('SUPABASE_SERVICE_ROLE_KEY') : envv('SUPABASE_ANON_KEY');
    if (!$base || !$key) throw new RuntimeException('Supabase environment variables are not configured.');
    $headers = ['apikey: '.$key, 'Authorization: Bearer '.($jwt ?: $key), 'Content-Type: application/json', 'Accept: application/json'];
    if (str_starts_with($path, '/rest/v1/') && in_array($method, ['POST','PATCH'], true)) $headers[] = 'Prefer: return=representation';
    $ch = curl_init($base.$path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_HTTPHEADER=>$headers, CURLOPT_TIMEOUT=>25]);
    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $response = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($response === false) { $err = curl_error($ch); curl_close($ch); throw new RuntimeException('Supabase connection failed: '.$err); }
    curl_close($ch); $decoded = json_decode($response, true);
    if ($code >= 400) throw new RuntimeException(is_array($decoded) ? ($decoded['message'] ?? $decoded['msg'] ?? $decoded['error_description'] ?? 'Supabase request failed') : 'Supabase request failed');
    return is_array($decoded) ? $decoded : [];
}
function current_user(): ?array { return $_SESSION['user'] ?? null; }
function require_user(bool $allowSuspended=false): array {
    $u=current_user();
    if (!$u) json_response(['error'=>'Please log in first.'],401);
    // Re-check account state on protected requests so a suspended seller loses access immediately.
    try {
      $profile=supabase_request('GET','/rest/v1/profiles?id=eq.'.urlencode($u['id']).'&select=account_status,role',null,true);
    } catch(Throwable $migrationNotApplied) {
      // Keep existing accounts working while an operator is applying v3; role is still refreshed.
      error_log('[account-status] v3 profile columns unavailable: '.$migrationNotApplied->getMessage());
      $profile=supabase_request('GET','/rest/v1/profiles?id=eq.'.urlencode($u['id']).'&select=role',null,true);
    }
    $state=$profile[0]['account_status']??'active';
    if (!empty($profile[0]['role'])) { $_SESSION['user']['role']=$profile[0]['role']; $u=$_SESSION['user']; }
    if ($state!=='active' && !$allowSuspended) json_response(['error'=>'This account is suspended or closed. Use Help & disputes to contact marketplace support.'],403);
    return $u;
}
function rate_limit(string $bucket,string $subject,int $limit,int $windowSeconds): void {
    $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
    $hash=hash('sha256',$bucket.'|'.$subject.'|'.$ip);
    $since=gmdate('c',time()-$windowSeconds);
    try {
      $rows=supabase_request('GET','/rest/v1/rate_limit_events?bucket=eq.'.urlencode($bucket).'&subject_hash=eq.'.$hash.'&created_at=gte.'.urlencode($since).'&select=id&limit='.($limit+1),null,true);
      if(count($rows)>=$limit) json_response(['error'=>'Too many attempts. Please wait and try again later.'],429);
      supabase_request('POST','/rest/v1/rate_limit_events',['bucket'=>$bucket,'subject_hash'=>$hash],true);
    } catch(Throwable $e) {
      // Avoid locking out all authentication while the v3 rate-limit table is being installed.
      error_log('[rate-limit-unavailable] '.$e->getMessage());
    }
}
function audit_admin_action(array $admin): void {
    try {
      supabase_request('POST','/rest/v1/admin_audit_logs',[
        'actor_id'=>$admin['id'],'action'=>($_SERVER['REQUEST_METHOD']??'').' '.(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/'),
        'target_type'=>'api_request','target_id'=>null,'details'=>['role'=>$admin['role']??'admin']
      ],true);
    } catch(Throwable $e) { error_log('[admin-audit] '.$e->getMessage()); }
}
function require_admin(): array { $u=require_user(); if (!in_array(($u['role'] ?? ''), ['admin','owner'], true)) json_response(['error'=>'Administrator access required.'],403); audit_admin_action($u); return $u; }
function api_error(Throwable $e): never { json_response(['error'=>envv('APP_DEBUG')==='true'?$e->getMessage():'The request could not be completed. Please try again.'],400); }
function pesapal_environment(): string {
    $value=strtolower(trim(envv('PESAPAL_ENVIRONMENT',envv('PESAPAL_ENV','sandbox'))));
    return in_array($value,['live','production','prod'],true) ? 'live' : 'sandbox';
}
function pesapal_notification_id(): string {
    return trim(envv('PESAPAL_NOTIFICATION_ID',envv('PESAPAL_IPN_ID')));
}
function pesapal_base(): string { return pesapal_environment() === 'live' ? 'https://pay.pesapal.com/v3' : 'https://cybqa.pesapal.com/pesapalv3'; }
function pesapal_call(string $method, string $endpoint, ?array $payload=null, ?string $token=null): array {
    $headers=['Accept: application/json','Content-Type: application/json'];
    if ($token) $headers[]='Authorization: Bearer '.$token;
    $ch=curl_init(pesapal_base().$endpoint);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>35]);
    if ($payload!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload));
    $raw=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    if ($raw===false) { $err=curl_error($ch); curl_close($ch); throw new RuntimeException('Pesapal connection error: '.$err); }
    curl_close($ch); $data=json_decode($raw,true);
    if (!is_array($data)) throw new RuntimeException('Pesapal returned a non-JSON response (HTTP '.$code.').');
    $apiError=$data['error']??null;
    if ($code>=400 || ($apiError!==null && $apiError!==false && $apiError!=='')) {
        $detail=is_array($apiError)?($apiError['message']??$apiError['code']??''):($apiError?:'');
        if (!$detail) $detail=$data['message']??$data['msg']??$data['error_description']??'Request rejected';
        throw new RuntimeException('Pesapal API error (HTTP '.$code.'): '.substr((string)$detail,0,350));
    }
    return $data;
}
function pesapal_token(): string {
    $consumerKey=trim(envv('PESAPAL_CONSUMER_KEY'));
    $consumerSecret=trim(envv('PESAPAL_CONSUMER_SECRET'));
    if ($consumerKey==='' || $consumerSecret==='') {
        throw new RuntimeException('Pesapal credentials are missing in Render. Set PESAPAL_CONSUMER_KEY and PESAPAL_CONSUMER_SECRET.');
    }
    $d=pesapal_call('POST','/api/Auth/RequestToken',['consumer_key'=>$consumerKey,'consumer_secret'=>$consumerSecret]);
    if (empty($d['token'])) {
        $detail=$d['message']??$d['error_description']??'No token field was included in the response';
        throw new RuntimeException('Pesapal authentication failed: '.substr((string)$detail,0,300).'. Check that the consumer key and secret belong to the selected '.strtoupper(pesapal_environment()).' environment.');
    }
    return (string)$d['token'];
}
function fee_for(int $base): int {
    $settings=[];
    try { foreach(supabase_request('GET','/rest/v1/platform_settings?select=key,value',null,true) as $r) $settings[$r['key']]=$r['value']; } catch(Throwable $e) {}
    $mode=$settings['payment_fee_mode']??envv('PESAPAL_FEE_MODE','none');
    $fixed=(float)($settings['payment_fee_fixed_ugx']??envv('PESAPAL_FEE_FIXED_UGX','0'));
    $pct=(float)($settings['payment_fee_percent']??envv('PESAPAL_FEE_PERCENT','0'));
    $fee=match($mode) {'fixed'=>$fixed,'percentage'=>$base*$pct/100,'fixed_plus_percentage'=>$fixed+($base*$pct/100),default=>0};
    return (int)ceil(max(0,$fee));
}
function storage_upload(string $bucket, string $path, string $tmp, string $mime): string {
    $base=rtrim(envv('SUPABASE_URL'),'/'); $key=envv('SUPABASE_SERVICE_ROLE_KEY');
    $ch=curl_init($base.'/storage/v1/object/'.rawurlencode($bucket).'/'.$path);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>'POST',CURLOPT_HTTPHEADER=>['apikey: '.$key,'Authorization: Bearer '.$key,'Content-Type: '.$mime,'x-upsert: true'],CURLOPT_POSTFIELDS=>file_get_contents($tmp),CURLOPT_TIMEOUT=>45]);
    $raw=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code>=300) throw new RuntimeException('Image upload failed.');
    return $base.'/storage/v1/object/public/'.rawurlencode($bucket).'/'.$path;
}


function verify_and_publish(string $ref,string $tracking): void {
    $token=pesapal_token();
    $status=pesapal_call('GET','/api/Transactions/GetTransactionStatus?orderTrackingId='.urlencode($tracking),null,$token);
    $code=strtoupper((string)($status['status_code']??''));
    $payment=supabase_request('GET','/rest/v1/advertisement_payments?order_reference=eq.'.urlencode($ref).'&select=*',null,true);
    if (!$payment) {
        $bidPayments=supabase_request('GET','/rest/v1/promotion_bid_payments?order_reference=eq.'.urlencode($ref).'&select=*',null,true);
        if (!$bidPayments) return;
        $bidPay=$bidPayments[0];
        if (!empty($bidPay['pesapal_order_tracking_id']) && $bidPay['pesapal_order_tracking_id'] !== $tracking) throw new RuntimeException('Pesapal promotion tracking reference mismatch.');
        if ($code==='1' || $code==='COMPLETED') {
            if ((int)round((float)($status['amount']??0)) !== (int)$bidPay['amount_ugx']) throw new RuntimeException('Verified promotion payment amount mismatch.');
            if (strtoupper((string)($status['currency']??'UGX')) !== strtoupper((string)$bidPay['currency'])) throw new RuntimeException('Verified promotion payment currency mismatch.');
            if (($bidPay['status']??'')!=='completed') {
                supabase_request('PATCH','/rest/v1/promotion_bid_payments?order_reference=eq.'.urlencode($ref).'&status=neq.completed',['status'=>'completed','pesapal_status'=>$status,'updated_at'=>gmdate('c')],true);
                supabase_request('PATCH','/rest/v1/promotion_bids?id=eq.'.$bidPay['promotion_bid_id'].'&status=eq.pending_payment',['status'=>'pending','updated_at'=>gmdate('c')],true);
            }
        } else {
            $desc=strtolower((string)($status['payment_status_description']??'pending'));
            supabase_request('PATCH','/rest/v1/promotion_bid_payments?order_reference=eq.'.urlencode($ref).'&status=neq.completed',['status'=>$desc,'pesapal_status'=>$status,'updated_at'=>gmdate('c')],true);
        }
        return;
    }
    $pay=$payment[0];
    if (!empty($pay['pesapal_order_tracking_id']) && $pay['pesapal_order_tracking_id'] !== $tracking) {
        throw new RuntimeException('Pesapal tracking reference mismatch.');
    }
    if ($code==='1' || $code==='COMPLETED') {
        if ((int)round((float)($status['amount']??0)) !== (int)$pay['total_ugx']) throw new RuntimeException('Verified payment amount mismatch.');
        if (strtoupper((string)($status['currency']??'UGX')) !== strtoupper((string)$pay['currency'])) throw new RuntimeException('Verified payment currency mismatch.');
        if ($pay['status'] !== 'completed') {
            supabase_request('PATCH','/rest/v1/advertisement_payments?order_reference=eq.'.urlencode($ref).'&status=neq.completed',['status'=>'completed','pesapal_status'=>$status,'updated_at'=>gmdate('c')],true);
        }
        $ad=supabase_request('GET','/rest/v1/advertisements?id=eq.'.$pay['advertisement_id'].'&select=duration_id,status,expires_at',null,true);
        if (!$ad) throw new RuntimeException('Associated advert not found.');
        // Publish automatically after verified payment; keep the original expiry on duplicate IPN/callbacks.
        if (($ad[0]['status']??'') !== 'active' || empty($ad[0]['expires_at'])) {
            $dur=supabase_request('GET','/rest/v1/advertisement_durations?id=eq.'.$ad[0]['duration_id'].'&select=days',null,true);
            $days=(int)($dur[0]['days']??0);
            if ($days < 1) throw new RuntimeException('Invalid advert duration.');
            $now=gmdate('c');
            $expires=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+'.$days.' days')->format('c');
            supabase_request('PATCH','/rest/v1/advertisements?id=eq.'.$pay['advertisement_id'].'&status=in.(pending_payment,pending_review,active)',['status'=>'active','published_at'=>$now,'expires_at'=>$expires],true);
        }
    } else {
        $desc=strtolower((string)($status['payment_status_description']??'pending'));
        supabase_request('PATCH','/rest/v1/advertisement_payments?order_reference=eq.'.urlencode($ref).'&status=neq.completed',['status'=>$desc,'pesapal_status'=>$status,'updated_at'=>gmdate('c')],true);
    }
}
