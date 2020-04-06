<?php

namespace Choval\System;

use Choval\Async;
use React\EventLoop\LoopInterface;
use React\Promise;
use React\Promise\FulfilledPromise;

final class Stats
{
    private $mode;

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

    private $net_stats;
    private $net_stats_raw;


    /**
     *
     * Constructor
     *
     */
    public function __construct()
    {
        $this->runCpuModels();
        $this->mode = 'block';
        $vars = func_get_args();
        foreach ($vars as $var) {
            if (is_a($var, LoopInterface::class)) {
                $this->loop = $var;
                $this->mode = 'react';
            } elseif (is_numeric($var)) {
                $this->frequency = $var;
            }
        }
        if ($this->frequency) {
            if ($this->loop) {
                $this->mode = 'react';
                $this->start();
            }
        }
    }



    /**
     * Mode
     */
    public function getMode()
    {
        return $this->mode;
    }



    /**
     *
     * Creates an instance
     *
     */
    public static function create()
    {
        $vars = func_get_args();
        return call_user_func_array([ self::class, '__construct' ], $vars);
    }



    /**
     *
     * Start/resumes running every [frequency] secs
     *
     */
    public function start()
    {
        if ($this->frequency) {
            $this->stop();
            if ($this->mode === 'react') {
                $this->timer = $this->loop->addPeriodicTimer($this->frequency, function () {
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
    public function stop()
    {
        if ($this->timer) {
            if ($this->mode === 'react') {
                $this->loop->cancelTimer($this->timer);
            }
            $this->timer = false;
        }
        return $this;
    }



    /**
     *
     * Refresh
     *
     */
    public function refresh()
    {
        if ($this->mode !== 'react') {
            throw new \Exception('This can only be used with an EventLoop');
        }
        return Async\resolve(function () {
            $this->updated = time();
            $proms = [];
            $proms[] = $this->runDiskStats();
            $proms[] = $this->runCpuLoads();
            $proms[] = $this->runMemStats();
            $proms[] = $this->runMemUsage();
            $proms[] = $this->runNetStats();
            yield Promise\all($proms);
            return $this;
        }, $this->loop);
    }




    /**
     *
     * Output
     *
     */
    public function output()
    {
        $out = [];
        $out['cpu_loads'] = $this->getCpuLoads();
        $out['cpu_models'] = $this->getCpuModels();
        $out['disk_stats'] = $this->getDiskStats();
        $out['mem_stats'] = $this->getMemStats();
        $out['mem_usage'] = $this->getMemUsage();
        $out['net_stats'] = $this->getNetStats();
        if ($this->mode === 'react') {
            return Async\resolve(function () use ($out) {
                $output = yield Promise\all($out);
                $output['updated'] = $this->updated ?? time();
                return $output;
            });
        }
        return $out;
    }



    /**
     *
     * Gets the memory usage
     *
     */
    public function getMemUsage()
    {
        if ($this->frequency && $this->mem_usage) {
            return new FulfilledPromise($this->mem_usage);
        }
        return $this->runMemUsage();
    }



    /**
     *
     * Runs memory usage
     *
     */
    public function runMemUsage()
    {
        $res = [];
        $res['peak'] = memory_get_peak_usage(true);
        $res['peak_active'] = memory_get_peak_usage();
        $res['current'] = memory_get_usage(true);
        $res['current_active'] = memory_get_usage();
        $this->mem_usage = $res;
        if ($this->mode === 'react') {
            return new FulfilledPromise($res);
        }
        return $res;
    }



    /**
     *
     * Gets memory stats
     *
     */
    public function getMemStats()
    {
        if ($this->frequency && $this->mem_stats) {
            return new FulfilledPromise($this->mem_stats);
        }
        return $this->runMemStats();
    }



    /**
     *
     * Run mem stats
     *
     */
    private function runMemStats()
    {
        $cmd = '(test -e /proc/meminfo && cat /proc/meminfo) || (which vm_stat && vm_stat)';
        if ($this->mode == 'react') {
            return Async\resolve(function () use ($cmd) {
                $raw = yield Async\execute($this->loop, $cmd);
                $this->mem_stats_raw = $raw;
                $this->mem_stats = $this->parseMemStats($raw);
                return $this->mem_stats;
            });
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
    private function parseMemStats(string $raw)
    {
        $lines = explode("\n", $raw);
        $rows = [];
        foreach ($lines as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) == 2) {
                $k = trim($parts[0]);
                $v = trim($parts[1]);
                $rows[$k] = $v;
            }
        }
        $base = 1024;
        $mac = false;
        $tmp = [];
        // Mac
        if (isset($rows['Mach Virtual Memory Statistics'])) {
            $pageSize = (int)preg_replace('/[^0-9]/', '', $rows['Mach Virtual Memory Statistics']);
            $base = 1024 * 1024 / $pageSize;
            $mac = true;
        }
        foreach ($rows as $k => $v) {
            $tmp[$k] = ((float)$v) / $base;
        }
        $cols = [
            'total' => ['MemTotal', 'Pages free', 'Pages active', 'Pages inactive', 'Pages speculative', 'Pages throttled', 'Pages wired down', 'Pages occupied by compressor'],
            'free' => ['MemFree', 'Pages free'],
            'used' => ['MemTotal', 'Pages active', 'Pages speculative', 'Pages throttled', 'Pages wired down', 'Pages occupied by compressor'],
            'available' => ['MemAvailable', 'Pages free', 'Pages inactive'],
        ];
        $final = [];
        foreach ($cols as $k => $source) {
            $final[ $k ] = 0;
            foreach ($source as $col) {
                $final[ $k ] += $tmp[$col] ?? 0;
            }
        }
        if (!$mac) {
            // https://gitlab.com/procps-ng/procps/blob/master/proc/sysinfo.c
            $final['used'] -= $tmp['MemFree'];
            $final['used'] -= $tmp['Cached'];
            $final['used'] -= $tmp['SReclaimable'];
            $final['used'] -= $tmp['Buffers'];
        }
        $final['capacity'] = $final['available'] / $final['total'] * 100;
        foreach ($final as $k => $v) {
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
    public function getCpuLoads($min = false)
    {
        if ($min) {
            if (is_numeric($min)) {
                $min = $min . '_min';
            }
            $mins = [
                '1_min',
                '5_min',
                '15_min',
              ];
            if (!in_array($min, $mins)) {
                throw new \Exception('Non valid load');
            }
        }
        if ($this->mode === 'react') {
            return Async\resolve(function () use ($min) {
                if ($this->frequency && $this->cpu_loads) {
                    if ($min) {
                        return $this->cpu_loads[$min];
                    }
                    return $this->cpu_loads;
                }
                $loads = yield $this->runCpuLoads();
                if ($min) {
                    return $loads[$min];
                }
                return $loads;
            });
        }
        $loads = $this->runCpuLoads();
        if ($min) {
            return $loads[$min];
        }
        return $loads;
    }



    /**
     *
     * Run cpu load
     *
     */
    private function runCpuLoads()
    {
        $load = sys_getloadavg();
        if ($this->mode === 'react') {
            return Async\resolve(function () use ($load) {
                $models = yield $this->getCpuModels();
                $cpu_count = count($models);
                $this->cpu_loads = [
                    '1_min' => round($load[0] / $cpu_count * 100),
                    '5_min' => round($load[1] / $cpu_count * 100),
                   '15_min' => round($load[2] / $cpu_count * 100),
                ];
                return $this->cpu_loads;
            });
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
    public function getCpuModels()
    {
        if ($this->cpu_models) {
            return $this->cpu_models;
        }
        return $this->runCpuModels();
    }



    /**
     *
     * Run cpu models
     *
     */
    private function runCpuModels()
    {
        $cmd = "test -e /proc/cpuinfo && grep 'model name' /proc/cpuinfo || (which nproc && nproc ) || (which sysctl && (sysctl hw.ncpu | awk '{print $2}') && sysctl machdep.cpu.brand_string) | tail -n 2 || echo 'Unknown'";
        $promise = false;
        if ($this->mode === 'react') {
            return Async\resolve(function () use ($cmd) {
                $output = yield Async\execute($this->loop, $cmd);
                $this->cpu_models_raw = $output;
                $this->cpu_models = $this->parseCpuModels($output);
                return $this->cpu_models;
            });
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
    private function parseCpuModels(string $output)
    {
        $names = [];
        $lines = explode("\n", $output);
        $nproc = $lines[0];
        if (is_numeric($nproc)) {
            array_shift($lines);
            while ($nproc--) {
                $names[] = current($lines) ?? 'Unknown';
            }
            return $names;
        }
        $output = preg_replace('/model name[\s\t]+:/i', '', $output);
        $cpus = explode("\n", $output);
        foreach ($cpus as $cpu) {
            $cpu = trim($cpu);
            $cpu = str_replace('machdep.cpu.brand_string: ', '', $cpu);
            if (!empty($cpu)) {
                $names[] = $cpu;
            }
        }
        if (empty($names)) {
            $names[] = 'Unknown';
        }
        return $names;
    }



    /**
     *
     * Gets the disk stats
     *
     */
    public function getDiskStats(string $target = '')
    {
        if ($this->frequency && $this->disk_stats && $target === '' && $this->mode === 'react') {
            return new FulfilledPromise($this->disk_stats);
        }
        return $this->runDiskStats($target);
    }



    /**
     *
     * Gets the stats of a single disk
     *
     */
    public function getSingleDiskStats(string $target)
    {
        if ($this->mode === 'react') {
            return Async\resolve(function () use ($target) {
                if ($this->frequency) {
                    $rows = yield $this->getDiskStats();
                } else {
                    $rows = yield $this->getDiskStats($target);
                }
                if ($this->frequency) {
                    $mounts = [];
                    foreach ($rows as $pos => $row) {
                        $mounts[$pos] = $row['mounted_on'];
                    }
                    $dir = $target;
                    do {
                        $key = array_search($dir, $mounts);
                        if ($key) {
                            return $rows[$key];
                        }
                    } while ($dir != dirname($dir) && $dir = dirname($dir));
                }
                return current($rows);
            });
        }
        $rows = $this->getDiskStats($target);
        return current($rows);
    }



    /**
     *
     * Runs the disk stats
     *
     */
    private function runDiskStats(string $target = '')
    {
        if ($target && !is_dir($target)) {
            throw new \Exception('Target is not a directory');
        }
        if ($target) {
            $target = escapeshellarg($target);
        }
        $cmd = "df -BM --output=source,size,used,avail,pcent,target {$target} 2>/dev/null || df -bm {$target} 2>/dev/null";
        if ($this->mode == 'react') {
            return Async\resolve(function () use ($cmd, $target) {
                $raw = yield Async\execute($this->loop, $cmd);
                if ($target) {
                    return $this->parseDiskStats($raw);
                }
                $this->disk_stats_raw = $raw;
                $this->disk_stats = $this->parseDiskStats($raw);
                return $this->disk_stats;
            });
        }
        $output = [];
        exec($cmd, $output);
        $raw = implode("\n", $output);
        if ($target) {
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
    private function parseDiskStats(string $raw)
    {
        $lines = explode("\n", $raw);
        $header = array_shift($lines);
        $columns = preg_split('/[\s]+/', $header, 6);
        $rows = [];
        foreach ($lines as $line) {
            $parts = preg_split('/[ ]+/', $line);
            $fs = [];
            while ($part = array_shift($parts)) {
                if (preg_match('/^[0-9]+M?$/', $part)) {
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
            $row['capacity'] = 100 - ((int)array_shift($parts));
            $row['mounted_on'] = implode(' ', $parts);
            if ($row['mounted_on']) {
                $rows[] = $row;
            }
        }
        return $rows;
    }



    /**
     *
     * Run net stats
     *
     */
    private function runNetStats()
    {
        $cmd = "(netstat -ie 2>/dev/null || netstat -ibnl 2>/dev/null )";
        if ($this->mode == 'react') {
            return Async\resolve(function () use ($cmd) {
                $raw = yield Async\execute($this->loop, $cmd);
                $this->net_stats_raw = $raw;
                $this->net_stats = $this->parseNetStats($raw);
                return $this->net_stats;
            });
        }
        $output = [];
        exec($cmd, $output);
        $raw = implode("\n", $output);
        $this->net_stats_raw = $raw;
        $this->net_stats = $this->parseNetStats($raw);
        return $this->net_stats;
    }



    /**
     *
     * Parses CLI table
     *
     */
    public function parseTable(string $raw)
    {
        $lines = explode("\n", $raw);
        $headers = [];
        $header_line = array_shift($lines);
        $header_parts = preg_split('/[\s]+/', $header_line);
        foreach ($header_parts as $part) {
            $headers[] = $part;
        }
        $headers_count = count($headers);
        $res = [];
        foreach ($lines as $line) {
            $parts = preg_split('/[\s]+/', $line);
            $parts_count = count($parts);
            if ($parts_count !== $headers_count) {
                continue;
            }
            $row = [];
            foreach ($parts as $pos => $part) {
                $k = $headers[$pos];
                $row[$k] = $part;
            }
            $res[] = $row;
        }
        return $res;
    }


  

    /**
     *
     * Parses netstat results
     *
     */
    private function parseNetStats(string $raw)
    {
        // Mac handling
        if (preg_match('/Name[\s]+Mtu[\s]+Network[\s]+Address[\s]+Ipkts[\s]+Ierrs[\s]+Ibytes[\s]+Opkts[\s]+Oerrs[\s]+Obytes[\s]+Coll/', $raw)) {
            $lines = $this->parseTable($raw);
            $interfaces = [];
            foreach ($lines as $tmp) {
                $iface = $tmp['Name'];
                if (!isset($interfaces[$iface])) {
                    $row = [];
                    $row['interface'] = $iface;
                    $row['mtu'] = (int) $tmp['Mtu'];
                    $row['addresses'] = [];
                    $links = [
                        'Ipkts' => 'packets_in',
                        'Opkts' => 'packets_out',
                        'Ibytes' => 'bytes_in',
                        'Obytes' => 'bytes_out',
                        'Ierrs' => 'errors_in',
                        'Oerrs' => 'errors_out',
                    ];
                    foreach ($links as $k) {
                        $row[$k] = 0;
                    }
                    foreach ($tmp as $k => $v) {
                        $parts = explode(' ', $k, 2);
                        $key = current($parts);
                        if (isset($links[$key])) {
                            $row[ $links[$key] ] = (float)$v;
                        }
                    }
                    $interfaces[ $iface ] = $row;
                }
                // Mac
                if (preg_match('/^[0-9a-f]{2}(:[0-9a-f]{2}){5}$/', $tmp['Address'])) {
                    $interfaces[ $iface ]['addresses']['mac'] = $tmp['Address'];
                } elseif (preg_match('/^[0-9]{1,3}(\.[0-9]{1,3}){3}$/', $tmp['Address'])) {
                    $interfaces[ $iface ]['addresses']['ipv4'] = $tmp['Address'];
                } elseif (strpos($tmp['Address'], '::') !== false || preg_match('/[0-9a-f]{4}/', $tmp['Address'])) {
                    $interfaces[ $iface ]['addresses']['ipv6'] = $tmp['Address'];
                }
            }
            $res = [];
            foreach ($interfaces as $interface) {
                if (isset($interface['addresses']['mac'])) {
                    $res[] = $interface;
                }
            }
            return $res;
        }
        // Linux handling
        $interfaces = [];
        $parts = explode("\n\n", $raw);
        foreach ($parts as $tmp) {
            if (preg_match('/(?P<iface>[^\s]+)[\s]+Link[\s].+[\s]HWaddr[\s](?P<mac>[0-9a-f]{2}(:[0-9a-f]{2}){5})/', $tmp, $match)) {
                $row = [];
                $row['interface'] = $match['iface'];
                $row['addresses'] = [
                    'mac' => $match['mac'],
                ];
                if (preg_match('/inet6 addr:[\s]*(?P<ipv6>[0-9a-f\:\/]+) /', $tmp, $match)) {
                    $row['addresses']['ipv6'] = $match['ipv6'];
                }
                if (preg_match('/ MTU:[\s]*(?P<mtu>[0-9]+)/', $tmp, $match)) {
                    $row['mtu'] = (int)$match['mtu'];
                }
                if (preg_match('/inet addr:[\s]*(?P<ipv4>[0-9]{1,3}(\.[0-9]{1,3}){3}) /', $tmp, $match)) {
                    $row['addresses']['ipv4'] = $match['ipv4'];
                }
                if (preg_match('/RX packets:[\s]*(?P<packets_in>[0-9]+) errors:[\s]*(?P<errors_in>[0-9]+) /', $tmp, $match)) {
                    $row['packets_in'] = (float) $match['packets_in'];
                    $row['errors_in'] = (float) $match['errors_in'];
                }
                if (preg_match('/TX packets:[\s]*(?P<packets_out>[0-9]+) errors:[\s]*(?P<errors_out>[0-9]+) /', $tmp, $match)) {
                    $row['packets_out'] = (float) $match['packets_out'];
                    $row['errors_out'] = (float) $match['errors_out'];
                }
                if (preg_match('/RX bytes:[\s]*(?P<bytes_in>[0-9]+) /', $tmp, $match)) {
                    $row['bytes_in'] = (float) $match['bytes_in'];
                }
                if (preg_match('/TX bytes:[\s]*(?P<bytes_out>[0-9]+) /', $tmp, $match)) {
                    $row['bytes_out'] = (float) $match['bytes_out'];
                }
                $interfaces[] = $row;
            }
        }
        return $interfaces;
    }



    /**
     *
     * Gets the net stats
     *
     */
    public function getNetStats()
    {
        if ($this->frequency && $this->net_stats) {
            return new FulfilledPromise($this->net_stats);
        }
        return $this->runNetStats();
    }


  
    /**
     *
     * Returns when was the data refreshed
     *
     */
    public function getUpdated()
    {
        return $this->updated;
    }
}
