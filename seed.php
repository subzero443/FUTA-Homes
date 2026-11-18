<?php
declare(strict_types=1);
// CLI-only demo fixture seeder. Run: php backend/seed.php
if(PHP_SAPI!=='cli'){http_response_code(403);exit('CLI only');}
$config=require __DIR__.'/config.php';
try{$db=new PDO("mysql:host={$config['db_host']};port={$config['db_port']};dbname={$config['db_name']};charset=utf8mb4",$config['db_user'],$config['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$db->exec("SET time_zone = '+01:00'");}
catch(Throwable $e){exit("Database connection failed: ".$e->getMessage()."\n");}
function account(PDO $db,string $role,string $name,string $email,string $password): array { $q=$db->prepare('SELECT id FROM users WHERE email=?');$q->execute([$email]);if($row=$q->fetch())return [(int)$row['id'],false];$q=$db->prepare("INSERT INTO users(name,email,password_hash,role,phone) VALUES(?,?,?,?,?)");$q->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),$role,$role==='agent'?'07000000000':null]);$id=(int)$db->lastInsertId();if($role==='agent'){$db->prepare("INSERT INTO agent_profiles(user_id,business_name,verification_status,verified_until,plan,plan_expires_at) VALUES(?,?,'approved',CURRENT_TIMESTAMP+INTERVAL 1 YEAR,'pro',CURRENT_TIMESTAMP+INTERVAL 1 MONTH)")->execute([$id,'Marvins Home and Apartments']);}return [$id,true];}
$generated=[];
foreach([['admin','Admin','admin@futa-homes.local'],['agent','Marvins Properties','agent@futa-homes.local'],['user','Demo User','user@futa-homes.local']] as [$role,$name,$email]){$pw='Demo-'.bin2hex(random_bytes(6)).'!';$account=account($db,$role,$name,$email,$pw);$generated[$role]=[$account[0],$email,$account[1]?$pw:'[existing account — password unchanged]'];}
$agent=$generated['agent'][0];
$props=[
 ['Room Selfcon Apartment','Comfortable self-contained apartment. Demo photo shown for UI testing only.','North Gate, Akure','North Gate',300000,340000,1,1,'room-gaming.jpg'],
 ['Two Bedroom Apartment','A spacious two-bedroom apartment close to local amenities. Demo listing.','North Gate, Akure','North Gate',450000,520000,2,2,'office-view.jpg'],
 ['Selfcon near South Gate','Self-contained apartment near South Gate. Demo listing—replace images with real accommodation photos before launch.','South Gate, Akure','South Gate',600000,670000,1,1,'room-blue.jpg'],
 ['Modern Apartment','Modern shared compound near FUTA. Demo listing.','West Gate, Akure','West Gate',350000,410000,1,1,'building.jpg']
];
$check=$db->prepare('SELECT COUNT(*) n FROM properties WHERE agent_id=?');$check->execute([$agent]);$existing=(int)$check->fetch()['n'];
if(!$existing){foreach($props as [$title,$desc,$address,$location,$price,$total,$beds,$baths,$img]){$stmt=$db->prepare("INSERT INTO properties(agent_id,title,description,address,location,price,total_package,bedrooms,bathrooms,moderation_status) VALUES(?,?,?,?,?,?,?,?,?,'approved')");$stmt->execute([$agent,$title,$desc,$address,$location,$price,$total,$beds,$baths]);$pid=(int)$db->lastInsertId();$src=__DIR__.'/../frontend/public/demo/'.$img;$target=__DIR__.'/uploads/'.$img;if(is_file($src)&&!is_file($target))copy($src,$target);$db->prepare("INSERT INTO property_media(property_id,file_path,media_type) VALUES(?,?,'image')")->execute([$pid,'/media/'.$img]);}}
echo "\nFUTA Homes demo setup completed.\n";
echo "Existing accounts are NOT overwritten. Passwords below work only for newly created accounts.\n\n";
foreach($generated as $role=>[$id,$email,$pw])echo strtoupper($role)."   Email: $email    New account password: $pw\n";
echo "\nKeep this output private. The photos and listings are DEMO EXAMPLES, not verified rental properties.\n";
