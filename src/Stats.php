<?php
namespace Choval\System;

use React\EventLoop\LoopInterface;
use React\Promise\Deferred;

use function Choval\Async\execute;

final class Stats {


  private $loop;
  private $frequency;
  private $timer;

  private $disk_stats;
  private $disk_stats_raw;

  private $cpu_loads;
  private $cpu_models;
  private $cpu_models_raw;


  /**
   *
   * Constructor
   *
   */
  public function __construct(LoopInterface $loop=null, float $frequency=60) {
    if(!is_null($loop)) {
      $this->loop = $loop;
      $this->frequency = $frequency;
    }
  }



  /** 
   *
   * Creates an instance
   *
   */
  static function create(LoopInterface $loop, float $frequency=60) {
    return new self($loop, $frequency);
  }



  /**
   *
   * Start/resumes running every [frequency] secs
   *
   */
  public function start() {
    $this->refresh();
    if($this->loop) {
      $this->stop();
      $this->timer = $this->loop->addPeriodicTimer( $this->frequency, function() {
        $this->refresh();
      });
    }
    return $this;
  }



  /**
   *
   * Stops/pauses running in the background
   *
   */
  public function stop() {
    $this->loop->cancelTimer( $this->timer );
    return $this;
  }



  /**
   *
   * Refresh
   *
   */
  public function refresh() {
    $this->updated = time();  
    $this->getDiskStats();
    $this->getCpuLoads();
    return $this;
  }




  /**
   *
   * Output
   *
   */
  public function output() {
    // TODO
  }



  /**
   *
   * To String
   *
   */
  public function __toString() {
    return $this->output();
  }



  /**
   *
   * Gets the CPU load
   *
   */
  public function getCpuLoads($min=false) {
    if($min) {
      if(is_numeric($min)) {
        $min = $min.'_min';
      }
      $mins = [
        '1_min',
        '5_min',
        '15_min',
      ];
      if(!in_array($min, $mins)) {
        throw new \Exception('Non valid load');
      }
    }
    if($this->loop) {
      $this->runCpuLoad()
        ->then(function($loads) use ($defer, $min) {
          if($min) {
            return $defer->resolve( $loads[$min] );
          }
          return $defer->resolve( $loads );
        })
        ->otherwise(function($e) use ($defer) {
          $defer->reject($e);
        });
      return $defer->promise();
    }
    $loads = $this->runCpuLoads();
    if($min) {
      return $loads[$min];
    }
    return $loads;
  }



  /**
   *
   * Run cpu load
   *
   */
  private function runCpuLoads() {
    $load = sys_getloadavg();
    if($this->loop) {
      $defer = new Deferred;
      $this->getCputModels()
        ->then(function($models) use ($defer) {
          $cpu_count = count($models);
          $this->cpu_loads = [
             '1_min' => round($load[0] / $cpu_count * 100),
             '5_min' => round($load[1] / $cpu_count * 100),
            '15_min' => round($load[2] / $cpu_count * 100),
            ];
          return $defer->resolve($this->cpu_loads);
        })
        ->otherwise(function($e) use ($defer) {
          $defer->reject($e);
        });
      return $defer->promise();
    }
    $models = $this->getCpuModels();
    $cpu_count = count($models);
    $this->cpu_loads = [
       '1_min' => round($load[0] / $cpu_count * 100),
       '5_min' => round($load[1] / $cpu_count * 100),
      '15_min' => round($load[2] / $cpu_count * 100),
    ];
    return $this->cpu_loads;
  }



  /**
   *
   * Get CPU models
   *
   */
  public function getCpuModels() {
    if($this->loop) {
      return Promise\resolve( $this->runCpuModels() );
    }
    return $this->runCpuModels();
  }



