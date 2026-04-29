<?php

namespace Tests\Unit\TripPlanner;

use App\Services\FlightDataRepository;
use App\Services\TripPlannerV3;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\BuildsFlightData;

class TripPlannerV3Test extends TestCase
{
    use BuildsFlightData;

    public function test_v3_connection_scan_finds_multi_connection_remote_routes(): void
    {
        $planner = new TripPlannerV3($this->data([
            $this->airport('YUL'),
            $this->airport('AAA'),
            $this->airport('BBB'),
            $this->airport('POM', 'Pacific/Port_Moresby'),
            $this->airport('WBM', 'Pacific/Port_Moresby'),
            $this->airport('NOI'),
        ], [
            $this->flight('AC', '100', 'YUL', '08:00', 'AAA', '09:00', '100.00'),
            $this->flight('AC', '200', 'AAA', '10:00', 'BBB', '11:00', '100.00'),
            $this->flight('AC', '300', 'BBB', '12:00', 'POM', '13:00', '100.00'),
            $this->flight('AC', '400', 'POM', '14:00', 'WBM', '15:00', '100.00'),
            $this->flight('AC', '999', 'YUL', '08:15', 'NOI', '09:15', '10.00'),
        ]), new DateTimeImmutable('2026-04-29 00:00:00 UTC'));

        $results = $planner->searchOneWay('YUL', 'WBM', '2026-05-01');

        $this->assertCount(1, $results);
        $this->assertSame(['AC100', 'AC200', 'AC300', 'AC400'], array_column($results[0]['segments'], 'flight_number'));
        $this->assertSame(3, $results[0]['stops']);
    }

    public function test_v3_connection_scan_prunes_unreachable_airports(): void
    {
        $planner = new TripPlannerV3($this->data([
            $this->airport('YUL'),
            $this->airport('AAA'),
            $this->airport('WBM'),
            $this->airport('NOI'),
        ], [
            $this->flight('AC', '100', 'YUL', '08:00', 'AAA', '09:00', '100.00'),
            $this->flight('AC', '200', 'AAA', '10:00', 'WBM', '11:00', '100.00'),
            $this->flight('AC', '999', 'YUL', '08:15', 'NOI', '09:15', '10.00'),
            $this->flight('AC', '998', 'NOI', '10:15', 'YUL', '11:15', '10.00'),
        ]), new DateTimeImmutable('2026-04-29 00:00:00 UTC'));

        $results = $planner->searchOneWay('YUL', 'WBM', '2026-05-01');

        $this->assertCount(1, $results);
        $this->assertSame(['AC100', 'AC200'], array_column($results[0]['segments'], 'flight_number'));
    }

    #[Group('performance')]
    public function test_v3_finds_yul_to_wbm_in_full_data_without_a_stop_filter(): void
    {
        $path = __DIR__.'/../../../data/generated/trip_data_full.json';

        if (! is_file($path)) {
            $this->markTestSkipped('Full generated trip data is not available.');
        }

        ini_set('memory_limit', '1536M');

        $data = (new FlightDataRepository($path))->load();
        $planner = new TripPlannerV3($data, new DateTimeImmutable('2026-04-29 00:00:00 UTC'));
        $startedAt = hrtime(true);

        $results = $planner->searchOneWay('YUL', 'WBM', '2026-05-15', [
            'max_results' => 3,
            'max_segments' => 6,
            'max_duration_hours' => 240,
            'max_connections_scanned' => 750000,
        ]);

        $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;

        $this->assertNotEmpty($results, "V3 returned no YUL -> WBM routes in {$elapsedMs}ms.");
        $this->assertSame('WBM', $results[0]['destination']);
        $this->assertLessThan(10000, $elapsedMs, "V3 YUL -> WBM took {$elapsedMs}ms.");
    }
}
