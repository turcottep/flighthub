<?php

namespace Tests\Unit\TripPlanner;

use App\Services\TripPlanner;
use App\Services\TripPlannerBenchmark;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\BuildsFlightData;

#[Group('performance')]
class TripPlannerBenchmarkTest extends TestCase
{
    use BuildsFlightData;

    public function test_harness_reports_repeat_timings_and_summary_without_counting_setup(): void
    {
        $benchmark = new TripPlannerBenchmark(
            new TripPlanner($this->benchmarkFixture(), new DateTimeImmutable('2026-04-29 00:00:00 UTC')),
            $this->benchmarkFixture(),
        );

        $report = $benchmark->run(['direct_longhaul', 'round_trip_direct'], repeats: 2, warmups: 0);

        $this->assertSame(2, $report['summary']['case_count']);
        $this->assertSame(2, $report['repeats']);
        $this->assertSame(0, $report['warmups']);
        $this->assertCount(2, $report['cases']);
        $this->assertSame('direct_longhaul', $report['cases'][0]['name']);
        $this->assertCount(2, $report['cases'][0]['runs_ms']);
        $this->assertArrayHasKey('p50_ms', $report['cases'][0]);
        $this->assertArrayHasKey('p95_ms', $report['summary']);
        $this->assertGreaterThanOrEqual(1, $report['cases'][0]['results']);
    }

    private function benchmarkFixture(): array
    {
        return $this->data($this->easternCanadaAirports(), [
            $this->flight('AC', '100', 'YUL', '08:00', 'YVR', '10:00', '100.00'),
            $this->flight('AC', '101', 'YUL', '09:00', 'YYZ', '10:00', '50.00'),
            $this->flight('AC', '102', 'YYZ', '11:00', 'YVR', '13:00', '60.00'),
            $this->flight('AC', '200', 'YVR', '15:00', 'YUL', '23:00', '120.00'),
        ]);
    }
}
