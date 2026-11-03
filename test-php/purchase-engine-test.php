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
use Baloot\Game\Rules;
use Baloot\Game\Purchase;
use Baloot\Game\Qaid;
function ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}

$g=Rules::create(['A','B','C','D']); Rules::deal($g);
ok($g['phase']==='auction1','first auction');
ok(array_sum(array_map('count',$g['hands']))===20,'initial five-card deal');
$buyer=$g['turn']; Purchase::choose($g,$buyer,'take');
while($g['phase']==='postPurchase' && $g['postPurchase']['stage']==='responses'){ $s=$g['turn']; Purchase::respond($g,$s,'pass'); }
ok($g['phase']==='buyerConfirm','first-round buyer confirmation');
Purchase::respond($g,$buyer,'confirmHukm');
while($g['phase']==='postPurchase' && $g['postPurchase']['stage']==='doubleResponse'){ $s=$g['turn']; Purchase::respond($g,$s,'pass'); }
ok(in_array($g['phase'],['playing','projectDeclaration'],true),'first purchase completes');
ok(count(array_filter($g['hands'],fn($h)=>count($h)!==8))===0,'first purchase deals eight cards each');

$second=Rules::create(['A','B','C','D']); Rules::deal($second);
for($i=0;$i<4;$i++) Purchase::choose($second,$second['turn'],'pass');
ok($second['phase']==='auction2','four passes open second auction');
$secondBuyer=$second['turn']; Purchase::choose($second,$secondBuyer,'takeSecond');
while($second['phase']==='postPurchase' && $second['postPurchase']['stage']==='secondPasses'){ $s=$second['turn']; Purchase::respond($second,$s,'pass'); }
ok($second['phase']==='buyerSelectHukm','second-round buyer selects trump');
$available=array_values(array_filter(Rules::SUITS,fn($s)=>$s!==$second['upCard']['suit']));
Purchase::respond($second,$secondBuyer,$available[0]);
while($second['phase']==='postPurchase' && $second['postPurchase']['stage']==='doubleResponse'){ $s=$second['turn']; Purchase::respond($second,$s,'pass'); }
ok(in_array($second['phase'],['playing','projectDeclaration'],true),'second purchase completes');
ok($second['contract']['trump']===$available[0],'second-round trump retained');
ok(count(array_filter($second['hands'],fn($h)=>count($h)!==8))===0,'second purchase deals eight cards each');

$qaid=['phase'=>'playing','qaidEnabled'=>true,'history'=>[['seat'=>1,'violation'=>true]],'contract'=>['type'=>'sun','buyer'=>1,'buyerTeam'=>1,'originalBuyerTeam'=>1],'projectClaims'=>[[],[],[],[]],'balotClaims'=>[],'dealer'=>0,'scores'=>[0,0]];
$result=Qaid::claim($qaid,0,0,0);
ok($result['success']===true && $result['winnerTeam']===0,'valid qaid claim awards claimant team');
ok($qaid['phase']==='roundEnd' && $qaid['scores'][0]>0,'qaid resolves and scores');

$clock=['turn'=>1]; TurnClock::arm($clock,30,100);
ok(!TurnClock::expired($clock,129),'clock before deadline');
ok(TurnClock::expired($clock,130),'clock expires at deadline');
$botChoice=Bot::bid($h,$h['turn']);
ok(in_array($botChoice,['pass','sun','takeSecond','S','H','D','C'],true),'bot auction choice is valid');

echo "PHP purchase, qaid, bot and clock tests passed\n";
