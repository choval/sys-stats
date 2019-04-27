<?php

use PHPUnit\Framework\TestCase;
use React\EventLoop\Factory;
use React\Promise\Deferred;

use function Choval\Async\sync;
use function Choval\Async\sleep;

use Choval\System\Stats;

class AsyncStatsTest extends TestCase {
  
  static $loop;
  static $stats;

  public static function setUpBeforeClass() {
    static::$loop = Factory::create();
    static::$loop->run();

    static::$stats = new Stats( static::$loop, 1 );
  }



  public function testOutput() {
    $stats = static::$stats;
    $loop = static::$loop;

    $output = sync( $loop, $stats->output() );
    $this->assertArrayHasKey( 'updated', $output );

    $updated = $output['updated'];

    sync( $loop, sleep( $loop, 2));
    $output = sync( $loop, $stats->output() );
    $this->assertArrayHasKey( 'updated', $output );

    $this->assertNotEquals($updated, $output['updated']);
    $updated = $output['updated'];
    
    $stats->stop();
    $output1 = sync( $loop, $stats->output() );
    sync( $loop, sleep( $loop, 2));
    $output2 = sync( $loop, $stats->output() );
    $this->assertEquals($output1['updated'], $output2['updated']);

  }



  public function testDiskStats() {
    $stats = static::$stats;
    $loop = static::$loop;

    $disk = sync( $loop, $stats->getDiskStats() );
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
          $this->assertLessThanOrEqual(120, $row[$col]);
        }
      }
    }

    $stats = sync($loop, $stats->getSingleDiskStats(getcwd()) );
    $this->assertNotEmpty($stats);
    foreach($cols as $col) {
      $this->assertArrayHasKey($col, $stats);
      if(!in_array($col, ['filesystem', 'mounted_on'])) {
        $this->assertInternalType('int', $stats[$col]);
      }
      if($col == 'capacity') {
        $this->assertGreaterThanOrEqual(0, $stats[$col]);
        $this->assertLessThanOrEqual(120, $stats[$col]);
      }
      $this->assertContains($stats, $disk);
    }

  }



  public function testCpuModels() {
    $stats = static::$stats;
    $loop = static::$loop;

    $models = sync( $loop, $stats->getCpuModels() );
    $this->assertNotEmpty($models);
  }



  /**
   * @depends testCpuModels
   */
  public function testCpuLoads() {
    $stats = static::$stats;
    $loop = static::$loop;

    $loads = sync( $loop, $stats->getCpuLoads() );
    $this->assertArrayHasKey('1_min', $loads);
    $this->assertArrayHasKey('5_min', $loads);
    $this->assertArrayHasKey('15_min', $loads);
    foreach($loads as $load) {
      $this->assertGreaterThanOrEqual(0, $load);
      $this->assertLessThanOrEqual(120, $load);
    }
    print_r($loads);
  }


  public function testMemStats() {
    $stats = static::$stats;
    $loop = static::$loop;

    $stats = sync( $loop, $stats->getMemStats() );
    $cols = ['total', 'free', 'used', 'available', 'capacity'];
    foreach($cols as $col) {
      $this->assertArrayHasKey($col, $stats);
    }
    $this->assertGreaterThanOrEqual(0, $stats['capacity']);
    $this->assertLessThanOrEqual(120, $stats['capacity']);
    print_r($stats);
  }


}



