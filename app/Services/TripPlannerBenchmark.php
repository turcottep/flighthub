<?php

namespace App\Services;

use DateTimeImmutable;
use InvalidArgumentException;

class TripPlannerBenchmark
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        private readonly TripPlanner $planner,
        private readonly array $data,
    ) {}

    public static function fromDataPath(string $path, ?DateTimeImmutable $now = null): self
    {
        $data = (new FlightDataRepository($path))->load();

        return new self(
            new TripPlanner($data, $now ?? new DateTimeImmutable('2026-04-29 00:00:00 UTC')),
            $data,
        );
    }

    /**
     * @return list<array{name: string, type: string, description: string}>
     */
    public function cases(): array
    {
        return [
            [
                'name' => 'direct_shorthaul',
                'type' => 'one_way',
                'description' => 'Direct YUL -> YYZ',
            ],
            [
                'name' => 'direct_longhaul',
                'type' => 'one_way',
                'description' => 'Direct YUL -> YVR',
            ],
            [
                'name' => 'one_stop_airport_pair',
                'type' => 'one_way',
                'description' => 'One-stop-capable YUL -> ORD',
            ],
            [
                'name' => 'city_code_to_city_code',
                'type' => 'one_way',
                'description' => 'City-code YMQ -> YTO',
            ],
            [
                'name' => 'nearby_metro_to_metro',
                'type' => 'one_way_near',
                'description' => 'Nearby Montreal -> Toronto',
            ],
            [
                'name' => 'round_trip_direct',
                'type' => 'round_trip',
                'description' => 'Direct round-trip YUL <-> YVR',
            ],
            [
                'name' => 'open_jaw',
                'type' => 'open_jaw',
                'description' => 'Open-jaw YUL -> YVR, LAX -> YUL',
            ],
            [
                'name' => 'multi_city_three_legs',
                'type' => 'multi_city',
                'description' => 'YUL -> YYZ -> YVR -> LAX',
            ],
            [
                'name' => 'no_result',
                'type' => 'one_way',
                'description' => 'Likely no-result YUL -> ZNZ',
            ],
            [
                'name' => 'airline_preferred',
                'type' => 'one_way',
                'description' => 'Airline-preferred YUL -> YVR on AC',
            ],
        ];
    }

    /**
     * @param  list<string>|null  $caseNames
     * @return array{
     *     data: array{airlines: int, airports: int, flights: int},
     *     repeats: int,
     *     warmups: int,
     *     cases: list<array<string, mixed>>,
     *     summary: array{case_count: int, p50_ms: float, p95_ms: float, max_ms: float}
     * }
     */
    public function run(?array $caseNames = null, int $repeats = 5, int $warmups = 1): array
    {
        if ($repeats < 1) {
            throw new InvalidArgumentException('Benchmark repeats must be greater than or equal to 1.');
        }

        if ($warmups < 0) {
            throw new InvalidArgumentException('Benchmark warmups must be greater than or equal to 0.');
        }

        $selectedCases = $this->selectCases($caseNames);
        $caseResults = [];

        foreach ($selectedCases as $case) {
            for ($index = 0; $index < $warmups; $index++) {
                $this->runCase($case['name']);
            }

            $runs = [];
            $resultCount = null;

            for ($index = 0; $index < $repeats; $index++) {
                $startedAt = hrtime(true);
                $results = $this->runCase($case['name']);
                $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;

                $runs[] = $elapsedMs;
                $resultCount ??= count($results);
            }

            sort($runs);

            $caseResults[] = [
                ...$case,
                'results' => $resultCount,
                'min_ms' => round(min($runs), 2),
                'p50_ms' => round($this->percentile($runs, 50), 2),
                'p95_ms' => round($this->percentile($runs, 95), 2),
                'max_ms' => round(max($runs), 2),
                'runs_ms' => array_map(fn (float $value): float => round($value, 2), $runs),
            ];
        }

        return [
            'data' => [
                'airlines' => count($this->data['airlines'] ?? []),
                'airports' => count($this->data['airports'] ?? []),
                'flights' => count($this->data['flights'] ?? []),
            ],
            'repeats' => $repeats,
            'warmups' => $warmups,
            'cases' => $caseResults,
            'summary' => $this->summary($caseResults),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function runCase(string $name): array
    {
        return match ($name) {
            'direct_shorthaul' => $this->planner->searchOneWay('YUL', 'YYZ', '2026-05-15', [
                'max_stops' => 0,
                'max_results' => 20,
                'sort' => 'best',
            ]),
            'direct_longhaul' => $this->planner->searchOneWay('YUL', 'YVR', '2026-05-15', [
                'max_stops' => 0,
                'max_results' => 20,
                'sort' => 'best',
            ]),
            'one_stop_airport_pair' => $this->planner->searchOneWay('YUL', 'ORD', '2026-05-15', [
                'max_stops' => 1,
                'max_results' => 20,
                'max_expansions' => 3000,
                'sort' => 'best',
            ]),
            'city_code_to_city_code' => $this->planner->searchOneWay('YMQ', 'YTO', '2026-05-15', [
                'max_stops' => 1,
                'max_results' => 20,
                'max_expansions' => 3000,
                'sort' => 'best',
            ]),
            'nearby_metro_to_metro' => $this->planner->searchOneWayNear(
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
            ),
            'round_trip_direct' => $this->planner->searchRoundTrip('YUL', 'YVR', '2026-05-15', '2026-05-22', [
                'max_stops' => 0,
                'max_results' => 20,
                'sort' => 'best',
            ]),
            'open_jaw' => $this->planner->searchOpenJaw(
                origin: 'YUL',
                outboundDestination: 'YVR',
                returnOrigin: 'LAX',
                finalDestination: 'YUL',
                departureDate: '2026-05-15',
                returnDate: '2026-05-22',
                options: [
                    'max_stops' => 0,
                    'max_results' => 20,
                    'sort' => 'best',
                ],
            ),
            'multi_city_three_legs' => $this->planner->searchMultiCity([
                ['origin' => 'YUL', 'destination' => 'YYZ', 'departure_date' => '2026-05-15'],
                ['origin' => 'YYZ', 'destination' => 'YVR', 'departure_date' => '2026-05-17'],
                ['origin' => 'YVR', 'destination' => 'LAX', 'departure_date' => '2026-05-19'],
            ], [
                'max_stops' => 0,
                'max_results' => 20,
                'sort' => 'best',
            ]),
            'no_result' => $this->planner->searchOneWay('YUL', 'ZNZ', '2026-05-15', [
                'max_stops' => 1,
                'max_results' => 20,
                'max_expansions' => 3000,
                'sort' => 'best',
            ]),
            'airline_preferred' => $this->planner->searchOneWay('YUL', 'YVR', '2026-05-15', [
                'airline' => 'AC',
                'max_stops' => 1,
                'max_results' => 20,
                'max_expansions' => 3000,
                'sort' => 'best',
            ]),
            default => throw new InvalidArgumentException("Unknown benchmark case: {$name}"),
        };
    }

    /**
     * @param  list<string>|null  $caseNames
     * @return list<array{name: string, type: string, description: string}>
     */
    private function selectCases(?array $caseNames): array
    {
        $cases = $this->cases();

        if ($caseNames === null || $caseNames === []) {
            return $cases;
        }

        $caseSet = array_fill_keys($caseNames, true);
        $selected = array_values(array_filter(
            $cases,
            fn (array $case): bool => isset($caseSet[$case['name']]),
        ));

        if (count($selected) !== count($caseSet)) {
            $known = array_column($cases, 'name');
            $unknown = array_values(array_diff(array_keys($caseSet), $known));

            throw new InvalidArgumentException('Unknown benchmark case(s): '.implode(', ', $unknown));
        }

        return $selected;
    }

    /**
     * @param  list<float>  $sortedValues
     */
    private function percentile(array $sortedValues, int $percentile): float
    {
        if ($sortedValues === []) {
            return 0.0;
        }

        $index = (int) ceil(($percentile / 100) * count($sortedValues)) - 1;

        return $sortedValues[max(0, min($index, count($sortedValues) - 1))];
    }

    /**
     * @param  list<array<string, mixed>>  $caseResults
     * @return array{case_count: int, p50_ms: float, p95_ms: float, max_ms: float}
     */
    private function summary(array $caseResults): array
    {
        $p50s = array_column($caseResults, 'p50_ms');
        sort($p50s);
        $p95s = array_column($caseResults, 'p95_ms');
        sort($p95s);
        $maxes = array_column($caseResults, 'max_ms');

        return [
            'case_count' => count($caseResults),
            'p50_ms' => round($this->percentile($p50s, 50), 2),
            'p95_ms' => round($this->percentile($p95s, 95), 2),
            'max_ms' => round($maxes === [] ? 0 : max($maxes), 2),
        ];
    }
}
