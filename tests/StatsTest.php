<?php

use Choval\System\Stats;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Factory;

use React\Promise\Deferred;

class StatsTest extends TestCase
{
    public static $stats;

    public static function setUpBeforeClass(): void
    {
        static::$stats = new Stats();
    }



    public function testDiskStats()
    {
        $stats = static::$stats;

        $disk = $stats->getDiskStats();
        $this->assertNotEmpty($disk);
        $cols = ['filesystem', 'size', 'used', 'available', 'capacity', 'mounted_on'];
        foreach ($disk as $row) {
            foreach ($cols as $col) {
                $this->assertArrayHasKey($col, $row);
                if (!in_array($col, ['filesystem', 'mounted_on'])) {
                    $this->assertIsInt($row[$col]);
                }
                if ($col == 'capacity') {
                    $this->assertGreaterThanOrEqual(0, $row[$col]);
                    $this->assertLessThanOrEqual(100, $row[$col]);
                }
            }
        }

        $stats = $stats->getSingleDiskStats(getcwd());
        $this->assertNotEmpty($stats);
        foreach ($cols as $col) {
            $this->assertArrayHasKey($col, $stats);
            if (!in_array($col, ['filesystem', 'mounted_on'])) {
                $this->assertIsInt($stats[$col]);
            }
            if ($col == 'capacity') {
                $this->assertGreaterThanOrEqual(0, $stats[$col]);
                $this->assertLessThanOrEqual(100, $stats[$col]);
            }
        }
    }



    public function testCpuModels()
    {
        $stats = static::$stats;

        $models = $stats->getCpuModels();
        $this->assertNotEmpty($models);
    }



    /**
     * @depends testCpuModels
     */
    public function testCpuLoads()
    {
        $stats = static::$stats;

        $loads = $stats->getCpuLoads();
        $this->assertArrayHasKey('1_min', $loads);
        $this->assertArrayHasKey('5_min', $loads);
        $this->assertArrayHasKey('15_min', $loads);
        foreach ($loads as $load) {
            $this->assertGreaterThanOrEqual(0, $load);
            $this->assertLessThanOrEqual(100, $load);
        }
        print_r($loads);
    }


    public function testMemStats()
    {
        $stats = static::$stats;

        $stats = $stats->getMemStats();
        $cols = ['total', 'free', 'used', 'available', 'capacity'];
        foreach ($cols as $col) {
            $this->assertArrayHasKey($col, $stats);
        }
        $this->assertGreaterThanOrEqual(0, $stats['capacity']);
        $this->assertLessThanOrEqual(100, $stats['capacity']);
        print_r($stats);
    }


    public function testMemUsage()
    {
        $stats = static::$stats;

        $usage = $stats->getMemUsage();
        $cols = ['peak', 'peak_active', 'current', 'current_active'];
        foreach ($cols as $col) {
            $this->assertArrayHasKey($col, $usage);
        }
        print_r($usage);
    }


    public function testNetStats()
    {
        $stats = static::$stats;

        $ifaces = $stats->getNetStats();
        $cols = ['interface', 'bytes_in', 'bytes_out', 'errors_in', 'errors_out', 'packets_in', 'packets_out', 'addresses'];
        foreach ($ifaces as $iface) {
            foreach ($cols as $col) {
                $this->assertArrayHasKey($col, $iface);
            }
            $this->assertIsArray($iface['addresses']);
        }
    }
}
