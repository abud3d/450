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
$game = qaidGame(true);
$game['history'] = [];
$game['turn'] = 1;
$game['hands'][1] = [
    ['suit' => 'S', 'rank' => 'A', 'id' => 'SA'],
    ['suit' => 'H', 'rank' => '7', 'id' => 'H7'],
];
$game['trick'] = [['seat' => 0, 'card' => ['suit' => 'S', 'rank' => '7', 'id' => 'S7']]];
Rules::play($game, 1, 'H7');
$proof = $game['history'][0] ?? [];
qaidCheck(!empty($proof['violation']) && ($proof['proofCard']['id'] ?? '') === 'SA', 'History must retain the played violation and the matching-suit proof card.');

$game = qaidGame(false);
$game['phase'] = 'gameEnd';
$game['scores'] = [152, 140];
$game['lastAwarded'] = [0, 0];
$game['roundScoresBefore'] = [130, 140];
$result = Qaid::expire($game, 0);
qaidCheck($result['timeout'] === true && $game['phase'] === 'gameEnd' && $game['winner'] === 1 && $game['scores'] === [130, 166], 'A timeout opened on the final-score screen must restore the pre-round score and recalculate the winner.');
echo "PHP automatic qaid settlement tests passed\n";
