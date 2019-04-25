<?php

use PHPUnit\Framework\TestCase;
use React\EventLoop\Factory;
use React\Promise\Deferred;


/*
use function Choval\Async\execute;
use function Choval\Async\resolve_generator;
use function Choval\Async\sleep;
use function Choval\Async\sync;
use function Choval\Async\chain_resolve;
*/

use Choval\System\Stats;

class StatsTest extends TestCase {
  
  static $loop;
  static $stats;

  public static function setUpBeforeClass() {
    static::$loop = Factory::create();
    static::$loop->run();
    static::$stats = new Stats;
  }



  public function testDiskStats() {
    $stats = static::$stats;

    $disk = $stats->getDiskStats();
    $this->assertNotEmpty($disk);
    $cols = ['filesystem', 'size', 'used', 'available', 'percent', 'mounted_on'];
    foreach($disk as $row) {
      foreach($cols as $col) {
        $this->assertArrayHasKey($col, $row);
        if(!in_array($col, ['filesystem', 'mounted_on'])) {
          $this->assertInternalType('int', $row[$col]);
        }
      }
    }

    $stats = $stats->getSingleDiskStats(getcwd());
    $this->assertNotEmpty($stats);
    foreach($cols as $col) {
      $this->assertArrayHasKey($col, $stats);
      if(!in_array($col, ['filesystem', 'mounted_on'])) {
        $this->assertInternalType('int', $stats[$col]);
      }
    }
  }



  public function testCpuModels() {
    $stats = static::$stats;

    $models = $stats->getCpuModels();
    $this->assertNotEmpty($models);
  }



  /**
   * @depends testCpuModels
   */
  public function testCpuLoads() {
    $stats = static::$stats;

    $loads = $stats->getCpuLoads();
    $this->assertArrayHasKey('1_min', $loads);
    $this->assertArrayHasKey('5_min', $loads);
    $this->assertArrayHasKey('15_min', $loads);
    foreach($loads as $load) {
      $this->assertGreaterThanOrEqual(0, $load);
      $this->assertLessThanOrEqual(100, $load);
    }
    print_r($loads);
  }



}

