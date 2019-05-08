<?php
namespace Choval\System;

use function Choval\Async\execute;
use React\EventLoop\LoopInterface;
use React\Promise;
use React\Promise\Deferred;
use React\Promise\FulfilledPromise;

final class Stats {


  private $loop;
  private $frequency;
  private $timer;
  private $updated;

  private $mem_usage;

  private $mem_stats;
  private $mem_stats_raw;

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
  public function __construct(LoopInterface $loop=null, float $frequency=0) {
    if(!is_null($loop)) {
      $this->loop = $loop;
      if($frequency > 0) {
        $this->frequency = $frequency;
        $this->start();
      }
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
    if($this->loop) {
      if($this->frequency) {
        $this->stop();
        $this->timer = $this->loop->addPeriodicTimer( $this->frequency, function() {
          $this->refresh();
        });
        $this->refresh();
      }
    }
    return $this;
  }



  /**
   *
   * Stops/pauses running in the background
   *
   */
  public function stop() {
    if($this->timer) {
      $this->loop->cancelTimer( $this->timer );
    }
    return $this;
  }



  /**
   *
   * Refresh
   *
   */
  public function refresh() {
    if(!$this->loop || !$this->frequency) {
      throw new \Exception('This can only be used with a LoopInterface');
    }
    $this->updated = time();  
    $defer = new Deferred;
    $proms = [];
    $proms[] = $this->runDiskStats();
    $proms[] = $this->runCpuLoads();
    $proms[] = $this->runMemStats();
    $proms[] = $this->getMemUsage();
    Promise\all($proms)
      ->then(function() use ($defer) {
        $defer->resolve( $this->output() );
      })
      ->otherwise(function($e) use ($defer) {
        $defer->reject($e);
      });
    return $defer->promise();
  }




  /**
   *
   * Output
   *
   */
  public function output() {
    $out = [];
    $out['cpu_loads'] = $this->getCpuLoads();
    $out['cpu_models'] = $this->getCpuModels();
    $out['disk_stats'] = $this->getDiskStats();
    $out['mem_stats'] = $this->getMemStats();
    $out['mem_usage'] = $this->getMemUsage();
    if($this->loop) {
      $defer = new Deferred;
      Promise\all($out)
        ->then(function($output) use ($defer) {
          if($this->frequency) {
            $output['updated'] = $this->updated;
          } else {
            $output['updated'] = $this->updated ?? time();
          }
          $defer->resolve( $output );
        })
        ->otherwise(function($e) use ($defer) {
          $defer->reject($e);
        });
      return $defer->promise();
    }
    return $out;
  }



  /**
   *
   * Gets the memory usage
   *
   */
  public function getMemUsage() {
    if($this->frequency && $this->mem_usage) {
      return new FulfilledPromise( $this->mem_usage );
    }
    $res = [];
    $res['peak'] = memory_get_peak_usage(true);
    $res['peak_active'] = memory_get_peak_usage();
    $res['current'] = memory_get_usage(true);
    $res['current_active'] = memory_get_usage();
    $this->mem_usage = $res;
    if($this->loop) {
      return new FulfilledPromise($res);
    }
    return $res;
  }


  /**
   *
   * Gets memory stats
   *
   */
  public function getMemStats() {
    if($this->frequency && $this->mem_stats) {
      return new FulfilledPromise( $this->mem_stats );
    }
    if($this->loop) {
      return $this->runMemStats();
    }
    return $this->runMemStats();
  }



  /**
   *
   * Run mem stats
   *
   */
  private function runMemStats() {
    $cmd = 'test -e /proc/meminfo && cat /proc/meminfo || which vm_stat && vm_stat';
    if($this->loop) {
      $defer = new Deferred;
      execute($this->loop, $cmd)
        ->then(function($raw) use ($defer) {
          $this->mem_stats_raw = $raw;
          $this->mem_stats = $this->parseMemStats($raw);
          $defer->resolve($this->mem_stats);
        })
        ->otherwise(function($e) use ($defer) {
          $defer->reject($e);
        });
      return $defer->promise();
    }
    $output = [];
    exec($cmd, $output);
    $raw = implode("\n", $output);
    $this->mem_stats_raw = $raw;
    $this->mem_stats = $this->parseMemStats($raw);
    return $this->mem_stats;
  }



  /**
   *
   * Parse mem stats
   *
   */
  private function parseMemStats(string $raw) {
    $lines = explode("\n", $raw);
    $rows = [];
    foreach($lines as $line) {
      $parts = explode(':', $line, 2);
      if(count($parts) == 2) {
        $k = trim($parts[0]);
        $v = trim($parts[1]);
        $rows[$k] = $v;
      }
    }
    $base = 1024;
    $mac = false;
    $tmp = [];
    // Mac
    if(isset($rows['Mach Virtual Memory Statistics'])) {
      $pageSize = (int)preg_replace('/[^0-9]/','', $rows['Mach Virtual Memory Statistics']);
      $base = 1024*1024/$pageSize;
      $mac = true;
    }
    foreach($rows as $k=>$v) {
      $tmp[$k] = ( (float)$v ) / $base;
    }
    $cols = [
      'total' => ['MemTotal', 'Pages free', 'Pages active', 'Pages inactive', 'Pages speculative', 'Pages throttled', 'Pages wired down', 'Pages occupied by compressor'],
      'free' => ['MemFree', 'Pages free'],
      'used' => ['MemTotal', 'Pages active', 'Pages speculative', 'Pages throttled', 'Pages wired down', 'Pages occupied by compressor'],
      'available' => ['MemAvailable', 'Pages free', 'Pages inactive'],
    ];
    $final = [];
    foreach($cols as $k=>$source) {
      $final[ $k ] = 0;
      foreach($source as $col) {
        $final[ $k ] += $tmp[$col] ?? 0;
      }
    }
    if(!$mac) {
      // https://gitlab.com/procps-ng/procps/blob/master/proc/sysinfo.c
      $final['used'] -= $tmp['MemFree'];
      $final['used'] -= $tmp['Cached'];
      $final['used'] -= $tmp['SReclaimable'];
      $final['used'] -= $tmp['Buffers'];
    }
    $final['capacity'] = $final['available'] / $final['total'] * 100;
    foreach($final as $k=>$v) {
      $final[$k] = round($v);
    }
    $this->mem_stats = $final;
    return $this->mem_stats;
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
    if($this->frequency && $this->cpu_loads) {
      if($min) {
        return new FulfilledPromise( $this->cpu_loads[$min] );
      }
      return new FulfilledPromise( $this->cpu_loads );
    }
    if($this->loop) {
      $defer = new Deferred;
      $this->runCpuLoads()
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
      $this->runCpuModels()
        ->then(function($models) use ($defer, $load) {
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
    if($this->frequency && $this->cpu_models) {
      return new FulfilledPromise( $this->cpu_models );
    }
    if($this->loop) {
      return $this->runCpuModels();
    }
    return $this->runCpuModels();
  }



  /**
   *
   * Run cpu models
   *
   */
  private function runCpuModels() {
    $cmd = "test -e /proc/cpuinfo && grep 'model name' /proc/cpuinfo || (which nproc && nproc ) || (which sysctl && (sysctl hw.ncpu | awk '{print $2}') && sysctl machdep.cpu.brand_string) | tail -n 2 || echo 'Unknown'";
    if($this->loop) {
      $defer = new Deferred;
      execute($this->loop, $cmd)
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
    $lines = explode("\n", $output);
    $nproc = $lines[0];
    if(is_numeric($nproc)) {
      array_shift($lines);
      while($nproc--) {
        $names[] = current($lines) ?? 'Unknown';
      }
      return $names;
    }
    $output = preg_replace('/model name[\s\t]+:/i','', $output);
    $cpus = explode("\n", $output);
    foreach($cpus as $cpu) {
      $cpu = trim($cpu);
      $cpu = str_replace('machdep.cpu.brand_string: ', '', $cpu);
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
    if($this->frequency && $target == '' && $this->disk_stats) {
      return new FulfilledPromise( $this->disk_stats );
    }
    if($this->loop) {
      return $this->runDiskStats($target);
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
      if($this->frequency) {
        $promise = $this->getDiskStats();
      } else {
        $promise = $this->getDiskStats($target);
      }
      $promise
        ->then(function($rows) use ($defer, $target) {
          if($this->frequency) {
            $mounts = [];
            foreach($rows as $pos=>$row) {
              $mounts[$pos] = $row['mounted_on'];
            }
            $dir = $target;
            do {
              $key = array_search($dir, $mounts);
              if($key) {
                return $defer->resolve( $rows[$key] );
              }
            } while( $dir != dirname($dir) && $dir = dirname($dir) );
          }
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
        ->then(function($raw) use ($defer, $target) {
          if($target) {
            return $defer->resolve( $this->parseDiskStats($raw) );
          }
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
    if($target) {
      return $this->parseDiskStats($raw);
    }
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
          array_unshift($parts, $part);
          break;
        }
        $fs[] = $part;
      }
      $row = [];
      $row['filesystem'] = implode(' ', $fs);
      $row['size'] = (int)array_shift($parts);
      $row['used'] = (int)array_shift($parts);
      $row['available'] = (int)array_shift($parts);
      $row['capacity'] = 100 - ( (int)array_shift($parts) );
      $row['mounted_on'] = implode(' ', $parts);
      if($row['mounted_on']) {
        $rows[] = $row;
      }
    }
    return $rows;
  }


  
  /**
   *
   * Returns when was the data refreshed
   *
   */
  public function getUpdated() {
    return $this->updated;
  }



}




