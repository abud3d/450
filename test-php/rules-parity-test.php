<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Game/Seating.php';
require_once __DIR__ . '/../app/Game/Projects.php';
require_once __DIR__ . '/../app/Game/Scoring.php';
require_once __DIR__ . '/../app/Game/Rules.php';

use Baloot\Game\Projects;
use Baloot\Game\Rules;
use Baloot\Game\Scoring;

function parity(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function cards(string $suit, array $ranks): array { return array_map(fn($rank) => ['suit'=>$suit,'rank'=>$rank,'id'=>$suit.$rank], $ranks); }

// Golden expectations are taken from game/rules.js, game/projects.js and game/scoring.js.
$seatAudit = Rules::create(['A','B','C','D']);
Rules::deal($seatAudit);
parity($seatAudit['auctionOrder'] === [3,2,1,0], 'auction order follows dealer-right counterclockwise seat progression');
$resetAudit = Rules::create(['A','B','C','D']);
Rules::deal($resetAudit);
$resetAudit['lastWinner']=2;$resetAudit['lastLandTeam']=0;$resetAudit['lastLandPoints']=10;
$resetAudit['phase']='roundEnd';
Rules::nextRound($resetAudit);
parity($resetAudit['lastWinner']===null && $resetAudit['lastLandTeam']===null && $resetAudit['lastLandPoints']===0, 'new deal clears previous trick winner and last-land markers');
$deck = Rules::deck();
parity(count($deck) === 32 && count(array_unique(array_column($deck,'id'))) === 32, 'reference deck has 32 unique cards');
parity(array_sum(array_map(fn($c)=>Rules::points($c,['type'=>'sun']),$deck)) === 120, 'sun card points total 120 before last trick');
parity(array_sum(array_map(fn($c)=>Rules::points($c,['type'=>'hokum','trump'=>'S']),$deck)) === 152, 'hokum card points total 152 before last trick');
parity([Rules::rank(['suit'=>'S','rank'=>'J'],'S'),Rules::rank(['suit'=>'S','rank'=>'9'],'S')] === [7,6], 'trump J outranks trump 9');
parity([Rules::rank(['suit'=>'H','rank'=>'A'],'S'),Rules::rank(['suit'=>'H','rank'=>'7'],'S')] === [7,0], 'non-trump ace is above seven');

$followHand = [...cards('H',['7']), ...cards('S',['J'])];
$followTrick = [['seat'=>0,'card'=>['suit'=>'H','rank'=>'A','id'=>'HA']]];
$legal = Rules::legal($followHand,$followTrick,['type'=>'hokum','trump'=>'S'],1);
parity(array_column($legal,'id') === ['H7'], 'must follow suit when able');

$overTrumpHand = [...cards('S',['J','7']), ...cards('C',['A'])];
$overTrumpTrick = [
 ['seat'=>0,'card'=>['suit'=>'H','rank'=>'A','id'=>'HA']],
 ['seat'=>1,'card'=>['suit'=>'S','rank'=>'9','id'=>'S9']],
];
$legal = Rules::legal($overTrumpHand,$overTrumpTrick,['type'=>'hokum','trump'=>'S'],2);
parity(array_column($legal,'id') === ['SJ'], 'must overtrump when opponent is winning and a higher trump exists');

$partnerTrick = [
 ['seat'=>0,'card'=>['suit'=>'H','rank'=>'A','id'=>'HA']],
 ['seat'=>1,'card'=>['suit'=>'H','rank'=>'K','id'=>'HK']],
];
$partnerHand = [...cards('S',['7']), ...cards('C',['A'])];
$legal = Rules::legal($partnerHand,$partnerTrick,['type'=>'hokum','trump'=>'S'],2);
parity(count($legal) === 2, 'may discard any card when partner is winning without restriction');
$legal = Rules::legal($partnerHand,$partnerTrick,['type'=>'hokum','trump'=>'S','restricted'=>true],2);
parity(array_column($legal,'id') === ['CA'], 'restricted contract requires non-trump discard when partner wins');

$leadHand = [...cards('S',['7']), ...cards('C',['A'])];
$restrictedLead = Rules::legal($leadHand,[],['type'=>'hokum','trump'=>'S','restricted'=>true,'lastWinnerSeat'=>0],1);
parity(array_column($restrictedLead,'id') === ['CA'], 'restricted lead follows last winner rule');
$unrestrictedLead = Rules::legal($leadHand,[],['type'=>'hokum','trump'=>'S','restricted'=>true,'lastWinnerSeat'=>1],1);
parity(count($unrestrictedLead) === 2, 'last winner seat is exempt from restricted lead');

$run3 = Projects::detect(cards('S',['A','K','Q']),['type'=>'sun'],0);
parity(($run3[0]['type'] ?? '') === '20' && count($run3[0]['cards']) === 3, 'three-card run matches reference');
$run4 = Projects::detect(cards('S',['A','K','Q','J']),['type'=>'sun'],0);
parity(($run4[0]['type'] ?? '') === '50' && count($run4[0]['cards']) === 4, 'four-card run matches reference');
$run5 = Projects::detect(cards('S',['A','K','Q','J','10']),['type'=>'sun'],0);
parity(($run5[0]['type'] ?? '') === '100-run' && count($run5[0]['cards']) === 5, 'five-card run matches reference');
$fourAces = [...cards('S',['A']),...cards('H',['A']),...cards('D',['A']),...cards('C',['A'])];
$project400 = Projects::detect($fourAces,['type'=>'sun'],0);
parity(($project400[0]['type'] ?? '') === '400' && ($project400[0]['high'] ?? -1) === 8, 'four aces are the 400 project with reference rank index');
$project100 = Projects::detect($fourAces,['type'=>'hokum','trump'=>'S'],0);
parity(($project100[0]['type'] ?? '') === '100-set', 'four aces in hokum are a 100-set, not 400');
$belot = Projects::detect([...cards('S',['K','Q'])],['type'=>'hokum','trump'=>'S'],0);
parity(count(array_filter($belot,fn($p)=>$p['type']==='belot')) === 1, 'trump king and queen detect belot');

$resolved = Projects::resolve([
 [['type'=>'20','strength'=>1,'high'=>0,'suit'=>'S','seat'=>3,'team'=>1]],
 [['type'=>'20','strength'=>1,'high'=>0,'suit'=>'H','seat'=>1,'team'=>1]],
],0,'sun');
parity(($resolved['winner']['seat'] ?? -1) === 1, 'equal projects are ordered from dealer right as in reference');

parity(Scoring::convertRaw(65,'sun') === 13, 'sun 65 converts to 13');
parity(Scoring::convertRaw(64,'sun') === 12, 'sun 64 rounds to 60 before conversion');
parity(Scoring::convertRaw(66,'sun') === 14, 'sun 66 rounds to 70 before conversion');
parity(Scoring::convertRaw(81,'hokum') === 8, 'hokum 81 converts to 8');
parity(Scoring::convertRaw(86,'hokum') === 9, 'hokum 86 converts to 9');

$score = Scoring::score([65,65],['type'=>'sun','buyer'=>0,'buyerTeam'=>0,'originalBuyerTeam'=>0],[],0,[4,4]);
parity($score['buyerFailed'] === false && $score['awarded'] === [13,13], 'sun 65-65 boundary matches reference scoring');
$score = Scoring::score([81,81],['type'=>'hokum','buyer'=>0,'buyerTeam'=>0,'originalBuyerTeam'=>0],[],0,[4,4]);
parity($score['buyerFailed'] === false && $score['awarded'] === [8,8], 'hokum 81-81 boundary matches reference scoring');

// Qaid violation history must be recorded only when the option is enabled, matching game/rules.js.
$qaidGame = [
 'phase'=>'playing','turn'=>1,'qaidEnabled'=>true,
 'hands'=>[[],[...cards('H',['7']),...cards('C',['7'])],[],[]],
 'trick'=>[['seat'=>0,'card'=>['suit'=>'H','rank'=>'A','id'=>'HA']]],
 'contract'=>['type'=>'sun','buyer'=>0],'history'=>[],'balotClaims'=>[],
 'doubleWindow'=>true,'lastWinner'=>null,'tricks'=>[],'tricksWon'=>[0,0],
];
Rules::play($qaidGame,1,'C7');
parity(($qaidGame['history'][0]['violation'] ?? false) === true, 'enabled qaid records a failure to follow suit');
$noQaidGame = $qaidGame;
$noQaidGame['phase']='playing';$noQaidGame['turn']=1;$noQaidGame['qaidEnabled']=false;
$noQaidGame['hands'][1]=[...cards('H',['7']),...cards('C',['7'])];
$noQaidGame['trick']=[['seat'=>0,'card'=>['suit'=>'H','rank'=>'A','id'=>'HA']]];
$noQaidGame['history']=[];$noQaidGame['tricks']=[];$noQaidGame['tricksWon']=[0,0];
$rejected=false;
try { Rules::play($noQaidGame,1,'C7'); } catch (DomainException $e) { $rejected=true; }
parity($rejected && $noQaidGame['history']===[], 'without qaid, illegal off-suit play is rejected and not marked as a qaid violation');

echo "PHP rule/project/scoring/qaid parity fixtures passed\\n";
