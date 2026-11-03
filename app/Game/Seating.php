<?php
declare(strict_types=1);
namespace Baloot\Game;
final class Seating {
 public const PLAYER_NUMBERS=[1,2,3,4];
 public static function toPlayerNumber(int $s):int{if($s<0||$s>3)throw new \InvalidArgumentException('Invalid seat');return $s+1;}
 public static function toSeat(int $n):int{if($n<1||$n>4)throw new \InvalidArgumentException('Invalid player number');return $n-1;}
 public static function clockwiseSeat(int $s):int{return ($s+1)%4;}
 public static function counterClockwiseSeat(int $s):int{return ($s+3)%4;}
}