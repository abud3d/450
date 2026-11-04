<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/Game/Seating.php';
require_once __DIR__ . '/../app/Game/Projects.php';
require_once __DIR__ . '/../app/Game/Scoring.php';
require_once __DIR__ . '/../app/Game/Rules.php';
require_once __DIR__ . '/../app/Game/Purchase.php';
require_once __DIR__ . '/../app/Game/Bot.php';
require_once __DIR__ . '/../app/Game/TurnClock.php';
require_once __DIR__ . '/../app/Game/Qaid.php';
require_once __DIR__ . '/../app/Game/Engine.php';
use Baloot\Game\Engine;
use Baloot\Game\TurnClock;
$gid=str_repeat('a',32);
$room=['code'=>'ABC123','ownerGuestId'=>$gid,'qaidEnabled'=>true,'players'=>[['name'=>'Host','guestId'=>$gid,'bot'=>false],null,null,null],'game'=>null];
Engine::start($room,0);
if($room['game']['phase']!=='auction1'||count($room['players'])!==4)throw new RuntimeException('room start failed');
if(count(Engine::snapshot($room,0)['hand'])!==5)throw new RuntimeException('private hand snapshot failed');
if(Engine::advanceBots($room,16)<1)throw new RuntimeException('bots did not advance');
$room['game']['phase'] = 'playing';
$room['game']['turn'] = 0;
$room['game']['contract'] = ['type' => 'sun', 'buyer' => 0, 'buyerTeam' => 0, 'level' => 1];
$room['game']['history'] = [[
    'seat' => 2,
    'card' => ['suit' => 'H', 'rank' => '7', 'id' => 'H7'],
    'proofCard' => ['suit' => 'S', 'rank' => 'A', 'id' => 'SA'],
    'violationReason' => 'لم يتبع الشكل رغم وجوده في يده',
    'violation' => true,
    'trickIndex' => 0,
]];
TurnClock::arm($room, 120);
Engine::apply($room, 0, 'qaid_open');
if (isset($room['turnDeadlineAt'])) throw new RuntimeException('turn clock must pause during qaid review');
$claimantSnapshot = Engine::snapshot($room, 0);
$otherSnapshot = Engine::snapshot($room, 1);
if (empty($claimantSnapshot['qaidWindowActive']) || empty($claimantSnapshot['qaidDeadline'])) throw new RuntimeException('claimant must recover the active qaid window after refresh');
if (empty($claimantSnapshot['qaidHistory'][0]['cards'][0]['proofCard']['id'])) throw new RuntimeException('claimant must see the proof card');
if (($claimantSnapshot['qaidHistory'][0]['cards'][0]['violationReason'] ?? '') !== 'لم يتبع الشكل رغم وجوده في يده') throw new RuntimeException('claimant must see why the played card was illegal');
if (!empty($otherSnapshot['qaidHistory']) || !empty($otherSnapshot['qaidDeadline'])) throw new RuntimeException('proof cards must stay private from other seats');
if (empty($claimantSnapshot['legalCardIds'])) throw new RuntimeException('active player must receive legal card IDs for turn-time validation');
echo "room engine tests passed\n";
