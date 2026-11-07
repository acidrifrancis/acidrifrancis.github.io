<?php
declare(strict_types=1);
if (!hash_equals(envv('CRON_SECRET'), (string)($_GET['key']??''))) json_response(['error'=>'Forbidden'],403);
try {
    $rows=supabase_request('GET','/rest/v1/advertisements?status=eq.active&expires_at=lt.'.urlencode(gmdate('c')).'&select=id',null,true);
    foreach ($rows as $row) supabase_request('PATCH','/rest/v1/advertisements?id=eq.'.$row['id'],['status'=>'expired'],true);
    json_response(['ok'=>true,'expired'=>count($rows)]);
} catch(Throwable $e) { api_error($e); }
