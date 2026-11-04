<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/Game/Seating.php';
require_once __DIR__ . '/../app/Game/Projects.php';
require_once __DIR__ . '/../app/Game/Scoring.php';
require_once __DIR__ . '/../app/Game/Rules.php';
require_once __DIR__ . '/../app/Game/Qaid.php';
use Baloot\Game\Qaid;
use Baloot\Game\Rules;
function qaidCheck(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function qaidGame(bool $violation):array{$game=Rules::create();$game['qaidEnabled']=true;$game['phase']='playing';$game['contract']=['type'=>'sun','trump'=>null,'buyer'=>0,'buyerTeam'=>0,'level'=>1];$game['history']=$violation?[['seat'=>2,'violation'=>true]]:[];return $game;}
$game=qaidGame(true);$result=Qaid::decide($game,1);
qaidCheck($result['success']===true&&$result['winnerTeam']===1,'A proven violation should award the claimant team automatically.');
qaidCheck($game['scores']===[0,26],'Automatic correct qaid should award the claimant team.');
$game=qaidGame(false);$result=Qaid::decide($game,0);
qaidCheck($result['success']===false&&$result['winnerTeam']===1,'No violation should award the opposing team.');
$game=qaidGame(false);$result=Qaid::expire($game,0);
qaidCheck($result['timeout']===true&&$result['winnerTeam']===1,'Qaid timeout should award the opposing team.');
qaidCheck($game['scores']===[0,26],'Qaid timeout should apply the loss score.');
$game=qaidGame(true);$game['scores']=[140,130];$result=Qaid::decide($game,1);
qaidCheck($game['phase']==='gameEnd'&&$game['winner']===1,'Qaid settlement must finish the match when the claimant reaches 152.');
echo "PHP automatic qaid settlement tests passed\n";
