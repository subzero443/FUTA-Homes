<?php
declare(strict_types=1);
// FUTA Homes local JSON API: PHP 8.1+, MariaDB, PDO_MYSQL.
$config = require __DIR__.'/config.php';
date_default_timezone_set('Africa/Lagos');
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('session.use_strict_mode','1');
ini_set('session.use_only_cookies','1');
ini_set('session.use_trans_sid','0');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Content-Security-Policy: default-src \'none\'; frame-ancestors \'none\'; base-uri \'none\'');
header('Cross-Origin-Resource-Policy: same-origin');
header('Cache-Control: no-store');
header("Access-Control-Allow-Origin: https://futahomes.pages.dev");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}
$sessionContext=$_SERVER['HTTP_X_FUTA_SESSION'] ?? 'user';
if(!in_array($sessionContext,['user','agent','admin'],true)){http_response_code(400);echo json_encode(['error'=>'Invalid session context.']);exit;}
session_name('FUTA_HOMES_'.strtoupper($sessionContext));
session_set_cookie_params(['lifetime'=>0,'path'=>'/','httponly'=>true,'secure'=>$isHttps,'samesite'=>'Lax']);
session_start();
if (!isset($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
function out(array $data=[], int $status=200): never { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function fail(string $message,int $status=400): never { out(['error'=>$message],$status); }
function pdo(): PDO { global $config; static $db; if(!$db) { $dsn="mysql:host={$config['db_host']};port={$config['db_port']};dbname={$config['db_name']};charset=utf8mb4"; $db=new PDO($dsn,$config['db_user'],$config['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]); $db->exec("SET time_zone = '+01:00'"); } return $db; }
function normalizeRow(array $row): array { foreach(['is_suspended','is_read'] as $column)if(array_key_exists($column,$row))$row[$column]=$row[$column]===true || $row[$column]===1 || in_array(strtolower((string)$row[$column]),['1','t','true'],true); return $row; }
function rows(string $sql,array $args=[]): array { $q=pdo()->prepare($sql); $q->execute($args); return array_map('normalizeRow',$q->fetchAll()); }
function one(string $sql,array $args=[]): ?array { $q=pdo()->prepare($sql); $q->execute($args); $row=$q->fetch(); return $row ? normalizeRow($row) : null; }
function run(string $sql,array $args=[]): int { $q=pdo()->prepare($sql); $q->execute($args); return $q->rowCount(); }
function body(): array {
  if(($_SERVER['REQUEST_METHOD'] ?? 'GET')==='GET')return [];
  $type=strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE'] ?? '')[0]));
  if($type==='multipart/form-data')return $_POST;
  if($type!=='application/json')fail('Content-Type must be application/json or multipart/form-data.',415);
  $raw=file_get_contents('php://input');
  if($raw===false)fail('Could not read the request body.',400);
  if($raw==='')return [];
  try{$value=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(JsonException){fail('Request body contains invalid JSON.',400);}
  if(!is_array($value))fail('JSON request body must be an object.',400);
  return $value;
}
function s(mixed $v,int $max=255): string { if($v!==null && !is_scalar($v))fail('Invalid field value.'); return mb_substr(trim((string)($v ?? '')),0,$max); }
function n(mixed $v): int { if($v===null || $v==='')return 0; if(!is_scalar($v) || !is_numeric($v))fail('Invalid numeric value.'); return max(0,(int)$v); }
function user(): ?array { $id=(int)($_SESSION['uid'] ?? 0); if(!$id)return null; $u=one('SELECT id,name,email,role,phone,avatar,is_suspended,created_at FROM users WHERE id=?',[$id]); if(!$u){unset($_SESSION['uid']);return null;} if($u['is_suspended']) { unset($_SESSION['uid']); return null; } if($u['role']==='agent') $u['agent']=one('SELECT business_name,bio,verification_status,verification_note,verified_until,plan,plan_expires_at FROM agent_profiles WHERE user_id=?',[$id]); return $u; }
function requireUser(?string $role=null): array { $u=user(); if(!$u)fail('Please sign in first.',401); if($role && $u['role']!==$role)fail('You do not have permission to do that.',403); return $u; }
function need(string $value,string $field,int $min=1): string { if(mb_strlen($value)<$min)fail("Please provide a valid $field."); return $value; }
function mailValid(string $email): string { $email=mb_strtolower($email); if(!filter_var($email,FILTER_VALIDATE_EMAIL))fail('Enter a valid email address.'); return $email; }
function notice(int $uid,string $content): void { run('INSERT INTO notifications(user_id,content) VALUES(?,?)',[$uid,s($content,255)]); }
function effectivePlan(int $agentId): string { $a=one('SELECT plan,plan_expires_at FROM agent_profiles WHERE user_id=?',[$agentId]); return ($a && $a['plan']!=='free' && $a['plan_expires_at'] && strtotime($a['plan_expires_at'])>time()) ? $a['plan'] : 'free'; }
function limitFor(string $plan): int { return ['free'=>3,'pro'=>100,'premium'=>250][$plan] ?? 3; }
function publicPropertyQuery(): string { return "SELECT p.*, u.name AS agent_name,u.phone AS agent_phone,u.avatar AS agent_avatar, a.business_name,a.verification_status,a.verified_until,a.plan,a.plan_expires_at, (SELECT file_path FROM property_media WHERE property_id=p.id AND media_type='image' ORDER BY sort_order,id LIMIT 1) AS image, (SELECT COUNT(*) FROM property_views WHERE property_id=p.id) AS views FROM properties p JOIN users u ON u.id=p.agent_id JOIN agent_profiles a ON a.user_id=u.id"; }
function decorate(array &$p): void { $p['effective_plan']=($p['plan']!=='free' && !empty($p['plan_expires_at']) && strtotime($p['plan_expires_at'])>time()) ? $p['plan'] : 'free'; $p['verified_agent']=($p['verification_status']==='approved' && !empty($p['verified_until']) && strtotime($p['verified_until'])>time()); unset($p['plan_expires_at']); }
function getProperty(int $id,bool $private=false): ?array { $sql=publicPropertyQuery().' WHERE p.id=?'.($private?'':" AND p.moderation_status='approved'"); $p=one($sql,[$id]); if($p) { decorate($p);$p['media']=rows('SELECT id,file_path,media_type FROM property_media WHERE property_id=? ORDER BY sort_order,id',[$id]); } return $p; }
function ownProperty(int $id,int $uid): array { $p=one('SELECT * FROM properties WHERE id=? AND agent_id=?',[$id,$uid]); if(!$p)fail('Property not found or not yours.',404); return $p; }
function uploaded(string $field,string $purpose): string { if(empty($_FILES[$field]) || $_FILES[$field]['error']!==UPLOAD_ERR_OK) fail('Please select a valid file.'); $f=$_FILES[$field]; $size=(int)$f['size']; $max=$purpose==='doc'?5*1024*1024:($purpose==='video'?30*1024*1024:8*1024*1024); if($size<1 || $size>$max)fail('File exceeds the upload limit or is empty.'); if(!is_uploaded_file($f['tmp_name']))fail('Invalid uploaded file.'); $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']); $allow=$purpose==='doc'?['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png']:($purpose==='video'?['video/mp4'=>'mp4','video/webm'=>'webm']:['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp']); if(!isset($allow[$mime]))fail('Unsupported file type.'); $name=bin2hex(random_bytes(16)).'.'.$allow[$mime]; $dir=$purpose==='doc'?__DIR__.'/private':__DIR__.'/uploads'; if(!move_uploaded_file($f['tmp_name'],$dir.'/'.$name))fail('Could not save file.',500); return $purpose==='doc'?$name:'/media/'.$name; }
function applyPayment(int $paymentId): void { $db=pdo();$db->beginTransaction();try { $q=$db->prepare('SELECT * FROM payments WHERE id=? FOR UPDATE');$q->execute([$paymentId]);$pay=$q->fetch();if(!$pay)throw new Exception('Payment not found');if($pay['status']==='paid'){ $db->commit();return; } $amount=['pro'=>1000000,'premium'=>1500000][$pay['plan']] ?? -1;if((int)$pay['amount_kobo']!==$amount)throw new Exception('Invalid amount');$q=$db->prepare("UPDATE payments SET status='paid',paid_at=CURRENT_TIMESTAMP WHERE id=?");$q->execute([$paymentId]);$q=$db->prepare("UPDATE agent_profiles SET plan=?,plan_expires_at=GREATEST(COALESCE(plan_expires_at,CURRENT_TIMESTAMP),CURRENT_TIMESTAMP)+INTERVAL 1 MONTH WHERE user_id=?");$q->execute([$pay['plan'],$pay['agent_id']]);$db->commit();notice((int)$pay['agent_id'],'Your '.ucfirst($pay['plan']).' subscription is active.');} catch(Throwable $e){$db->rollBack();throw $e;} }
function checkPaystack(string $reference): ?array { global $config; $secret=$config['paystack_secret'] ?? ''; if(!$secret)return null; $ch=curl_init('https://api.paystack.co/transaction/verify/'.rawurlencode($reference));curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>12,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$secret]]);$resp=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$data=json_decode($resp ?: '{}',true);return ($status===200 && !empty($data['status']))?($data['data']??null):null; }
try {
  $route=s($_GET['route'] ?? 'session',90); $method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
  $allowedMethods=[
    'session'=>['GET'],'register'=>['POST'],'login'=>['POST'],'logout'=>['POST'],
    'profile/update'=>['POST'],'profile/password'=>['POST'],'profile/pin'=>['POST'],'recovery/reset'=>['POST'],
    'properties/list'=>['GET'],'properties/get'=>['GET'],'properties/view'=>['POST'],'properties/my'=>['GET'],
    'properties/save'=>['POST'],'properties/media/list'=>['GET'],'properties/media'=>['POST'],
    'properties/media/delete'=>['POST'],'properties/delete'=>['POST'],
    'favorites/list'=>['GET'],'favorites/toggle'=>['POST'],
    'bookings/create'=>['POST'],'bookings/my'=>['GET'],'bookings/agent'=>['GET'],'bookings/status'=>['POST'],
    'enquiries/create'=>['POST'],'enquiries/agent'=>['GET'],'enquiries/status'=>['POST'],
    'reviews/list'=>['GET'],'reviews/create'=>['POST'],
    'conversations/list'=>['GET'],'conversations/start'=>['POST'],'conversations/messages'=>['GET'],'conversations/send'=>['POST'],
    'properties/qa/list'=>['GET'],'properties/qa/ask'=>['POST'],'properties/qa/answer'=>['POST'],
    'properties/reviews/list'=>['GET'],'properties/reviews/create'=>['POST'],
    'agent/overview'=>['GET'],'agent/verification'=>['GET','POST'],'agent/analytics'=>['GET'],
    'agent/payments'=>['GET'],'agent/bulk'=>['POST'],
    'support/create'=>['POST'],'support/my'=>['GET'],'notifications'=>['GET'],'notifications/read'=>['POST'],
    'paystack/init'=>['POST'],'paystack/verify'=>['POST'],'paystack/webhook'=>['POST'],
    'admin/overview'=>['GET'],'admin/users'=>['GET'],'admin/users/status'=>['POST'],'admin/users/delete'=>['POST'],
    'admin/properties'=>['GET'],'admin/properties/status'=>['POST'],'admin/agents'=>['GET'],
    'admin/agents/document'=>['GET'],'admin/agents/status'=>['POST'],'admin/plan/grant'=>['POST'],
    'admin/bookings'=>['GET'],'admin/enquiries'=>['GET'],'admin/payments'=>['GET'],'admin/reports/data'=>['GET'],
    'admin/payment/recheck'=>['POST'],'admin/reviews'=>['GET'],'admin/reviews/status'=>['POST'],
    'admin/support'=>['GET'],'admin/support/reply'=>['POST'],
  ];
  if(!isset($allowedMethods[$route]))fail('Unknown API route.',404);
  if(!in_array($method,$allowedMethods[$route],true)){header('Allow: '.implode(', ',$allowedMethods[$route]));fail('Method not allowed.',405);}
  $b=body();
  if($route==='paystack/webhook'){
    global $config; $raw=file_get_contents('php://input'); $secret=$config['paystack_secret'] ?? '';$signature=$_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
    if(!$secret || !hash_equals(hash_hmac('sha512',$raw,$secret),$signature))fail('Invalid webhook signature.',403);
    $event=json_decode($raw,true);
    if(($event['event']??'')==='charge.success') {
      $reference=(string)($event['data']['reference']??'');$p=one('SELECT * FROM payments WHERE reference=?',[$reference]);
      if($p && $p['status']==='pending' && (int)($event['data']['amount']??0)===(int)$p['amount_kobo'] && ($event['data']['currency']??'')==='NGN') {
        $email=one('SELECT email FROM users WHERE id=?',[$p['agent_id']]);
        if(strtolower((string)($event['data']['customer']['email']??''))===strtolower((string)($email['email']??''))) applyPayment((int)$p['id']);
      }
    }
    out(['ok'=>true]);
  }
  if($method!=='GET' && !hash_equals((string)($_SESSION['csrf']??''),(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'')))fail('Session expired. Refresh the page and try again.',419);
  switch($route){
    case 'session': out(['user'=>user(),'csrf'=>$_SESSION['csrf'],'plans'=>[['id'=>'free','name'=>'Free','price'=>0,'limit'=>3],['id'=>'pro','name'=>'Pro','price'=>10000,'limit'=>100],['id'=>'premium','name'=>'Premium','price'=>15000,'limit'=>250]]]);
    case 'register':
      if($method!=='POST')fail('Method not allowed',405);
      $email=mailValid(s($b['email']??'',190));$name=need(s($b['name']??'',120),'name',2);$password=(string)($b['password']??'');$role=s($b['role']??'user');
      if(!in_array($role,['user','agent'],true))fail('Choose User or Agent.');if($role!==$sessionContext)fail('Choose the matching sign-in or registration URL for this account type.',403);if(strlen($password)<8 || strlen($password)>72)fail('Password must be 8–72 characters.');
      if(one('SELECT id FROM users WHERE email=?',[$email]))fail('Email is already registered.',409);
      pdo()->beginTransaction();try {$insert=pdo()->prepare('INSERT INTO users(name,email,password_hash,role,phone) VALUES(?,?,?,?,?)');$insert->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),$role,s($b['phone']??'',30)?:null]);$id=(int)pdo()->lastInsertId();if($role==='agent')run('INSERT INTO agent_profiles(user_id,business_name) VALUES(?,?)',[$id,s($b['business_name']??'',160)?:$name]);pdo()->commit();}catch(Throwable $e){pdo()->rollBack();throw $e;}
      session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));$_SESSION['uid']=$id;out(['user'=>user(),'csrf'=>$_SESSION['csrf']],201);
    case 'login':
      if($method!=='POST')fail('Method not allowed',405);$email=mailValid(s($b['email']??'',190));$pw=(string)($b['password']??'');$window=(int)($_SESSION['login_window']??0);if(time()-$window>900){$_SESSION['login_window']=time();$_SESSION['login_attempts']=0;}if((int)($_SESSION['login_attempts']??0)>=10)fail('Too many attempts. Try again in 15 minutes.',429);
      if(strlen($pw)>72){$_SESSION['login_attempts']=n($_SESSION['login_attempts']??0)+1;fail('Incorrect email or password.',401);}
      $row=one('SELECT id,password_hash,is_suspended,role FROM users WHERE email=?',[$email]);if(!$row || !password_verify($pw,$row['password_hash'])){$_SESSION['login_attempts']=n($_SESSION['login_attempts']??0)+1;fail('Incorrect email or password.',401);}if($row['role']!==$sessionContext)fail('Use the sign-in URL for your account type.',403);if($row['is_suspended'])fail('Account is suspended.',403);
      if(password_needs_rehash($row['password_hash'],PASSWORD_DEFAULT))run('UPDATE users SET password_hash=? WHERE id=?',[password_hash($pw,PASSWORD_DEFAULT),$row['id']]);
      $_SESSION['login_attempts']=0;session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));$_SESSION['uid']=(int)$row['id'];out(['user'=>user(),'csrf'=>$_SESSION['csrf']]);
    case 'logout':
      requireUser();$_SESSION=[];
      if(ini_get('session.use_cookies')){$cookie=session_get_cookie_params();setcookie(session_name(),'',array_merge($cookie,['expires'=>time()-42000]));}
      session_destroy();out(['ok'=>true]);
    case 'profile/update':
      $u=requireUser();if($method!=='POST')fail('Method not allowed',405);$name=need(s($b['name']??'',120),'name',2);run('UPDATE users SET name=?,phone=? WHERE id=?',[$name,s($b['phone']??'',30),$u['id']]);if($u['role']==='agent')run('UPDATE agent_profiles SET business_name=?,bio=? WHERE user_id=?',[s($b['business_name']??'',160),s($b['bio']??'',2000),$u['id']]);out(['user'=>user()]);
    case 'profile/password':
      $u=requireUser();$row=one('SELECT password_hash FROM users WHERE id=?',[$u['id']]);if(!password_verify((string)($b['current_password']??''),$row['password_hash']))fail('Current password is incorrect.',403);$next=(string)($b['new_password']??'');if(strlen($next)<8 || strlen($next)>72)fail('New password must be 8–72 characters.');run('UPDATE users SET password_hash=? WHERE id=?',[password_hash($next,PASSWORD_DEFAULT),$u['id']]);out(['ok'=>true]);
    case 'profile/pin':
      $u=requireUser();$pin=(string)($b['pin']??'');if(!preg_match('/^[0-9]{4}$/',$pin))fail('PIN must be exactly four digits.');$row=one('SELECT password_hash FROM users WHERE id=?',[$u['id']]);if(!password_verify((string)($b['password']??''),$row['password_hash']))fail('Your password is required to set a recovery PIN.',403);run('UPDATE users SET recovery_pin_hash=?,pin_failures=0,pin_locked_until=NULL WHERE id=?',[password_hash($pin,PASSWORD_DEFAULT),$u['id']]);out(['ok'=>true]);
    case 'recovery/reset':
      $email=mailValid(s($b['email']??'',190));$pin=(string)($b['pin']??'');$pw=(string)($b['new_password']??'');if(strlen($pw)<8 || strlen($pw)>72)fail('Use a password of 8–72 characters.');$row=one('SELECT id,recovery_pin_hash,pin_failures,pin_locked_until FROM users WHERE email=?',[$email]);if(!$row || !$row['recovery_pin_hash'])fail('Invalid recovery details.',403);if($row['pin_locked_until'] && strtotime($row['pin_locked_until'])>time())fail('Recovery locked. Try again after 24 hours.',429);      if(!password_verify($pin,$row['recovery_pin_hash'])){run("UPDATE users SET pin_failures=pin_failures+1,pin_locked_until=CASE WHEN pin_failures>=2 THEN CURRENT_TIMESTAMP+INTERVAL 1 DAY ELSE NULL END WHERE id=?",[$row['id']]);fail('Invalid recovery details.',403);}run('UPDATE users SET password_hash=?,recovery_pin_hash=NULL,pin_failures=0,pin_locked_until=NULL WHERE id=?',[password_hash($pw,PASSWORD_DEFAULT),$row['id']]);out(['ok'=>true]);
    case 'properties/list':
      $where=["p.moderation_status='approved'","p.availability='available'","u.is_suspended=FALSE"]; $args=[];
      if($q=s($_GET['q']??'',100)){ $where[]='(LOWER(p.title) LIKE LOWER(?) OR LOWER(p.location) LIKE LOWER(?) OR LOWER(p.description) LIKE LOWER(?) OR LOWER(a.business_name) LIKE LOWER(?))';foreach(range(1,4) as $_)$args[]='%'.$q.'%';}
      if($loc=s($_GET['location']??'',100)){$where[]='LOWER(p.location) LIKE LOWER(?)';$args[]='%'.$loc.'%';}
      if($type=s($_GET['type']??'',30)){$where[]='p.property_type=?';$args[]=$type;}
      if($listing=s($_GET['listing']??'',30)){$where[]='p.listing_type=?';$args[]=$listing;}
      if(n($_GET['max']??0)>0){$where[]='p.price<=?';$args[]=n($_GET['max']);}
      $sql=publicPropertyQuery().' WHERE '.implode(' AND ',$where).' ORDER BY CASE WHEN a.plan IN (\'pro\',\'premium\') AND a.plan_expires_at>CURRENT_TIMESTAMP THEN 0 ELSE 1 END, p.created_at DESC LIMIT 100';
      $list=rows($sql,$args);foreach($list as &$p)decorate($p);unset($p);out(['properties'=>$list]);
    case 'properties/get':
      $id=n($_GET['id']??0);$p=getProperty($id);if(!$p)fail('Listing not found or awaiting approval.',404);out(['property'=>$p]);
    case 'properties/view':
      $id=n($b['id']??0);if(!getProperty($id))fail('Listing not found.',404);if(!isset($_SESSION['seen']))$_SESSION['seen']=[];if(empty($_SESSION['seen'][$id]) || time()-$_SESSION['seen'][$id]>3600){run('INSERT INTO property_views(property_id,user_id) VALUES(?,?)',[$id,user()['id']??null]);$_SESSION['seen'][$id]=time();}out(['ok'=>true]);
    case 'properties/my':
      $u=requireUser('agent');$list=rows(publicPropertyQuery().' WHERE p.agent_id=? ORDER BY p.created_at DESC',[$u['id']]);foreach($list as &$p)decorate($p);unset($p);out(['properties'=>$list]);
    case 'properties/save':
      $u=requireUser('agent');if($method!=='POST')fail('Method not allowed',405);$id=n($b['id']??0);if($id)ownProperty($id,(int)$u['id']);else{$agent=one('SELECT verification_status,verified_until FROM agent_profiles WHERE user_id=?',[$u['id']]);if(!$agent || $agent['verification_status']!=='approved' || empty($agent['verified_until']) || strtotime($agent['verified_until'])<=time())fail('Admin approval of your agent verification is required before you can list a property.',403);$plan=effectivePlan((int)$u['id']);$count=one("SELECT COUNT(*) c FROM properties WHERE agent_id=? AND availability='available'",[$u['id']]);if((int)$count['c']>=limitFor($plan))fail('Active listing limit reached. Upgrade your plan or mark an old listing unavailable.',403);}
      $title=need(s($b['title']??'',170),'title',4);$description=need(s($b['description']??'',3000),'description',5);$address=need(s($b['address']??'',200),'address');$location=need(s($b['location']??'',120),'location');$price=(float)($b['price']??0);if($price<=0 || $price>100000000000)fail('Enter a valid price.');$type=s($b['property_type']??'selfcon');$listing=s($b['listing_type']??'rent');$period=s($b['price_period']??'year');$availability=s($b['availability']??'available');if(!in_array($type,['selfcon','apartment','hostel','shared','house','other']) || !in_array($listing,['rent','sale']) || !in_array($period,['year','month','once']) || !in_array($availability,['available','unavailable']))fail('Invalid listing options.');
      $params=[$title,$description,$address,$location,$type,$listing,$price,$period,($b['total_package']??'')===''?null:(float)$b['total_package'],min(n($b['bedrooms']??1),99),min(n($b['bathrooms']??1),99),$availability];
      if($id && $availability==='available'){ $current=ownProperty($id,(int)$u['id']); if($current['availability']!=='available'){ $active=one("SELECT COUNT(*) c FROM properties WHERE agent_id=? AND availability='available'",[$u['id']]); if((int)$active['c']>=limitFor(effectivePlan((int)$u['id'])))fail('Active listing limit reached.',403); }}
      if($id)run('UPDATE properties SET title=?,description=?,address=?,location=?,property_type=?,listing_type=?,price=?,price_period=?,total_package=?,bedrooms=?,bathrooms=?,availability=?,moderation_status=\'pending\',moderation_note=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=? AND agent_id=?',[...$params,$id,$u['id']]);else{$insert=pdo()->prepare('INSERT INTO properties(title,description,address,location,property_type,listing_type,price,price_period,total_package,bedrooms,bathrooms,availability,agent_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?) ');$insert->execute([...$params,$u['id']]);$id=(int)pdo()->lastInsertId();}
      out(['id'=>$id,'message'=>'Saved for admin approval.']);
    case 'properties/media/list':
      $u=requireUser('agent');$id=n($_GET['id']??0);ownProperty($id,(int)$u['id']);out(['media'=>rows('SELECT id,file_path,media_type FROM property_media WHERE property_id=? ORDER BY id',[$id])]);
    case 'properties/media':
      $u=requireUser('agent');if($method!=='POST')fail('Method not allowed',405);$id=n($b['property_id']??0);ownProperty($id,(int)$u['id']);$count=one('SELECT COUNT(*) c FROM property_media WHERE property_id=?',[$id]);if((int)$count['c']>=12)fail('Maximum 12 media files per property.');if(empty($_FILES['file']))fail('Select a file.');$mime=(new finfo(FILEINFO_MIME_TYPE))->file($_FILES['file']['tmp_name']);$kind=str_starts_with($mime,'video/')?'video':'image';$path=uploaded('file',$kind);run('INSERT INTO property_media(property_id,file_path,media_type) VALUES(?,?,?)',[$id,$path,$kind]);run("UPDATE properties SET moderation_status='pending' WHERE id=?",[$id]);out(['path'=>$path,'kind'=>$kind]);
    case 'properties/media/delete':
      $u=requireUser('agent');$id=n($b['media_id']??0);$m=one('SELECT m.*,p.agent_id FROM property_media m JOIN properties p ON p.id=m.property_id WHERE m.id=?',[$id]);if(!$m || (int)$m['agent_id']!==(int)$u['id'])fail('Media not found.',404);run('DELETE FROM property_media WHERE id=?',[$id]);$base=basename($m['file_path']);if(str_starts_with($m['file_path'],'/media/') && is_file(__DIR__.'/uploads/'.$base))unlink(__DIR__.'/uploads/'.$base);run("UPDATE properties SET moderation_status='pending' WHERE id=?",[$m['property_id']]);out(['ok'=>true]);
    case 'properties/delete':
      $u=requireUser('agent');$id=n($b['id']??0);ownProperty($id,(int)$u['id']);$media=rows('SELECT file_path FROM property_media WHERE property_id=?',[$id]);run('DELETE FROM properties WHERE id=?',[$id]);foreach($media as $m){$path=__DIR__.'/uploads/'.basename($m['file_path']);if(str_starts_with($m['file_path'],'/media/') && is_file($path))unlink($path);}out(['ok'=>true]);
    case 'favorites/list':
      $u=requireUser('user');$list=rows(publicPropertyQuery().' JOIN favorites f ON f.property_id=p.id WHERE f.user_id=? AND p.moderation_status=\'approved\' ORDER BY f.created_at DESC',[$u['id']]);foreach($list as &$p)decorate($p);unset($p);out(['properties'=>$list]);
    case 'favorites/toggle':
      $u=requireUser('user');$id=n($b['property_id']??0);if(!getProperty($id))fail('Listing unavailable.',404);$exists=one('SELECT user_id FROM favorites WHERE user_id=? AND property_id=?',[$u['id'],$id]);if($exists)run('DELETE FROM favorites WHERE user_id=? AND property_id=?',[$u['id'],$id]);else run('INSERT INTO favorites(user_id,property_id) VALUES(?,?)',[$u['id'],$id]);out(['saved'=>!$exists]);
    case 'bookings/create':
      $u=requireUser('user');$id=n($b['property_id']??0);$p=getProperty($id);if(!$p || $p['availability']!=='available')fail('Property unavailable.',404);$viewing=s($b['viewing_at']??'',32);$timestamp=strtotime($viewing);if(!$timestamp || $timestamp<time()+1800 || $timestamp>time()+90*86400)fail('Choose a viewing time between 30 minutes and 90 days from now.');$phone=need(s($b['phone']??'',30),'phone',7);run('INSERT INTO bookings(property_id,user_id,agent_id,viewing_at,phone,message) VALUES(?,?,?,?,?,?)',[$id,$u['id'],$p['agent_id'],date('Y-m-d H:i:s',$timestamp),$phone,s($b['message']??'',1200)]);notice((int)$p['agent_id'],'New viewing request for '.$p['title']);out(['message'=>'Viewing request sent.'],201);
    case 'bookings/my':
      $u=requireUser('user');out(['bookings'=>rows('SELECT b.*,p.title,p.location,(SELECT file_path FROM property_media WHERE property_id=p.id AND media_type=\'image\' ORDER BY id LIMIT 1) image,u.name agent_name FROM bookings b JOIN properties p ON p.id=b.property_id JOIN users u ON u.id=b.agent_id WHERE b.user_id=? ORDER BY b.created_at DESC',[$u['id']])]);
    case 'bookings/agent':
      $u=requireUser('agent');out(['bookings'=>rows('SELECT b.*,p.title,u.name user_name,u.email user_email FROM bookings b JOIN properties p ON p.id=b.property_id JOIN users u ON u.id=b.user_id WHERE b.agent_id=? ORDER BY b.created_at DESC',[$u['id']])]);
    case 'bookings/status':
      $u=requireUser();$id=n($b['id']??0);$status=s($b['status']??'');$booking=one('SELECT * FROM bookings WHERE id=?',[$id]);if(!$booking)fail('Booking not found.',404);$agent=$u['role']==='agent' && (int)$booking['agent_id']===(int)$u['id'];$owner=$u['role']==='user' && (int)$booking['user_id']===(int)$u['id'];if(!$agent && !$owner && $u['role']!=='admin')fail('Not permitted.',403);$allowed=$agent?['confirmed','completed','cancelled']:($owner?['cancelled']:['confirmed','completed','cancelled']);if(!in_array($status,$allowed,true))fail('Invalid booking status.');if(in_array($booking['status'],['cancelled','completed'],true))fail('This booking is closed.');run('UPDATE bookings SET status=? WHERE id=?',[$status,$id]);notice((int)($agent?$booking['user_id']:$booking['agent_id']),'Booking #'.$id.' is now '.$status.'.');out(['ok'=>true]);
    case 'enquiries/create':
      $u=requireUser('user');$id=n($b['property_id']??0);$p=getProperty($id);if(!$p)fail('Property not found.',404);$last=one('SELECT created_at FROM enquiries WHERE property_id=? AND user_id=? ORDER BY id DESC LIMIT 1',[$id,$u['id']]);if($last && time()-strtotime($last['created_at'])<60)fail('Please wait a minute before contacting the same agent again.',429);$ref='FH-ENQ-'.strtoupper(bin2hex(random_bytes(4)));$message=s($b['message']??'Hello, I am interested in this property.',1000);run('INSERT INTO enquiries(reference,property_id,user_id,agent_id,message) VALUES(?,?,?,?,?)',[$ref,$id,$u['id'],$p['agent_id'],$message]);notice((int)$p['agent_id'],'New enquiry '.$ref.' for '.$p['title']);$digits=preg_replace('/\D/','',(string)($p['agent_phone']??''));if(str_starts_with($digits,'0'))$digits='234'.substr($digits,1);$link=$digits?'https://wa.me/'.$digits.'?text='.rawurlencode($message.' | Property ID: FH'.$id.' | Ref: '.$ref):null;out(['reference'=>$ref,'whatsapp_url'=>$link]);
    case 'enquiries/agent':
      $u=requireUser('agent');out(['enquiries'=>rows('SELECT e.*,p.title,u.name user_name,u.phone user_phone,u.email user_email FROM enquiries e JOIN properties p ON p.id=e.property_id JOIN users u ON u.id=e.user_id WHERE e.agent_id=? ORDER BY e.created_at DESC',[$u['id']])]);
    case 'enquiries/status':
      $u=requireUser('agent');$status=s($b['status']??'');if(!in_array($status,['open','contacted','closed']))fail('Invalid status.');run('UPDATE enquiries SET status=? WHERE id=? AND agent_id=?',[$status,n($b['id']??0),$u['id']]);out(['ok'=>true]);
    case 'reviews/list':
      $agent=n($_GET['agent_id']??0);$list=rows("SELECT r.id,r.rating,r.comment,r.created_at,u.name AS user_name FROM agent_reviews r JOIN users u ON u.id=r.user_id WHERE r.agent_id=? AND r.status='approved' ORDER BY r.created_at DESC LIMIT 100",[$agent]);$summary=one("SELECT COUNT(*) count,ROUND(AVG(rating),1) average FROM agent_reviews WHERE agent_id=? AND status='approved'",[$agent]);out(['reviews'=>$list,'summary'=>$summary]);
    case 'reviews/create':
      $u=requireUser('user');$agent=n($b['agent_id']??0);$rating=n($b['rating']??0);if($rating<1 || $rating>5)fail('Choose 1–5 stars.');if(!one('SELECT id FROM bookings WHERE agent_id=? AND user_id=? LIMIT 1',[$agent,$u['id']]))fail('You can review an agent after requesting a viewing.',403);if(one('SELECT id FROM agent_reviews WHERE agent_id=? AND user_id=?',[$agent,$u['id']]))fail('You already reviewed this agent.',409);run('INSERT INTO agent_reviews(agent_id,user_id,rating,comment) VALUES(?,?,?,?)',[$agent,$u['id'],$rating,need(s($b['comment']??'',800),'review',5)]);out(['message'=>'Review sent for moderation.']);
    case 'conversations/list':
      $u=requireUser();
      if($u['role']==='agent'){
        $list=rows("SELECT c.*, p.title AS property_title, p.location AS property_location,
                           (SELECT file_path FROM property_media WHERE property_id=p.id AND media_type='image' ORDER BY sort_order,id LIMIT 1) AS property_image,
                           u.id AS partner_id, u.name AS partner_name, u.email AS partner_email, u.phone AS partner_phone, u.avatar AS partner_avatar,
                           (SELECT COUNT(*) FROM direct_messages WHERE conversation_id=c.id AND sender_id!=? AND is_read=FALSE) AS unread_count
                    FROM conversations c
                    LEFT JOIN properties p ON p.id=c.property_id
                    JOIN users u ON u.id=c.user_id
                    WHERE c.agent_id=?
                    ORDER BY c.last_message_at DESC", [$u['id'],$u['id']]);
      } else {
        $list=rows("SELECT c.*, p.title AS property_title, p.location AS property_location,
                           (SELECT file_path FROM property_media WHERE property_id=p.id AND media_type='image' ORDER BY sort_order,id LIMIT 1) AS property_image,
                           a.id AS partner_id, a.name AS partner_name, a.phone AS partner_phone, a.avatar AS partner_avatar,
                           ap.business_name AS partner_business,
                           (SELECT COUNT(*) FROM direct_messages WHERE conversation_id=c.id AND sender_id!=? AND is_read=FALSE) AS unread_count
                    FROM conversations c
                    LEFT JOIN properties p ON p.id=c.property_id
                    JOIN users a ON a.id=c.agent_id
                    LEFT JOIN agent_profiles ap ON ap.user_id=a.id
                    WHERE c.user_id=?
                    ORDER BY c.last_message_at DESC", [$u['id'],$u['id']]);
      }
      out(['conversations'=>$list]);
    case 'conversations/start':
      $u=requireUser();if($method!=='POST')fail('Method not allowed',405);
      $propId=n($b['property_id']??0);
      $p=$propId?getProperty($propId,true):null;
      if($propId && !$p)fail('Property not found.',404);
      $agentId=$p?(int)$p['agent_id']:n($b['agent_id']??0);
      if(!$agentId)fail('Agent required.',400);
      if((int)$u['id']===$agentId)fail('You cannot chat with yourself.',400);
      $userId=($u['role']==='agent')?n($b['user_id']??0):(int)$u['id'];
      if(!$userId)fail('Student/user ID required.',400);
      $conv=one('SELECT * FROM conversations WHERE property_id=? AND user_id=? AND agent_id=?',[$propId?:null,$userId,$agentId]);
      $initial=s($b['message']??'',2000);
      if(!$conv){
        run('INSERT INTO conversations(property_id,user_id,agent_id,last_message,last_message_at) VALUES(?,?,?,?,CURRENT_TIMESTAMP)',[$propId?:null,$userId,$agentId,$initial?:'Conversation started']);
        $convId=(int)pdo()->lastInsertId();
      } else {
        $convId=(int)$conv['id'];
      }
      if($initial!==''){
        run('INSERT INTO direct_messages(conversation_id,sender_id,message) VALUES(?,?,?)',[$convId,$u['id'],$initial]);
        run('UPDATE conversations SET last_message=?,last_message_at=CURRENT_TIMESTAMP WHERE id=?',[mb_substr($initial,0,150),$convId]);
        $recipientId=((int)$u['id']===$userId)?$agentId:$userId;
        notice($recipientId,'New message from '.$u['name'].($p?(' about '.$p['title']):''));
      }
      out(['conversation_id'=>$convId]);
    case 'conversations/messages':
      $u=requireUser();$convId=n($_GET['conversation_id']??0);
      $conv=one('SELECT * FROM conversations WHERE id=?',[$convId]);
      if(!$conv || ((int)$conv['user_id']!==(int)$u['id'] && (int)$conv['agent_id']!==(int)$u['id'] && $u['role']!=='admin'))fail('Conversation not found.',404);
      run('UPDATE direct_messages SET is_read=TRUE WHERE conversation_id=? AND sender_id!=?',[$convId,$u['id']]);
      $msgs=rows('SELECT m.id,m.conversation_id,m.sender_id,m.message,m.is_read,m.created_at,u.name AS sender_name,u.avatar AS sender_avatar FROM direct_messages m JOIN users u ON u.id=m.sender_id WHERE m.conversation_id=? ORDER BY m.created_at ASC,m.id ASC',[$convId]);
      $prop=$conv['property_id']?getProperty((int)$conv['property_id'],true):null;
      $partnerId=((int)$conv['user_id']===(int)$u['id'])?(int)$conv['agent_id']:(int)$conv['user_id'];
      $partner=one('SELECT u.id,u.name,u.phone,u.avatar,u.role,ap.business_name FROM users u LEFT JOIN agent_profiles ap ON ap.user_id=u.id WHERE u.id=?',[$partnerId]);
      out(['messages'=>$msgs,'conversation'=>$conv,'property'=>$prop,'partner'=>$partner]);
    case 'conversations/send':
      $u=requireUser();if($method!=='POST')fail('Method not allowed',405);
      $convId=n($b['conversation_id']??0);
      $conv=one('SELECT * FROM conversations WHERE id=?',[$convId]);
      if(!$conv || ((int)$conv['user_id']!==(int)$u['id'] && (int)$conv['agent_id']!==(int)$u['id'] && $u['role']!=='admin'))fail('Conversation not found.',404);
      $msg=need(s($b['message']??'',2000),'message',1);
      run('INSERT INTO direct_messages(conversation_id,sender_id,message) VALUES(?,?,?)',[$convId,$u['id'],$msg]);
      $msgId=(int)pdo()->lastInsertId();
      run('UPDATE conversations SET last_message=?,last_message_at=CURRENT_TIMESTAMP WHERE id=?',[mb_substr($msg,0,150),$convId]);
      $recipientId=((int)$conv['user_id']===(int)$u['id'])?(int)$conv['agent_id']:(int)$conv['user_id'];
      notice($recipientId,'New message from '.$u['name'].': '.mb_substr($msg,0,60));
      $sent=one('SELECT m.*,u.name AS sender_name,u.avatar AS sender_avatar FROM direct_messages m JOIN users u ON u.id=m.sender_id WHERE m.id=?',[$msgId]);
      out(['message'=>$sent]);
    case 'properties/qa/list':
      $propId=n($_GET['property_id']??0);
      $list=rows("SELECT q.*,u.name AS user_name,u.avatar AS user_avatar,a.name AS answered_by_name,ap.business_name FROM property_questions q JOIN users u ON u.id=q.user_id LEFT JOIN users a ON a.id=q.answered_by LEFT JOIN agent_profiles ap ON ap.user_id=a.id WHERE q.property_id=? ORDER BY q.created_at DESC LIMIT 100",[$propId]);
      out(['questions'=>$list]);
    case 'properties/qa/ask':
      $u=requireUser();if($method!=='POST')fail('Method not allowed',405);
      $propId=n($b['property_id']??0);$p=getProperty($propId);if(!$p)fail('Property not found.',404);
      $q=need(s($b['question']??'',1000),'question',5);
      $recent=one('SELECT created_at FROM property_questions WHERE property_id=? AND user_id=? ORDER BY id DESC LIMIT 1',[$propId,$u['id']]);
      if($recent && (time()-strtotime($recent['created_at']))<20)fail('Please wait a moment before asking another question.',429);
      run('INSERT INTO property_questions(property_id,user_id,question) VALUES(?,?,?)',[$propId,$u['id'],$q]);
      notice((int)$p['agent_id'],'New question on "'.$p['title'].'": '.mb_substr($q,0,80));
      out(['message'=>'Question posted successfully!']);
    case 'properties/qa/answer':
      $u=requireUser();if($method!=='POST')fail('Method not allowed',405);
      $qId=n($b['question_id']??0);
      $question=one('SELECT q.*,p.title AS property_title,p.agent_id FROM property_questions q JOIN properties p ON p.id=q.property_id WHERE q.id=?',[$qId]);
      if(!$question)fail('Question not found.',404);
      if((int)$u['id']!==(int)$question['agent_id'] && $u['role']!=='admin')fail('Only the listing agent or an admin can answer this question.',403);
      $answer=need(s($b['answer']??'',2000),'answer',2);
      run('UPDATE property_questions SET answer=?,answered_by=?,answered_at=CURRENT_TIMESTAMP WHERE id=?',[$answer,$u['id'],$qId]);
      notice((int)$question['user_id'],'Your question on "'.$question['property_title'].'" has been answered.');
      out(['ok'=>true,'message'=>'Answer submitted successfully.']);
    case 'properties/reviews/list':
      $propId=n($_GET['property_id']??0);
      $list=rows("SELECT r.*,u.name AS user_name,u.avatar AS user_avatar FROM property_reviews r JOIN users u ON u.id=r.user_id WHERE r.property_id=? ORDER BY r.created_at DESC LIMIT 100",[$propId]);
      $summary=one("SELECT COUNT(*) AS count,ROUND(AVG(rating),1) AS average,SUM(CASE WHEN category_water='Constant' THEN 1 ELSE 0 END) AS water_constant,SUM(CASE WHEN category_power='Stable (Prepaid)' THEN 1 ELSE 0 END) AS power_stable,SUM(CASE WHEN category_security='Very safe (Gated)' THEN 1 ELSE 0 END) AS security_safe FROM property_reviews WHERE property_id=?",[$propId]);
      out(['reviews'=>$list,'summary'=>$summary]);
    case 'properties/reviews/create':
      $u=requireUser('user');if($method!=='POST')fail('Method not allowed',405);
      $propId=n($b['property_id']??0);$p=getProperty($propId);if(!$p)fail('Property not found.',404);
      $rating=n($b['rating']??0);if($rating<1 || $rating>5)fail('Please choose a rating between 1 and 5 stars.');
      $comment=need(s($b['comment']??'',1500),'review comment',5);
      $water=s($b['category_water']??'',30);$power=s($b['category_power']??'',30);$security=s($b['category_security']??'',30);
      if(one('SELECT id FROM property_reviews WHERE property_id=? AND user_id=?',[$propId,$u['id']]))fail('You have already submitted a review for this property.',409);
      run('INSERT INTO property_reviews(property_id,user_id,rating,comment,category_water,category_power,category_security) VALUES(?,?,?,?,?,?,?)',[$propId,$u['id'],$rating,$comment,$water?:null,$power?:null,$security?:null]);
      notice((int)$p['agent_id'],'New '.$rating.'-star review on "'.$p['title'].'"');
      out(['message'=>'Thank you! Your review has been submitted.']);
    case 'agent/overview':
      $u=requireUser('agent');$id=(int)$u['id'];$stats=one("SELECT COUNT(*) AS total, SUM(CASE WHEN moderation_status='approved' THEN 1 ELSE 0 END) AS approved, SUM(CASE WHEN moderation_status='pending' THEN 1 ELSE 0 END) AS pending, SUM(CASE WHEN moderation_status='rejected' THEN 1 ELSE 0 END) AS rejected FROM properties WHERE agent_id=?",[$id]);$book=one("SELECT COUNT(*) AS total,SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending,SUM(CASE WHEN status='confirmed' THEN 1 ELSE 0 END) AS confirmed FROM bookings WHERE agent_id=?",[$id]);$enq=one('SELECT COUNT(*) total FROM enquiries WHERE agent_id=?',[$id]);$views=one('SELECT COUNT(*) total FROM property_views v JOIN properties p ON p.id=v.property_id WHERE p.agent_id=?',[$id]);$profile=one('SELECT business_name,bio,verification_status,verification_note,verified_until,plan,plan_expires_at FROM agent_profiles WHERE user_id=?',[$id]);out(['stats'=>$stats,'bookings'=>$book,'enquiries'=>$enq,'views'=>$views,'profile'=>$profile,'effective_plan'=>effectivePlan($id),'limit'=>limitFor(effectivePlan($id))]);
    case 'agent/verification':
      $u=requireUser('agent');if($method==='GET'){$verification=one('SELECT verification_status,verification_note,verified_until,verification_document,plan,plan_expires_at FROM agent_profiles WHERE user_id=?',[$u['id']]);if(!$verification)fail('Agent profile not found.',404);$verification['has_document']=!empty($verification['verification_document']);$verification['document_type']=$verification['verification_document']?strtoupper(pathinfo((string)$verification['verification_document'],PATHINFO_EXTENSION)):null;unset($verification['verification_document']);out(['verification'=>$verification]);}if($method!=='POST')fail('Method not allowed',405);$path=uploaded('document','doc');$old=one('SELECT verification_document FROM agent_profiles WHERE user_id=?',[$u['id']]);run("UPDATE agent_profiles SET verification_document=?,verification_status='pending',verification_note=NULL WHERE user_id=?",[$path,$u['id']]);if($old && $old['verification_document'] && is_file(__DIR__.'/private/'.$old['verification_document']))unlink(__DIR__.'/private/'.$old['verification_document']);out(['message'=>'Document submitted. An admin will review it.']);
    case 'agent/analytics':
      $u=requireUser('agent');$plan=effectivePlan((int)$u['id']);if($plan==='free')fail('Advanced analytics requires Pro or Premium.',403);$daily=rows("SELECT DATE(v.viewed_at) day,COUNT(*) total FROM property_views v JOIN properties p ON p.id=v.property_id WHERE p.agent_id=? AND v.viewed_at >= CURRENT_TIMESTAMP-INTERVAL 30 DAY GROUP BY DATE(v.viewed_at) ORDER BY day",[$u['id']]);$top=rows("SELECT p.id,p.title,COUNT(v.id) views FROM properties p LEFT JOIN property_views v ON v.property_id=p.id WHERE p.agent_id=? GROUP BY p.id,p.title ORDER BY views DESC LIMIT 10",[$u['id']]);out(['daily'=>$daily,'top'=>$top]);
    case 'agent/payments':
      $u=requireUser('agent');out(['payments'=>rows('SELECT id,reference,plan,amount_kobo,provider,status,paid_at,created_at FROM payments WHERE agent_id=? ORDER BY id DESC',[$u['id']])]);
    case 'agent/bulk':
      $u=requireUser('agent');$agent=one('SELECT verification_status,verified_until FROM agent_profiles WHERE user_id=?',[$u['id']]);if(!$agent || $agent['verification_status']!=='approved' || empty($agent['verified_until']) || strtotime($agent['verified_until'])<=time())fail('Admin approval of your agent verification is required before you can list a property.',403);if(effectivePlan((int)$u['id'])!=='premium')fail('Bulk importing requires Premium.',403);$items=$b['properties']??[];if(!is_array($items)||count($items)<1||count($items)>50)fail('Provide 1–50 properties.');$count=one("SELECT COUNT(*) c FROM properties WHERE agent_id=? AND availability='available'",[$u['id']]);if((int)$count['c']+count($items)>250)fail('Premium listing limit exceeded.');$created=0;pdo()->beginTransaction();try{foreach($items as $item){$title=need(s($item['title']??'',170),'title',4);$loc=need(s($item['location']??'',120),'location');$price=(float)($item['price']??0);if($price<=0)fail('Every property must have a positive price.');run('INSERT INTO properties(agent_id,title,description,address,location,price,bedrooms,bathrooms) VALUES(?,?,?,?,?,?,?,?)',[$u['id'],$title,need(s($item['description']??'',3000),'description',5),need(s($item['address']??'',200),'address'),$loc,$price,min(99,n($item['bedrooms']??1)),min(99,n($item['bathrooms']??1))]);$created++;}pdo()->commit();}catch(Throwable $e){pdo()->rollBack();throw $e;}out(['created'=>$created,'message'=>'Imported. Listings await moderation.']);
    case 'support/create':
      $u=requireUser();$subject=need(s($b['subject']??'',180),'subject',5);$message=need(s($b['message']??'',3000),'message',10);run('INSERT INTO support_tickets(user_id,subject,message) VALUES(?,?,?)',[$u['id'],$subject,$message]);out(['message'=>'Support ticket created. Your reply will appear in your dashboard.']);
    case 'support/my':
      $u=requireUser();out(['tickets'=>rows('SELECT * FROM support_tickets WHERE user_id=? ORDER BY id DESC',[$u['id']])]);
    case 'notifications':
      $u=requireUser();out(['notifications'=>rows('SELECT id,content,is_read,created_at FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 40',[$u['id']])]);
    case 'notifications/read':
      $u=requireUser();run('UPDATE notifications SET is_read=TRUE WHERE user_id=?',[$u['id']]);out(['ok'=>true]);
    case 'paystack/init':
      $u=requireUser('agent');global $config;$secret=$config['paystack_secret']??'';if(!$secret)fail('Paystack is not configured. Ask an admin for a local demo subscription.',503);$plan=s($b['plan']??'');if(!in_array($plan,['pro','premium'],true))fail('Choose Pro or Premium.');$amount=$plan==='pro'?1000000:1500000;$ref='FUTH-'.strtoupper(bin2hex(random_bytes(9)));run('INSERT INTO payments(agent_id,reference,plan,amount_kobo) VALUES(?,?,?,?)',[$u['id'],$ref,$plan,$amount]);$json=json_encode(['email'=>$u['email'],'amount'=>$amount,'currency'=>'NGN','reference'=>$ref,'callback_url'=>'http://127.0.0.1:5173/agent/profile?section=subscriptions&reference='.rawurlencode($ref)]);$ch=curl_init('https://api.paystack.co/transaction/initialize');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$json,CURLOPT_TIMEOUT=>12,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$secret,'Content-Type: application/json']]);$raw=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$info=json_decode($raw ?: '{}',true);if($status!==200 || empty($info['status']) || empty($info['data']['authorization_url']))fail('Could not start Paystack payment.',502);out(['url'=>$info['data']['authorization_url'],'reference'=>$ref]);
    case 'paystack/verify':
      $u=requireUser('agent');$ref=s($b['reference']??'',100);$p=one('SELECT * FROM payments WHERE reference=? AND agent_id=?',[$ref,$u['id']]);if(!$p)fail('Payment not found.',404);if($p['status']==='paid')out(['ok'=>true,'paid'=>true]);$tx=checkPaystack($ref);if(!$tx || ($tx['status']??'')!=='success' || (int)($tx['amount']??0)!==(int)$p['amount_kobo'] || ($tx['currency']??'')!=='NGN' || strtolower((string)($tx['customer']['email']??''))!==strtolower($u['email']))fail('Payment not confirmed. Do not close Paystack until completed.',400);applyPayment((int)$p['id']);out(['ok'=>true,'paid'=>true]);
    case 'admin/overview':
      requireUser('admin');$counts=[];foreach(['users'=>'users','agents'=>"users WHERE role='agent'",'properties'=>'properties','pending_properties'=>"properties WHERE moderation_status='pending'",'pending_agents'=>"agent_profiles WHERE verification_status='pending'",'bookings'=>'bookings','paid_payments'=>"payments WHERE status='paid'",'tickets'=>"support_tickets WHERE status='open'"] as $name=>$table){$counts[$name]=(int)(one("SELECT COUNT(*) n FROM $table")['n']??0);}out(['counts'=>$counts,'recent'=>rows('SELECT p.id,p.title,p.moderation_status,p.created_at,u.name agent_name FROM properties p JOIN users u ON u.id=p.agent_id ORDER BY p.created_at DESC LIMIT 8')]);
    case 'admin/users':
      requireUser('admin');out(['users'=>rows("SELECT id,name,email,role,phone,is_suspended,created_at FROM users ORDER BY CASE WHEN role='admin' THEN 0 ELSE 1 END,id DESC LIMIT 400")]);
    case 'admin/users/status':
      $u=requireUser('admin');$id=n($b['id']??0);if($id===(int)$u['id'])fail('You cannot suspend your own account.');run('UPDATE users SET is_suspended=? WHERE id=? AND role<>\'admin\'',[!empty($b['suspended'])?1:0,$id]);out(['ok'=>true]);
    case 'admin/users/delete':
      $admin=requireUser('admin');$id=n($b['id']??0);
      if($id<1)fail('Choose a valid account.');
      if($id===(int)$admin['id'])fail('You cannot remove your own account.');
      $target=one("SELECT id,role FROM users WHERE id=? AND role IN ('user','agent')",[$id]);
      if(!$target)fail('User or agent account not found.',404);
      $profile=one('SELECT verification_document FROM agent_profiles WHERE user_id=?',[$id]);
      $media=$target['role']==='agent'
        ? rows('SELECT m.file_path FROM property_media m JOIN properties p ON p.id=m.property_id WHERE p.agent_id=?',[$id])
        : [];
      pdo()->beginTransaction();
      try {
        $deleted=run("DELETE FROM users WHERE id=? AND role IN ('user','agent')",[$id]);
        if($deleted!==1) {
          pdo()->rollBack();
          fail('User or agent account not found.',404);
        }
        pdo()->commit();
      } catch(Throwable $error) {
        if(pdo()->inTransaction())pdo()->rollBack();
        throw $error;
      }
      $cleanupFailed=false;
      foreach($media as $item) {
        $relativePath=(string)$item['file_path'];
        if(str_starts_with($relativePath,'/media/')) {
          $path=__DIR__.'/uploads/'.basename($relativePath);
          if(is_file($path) && !unlink($path))$cleanupFailed=true;
        }
      }
      if($profile && $profile['verification_document']) {
        $path=__DIR__.'/private/'.basename((string)$profile['verification_document']);
        if(is_file($path) && !unlink($path))$cleanupFailed=true;
      }
      if($cleanupFailed)error_log("FUTA Homes account removal: uploaded file cleanup failed for user {$id}.");
      error_log("FUTA Homes admin {$admin['id']} removed {$target['role']} account {$id}.");
      out(['ok'=>true,'cleanup_warning'=>$cleanupFailed]);
    case 'admin/properties':
      requireUser('admin');$list=rows(publicPropertyQuery().' ORDER BY p.created_at DESC LIMIT 300');foreach($list as &$p)decorate($p);unset($p);out(['properties'=>$list]);
    case 'admin/properties/status':
      requireUser('admin');$id=n($b['id']??0);$status=s($b['status']??'');if(!in_array($status,['pending','approved','rejected']))fail('Invalid status.');$p=one('SELECT title,agent_id FROM properties WHERE id=?',[$id]);if(!$p)fail('Not found.',404);run('UPDATE properties SET moderation_status=?,moderation_note=? WHERE id=?',[$status,s($b['note']??'',500),$id]);notice((int)$p['agent_id'],'Listing "'.$p['title'].'" is '.$status.'.');out(['ok'=>true]);
    case 'admin/agents':
      requireUser('admin');out(['agents'=>rows("SELECT u.id,u.name,u.email,u.phone,u.is_suspended,a.business_name,a.verification_status,a.verification_document,a.verification_note,a.verified_until,a.plan,a.plan_expires_at FROM users u JOIN agent_profiles a ON a.user_id=u.id ORDER BY CASE WHEN a.verification_status='pending' THEN 0 ELSE 1 END,u.id DESC LIMIT 400")]);
    case 'admin/agents/document':
      requireUser('admin');$id=n($_GET['id']??0);$a=one('SELECT verification_document FROM agent_profiles WHERE user_id=?',[$id]);if(!$a || !$a['verification_document'])fail('No document.',404);$path=__DIR__.'/private/'.basename($a['verification_document']);if(!is_file($path))fail('Document missing.',404);$mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);header_remove('Content-Type');header('Content-Type: '.$mime);header('Content-Disposition: inline; filename="verification.'.pathinfo($path,PATHINFO_EXTENSION).'"');header('Cache-Control: no-store');readfile($path);exit;
    case 'admin/agents/status':
      requireUser('admin');$id=n($b['id']??0);$status=s($b['status']??'');if(!in_array($status,['pending','approved','rejected']))fail('Invalid status.');$verifiedUntil=$status==='approved'?date('Y-m-d H:i:s',strtotime('+1 year')):null;run('UPDATE agent_profiles SET verification_status=?,verification_note=?,verified_until=? WHERE user_id=?',[$status,s($b['note']??'',800),$verifiedUntil,$id]);notice($id,'Agent verification is '.$status.'.');out(['ok'=>true]);
    case 'admin/plan/grant':
      requireUser('admin');$id=n($b['id']??0);$plan=s($b['plan']??'');if(!in_array($plan,['free','pro','premium']))fail('Invalid plan.');if(!one('SELECT user_id FROM agent_profiles WHERE user_id=?',[$id]))fail('Agent not found.',404);$expires=$plan==='free'?null:date('Y-m-d H:i:s',strtotime('+1 month'));run('UPDATE agent_profiles SET plan=?,plan_expires_at=? WHERE user_id=?',[$plan,$expires,$id]);if($plan!=='free'){ $ref='ADMIN-'.strtoupper(bin2hex(random_bytes(8)));run("INSERT INTO payments(agent_id,reference,plan,amount_kobo,provider,status,paid_at) VALUES(?,?,?,?, 'admin','paid', CURRENT_TIMESTAMP)",[$id,$ref,$plan,0]); }notice($id,'Admin updated your plan to '.ucfirst($plan).'.');out(['ok'=>true]);
    case 'admin/bookings':
      requireUser('admin');out(['bookings'=>rows('SELECT b.*,p.title,u.name user_name,a.name agent_name FROM bookings b JOIN properties p ON p.id=b.property_id JOIN users u ON u.id=b.user_id JOIN users a ON a.id=b.agent_id ORDER BY b.id DESC LIMIT 400')]);
    case 'admin/enquiries':
      requireUser('admin');out(['enquiries'=>rows('SELECT e.*,p.title,u.name user_name,a.name agent_name FROM enquiries e JOIN properties p ON p.id=e.property_id JOIN users u ON u.id=e.user_id JOIN users a ON a.id=e.agent_id ORDER BY e.id DESC LIMIT 400')]);
    case 'admin/payments':
      requireUser('admin');out(['payments'=>rows('SELECT p.*,u.name agent_name,u.email agent_email FROM payments p JOIN users u ON u.id=p.agent_id ORDER BY p.id DESC LIMIT 500')]);
    case 'admin/reports/data':
      requireUser('admin');
      $monthStart=one("SELECT DATE_FORMAT(DATE_SUB(CURRENT_DATE, INTERVAL 11 MONTH),'%Y-%m-01') AS month_start")['month_start'];
      out([
        'monthly_users'=>rows("SELECT DATE_FORMAT(created_at,'%Y-%m') AS month,COUNT(*) AS total FROM users WHERE created_at>=? GROUP BY DATE_FORMAT(created_at,'%Y-%m') ORDER BY month",[$monthStart]),
        'monthly_properties'=>rows("SELECT DATE_FORMAT(created_at,'%Y-%m') AS month,COUNT(*) AS total FROM properties WHERE created_at>=? GROUP BY DATE_FORMAT(created_at,'%Y-%m') ORDER BY month",[$monthStart]),
        'users'=>rows('SELECT name,email,role,phone,created_at FROM users ORDER BY id DESC LIMIT 5000'),
        'payments'=>rows('SELECT p.id,p.reference,p.plan,p.amount_kobo,p.provider,p.status,p.created_at,p.paid_at,u.name AS agent_name,u.email AS agent_email FROM payments p JOIN users u ON u.id=p.agent_id ORDER BY p.id DESC LIMIT 5000'),
      ]);
    case 'admin/payment/recheck':
      requireUser('admin');if($method!=='POST')fail('Method not allowed',405);$id=n($b['id']??0);$payment=one('SELECT p.*,u.email agent_email FROM payments p JOIN users u ON u.id=p.agent_id WHERE p.id=?',[$id]);if(!$payment)fail('Payment not found.',404);if($payment['status']==='paid')out(['ok'=>true,'paid'=>true]);if($payment['provider']!=='paystack' || $payment['status']!=='pending')fail('Only pending Paystack payments can be rechecked.');$transaction=checkPaystack((string)$payment['reference']);if(!$transaction)fail('Could not verify this payment with Paystack. Check the API configuration and try again.',503);if(($transaction['status']??'')!=='success')out(['ok'=>true,'paid'=>false]);if((int)($transaction['amount']??0)!==(int)$payment['amount_kobo'] || ($transaction['currency']??'')!=='NGN' || strtolower((string)($transaction['customer']['email']??''))!==strtolower((string)$payment['agent_email']))fail('Paystack payment details do not match this transaction.',400);applyPayment((int)$payment['id']);out(['ok'=>true,'paid'=>true]);
    case 'admin/reviews':
      requireUser('admin');out(['reviews'=>rows('SELECT r.*,a.name agent_name,u.name user_name FROM agent_reviews r JOIN users a ON a.id=r.agent_id JOIN users u ON u.id=r.user_id ORDER BY CASE WHEN r.status=\'pending\' THEN 0 ELSE 1 END,r.id DESC LIMIT 200')]);
    case 'admin/reviews/status':
      requireUser('admin');$status=s($b['status']??'');if(!in_array($status,['approved','rejected']))fail('Invalid status.');run('UPDATE agent_reviews SET status=? WHERE id=?',[$status,n($b['id']??0)]);out(['ok'=>true]);
    case 'admin/support':
      requireUser('admin');out(['tickets'=>rows('SELECT t.*,u.name user_name,u.email user_email FROM support_tickets t JOIN users u ON u.id=t.user_id ORDER BY t.id DESC LIMIT 400')]);
    case 'admin/support/reply':
      requireUser('admin');$id=n($b['id']??0);$ticket=one('SELECT user_id FROM support_tickets WHERE id=?',[$id]);if(!$ticket)fail('Ticket not found.',404);$reply=need(s($b['reply']??'',3000),'reply',2);run("UPDATE support_tickets SET admin_reply=?,status='answered' WHERE id=?",[$reply,$id]);notice((int)$ticket['user_id'],'Support replied to ticket #'.$id.'.');out(['ok'=>true]);
    default: fail('Unknown API route: '.$route,404);
  }
} catch(PDOException $e){ error_log('FUTA Homes SQL: '.$e->getMessage()); fail('A database operation failed. Check the server logs.',500); }
catch(Throwable $e){ error_log('FUTA Homes API: '.$e->getMessage()); fail('The request could not be completed.',500); }