  /**
   *
   * Run cpu models
   *
   */
  private function runCpuModels() {
    $cmd = "test -e /proc/cpuinfo && (cat /proc/cpuinfo | grep 'model name') || (which nproc && nproc || which sysctl && sysctl hw.ncpu | awk 'NR==1{print $2}' ) | awk 'NR==2' || echo 'Unknown'";
    if($this->loop) {
      $defer = new Deferred;
      exec($this->loop, $cmd)
        ->then(function($output) use ($defer) {
          $this->cpu_models_raw = $output;
          $this->cpu_models = $this->parseCpuModels($output);
          $defer->resolve($this->cpu_models);
        })
        ->otherwise(function($e) use ($defer) {
          $defer->reject($e);
        });
      return $defer->promise();
    }
    exec($cmd, $output);
    $output = implode("\n", $output);
    $this->cpu_models_raw = $output;
    $this->cpu_models = $this->parseCpuModels($output); 
    return $this->cpu_models;
  }



  /**
   *
   * Parse cpu models
   *
   */
  private function parseCpuModels(string $output) {
    $names = [];
    $output = trim($output);
    if(is_numeric($output)) {
      while($output--) {
        $names[] = 'Unknown';
      }
      return $names;
    }
    $output = preg_replace('/model name[\s\t]+:/i','', $output);
    $cpus = explode("\n", $output);
    foreach($cpus as $cpu) {
      $cpu = trim($cpu);
      if(!empty($cpu)) {
        $names[] = $cpu;
      }
    }
    if(empty($names)) {
      $names[] = 'Unknown';
    }
    return $names;
  }






  /**
   *
   * Gets the disk stats
   *
   */
  public function getDiskStats(string $target='') {
    if($this->loop) {
      return Promise\resolve( $this->runDiskStats($target) );
    }
    return $this->runDiskStats($target);
  }



  /**
   *
   * Gets the stats of a single disk
   *
   */
  public function getSingleDiskStats(string $target) {
    if($this->loop) {
      $defer = new Deferred;
      $this->getDiskStats($target)
        ->then(function($rows) use ($defer) {
          $defer->resolve( current($rows) );
        })
        ->otherwise(function($e) use ($defer) {
          $defer->reject($e);
        });
      return $defer->promise();
    }
    $rows = $this->getDiskStats($target);
    return current($rows);
  }



  /**
   *
   * Runs the disk stats 
   *
   */
  private function runDiskStats(string $target='') {
    if($target && !is_dir($target)) {
      throw new \Exception('Target is not a directory');
    }
    if($target) {
      $target = escapeshellarg($target);
      $target = $target;
    }
    $cmd = "df -BM --output=source,size,used,avail,pcent,target {$target} 2>/dev/null || df -bm {$target} 2>/dev/null";
    if($this->loop) {
      $defer = new Deferred;
      execute($this->loop, $cmd)
        ->then(function($raw) use ($defer) {
          $this->disk_stats_raw = $raw;
          $this->disk_stats = $this->parseDiskStats($raw);
          $defer->resolve($this->disk_stats);
        })
        ->otherwise(function($e) use ($defer) {
          $defer->reject($e);
        });
      return $defer->promise();
    }
    $output = [];
    exec($cmd, $output);
    $raw = implode("\n", $output);
    $this->disk_stats_raw = $raw;
    $this->disk_stats = $this->parseDiskStats($raw);
    return $this->disk_stats;
  }



  /**
   *
   * Parses the raw results
   *
   * Filesystem | 1M-blocks | Used | Available | Capacity | Mounted on
   * Filesystem | 1M-blocks | Used | Avail | Use% | Mounted on
   *
   */
  private function parseDiskStats(string $raw) {
    $lines = explode("\n", $raw);
    $header = array_shift($lines);
    $columns = preg_split('/[\s]+/', $header, 6);
    $rows = [];
    foreach($lines as $line) {
      $parts = preg_split('/[ ]+/', $line);
      $fs = [];
      while($part = array_shift($parts)) {
        if(preg_match('/^[0-9]+M?$/', $part)) {
          break;
        }
        $fs[] = $part;
      }
      $row = [];
      $row['filesystem'] = implode(' ', $fs);
      $row['size'] = (int)array_shift($parts);
      $row['used'] = (int)array_shift($parts);
      $row['available'] = (int)array_shift($parts);
      $row['percent'] = (int)array_shift($parts);
      $row['mounted_on'] = implode(' ', $parts);
      $rows[] = $row;
    }
    return $rows;
  }


  


}




