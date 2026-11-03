<?php
declare(strict_types=1);
namespace Baloot\Game;
final class Scoring {
 public static function convertRaw(int $raw,string $type):int{if($type==='sun'){$r=$raw%10;$v=$r===5?floor($raw/10)*10+5:round($raw/10)*10;return (int)($v/5);}return (int)floor(($raw+4)/10);}
 public static function score(array $raw,array $c,array $claims=[],int $dealer=0,array $won=[0,0]):array{
  $level=$c['level']??1;$mult=$level===2?2:($level===3?3:($level===4?4:1));$pm=$level===2?2:1;$total=($c['type']==='sun'?26:16)*$mult;$buyer=$c['buyerTeam']??(($c['buyer']??0)%2);$original=$c['originalBuyerTeam']??(($c['buyer']??0)%2);$counter=1-$buyer;$p=Projects::resolve($claims,$dealer,$c['type']);$sp=[$p['regularPoints'][0]*$pm+$p['belotPoints'][0],$p['regularPoints'][1]*$pm+$p['belotPoints'][1]];$cr=$raw[$counter];$converted=self::convertRaw($cr,$c['type']);$cp=array_values(array_filter($p['counted'],fn($x)=>$x['team']===$counter));$buyerBelot=(bool)array_filter($p['belots'],fn($x)=>$x['team']===$buyer);
  if($level>=2&&isset($c['doubleTeam'])&&$raw[0]===$raw[1])$failed=$c['doubleTeam']===$buyer;elseif($c['type']==='sun'){$th=$cp?min(array_map(fn($x)=>['20'=>56,'50'=>41,'100-run'=>16,'100-set'=>16,'400'=>0][$x['type']]??66,$cp)):66;$failed=$cr>$th||($cr===$th&&$th!==65);}else{$th=$cp&&!$buyerBelot?min(array_map(fn($x)=>['20'=>72,'50'=>56,'100-run'=>32,'100-set'=>32][$x['type']]??82,$cp)):82;$failed=$cr>$th||($cr===$th&&$th!==81);}
  if($level===1&&$cr===($c['type']==='sun'?65:81)&&!$cp)$failed=false;$winner=$failed?$counter:$buyer;$a=[0,0];
  if($level===5){$a=[$raw[0]+$sp[0],$raw[1]+$sp[1]];$winner=$a[0]===$a[1]?$buyer:($a[0]>$a[1]?0:1);$failed=$winner!==$buyer;}elseif($failed)$a[$counter]=$total+$sp[$counter]+$sp[$buyer];else{$a[$counter]=$converted*$mult+$sp[$counter];$a[$buyer]=$total-$converted*$mult+$sp[$buyer];}
  $capot=in_array(8,$won,true);if($capot&&$level!==5){$winner=$won[0]===8?0:1;$a=[0,0];$a[$winner]=($c['type']==='sun'?44:25)*($level>=3?$level:1)+$sp[$winner];}
  return ['raw'=>$raw,'awarded'=>$a,'buyerTeam'=>$buyer,'counterTeam'=>$counter,'originalBuyerTeam'=>$original,'buyerFailed'=>$failed,'roundWinner'=>$winner,'buyerSucceeded'=>$winner===$original,'doubleSucceeded'=>$level>=2&&$winner!==$original,'convertedCounter'=>$converted,'projects'=>$p,'scoredProjectPoints'=>$sp,'capot'=>$capot,'total'=>$total,'multiplier'=>$mult,'projectMultiplier'=>$pm,'coffee'=>$level===5];
 }
}