<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/Game/Seating.php';
require_once __DIR__ . '/../app/Game/Projects.php';
require_once __DIR__ . '/../app/Game/Scoring.php';
require_once __DIR__ . '/../app/Game/Rules.php';
require_once __DIR__ . '/../app/Game/Purchase.php';
use Baloot\Game\Rules;
use Baloot\Game\Purchase;
function ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
$g=Rules::create(['A','B','C','D']); Rules::deal($g);
ok($g['phase']==='auction1','first auction'); ok(array_sum(array_map('count',$g['hands']))===20,'initial deal');
$buyer=$g['turn']; Purchase::choose($g,$buyer,'take');
while($g['phase']==='postPurchase' && $g['postPurchase']['stage']==='responses'){ $s=$g['turn']; Purchase::respond($g,$s,'pass'); }
ok($g['phase']==='buyerConfirm','buyer confirmation'); Purchase::respond($g,$buyer,'confirmHukm');
ok(in_array($g['phase'],['playing','projectDeclaration'],true),'first purchase complete');
ok(array_sum(array_map('count',$g['hands']))===32,'32 cards');
ok(count(array_filter($g['hands'],fn($h)=>count($h)!==8))===0,'eight each');
$h=Rules::create(['A','B','C','D']); Rules::deal($h); for($i=0;$i<4;$i++) Purchase::choose($h,$h['turn'],'pass'); ok($h['phase']==='auction2','second auction');
echo "purchase tests passed\n";
