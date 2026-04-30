<?php

namespace App\Services;

use DateTimeImmutable;
use InvalidArgumentException;

class TripPlannerMatrixBenchmark
{
    /** @var array<string, array<string, mixed>> */
    private array $airportsByCode = [];

    /** @var array<string, list<string>> */
    private array $destinationsByOrigin = [];

    /** @var array<string, list<string>> */
    private array $cityCodes = [];

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        private readonly TripPlannerV3 $planner,
        private readonly array $data,
    ) {
        foreach ($data['airports'] ?? [] as $airport) {
            $this->airportsByCode[$airport['code']] = $airport;
            $this->cityCodes[$airport['city_code']][] = $airport['code'];
        }

        foreach ($data['flights'] ?? [] as $flight) {
            $this->destinationsByOrigin[$flight['departure_airport']][$flight['arrival_airport']] = $flight['arrival_airport'];
        }

        foreach ($this->destinationsByOrigin as $origin => $destinations) {
            $this->destinationsByOrigin[$origin] = array_values($destinations);
            sort($this->destinationsByOrigin[$origin]);
        }
    }

    public static function fromDataPath(string $path, ?DateTimeImmutable $now = null): self
    {
        $data = (new FlightDataRepository($path))->load();

        return new self(
            new TripPlannerV3($data, $now ?? new DateTimeImmutable('2026-04-29 00:00:00 UTC')),
            $data,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function run(int $perGroup = 20, int $repeats = 3, int $warmups = 1): array
    {
        if ($perGroup < 1 || $repeats < 1 || $warmups < 0) {
            throw new InvalidArgumentException('perGroup and repeats must be >= 1; warmups must be >= 0.');
        }

        $cases = $this->cases($perGroup);
        $results = [];

        foreach ($cases as $case) {
            for ($index = 0; $index < $warmups; $index++) {
                $this->runCase($case);
            }

            for ($index = 0; $index < $repeats; $index++) {
                $startedAt = hrtime(true);
                $queryResults = $this->runCase($case);
                $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;

                $results[] = [
                    ...$case,
                    'run' => $index + 1,
                    'elapsed_ms' => round($elapsedMs, 2),
                    'result_count' => count($queryResults),
                ];
            }
        }

        return [
            'data' => [
                'airports' => count($this->data['airports'] ?? []),
                'flights' => count($this->data['flights'] ?? []),
                'airlines' => count($this->data['airlines'] ?? []),
            ],
            'per_group' => $perGroup,
            'repeats' => $repeats,
            'warmups' => $warmups,
            'case_count' => count($cases),
            'timed_executions' => count($results),
            'groups' => $this->summariesByGroup($results),
            'overall' => $this->summary($results),
            'results' => $results,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function cases(int $perGroup): array
    {
        return [
            ...$this->directCases($perGroup),
            ...$this->oneStopCases($perGroup),
            ...$this->remoteCases($perGroup),
            ...$this->constrainedNoResultCases($perGroup),
            ...$this->cityCodeCases($perGroup),
            ...$this->nearbyCases($perGroup),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function runCase(array $case): array
    {
        return match ($case['type']) {
            'one_way' => $this->planner->searchOneWay($case['origin'], $case['destination'], '2026-05-15', $case['options']),
            'nearby' => $this->planner->searchOneWayNear(
                originLatitude: $case['origin_latitude'],
                originLongitude: $case['origin_longitude'],
                destinationLatitude: $case['destination_latitude'],
                destinationLongitude: $case['destination_longitude'],
                departureDate: '2026-05-15',
                options: $case['options'],
            ),
            default => throw new InvalidArgumentException("Unknown benchmark matrix case type: {$case['type']}"),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function directCases(int $limit): array
    {
        $cases = [];

        foreach ($this->popularAirports() as $origin) {
            foreach ($this->destinationsByOrigin[$origin] ?? [] as $destination) {
                if (! isset($this->airportsByCode[$destination])) {
                    continue;
                }

                $cases[] = [
                    'group' => 'direct',
                    'name' => "direct_{$origin}_{$destination}",
                    'type' => 'one_way',
                    'origin' => $origin,
                    'destination' => $destination,
                    'options' => [
                        'max_segments' => 1,
                        'max_results' => 10,
                        'sort' => 'best',
                    ],
                ];

                if (count($cases) >= $limit) {
                    return $cases;
                }
            }
        }

        return $cases;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function oneStopCases(int $limit): array
    {
        $cases = [];

        foreach ($this->popularAirports() as $origin) {
            $direct = array_fill_keys($this->destinationsByOrigin[$origin] ?? [], true);

            foreach ($this->destinationsByOrigin[$origin] ?? [] as $connector) {
                foreach ($this->destinationsByOrigin[$connector] ?? [] as $destination) {
                    if ($destination === $origin || isset($direct[$destination])) {
                        continue;
                    }

                    $cases[] = [
                        'group' => 'one_stop',
                        'name' => "one_stop_{$origin}_{$destination}",
                        'type' => 'one_way',
                        'origin' => $origin,
                        'destination' => $destination,
                        'options' => [
                            'max_segments' => 3,
                            'max_results' => 10,
                            'max_duration_hours' => 72,
                            'max_connections_scanned' => 250000,
                            'sort' => 'best',
                        ],
                    ];

                    if (count($cases) >= $limit) {
                        return $cases;
                    }
                }
            }
        }

        return $cases;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function remoteCases(int $limit): array
    {
        $cases = [];
        $origins = array_slice($this->popularAirports(), 0, 20);

        foreach ($origins as $origin) {
            foreach ($this->remoteAirports() as $destination) {
                if ($origin === $destination || $this->shortestStaticPathLength($origin, $destination, 6) === null) {
                    continue;
                }

                $cases[] = [
                    'group' => 'remote',
                    'name' => "remote_{$origin}_{$destination}",
                    'type' => 'one_way',
                    'origin' => $origin,
                    'destination' => $destination,
                    'options' => [
                        'max_segments' => 6,
                        'max_results' => 5,
                        'max_duration_hours' => 120,
                        'max_connections_scanned' => 500000,
                        'sort' => 'best',
                    ],
                ];

                if (count($cases) >= $limit) {
                    return $cases;
                }
            }
        }

        return $cases;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function constrainedNoResultCases(int $limit): array
    {
        return array_map(
            fn (array $case): array => [
                ...$case,
                'group' => 'constrained_no_result',
                'name' => str_replace('remote_', 'constrained_', $case['name']),
                'options' => [
                    ...$case['options'],
                    'max_segments' => 1,
                    'max_results' => 5,
                    'max_duration_hours' => 48,
                ],
            ],
            array_slice($this->remoteCases($limit), 0, $limit),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cityCodeCases(int $limit): array
    {
        $cases = [];
        $codes = $this->popularCityCodes();

        foreach ($codes as $originCode) {
            foreach ($codes as $destinationCode) {
                if ($originCode === $destinationCode) {
                    continue;
                }

                if (! $this->cityPairHasStaticPath($originCode, $destinationCode, 3)) {
                    continue;
                }

                $cases[] = [
                    'group' => 'city_code',
                    'name' => "city_{$originCode}_{$destinationCode}",
                    'type' => 'one_way',
                    'origin' => $originCode,
                    'destination' => $destinationCode,
                    'options' => [
                        'max_segments' => 3,
                        'max_results' => 10,
                        'max_duration_hours' => 72,
                        'max_connections_scanned' => 250000,
                        'sort' => 'best',
                    ],
                ];

                if (count($cases) >= $limit) {
                    return $cases;
                }
            }
        }

        return $cases;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function nearbyCases(int $limit): array
    {
        $cases = [];
        $airports = array_slice($this->popularAirports(), 0, max($limit + 1, 10));

        for ($index = 0; $index < count($airports) - 1 && count($cases) < $limit; $index++) {
            $origin = $this->airportsByCode[$airports[$index]];
            $destination = $this->airportsByCode[$airports[$index + 1]];

            $cases[] = [
                'group' => 'nearby',
                'name' => "nearby_{$origin['code']}_{$destination['code']}",
                'type' => 'nearby',
                'origin_latitude' => (float) $origin['latitude'],
                'origin_longitude' => (float) $origin['longitude'],
                'destination_latitude' => (float) $destination['latitude'],
                'destination_longitude' => (float) $destination['longitude'],
                'options' => [
                    'radius_km' => 75,
                    'max_segments' => 3,
                    'max_results' => 10,
                    'max_duration_hours' => 72,
                    'max_connections_scanned' => 250000,
                    'sort' => 'best',
                ],
            ];
        }

        return $cases;
    }

    /**
     * @return list<string>
     */
    private function popularAirports(): array
    {
        $scores = [];

        foreach ($this->destinationsByOrigin as $origin => $destinations) {
            $scores[$origin] = ($scores[$origin] ?? 0) + count($destinations);
            foreach ($destinations as $destination) {
                $scores[$destination] = ($scores[$destination] ?? 0) + 1;
            }
        }

        arsort($scores);

        return array_values(array_filter(array_keys($scores), fn (string $code): bool => isset($this->airportsByCode[$code])));
    }

    /**
     * @return list<string>
     */
    private function popularCityCodes(): array
    {
        $codes = [];

        foreach ($this->popularAirports() as $airportCode) {
            $cityCode = $this->airportsByCode[$airportCode]['city_code'];

            if (strlen($cityCode) === 3 && isset($this->cityCodes[$cityCode])) {
                $codes[$cityCode] = true;
            }
        }

        return array_keys($codes);
    }

    /**
     * @return list<string>
     */
    private function remoteAirports(): array
    {
        $scores = [];

        foreach ($this->airportsByCode as $code => $_airport) {
            $scores[$code] = count($this->destinationsByOrigin[$code] ?? []);
        }

        asort($scores);

        return array_values(array_filter(
            array_keys($scores),
            fn (string $code): bool => ($scores[$code] > 0),
        ));
    }

    private function shortestStaticPathLength(string $origin, string $destination, int $maxDepth): ?int
    {
        $queue = [[$origin, 0]];
        $seen = [$origin => true];

        while ($queue !== []) {
            [$airport, $depth] = array_shift($queue);

            if ($airport === $destination) {
                return $depth;
            }

            if ($depth >= $maxDepth) {
                continue;
            }

            foreach ($this->destinationsByOrigin[$airport] ?? [] as $next) {
                if (! isset($seen[$next])) {
                    $seen[$next] = true;
                    $queue[] = [$next, $depth + 1];
                }
            }
        }

        return null;
    }

    private function cityPairHasStaticPath(string $originCityCode, string $destinationCityCode, int $maxDepth): bool
    {
        foreach ($this->cityCodes[$originCityCode] ?? [] as $originAirport) {
            foreach ($this->cityCodes[$destinationCityCode] ?? [] as $destinationAirport) {
                if ($this->shortestStaticPathLength($originAirport, $destinationAirport, $maxDepth) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, array<string, mixed>>
     */
    private function summariesByGroup(array $results): array
    {
        $grouped = [];

        foreach ($results as $result) {
            $grouped[$result['group']][] = $result;
        }

        return array_map(fn (array $groupResults): array => $this->summary($groupResults), $grouped);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    private function summary(array $results): array
    {
        $times = array_column($results, 'elapsed_ms');
        sort($times);
        $resultCounts = array_column($results, 'result_count');

        return [
            'executions' => count($results),
            'queries' => count(array_unique(array_column($results, 'name'))),
            'resultful_executions' => count(array_filter($resultCounts, fn (int $count): bool => $count > 0)),
            'min_ms' => round(min($times), 2),
            'p50_ms' => round($this->percentile($times, 50), 2),
            'p75_ms' => round($this->percentile($times, 75), 2),
            'p90_ms' => round($this->percentile($times, 90), 2),
            'p95_ms' => round($this->percentile($times, 95), 2),
            'p99_ms' => round($this->percentile($times, 99), 2),
            'max_ms' => round(max($times), 2),
        ];
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
}
