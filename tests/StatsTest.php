<?php

use PHPUnit\Framework\TestCase;
use React\EventLoop\Factory;
use React\Promise\Deferred;

use Choval\System\Stats;

class StatsTest extends TestCase {
  
  static $stats;

  public static function setUpBeforeClass() {
    static::$stats = new Stats;
  }



  public function testDiskStats() {
    $stats = static::$stats;

    $disk = $stats->getDiskStats();
    $this->assertNotEmpty($disk);
    $cols = ['filesystem', 'size', 'used', 'available', 'capacity', 'mounted_on'];
    foreach($disk as $row) {
      foreach($cols as $col) {
        $this->assertArrayHasKey($col, $row);
        if(!in_array($col, ['filesystem', 'mounted_on'])) {
          $this->assertInternalType('int', $row[$col]);
        }
        if($col == 'capacity') {
          $this->assertGreaterThanOrEqual(0, $row[$col]);
          $this->assertLessThanOrEqual(100, $row[$col]);
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
      if($col == 'capacity') {
        $this->assertGreaterThanOrEqual(0, $stats[$col]);
        $this->assertLessThanOrEqual(100, $stats[$col]);
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


  public function testMemStats() {
    $stats = static::$stats;

    $stats = $stats->getMemStats();
    $cols = ['total', 'free', 'used', 'available', 'capacity'];
    foreach($cols as $col) {
      $this->assertArrayHasKey($col, $stats);
    }
    $this->assertGreaterThanOrEqual(0, $stats['capacity']);
    $this->assertLessThanOrEqual(100, $stats['capacity']);
    print_r($stats);
  }


  public function testMemUsage() {
    $stats = static::$stats;

    $usage = $stats->getMemUsage();
    $cols = ['peak', 'peak_active', 'current', 'current_active'];
    foreach($cols as $col) {
      $this->assertArrayHasKey($col, $usage);
    }
    print_r($usage);
  }



}

