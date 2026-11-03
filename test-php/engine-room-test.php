<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/Game/Seating.php';
require_once __DIR__ . '/../app/Game/Projects.php';
require_once __DIR__ . '/../app/Game/Scoring.php';
require_once __DIR__ . '/../app/Game/Rules.php';
require_once __DIR__ . '/../app/Game/Purchase.php';
require_once __DIR__ . '/../app/Game/Bot.php';
require_once __DIR__ . '/../app/Game/Qaid.php';
require_once __DIR__ . '/../app/Game/Engine.php';
use Baloot\Game\Engine;
$gid=str_repeat('a',32);
$room=['code'=>'ABC123','ownerGuestId'=>$gid,'qaidEnabled'=>true,'players'=>[['name'=>'Host','guestId'=>$gid,'bot'=>false],null,null,null],'game'=>null];
Engine::start($room,0);
if($room['game']['phase']!=='auction1'||count($room['players'])!==4)throw new RuntimeException('room start failed');
if(count(Engine::snapshot($room,0)['hand'])!==5)throw new RuntimeException('private hand snapshot failed');
if(Engine::advanceBots($room,16)<1)throw new RuntimeException('bots did not advance');
echo "room engine tests passed\n";
