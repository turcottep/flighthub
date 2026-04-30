<?php

namespace Tests\Unit\TripPlanner;

use App\Services\TripPlannerMatrixBenchmark;
use App\Services\TripPlannerV3;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\BuildsFlightData;

#[Group('performance')]
class TripPlannerMatrixBenchmarkTest extends TestCase
{
    use BuildsFlightData;

    public function test_matrix_harness_reports_group_and_overall_percentiles(): void
    {
        $data = $this->data($this->easternCanadaAirports(), [
            $this->flight('AC', '100', 'YUL', '08:00', 'YVR', '10:00', '100.00'),
            $this->flight('AC', '101', 'YUL', '09:00', 'YYZ', '10:00', '50.00'),
            $this->flight('AC', '102', 'YYZ', '11:00', 'YVR', '13:00', '60.00'),
            $this->flight('AC', '103', 'YVR', '14:00', 'LAX', '16:00', '80.00'),
            $this->flight('AC', '104', 'YYZ', '15:00', 'LAX', '17:00', '70.00'),
        ]);
        $benchmark = new TripPlannerMatrixBenchmark(
            new TripPlannerV3($data, new DateTimeImmutable('2026-04-29 00:00:00 UTC')),
            $data,
        );

        $report = $benchmark->run(perGroup: 1, repeats: 2, warmups: 1);

        $this->assertSame(6, $report['case_count']);
        $this->assertSame(12, $report['timed_executions']);
        $this->assertArrayHasKey('overall', $report);
        $this->assertArrayHasKey('groups', $report);
        $this->assertArrayHasKey('p95_ms', $report['overall']);
        $this->assertSame([
            'direct',
            'one_stop',
            'remote',
            'constrained_no_result',
            'city_code',
            'nearby',
        ], array_keys($report['groups']));

        foreach ($report['groups'] as $summary) {
            $this->assertSame(1, $summary['queries']);
            $this->assertSame(2, $summary['executions']);
            $this->assertArrayHasKey('p50_ms', $summary);
            $this->assertArrayHasKey('p95_ms', $summary);
            $this->assertArrayHasKey('max_ms', $summary);
        }

        $this->assertSame([1, 2], array_values(array_unique(array_column($report['results'], 'run'))));
    }
}
