<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Infrastructure/Database.php';
require_once __DIR__ . '/../app/Infrastructure/RoomStore.php';

use Baloot\Infrastructure\RoomStore;

if (($argv[1] ?? '') === 'worker') {
    $code = (string)$argv[2];
    $guest = (string)$argv[3];
    $actionId = (string)$argv[4];
    $counter = (string)$argv[5];
    $store = new RoomStore();
    $result = $store->mutate($code,$guest,$actionId,'parallel_test',function(array &$state,int $seat) use ($counter):array {
        $value = (int)($state[$counter] ?? 0);
        usleep(100000);
        $state[$counter] = $value + 1;
        return ['value'=>$value+1];
    });
    echo json_encode($result,JSON_THROW_ON_ERROR);
    exit(0);
}
function concurrentCheck(bool $ok,string $message):void { if(!$ok) throw new RuntimeException($message); }
function launchWorkers(string $code,string $guest,array $ids,string $counter):void {
    $workers=[];
    foreach($ids as $id) {
        $pipes=[];
        $process=proc_open([PHP_BINARY,__FILE__,'worker',$code,$guest,$id,$counter],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process)) throw new RuntimeException('could not start concurrency worker');
        fclose($pipes[0]);
        $workers[]=[$process,$pipes];
    }
    foreach($workers as [$process,$pipes]) {
        $stdout=stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr=stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exit=proc_close($process);
        if($exit!==0) throw new RuntimeException('concurrency worker failed: '.$stderr.' '.$stdout);
    }
}
function uuidFor(int $n):string{return sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012x',$n);}
$store=new RoomStore();
$owner=str_repeat('9',64);
$room=$store->create($owner,'Concurrent Host',true);
$code=$room['code'];
$initial=(int)$store->read($code)['version'];
$unique=array_map('uuidFor',range(1,8));
launchWorkers($code,$owner,$unique,'concurrentCounter');
$after=$store->read($code);
concurrentCheck(($after['state']['concurrentCounter']??0)===8,'concurrent independent actions must not overwrite one another');
concurrentCheck((int)$after['version']===$initial+8,'each concurrent committed action increments version once');

$duplicate=array_fill(0,8,uuidFor(99));
launchWorkers($code,$owner,$duplicate,'duplicateCounter');
$afterDuplicate=$store->read($code);
concurrentCheck(($afterDuplicate['state']['duplicateCounter']??0)===1,'same idempotency key executed only once under concurrency');
concurrentCheck((int)$afterDuplicate['version']===$initial+9,'duplicate parallel requests cause only one state version increment');
echo "MySQL concurrent mutations and idempotency tests passed\\n";
