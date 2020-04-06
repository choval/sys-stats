# Choval\SysStats

Gets system status.  
Tested on Unix based OS.

* If run in blocking mode, stats are retrieved at that instant.
* If run with ReactPHP loop without a frequency, promises are returned.
* If run with ReactPHP loop AND a frequency, all stats are executed in the background and all getters return from the cache.


## Install

```sh
composer require choval/sys-stats
```

Tested on Ubuntu/Debian & Mac only. Won't work on Windows as multiple commands are not available.

### Tests

```sh
composer install
./runtests --testdox
```

You can also use the tests as an example of how this library works.


## Usage

```php
$stats = new \Choval\System\Stats;
/*
Creates the stats object
*/

$stats->getMemUsage();
/*
Memory usage of current PHP execution.
All results in Bytes as PHP memory_get_usage & memory_get_peak_usage.
Array
(
  [peak] => inactive+active peak
  [peak_active] => active peak
  [current] => inactive+active currently
  [current_active] => active currently
)
*/

$stats->getMemStats();
/*
Memory stats.
All results in MiB, not MB.
Array
(
  [total] => system memory
  [free] => free memory (keep in mind linux memory usage, this will usually be close to 1-2)
  [used] => memory under use by applications and system
  [available] => available memory for use
  [capacity] => PERCENTAGE (without '%') of available memory/total memory
)
*/

$stats->getCpuModels();
/*
Gets the CPU model, repeated by number of (logical) CPUs (ncpu)
Array 
(
  [0] => Intel(R) Core(TM) M-5Y31 CPU
  [1] => Intel(R) Core(TM) M-5Y31 CPU
  [2] => Intel(R) Core(TM) M-5Y31 CPU
  [3] => Intel(R) Core(TM) M-5Y31 CPU
)
*/

$stats->getCpuLoads();
/*
Gets the CPU load, all results in PERCENTAGE (without '%').
Unlike PHP's results, these are percentage of the system capacity already.
Keep in mind some systems may underclock depending on load.
Array
(
  [1_min] => PERCENTAGE of load/capacity last min
  [5_min] => PERCENTAGE of load/capacity last 5min
  [15_min] => PERCENTAGE of load/capacity last 15min
)
*/

$stats->getDiskStats();
/*
Disk stats.
All results in MiB, not MB. NOT BYTES!
Only return mounted disks.
Array
(
  [0] => Array 
    (
      [filesystem] => The device, ie: /dev/sda1
      [size] => Capacity in MiB
      [used] => Used number of MiB
      [available] => Available capacity
      [capacity] => PERCENTAGE (without '%') of available/size
      [mounted_on] => Mount path
    )
)
*/

$stats->getSingleDiskStats( getcwd() );
/*
This returns the same data for the disk/partition the current working dir is using.
Or point to a different directory and get the stats.
Array
(
  [filesystem] => The device, ie: /dev/sda1
  [size] => Capacity in MiB
  [used] => Used number of MiB
  [available] => Available capacity
  [capacity] => PERCENTAGE (without '%') of available/size
  [mounted_on] => Mount path
)
*/

$stats->getNetStats();
/*
Returns network stats for every network interface.
Array
(
  [0] => Array
    (
      [interface] => Interface name
      [mtu] => MTU, int
      [addresses] => Array
        (
          [mac] => Mac address
          [ipv4] => IPv4
          [ipv6] => IPv6
        )
      [packets_in] => Packets, float
      [packets_out] => Packets, float
      [bytes_in] => Bytes, float
      [bytes_out] => Bytes, float
      [errors_in] => Errors, float
      [errors_out] => Errors, float
    )
)
*/

$stats->output();
/*
Returns all the data in one single call.
Array
(
  [cpu_loads] => getCpuLoads()
  [cpu_models] => getCpuModels()
  [disk_stats] => getDiskStats()
  [mem_stats] => getMemStats()
  [mem_usage] => getMemUsage()
  [net_stats] => getNetStats()
  [updated] => Time of stats
)
*/

```

With ReactPHP

```php
$stats = new \Choval\System\Stats($loop);
/*
Creates the stats object, without frequency.
All methods will return a React\Promise\Promise.
*/
```


With ReactPHP & a frequency

```php
$stats = new \Choval\System\Stats($loop, 60);
/*
Runs the stats every 60 secs in the background.
All methods will return a React\Promise\Promise as well,
but the data will be retrieved from the cache of the
last background execution.
*/

$stats->refresh();
/*
Forces a refresh of all data ignoring the frequency.
Will not reset the frequency timer.
Returns a promise.
*/

$stats->getUpdated();
/*
Returns the time of the stats.
Does not return a promise!
*/
```

## License

MIT, see LICENSE

