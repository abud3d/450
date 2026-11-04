<?php
declare(strict_types=1);

$base=rtrim((string)getenv('ROOM_TEST_BASE'),'/');
if($base==='')throw new RuntimeException('ROOM_TEST_BASE is missing');
function roomCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function roomRequest(string $method,string $path,?array $body=null,string $cookie=''):array{
 global $base;$headers=[];$ch=curl_init($base.$path);
 $requestHeaders=['Origin: '.$base,'Content-Type: application/json'];
 if($cookie!=='')$requestHeaders[]='Cookie: '.$cookie;
 curl_setopt_array($ch,[
  CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$requestHeaders,
  CURLOPT_HEADERFUNCTION=>function($curl,$line)use(&$headers){$n=strlen($line);$p=explode(':',$line,2);if(count($p)===2)$headers[strtolower(trim($p[0]))][]=trim($p[1]);return $n;},
 ]);
 if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
 $raw=curl_exec($ch);if($raw===false){$e=curl_error($ch);curl_close($ch);throw new RuntimeException($e);}
 $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);$data=json_decode($raw,true);
 if(!is_array($data))throw new RuntimeException('room API returned invalid JSON: '.$raw);
 $cookies=[];foreach($headers['set-cookie']??[] as $line){$pair=explode(';',$line,2)[0];$p=explode('=',$pair,2);if(count($p)===2)$cookies[$p[0]]=$p[1];}
 return ['status'=>$status,'data'=>$data,'cookies'=>$cookies];
}
function uuidRoom(int $n):string{return sprintf('bbbbbbbb-bbbb-4bbb-8bbb-%012x',$n);}
$anonymousCreate=roomRequest('POST','/api/room.php?action=create',['name'=>'Anonymous','qaidEnabled'=>true]);
roomCheck($anonymousCreate['status']===401,'anonymous room creation is rejected without an identity');
$anonymousState=roomRequest('GET','/api/room.php?action=state&code=ABC123');
roomCheck($anonymousState['status']===401,'anonymous room state access is rejected without an identity');

$clients=[];
$identity=roomRequest('GET','/api/room.php?action=identity');
roomCheck($identity['status']===200 && preg_match('/^[a-f0-9]{64}$/',(string)($identity['data']['guestId']??''))===1,'guest identity bootstrap returns a persistent identity');
$created=roomRequest('POST','/api/room.php?action=create',['name'=>'API Player 1','qaidEnabled'=>true],'baloot_guest='.($identity['cookies']['baloot_guest']??''));
roomCheck($created['status']===201 && ($created['data']['ok']??false),'room API creates first player room');
$code=(string)$created['data']['code'];
$clients[0]=['cookie'=>'baloot_guest='.($identity['cookies']['baloot_guest']??''),'seat'=>0];
roomCheck((bool)preg_match('/^[A-F0-9]{6}$/',$code),'room API returns a six-character room code');
for($seat=1;$seat<4;$seat++){
 $guestIdentity=roomRequest('GET','/api/room.php?action=identity');
 roomCheck($guestIdentity['status']===200,'joining browser obtains its own guest identity');
 $guestCookie='baloot_guest='.($guestIdentity['cookies']['baloot_guest']??'');
 $joined=roomRequest('POST','/api/room.php?action=join',['code'=>$code,'name'=>'API Player '.($seat+1)],$guestCookie);
 roomCheck($joined['status']===200 && ($joined['data']['seat']??-1)===$seat,'separate guest joins expected seat '.$seat);
 $guest=$joined['cookies']['baloot_guest']??'';
 roomCheck((bool)preg_match('/^[a-f0-9]{64}$/',$guest),'each joining browser receives its own guest cookie');
 $clients[$seat]=['cookie'=>$guestCookie,'seat'=>$seat];
}
$before=roomRequest('GET','/api/room.php?action=state&code='.$code,null,$clients[0]['cookie']);
roomCheck($before['status']===200 && ($before['data']['seat']??-1)===0,'room state read returns the owner seat');
$version=(int)$before['data']['snapshot']['version'];
$started=roomRequest('POST','/api/room.php?action=start',['code'=>$code,'actionId'=>uuidRoom(1),'version'=>$version],$clients[0]['cookie']);
roomCheck($started['status']===200 && ($started['data']['snapshot']['phase']??'')==='auction1','four-human room starts through PHP API');
$version=(int)$started['data']['snapshot']['version'];

$turn=(int)$started['data']['snapshot']['turn'];
roomCheck($turn===3,'dealer-right player begins the auction');
$bidPayload=['code'=>$code,'choice'=>'pass','actionId'=>uuidRoom(2),'version'=>$version];
$bidA=roomRequest('POST','/api/room.php?action=bid',$bidPayload,$clients[3]['cookie']);
$bidB=roomRequest('POST','/api/room.php?action=bid',$bidPayload,$clients[3]['cookie']);
roomCheck($bidA['status']===200 && $bidB['status']===200,'duplicate HTTP action requests return successful idempotent responses');
$afterBid=roomRequest('GET','/api/room.php?action=state&code='.$code,null,$clients[0]['cookie']);
roomCheck((int)$afterBid['data']['snapshot']['version']===$version+1,'duplicate browser action is committed once');
roomCheck((int)$afterBid['data']['snapshot']['turn']===2,'single bid advances exactly one auction seat');

$hands=[];
for($seat=0;$seat<4;$seat++){
 $res=roomRequest('GET','/api/room.php?action=resume&code='.$code,null,$clients[$seat]['cookie']);
 roomCheck($res['status']===200 && ($res['data']['seat']??-1)===$seat,'resume restores the same seat for browser '.$seat);
 $snapshot=$res['data']['snapshot'];
 roomCheck(count($snapshot['hand']??[])===5 && !array_key_exists('hands',$snapshot),'each HTTP session sees only its own five cards');
 $hands[$seat]=array_column($snapshot['hand'],'id');
}
for($a=0;$a<4;$a++)for($b=$a+1;$b<4;$b++)roomCheck(!array_intersect($hands[$a],$hands[$b]),'separate player hands are disjoint');

$dsn=(string)getenv('BALOUT_DB_DSN');
$pdo=new PDO($dsn,(string)getenv('BALOUT_DB_USER'),(string)getenv('BALOUT_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$stale=$pdo->prepare('UPDATE baloot_room_players p INNER JOIN baloot_rooms r ON r.id=p.room_id SET p.last_seen_at=UTC_TIMESTAMP(6)-INTERVAL 1 MINUTE WHERE r.room_code=? AND p.seat IN (1,2,3)');
$stale->execute([$code]);
$presence=roomRequest('GET','/api/room.php?action=state&code='.$code,null,$clients[0]['cookie']);
$players=$presence['data']['snapshot']['players']??[];
roomCheck(!empty($players[0]['connected']),'active poll marks the owner connected');
roomCheck(empty($players[1]['connected'])&&empty($players[2]['connected'])&&empty($players[3]['connected']),'stale players appear disconnected');
$reconnected=roomRequest('GET','/api/room.php?action=resume&code='.$code,null,$clients[1]['cookie']);
roomCheck($reconnected['status']===200 && ($reconnected['data']['seat']??-1)===1,'returning player resumes the original seat');
roomCheck(!empty($reconnected['data']['snapshot']['players'][1]['connected']),'returning player heartbeat restores online presence');
echo 'PHP room HTTP integration passed: four independent seats, private hands, idempotent action, polling, heartbeat and resume'.PHP_EOL;
