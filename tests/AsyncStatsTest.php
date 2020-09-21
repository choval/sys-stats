<?php

use Choval\System\Stats;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Factory;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function Choval\Async\sleep;
use function Choval\Async\sync;

class AsyncStatsTest extends TestCase
{
    public static $loop;
    public static $stats;

    public static function setUpBeforeClass(): void
    {
        static::$loop = Factory::create();
        static::$loop->run();

        static::$stats = new Stats(static::$loop, 1);
    }


    public function testDiskStats()
    {
        $stats = static::$stats;
        $loop = static::$loop;

        $mode = $stats->getMode();
        $this->assertEquals('react', $mode);

        $disk = sync($loop, $stats->getDiskStats());
        var_dump($disk);
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
                    $this->assertLessThanOrEqual(120, $row[$col]);
                }
            }
        }
    }


    public function testCpuModels()
    {
        $stats = static::$stats;
        $loop = static::$loop;

        $models = sync($loop, $stats->getCpuModels());
        $this->assertNotEmpty($models);
    }


    /**
     * @depends testCpuModels
     */
    public function testCpuLoads()
    {
        $stats = static::$stats;
        $loop = static::$loop;

        $prom = $stats->getCpuLoads();
        $this->assertInstanceOf(PromiseInterface::class, $prom);
        $loads = sync($loop, $prom);
        $this->assertIsArray($loads);
        $this->assertArrayHasKey('1_min', $loads);
        $this->assertArrayHasKey('5_min', $loads);
        $this->assertArrayHasKey('15_min', $loads);
        foreach ($loads as $load) {
            $this->assertGreaterThanOrEqual(0, $load);
            $this->assertLessThanOrEqual(120, $load);
        }
    }


    public function testMemStats()
    {
        $stats = static::$stats;
        $loop = static::$loop;

        $stats = sync($loop, $stats->getMemStats());
        $cols = ['total', 'free', 'used', 'available', 'capacity'];
        foreach ($cols as $col) {
            $this->assertArrayHasKey($col, $stats);
        }
        $this->assertGreaterThanOrEqual(0, $stats['capacity']);
        $this->assertLessThanOrEqual(120, $stats['capacity']);
    }


    public function testNetStats()
    {
        $stats = static::$stats;
        $loop = static::$loop;

        $ifaces = sync($loop, $stats->getNetStats());
        $cols = ['interface', 'bytes_in', 'bytes_out', 'errors_in', 'errors_out', 'packets_in', 'packets_out', 'addresses'];
        foreach ($ifaces as $iface) {
            foreach ($cols as $col) {
                $this->assertArrayHasKey($col, $iface);
            }
            $this->assertIsArray($iface['addresses']);
        }
    }


    public function testOutput()
    {
        $stats = static::$stats;
        $loop = static::$loop;

        $output = sync($loop, $stats->output());
        $this->assertArrayHasKey('updated', $output);

        $updated = $output['updated'];

        sync($loop, sleep($loop, 1));
        $output = sync($loop, $stats->output());
        $this->assertArrayHasKey('updated', $output);

        $this->assertNotEquals($updated, $output['updated']);
        $updated = $output['updated'];
    
        $stats->stop();
        $output1 = sync($loop, $stats->output());
        sync($loop, sleep($loop, 1));
        $output2 = sync($loop, $stats->output());
        $this->assertEquals($output1['updated'], $output2['updated']);
    }
}
