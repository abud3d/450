<?php
declare(strict_types=1);
namespace Baloot\Game;
final class Qaid
{
 public static function decide(array &$game,int $claimantSeat):array
 {
  if(empty($game['qaidEnabled'])||!in_array($game['phase']??'', ['playing','roundEnd','gameEnd'],true))throw new \DomainException('طلب القيد غير متاح الآن');
  if($claimantSeat<0||$claimantSeat>3)throw new \DomainException('مقعد المطالب غير صحيح');
  if(!empty($game['qaidResult']))throw new \DomainException('تم حسم طلب القيد لهذه التوزيعة مسبقاً');
  return self::settle($game,$claimantSeat,self::violationTeams($game),'automatic',false);
 }
 public static function expire(array &$game,int $claimantSeat):array
 {
  if(!in_array($game['phase']??'', ['playing','roundEnd','gameEnd'],true)||!empty($game['qaidResult']))return [];
  return self::settle($game,$claimantSeat,[],'timeout',true);
 }
 private static function violationTeams(array $game):array
 {
  $teams=[];
  foreach($game['history']??[] as $play){
   if(!empty($play['violation'])&&isset($play['seat']))$teams[Rules::team((int)$play['seat'])]=true;
  }
  return array_map('intval',array_keys($teams));
 }
 private static function settle(array &$game,int $claimantSeat,array $violatingTeams,string $verdict,bool $timeout):array
 {
  $previous=$game['lastAwarded']??[0,0];
  if(in_array($game['phase'],['roundEnd','gameEnd'],true)){if(isset($game['roundScoresBefore'])&&is_array($game['roundScoresBefore']))$game['scores']=$game['roundScoresBefore'];else foreach([0,1] as $team)$game['scores'][$team]=($game['scores'][$team]??0)-($previous[$team]??0);}
  $actualViolation=!empty($violatingTeams);$claimantTeam=Rules::team($claimantSeat);if(!$actualViolation){$winnerTeam=1-$claimantTeam;}elseif(count($violatingTeams)===1){$winnerTeam=$violatingTeams[0];}else{$winnerTeam=$claimantTeam;}
  $claims=array_merge($game['projectClaims']??[[],[],[],[]],[$game['balotClaims']??[]]);
  $scored=Scoring::score([0,0],$game['contract'],$claims,(int)($game['dealer']??0),[0,0]);
  $base=(($game['contract']['type']??'sun')==='sun')?26:16;$total=$base+array_sum($scored['scoredProjectPoints']??[0,0]);
  $awarded=[0,0];$awarded[$winnerTeam]=$total;$game['scores'][$winnerTeam]=($game['scores'][$winnerTeam]??0)+$total;
  $buyer=(int)($game['contract']['buyer']??$claimantSeat);$buyerTeam=isset($game['contract']['buyerTeam'])?(int)$game['contract']['buyerTeam']:Rules::team($buyer);
  $game['lastAwarded']=$awarded;$game['qaidResult']=['claimantSeat'=>$claimantSeat,'winnerTeam'=>$winnerTeam,'claimantTeam'=>$claimantTeam,'success'=>$actualViolation,'actualViolation'=>$actualViolation,'verdict'=>$verdict,'timeout'=>$timeout,'total'=>$total,'base'=>$base];
  $game['lastScoring']=array_merge(in_array($game['phase'],['roundEnd','gameEnd'],true)?($game['lastScoring']??[]):[],[
   'raw'=>in_array($game['phase'],['roundEnd','gameEnd'],true)?($game['lastScoring']['raw']??$game['lastRaw']??[0,0]):[0,0],
   'projects'=>in_array($game['phase'],['roundEnd','gameEnd'],true)?($game['lastScoring']['projects']??$scored['projects']):$scored['projects'],
   'awarded'=>$awarded,'buyerSucceeded'=>$winnerTeam===$buyerTeam,'buyerFailed'=>$winnerTeam!==$buyerTeam,
   'roundWinner'=>$winnerTeam,'qaid'=>true,'scoredProjectPoints'=>$scored['scoredProjectPoints']??[0,0]
  ]);
  $game['phase']=(($game['scores'][0]??0)>=152||($game['scores'][1]??0)>=152)&&($game['scores'][0]??0)!==($game['scores'][1]??0)?'gameEnd':'roundEnd';
  if($game['phase']==='gameEnd')$game['winner']=($game['scores'][0]??0)>($game['scores'][1]??0)?0:1;else unset($game['winner']);
  return $game['qaidResult'];
 }
 private function __construct(){}
}
