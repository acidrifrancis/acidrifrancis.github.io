<?php
declare(strict_types=1);
require_once __DIR__.'/../../src/bootstrap.php';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reset password · Northern Uganda Market Connect</title><link rel="stylesheet" href="/assets/styles.css"></head><body><main style="max-width:520px;margin:8vh auto;padding:24px"><h1>Reset your password</h1><p class="sub">Choose a new password of at least 12 characters. This page uses the secure recovery link sent to your email.</p><form id="resetForm"><label for="newPassword">New password</label><input id="newPassword" type="password" minlength="12" required autocomplete="new-password"><button class="btn btn-primary" style="margin-top:16px">Update password</button></form><p id="resetMessage" role="status"></p><a href="/">Return to marketplace</a></main><script>
const token=new URLSearchParams(location.hash.slice(1)).get('access_token');
const type=new URLSearchParams(location.hash.slice(1)).get('type');
const form=document.getElementById('resetForm'),message=document.getElementById('resetMessage');
if(!token||type!=='recovery'){form.hidden=true;message.textContent='This recovery link is invalid or expired. Request a new password reset from the login window.';}
form.addEventListener('submit',async e=>{e.preventDefault();const password=document.getElementById('newPassword').value;try{const response=await fetch('/api/auth/reset',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':"<?php echo $_SESSION['csrf_token']; ?>"},body:JSON.stringify({access_token:token,password})});const data=await response.json();if(!response.ok)throw new Error(data.error||'Password reset failed.');form.hidden=true;message.textContent='Password updated. You can now return to the marketplace and log in.';}catch(err){message.textContent=err.message;}});
</script></body></html>