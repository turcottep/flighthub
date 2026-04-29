<?php

namespace Tests\Unit\TripPlanner;

use App\Services\FlightDataRepository;
use App\Services\TripPlanner;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('performance')]
class FullDataLatencyTest extends TestCase
{
    private const FULL_DATA_PATH = __DIR__.'/../../../data/generated/trip_data_full.json';

    private static TripPlanner $planner;

    private static int $flightCount;

    public static function setUpBeforeClass(): void
    {
        if (! is_file(self::FULL_DATA_PATH)) {
            self::markTestSkipped('Full generated trip data is not available.');
        }

        ini_set('memory_limit', '1536M');

        $data = (new FlightDataRepository(self::FULL_DATA_PATH))->load();
        self::$flightCount = count($data['flights']);
        self::$planner = new TripPlanner($data, new DateTimeImmutable('2026-04-29 00:00:00 UTC'));
    }

    public function test_full_data_fixture_is_large_enough_to_exercise_real_query_latency(): void
    {
        $this->assertGreaterThan(100000, self::$flightCount);
    }

    public function test_direct_one_way_query_latency_excludes_initial_load(): void
    {
        [$elapsedMs, $results] = $this->measureQuery(fn (): array => self::$planner->searchOneWay('YUL', 'YVR', '2026-05-15', [
            'max_stops' => 0,
            'max_results' => 5,
            'sort' => 'best',
        ]));

        $this->assertNotEmpty($results);
        $this->assertLessThan(250, $elapsedMs, "Direct one-way query took {$elapsedMs}ms.");
    }

    public function test_direct_round_trip_query_latency_excludes_initial_load(): void
    {
        [$elapsedMs, $results] = $this->measureQuery(fn (): array => self::$planner->searchRoundTrip('YUL', 'YVR', '2026-05-15', '2026-05-22', [
            'max_stops' => 0,
            'max_results' => 5,
            'sort' => 'best',
        ]));

        $this->assertNotEmpty($results);
        $this->assertLessThan(750, $elapsedMs, "Direct round-trip query took {$elapsedMs}ms.");
    }

    public function test_nearby_one_stop_query_latency_excludes_initial_load(): void
    {
        [$elapsedMs, $results] = $this->measureQuery(fn (): array => self::$planner->searchOneWayNear(
            originLatitude: 45.457714,
            originLongitude: -73.749908,
            destinationLatitude: 43.6532,
            destinationLongitude: -79.3832,
            departureDate: '2026-05-15',
            options: [
                'radius_km' => 60,
                'max_stops' => 1,
                'max_results' => 20,
                'max_expansions' => 3000,
                'sort' => 'best',
            ],
        ));

        $this->assertNotEmpty($results);
        $this->assertLessThan(6000, $elapsedMs, "Nearby one-stop query took {$elapsedMs}ms.");
    }

    /**
     * @return array{0: float, 1: array<int, array<string, mixed>>}
     */
    private function measureQuery(callable $query): array
    {
        $startedAt = hrtime(true);
        $results = $query();
        $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;

        return [$elapsedMs, $results];
    }
}
