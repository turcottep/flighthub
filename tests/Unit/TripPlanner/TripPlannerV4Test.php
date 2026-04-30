<?php

namespace Tests\Unit\TripPlanner;

use App\Services\FlightDataRepository;
use App\Services\TripPlannerV4;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\BuildsFlightData;

class TripPlannerV4Test extends TestCase
{
    use BuildsFlightData;

    public function test_v4_finds_route_pattern_before_materializing_flights(): void
    {
        $planner = new TripPlannerV4($this->data([
            $this->airport('AAA'),
            $this->airport('HUB'),
            $this->airport('MID'),
            $this->airport('ZZZ'),
            $this->airport('NOI'),
        ], [
            $this->flight('AC', '100', 'AAA', '08:00', 'HUB', '09:00', '100.00'),
            $this->flight('AC', '200', 'HUB', '10:00', 'MID', '11:00', '100.00'),
            $this->flight('AC', '300', 'MID', '12:00', 'ZZZ', '13:00', '100.00'),
            $this->flight('AC', '999', 'AAA', '08:05', 'NOI', '09:05', '1.00'),
            $this->flight('AC', '998', 'NOI', '10:05', 'AAA', '11:05', '1.00'),
        ]), new DateTimeImmutable('2026-04-29 00:00:00 UTC'));

        $results = $planner->searchOneWay('AAA', 'ZZZ', '2026-05-01');

        $this->assertCount(1, $results);
        $this->assertSame(['AC100', 'AC200', 'AC300'], array_column($results[0]['segments'], 'flight_number'));
        $this->assertSame(3, $results[0]['segment_count']);
    }

    public function test_v4_instantiates_multiple_schedule_choices_on_a_route_pattern(): void
    {
        $planner = new TripPlannerV4($this->data([
            $this->airport('AAA'),
            $this->airport('BBB'),
        ], [
            $this->flight('AC', '100', 'AAA', '08:00', 'BBB', '09:00', '300.00'),
            $this->flight('AC', '101', 'AAA', '09:00', 'BBB', '10:00', '100.00'),
        ]), new DateTimeImmutable('2026-04-29 00:00:00 UTC'));

        $results = $planner->searchOneWay('AAA', 'BBB', '2026-05-01', [
            'max_results' => 2,
            'sort' => 'price',
        ]);

        $this->assertCount(2, $results);
        $this->assertSame('AC101', $results[0]['segments'][0]['flight_number']);
        $this->assertSame('100.00', $results[0]['total_price']);
    }

    public function test_v4_preferred_airline_does_not_disable_mixed_airline_routes(): void
    {
        $planner = new TripPlannerV4($this->data([
            $this->airport('AAA'),
            $this->airport('HUB'),
            $this->airport('ZZZ'),
        ], [
            $this->flight('AC', '100', 'AAA', '08:00', 'HUB', '09:00', '100.00'),
            $this->flight('WS', '200', 'HUB', '10:00', 'ZZZ', '11:00', '100.00'),
        ]), new DateTimeImmutable('2026-04-29 00:00:00 UTC'));

        $results = $planner->searchOneWay('AAA', 'ZZZ', '2026-05-01', [
            'airline' => 'AC',
            'max_segments' => 2,
        ]);

        $this->assertCount(1, $results);
        $this->assertSame(['AC100', 'WS200'], array_column($results[0]['segments'], 'flight_number'));
    }

    public function test_v4_preferred_airline_affects_best_ranking_only(): void
    {
        $planner = new TripPlannerV4($this->data([
            $this->airport('AAA'),
            $this->airport('BBB'),
        ], [
            $this->flight('WS', '100', 'AAA', '08:00', 'BBB', '09:00', '100.00'),
            $this->flight('AC', '200', 'AAA', '08:30', 'BBB', '09:30', '140.00'),
        ]), new DateTimeImmutable('2026-04-29 00:00:00 UTC'));

        $withoutPreference = $planner->searchOneWay('AAA', 'BBB', '2026-05-01', [
            'max_results' => 2,
            'sort' => 'best',
        ]);
        $withPreference = $planner->searchOneWay('AAA', 'BBB', '2026-05-01', [
            'airline' => 'AC',
            'max_results' => 2,
            'sort' => 'best',
        ]);

        $this->assertSame('WS100', $withoutPreference[0]['segments'][0]['flight_number']);
        $this->assertSame('AC200', $withPreference[0]['segments'][0]['flight_number']);
        $this->assertSame(
            ['AC200', 'WS100'],
            array_map(fn (array $itinerary): string => $itinerary['segments'][0]['flight_number'], $withPreference),
        );
    }

    #[Group('performance')]
    public function test_v4_finds_remote_to_remote_full_data_route_quickly(): void
    {
        $path = __DIR__.'/../../../data/generated/trip_data_full.json';

        if (! is_file($path)) {
            $this->markTestSkipped('Full generated trip data is not available.');
        }

        ini_set('memory_limit', '1536M');

        $data = (new FlightDataRepository($path))->load();
        $planner = new TripPlannerV4($data, new DateTimeImmutable('2026-04-29 00:00:00 UTC'));
        $startedAt = hrtime(true);

        $results = $planner->searchOneWay('AAT', 'AAX', '2026-05-15', [
            'max_results' => 5,
            'max_segments' => 6,
        ]);

        $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;

        $this->assertNotEmpty($results, "V4 returned no AAT -> AAX route in {$elapsedMs}ms.");
        $this->assertSame('AAT', $results[0]['origin']);
        $this->assertSame('AAX', $results[0]['destination']);
        $this->assertLessThan(2500, $elapsedMs, "V4 AAT -> AAX took {$elapsedMs}ms.");
    }
}
