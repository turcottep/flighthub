<?php

namespace App\Services;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use SplPriorityQueue;

class TripPlanner
{
    private const DEFAULT_MAX_STOPS = 1;

    private const DEFAULT_MAX_RESULTS = 20;

    private const DEFAULT_MIN_LAYOVER_MINUTES = 60;

    private const DEFAULT_MAX_DURATION_HOURS = 36;

    private const DEFAULT_MAX_EXPANSIONS = 5000;

    private const BEST_DURATION_MINUTE_WEIGHT_CENTS = 35;

    private const BEST_STOP_PENALTY_CENTS = 7500;

    private const BEST_OVERNIGHT_PENALTY_CENTS = 10000;

    /** @var array<string, array<string, mixed>> */
    private array $airportsByCode = [];

    /** @var array<string, list<string>> */
    private array $airportCodesByCityCode = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $flightsByDepartureAirport = [];

    private DateTimeImmutable $nowUtc;

    /**
     * @param  array{airports: array<int, array<string, mixed>>, flights: array<int, array<string, mixed>>}  $data
     */
    public function __construct(array $data, ?DateTimeImmutable $now = null)
    {
        foreach ($data['airports'] ?? [] as $airport) {
            $this->airportsByCode[$airport['code']] = $airport;
            $this->airportCodesByCityCode[$airport['city_code']][] = $airport['code'];
        }

        foreach ($data['flights'] ?? [] as $flight) {
            $this->flightsByDepartureAirport[$flight['departure_airport']][] = $flight;
        }

        $this->nowUtc = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchOneWay(string $origin, string $destination, string $departureDate, array $options = []): array
    {
        $this->assertDate($departureDate);

        $options = $this->normalizeOptions($options);
        $results = $this->searchOneWayBetweenAirportSets(
            $this->resolveLocationCodes($origin),
            $this->resolveLocationCodes($destination),
            $departureDate,
            $options,
        );

        return array_slice($results, 0, $options['max_results']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchOneWayNear(
        float $originLatitude,
        float $originLongitude,
        float $destinationLatitude,
        float $destinationLongitude,
        string $departureDate,
        array $options = [],
    ): array {
        $this->assertDate($departureDate);
        $this->assertCoordinates($originLatitude, $originLongitude, 'origin');
        $this->assertCoordinates($destinationLatitude, $destinationLongitude, 'destination');
        $this->assertNonNegativeFloatOption($options, 'radius_km');

        $radiusKm = (float) ($options['radius_km'] ?? 50);
        $options = $this->normalizeOptions($options);
        $originCodes = $this->nearbyAirportCodes($originLatitude, $originLongitude, $radiusKm);
        $destinationCodes = $this->nearbyAirportCodes($destinationLatitude, $destinationLongitude, $radiusKm);

        if ($originCodes === [] || $destinationCodes === []) {
            return [];
        }

        $results = $this->searchOneWayBetweenAirportSets($originCodes, $destinationCodes, $departureDate, $options);

        return array_slice($results, 0, $options['max_results']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchOneWayBetweenAirportSets(array $originCodes, array $destinationCodes, string $departureDate, array $options): array
    {
        $destinationCodeSet = array_fill_keys($destinationCodes, true);
        $latestAllowedDepartureUtc = $this->nowUtc->add(new DateInterval('P365D'));
        $queue = new SplPriorityQueue;
        $queue->setExtractFlags(SplPriorityQueue::EXTR_DATA);

        foreach ($originCodes as $originCode) {
            $originTimezone = new DateTimeZone($this->airportsByCode[$originCode]['timezone']);
            $departureWindowStartLocal = new DateTimeImmutable("{$departureDate} 00:00:00", $originTimezone);
            $departureWindowEndLocal = $departureWindowStartLocal->modify('+1 day');
            $departureWindowStartUtc = $departureWindowStartLocal->setTimezone(new DateTimeZone('UTC'));
            $departureWindowEndUtc = $departureWindowEndLocal->setTimezone(new DateTimeZone('UTC'));

            $queue->insert([
                'airport' => $originCode,
                'available_after_utc' => $departureWindowStartUtc > $this->nowUtc ? $departureWindowStartUtc : $this->nowUtc,
                'segments' => [],
                'total_price_cents' => 0,
                'visited_airports' => [$originCode => true],
                'first_departure_utc' => null,
                'departure_window_start_utc' => $departureWindowStartUtc,
                'departure_window_end_utc' => $departureWindowEndUtc,
            ], 0);
        }

        $results = [];
        $expansions = 0;
        $maxSegments = $options['max_stops'] + 1;

        while (! $queue->isEmpty() && count($results) < $options['max_results'] && $expansions < $options['max_expansions']) {
            /** @var array<string, mixed> $state */
            $state = $queue->extract();
            $expansions++;

            if (isset($destinationCodeSet[$state['airport']]) && count($state['segments']) > 0) {
                $results[] = $this->formatItinerary($state['segments'], $state['total_price_cents']);

                continue;
            }

            if (count($state['segments']) >= $maxSegments) {
                continue;
            }

            foreach ($this->flightsByDepartureAirport[$state['airport']] ?? [] as $flight) {
                if ($options['airline'] !== null && $flight['airline'] !== $options['airline']) {
                    continue;
                }

                $arrivalAirport = $flight['arrival_airport'];
                if (isset($state['visited_airports'][$arrivalAirport]) && ! isset($destinationCodeSet[$arrivalAirport])) {
                    continue;
                }

                $minimumLayover = count($state['segments']) === 0 ? 0 : $options['minimum_layover_minutes'];
                $availableFromUtc = $this->addMinutes($state['available_after_utc'], $minimumLayover);
                $occurrence = $this->nextFlightOccurrence($flight, $availableFromUtc);

                if (count($state['segments']) === 0) {
                    if ($occurrence['departure_utc'] < $state['departure_window_start_utc'] || $occurrence['departure_utc'] >= $state['departure_window_end_utc']) {
                        continue;
                    }

                    if ($occurrence['departure_utc'] < $this->nowUtc || $occurrence['departure_utc'] > $latestAllowedDepartureUtc) {
                        continue;
                    }
                } else {
                    $firstDepartureUtc = $state['first_departure_utc'];
                    $durationMinutes = $this->minutesBetween($firstDepartureUtc, $occurrence['arrival_utc']);

                    if ($durationMinutes > $options['max_duration_hours'] * 60) {
                        continue;
                    }
                }

                $segment = $this->formatSegment($flight, $occurrence);
                $segments = [...$state['segments'], $segment];
                $totalPriceCents = $state['total_price_cents'] + $this->priceToCents($flight['price']);
                $visitedAirports = $state['visited_airports'];
                $visitedAirports[$arrivalAirport] = true;
                $firstDepartureUtc = $state['first_departure_utc'] ?? $occurrence['departure_utc'];

                $newState = [
                    'airport' => $arrivalAirport,
                    'available_after_utc' => $occurrence['arrival_utc'],
                    'segments' => $segments,
                    'total_price_cents' => $totalPriceCents,
                    'visited_airports' => $visitedAirports,
                    'first_departure_utc' => $firstDepartureUtc,
                    'departure_window_start_utc' => $state['departure_window_start_utc'],
                    'departure_window_end_utc' => $state['departure_window_end_utc'],
                ];

                $queue->insert($newState, $this->queuePriority($segments, $totalPriceCents, $options['sort']));
            }
        }

        return $this->sortItineraries($results, $options['sort']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchRoundTrip(string $origin, string $destination, string $departureDate, string $returnDate, array $options = []): array
    {
        $this->assertChronologicalDates($departureDate, $returnDate, 'return date must be on or after departure date.');

        $outboundOptions = $this->normalizeOptions($options);
        $maxResults = $outboundOptions['max_results'];
        $outboundOptions['max_results'] = max($outboundOptions['max_results'], 50);

        $outboundItineraries = $this->searchOneWay($origin, $destination, $departureDate, $outboundOptions);
        $returnItineraries = $this->searchOneWay($destination, $origin, $returnDate, $outboundOptions);
        $results = [];

        foreach ($outboundItineraries as $outbound) {
            foreach ($returnItineraries as $return) {
                if ($return['departure_utc'] <= $outbound['arrival_utc']) {
                    continue;
                }

                $results[] = [
                    'type' => 'round_trip',
                    'origin' => $origin,
                    'destination' => $destination,
                    'departure_date' => $departureDate,
                    'return_date' => $returnDate,
                    'total_price' => $this->centsToPrice($outbound['total_price_cents'] + $return['total_price_cents']),
                    'total_price_cents' => $outbound['total_price_cents'] + $return['total_price_cents'],
                    'duration_minutes' => $outbound['duration_minutes'] + $return['duration_minutes'],
                    'best_score' => $this->bestScoreForTrip([$outbound, $return]),
                    'legs' => [
                        [
                            'type' => 'outbound',
                            'itinerary' => $outbound,
                        ],
                        [
                            'type' => 'return',
                            'itinerary' => $return,
                        ],
                    ],
                ];
            }
        }

        $sorted = $this->sortRoundTrips($results, $outboundOptions['sort']);

        return array_slice($sorted, 0, $maxResults);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchOpenJaw(
        string $origin,
        string $outboundDestination,
        string $returnOrigin,
        string $finalDestination,
        string $departureDate,
        string $returnDate,
        array $options = [],
    ): array {
        $this->assertChronologicalDates($departureDate, $returnDate, 'return date must be on or after departure date.');

        $searchOptions = $this->normalizeOptions($options);
        $maxResults = $searchOptions['max_results'];
        $searchOptions['max_results'] = max($searchOptions['max_results'], 50);

        $outboundItineraries = $this->searchOneWay($origin, $outboundDestination, $departureDate, $searchOptions);
        $returnItineraries = $this->searchOneWay($returnOrigin, $finalDestination, $returnDate, $searchOptions);
        $results = [];

        foreach ($outboundItineraries as $outbound) {
            foreach ($returnItineraries as $return) {
                if ($return['departure_utc'] <= $outbound['arrival_utc']) {
                    continue;
                }

                $results[] = [
                    'type' => 'open_jaw',
                    'origin' => $origin,
                    'outbound_destination' => $outboundDestination,
                    'return_origin' => $returnOrigin,
                    'final_destination' => $finalDestination,
                    'departure_date' => $departureDate,
                    'return_date' => $returnDate,
                    'total_price' => $this->centsToPrice($outbound['total_price_cents'] + $return['total_price_cents']),
                    'total_price_cents' => $outbound['total_price_cents'] + $return['total_price_cents'],
                    'duration_minutes' => $outbound['duration_minutes'] + $return['duration_minutes'],
                    'best_score' => $this->bestScoreForTrip([$outbound, $return]),
                    'legs' => [
                        [
                            'type' => 'outbound',
                            'itinerary' => $outbound,
                        ],
                        [
                            'type' => 'return',
                            'itinerary' => $return,
                        ],
                    ],
                ];
            }
        }

        return array_slice($this->sortRoundTrips($results, $searchOptions['sort']), 0, $maxResults);
    }

    /**
     * @param  list<array{origin: string, destination: string, departure_date: string}>  $legs
     * @return list<array<string, mixed>>
     */
    public function searchMultiCity(array $legs, array $options = []): array
    {
        if (count($legs) < 1 || count($legs) > 5) {
            throw new InvalidArgumentException('Multi-city trips must include up to 5 legs.');
        }

        $searchOptions = $this->normalizeOptions($options);
        $maxResults = $searchOptions['max_results'];
        $searchOptions['max_results'] = max($searchOptions['max_results'], 50);
        $partialTrips = [
            [
                'legs' => [],
                'total_price_cents' => 0,
                'duration_minutes' => 0,
            ],
        ];

        foreach ($legs as $index => $leg) {
            foreach (['origin', 'destination', 'departure_date'] as $key) {
                if (! isset($leg[$key])) {
                    throw new InvalidArgumentException("Multi-city leg {$index} is missing {$key}.");
                }
            }

            $this->assertDate($leg['departure_date']);

            if ($index > 0) {
                $this->assertChronologicalDates(
                    $legs[$index - 1]['departure_date'],
                    $leg['departure_date'],
                    'Multi-city legs must be in chronological departure date order.',
                );
            }

            $legItineraries = $this->searchOneWay($leg['origin'], $leg['destination'], $leg['departure_date'], $searchOptions);
            $nextTrips = [];

            foreach ($partialTrips as $partialTrip) {
                foreach ($legItineraries as $itinerary) {
                    $previousLeg = $partialTrip['legs'][count($partialTrip['legs']) - 1]['itinerary'] ?? null;

                    if ($previousLeg !== null && $itinerary['departure_utc'] <= $previousLeg['arrival_utc']) {
                        continue;
                    }

                    $nextTrips[] = [
                        'legs' => [
                            ...$partialTrip['legs'],
                            [
                                'type' => 'leg_'.($index + 1),
                                'itinerary' => $itinerary,
                            ],
                        ],
                        'total_price_cents' => $partialTrip['total_price_cents'] + $itinerary['total_price_cents'],
                        'duration_minutes' => $partialTrip['duration_minutes'] + $itinerary['duration_minutes'],
                        'best_score' => $this->bestScoreForTrip([
                            ...array_column($partialTrip['legs'], 'itinerary'),
                            $itinerary,
                        ]),
                    ];
                }
            }

            $partialTrips = array_slice($this->sortMultiCityPartials($nextTrips, $searchOptions['sort']), 0, $maxResults);

            if ($partialTrips === []) {
                return [];
            }
        }

        $results = array_map(function (array $partialTrip): array {
            $firstLeg = $partialTrip['legs'][0]['itinerary'];
            $lastLeg = $partialTrip['legs'][count($partialTrip['legs']) - 1]['itinerary'];

            return [
                'type' => 'multi_city',
                'origin' => $firstLeg['origin'],
                'destination' => $lastLeg['destination'],
                'total_price' => $this->centsToPrice($partialTrip['total_price_cents']),
                'total_price_cents' => $partialTrip['total_price_cents'],
                'duration_minutes' => $partialTrip['duration_minutes'],
                'best_score' => $partialTrip['best_score'],
                'legs' => $partialTrip['legs'],
            ];
        }, $partialTrips);

        return array_slice($this->sortMultiCityTrips($results, $searchOptions['sort']), 0, $maxResults);
    }

    private function nextFlightOccurrence(array $flight, DateTimeImmutable $availableFromUtc): array
    {
        $departureAirport = $this->airportsByCode[$flight['departure_airport']];
        $arrivalAirport = $this->airportsByCode[$flight['arrival_airport']];
        $departureTimezone = new DateTimeZone($departureAirport['timezone']);
        $arrivalTimezone = new DateTimeZone($arrivalAirport['timezone']);
        $availableFromLocal = $availableFromUtc->setTimezone($departureTimezone);

        $departureLocal = new DateTimeImmutable(
            $availableFromLocal->format('Y-m-d').' '.$flight['departure_time'].':00',
            $departureTimezone
        );

        if ($departureLocal->setTimezone(new DateTimeZone('UTC')) < $availableFromUtc) {
            $departureLocal = $departureLocal->modify('+1 day');
        }

        $departureUtc = $departureLocal->setTimezone(new DateTimeZone('UTC'));
        $arrivalLocal = new DateTimeImmutable(
            $departureLocal->format('Y-m-d').' '.$flight['arrival_time'].':00',
            $arrivalTimezone
        );
        $arrivalUtc = $arrivalLocal->setTimezone(new DateTimeZone('UTC'));

        if ($arrivalUtc <= $departureUtc) {
            $arrivalLocal = $arrivalLocal->modify('+1 day');
            $arrivalUtc = $arrivalLocal->setTimezone(new DateTimeZone('UTC'));
        }

        return [
            'departure_local' => $departureLocal,
            'departure_utc' => $departureUtc,
            'arrival_local' => $arrivalLocal,
            'arrival_utc' => $arrivalUtc,
        ];
    }

    private function formatSegment(array $flight, array $occurrence): array
    {
        $departureAirport = $this->airportsByCode[$flight['departure_airport']];
        $arrivalAirport = $this->airportsByCode[$flight['arrival_airport']];

        return [
            'airline' => $flight['airline'],
            'number' => $flight['number'],
            'flight_number' => $flight['airline'].$flight['number'],
            'departure_airport' => $flight['departure_airport'],
            'arrival_airport' => $flight['arrival_airport'],
            'departure_timezone' => $departureAirport['timezone'],
            'arrival_timezone' => $arrivalAirport['timezone'],
            'departure_at' => $occurrence['departure_local']->format(DateTimeInterface::ATOM),
            'arrival_at' => $occurrence['arrival_local']->format(DateTimeInterface::ATOM),
            'departure_utc' => $occurrence['departure_utc']->format(DateTimeInterface::ATOM),
            'arrival_utc' => $occurrence['arrival_utc']->format(DateTimeInterface::ATOM),
            'duration_minutes' => $this->minutesBetween($occurrence['departure_utc'], $occurrence['arrival_utc']),
            'price' => $this->centsToPrice($this->priceToCents($flight['price'])),
            'price_cents' => $this->priceToCents($flight['price']),
        ];
    }

    private function formatItinerary(array $segments, int $totalPriceCents): array
    {
        $firstSegment = $segments[0];
        $lastSegment = $segments[count($segments) - 1];
        $departureUtc = new DateTimeImmutable($firstSegment['departure_utc']);
        $arrivalUtc = new DateTimeImmutable($lastSegment['arrival_utc']);

        return [
            'type' => 'one_way',
            'origin' => $firstSegment['departure_airport'],
            'destination' => $lastSegment['arrival_airport'],
            'stops' => max(0, count($segments) - 1),
            'segment_count' => count($segments),
            'total_price' => $this->centsToPrice($totalPriceCents),
            'total_price_cents' => $totalPriceCents,
            'best_score' => $this->bestScoreForItinerary($segments, $totalPriceCents),
            'departure_at' => $firstSegment['departure_at'],
            'arrival_at' => $lastSegment['arrival_at'],
            'departure_utc' => $firstSegment['departure_utc'],
            'arrival_utc' => $lastSegment['arrival_utc'],
            'duration_minutes' => $this->minutesBetween($departureUtc, $arrivalUtc),
            'segments' => $segments,
        ];
    }

    /**
     * @return array{max_stops: int, max_results: int, minimum_layover_minutes: int, max_duration_hours: int, max_expansions: int, airline: ?string, sort: string}
     */
    private function normalizeOptions(array $options): array
    {
        $sort = $options['sort'] ?? 'price';

        if (! in_array($sort, ['price', 'departure', 'arrival', 'duration', 'best'], true)) {
            throw new InvalidArgumentException('Sort must be one of price, departure, arrival, duration, or best.');
        }

        $this->assertIntegerOptionAtLeast($options, 'max_stops', 0);
        $this->assertIntegerOptionAtLeast($options, 'max_results', 1);
        $this->assertIntegerOptionAtLeast($options, 'minimum_layover_minutes', 0);
        $this->assertIntegerOptionAtLeast($options, 'max_duration_hours', 1);
        $this->assertIntegerOptionAtLeast($options, 'max_expansions', 1);

        return [
            'max_stops' => (int) ($options['max_stops'] ?? self::DEFAULT_MAX_STOPS),
            'max_results' => (int) ($options['max_results'] ?? self::DEFAULT_MAX_RESULTS),
            'minimum_layover_minutes' => (int) ($options['minimum_layover_minutes'] ?? self::DEFAULT_MIN_LAYOVER_MINUTES),
            'max_duration_hours' => (int) ($options['max_duration_hours'] ?? self::DEFAULT_MAX_DURATION_HOURS),
            'max_expansions' => (int) ($options['max_expansions'] ?? self::DEFAULT_MAX_EXPANSIONS),
            'airline' => $options['airline'] ?? null,
            'sort' => $sort,
        ];
    }

    private function sortItineraries(array $itineraries, string $sort): array
    {
        usort($itineraries, function (array $left, array $right) use ($sort): int {
            return match ($sort) {
                'departure' => $left['departure_utc'] <=> $right['departure_utc']
                    ?: $left['total_price_cents'] <=> $right['total_price_cents'],
                'arrival' => $left['arrival_utc'] <=> $right['arrival_utc']
                    ?: $left['total_price_cents'] <=> $right['total_price_cents'],
                'duration' => $left['duration_minutes'] <=> $right['duration_minutes']
                    ?: $left['total_price_cents'] <=> $right['total_price_cents'],
                'best' => $left['best_score'] <=> $right['best_score']
                    ?: $left['total_price_cents'] <=> $right['total_price_cents'],
                default => $left['total_price_cents'] <=> $right['total_price_cents']
                    ?: $left['arrival_utc'] <=> $right['arrival_utc'],
            };
        });

        return $itineraries;
    }

    private function sortRoundTrips(array $trips, string $sort): array
    {
        usort($trips, function (array $left, array $right) use ($sort): int {
            return match ($sort) {
                'departure' => $left['legs'][0]['itinerary']['departure_utc'] <=> $right['legs'][0]['itinerary']['departure_utc']
                    ?: $left['total_price_cents'] <=> $right['total_price_cents'],
                'arrival' => $left['legs'][1]['itinerary']['arrival_utc'] <=> $right['legs'][1]['itinerary']['arrival_utc']
                    ?: $left['total_price_cents'] <=> $right['total_price_cents'],
                'duration' => $left['duration_minutes'] <=> $right['duration_minutes']
                    ?: $left['total_price_cents'] <=> $right['total_price_cents'],
                'best' => $left['best_score'] <=> $right['best_score']
                    ?: $left['total_price_cents'] <=> $right['total_price_cents'],
                default => $left['total_price_cents'] <=> $right['total_price_cents']
                    ?: $left['duration_minutes'] <=> $right['duration_minutes'],
            };
        });

        return $trips;
    }

    private function sortMultiCityPartials(array $trips, string $sort): array
    {
        usort($trips, function (array $left, array $right) use ($sort): int {
            return match ($sort) {
                'departure' => $left['legs'][0]['itinerary']['departure_utc'] <=> $right['legs'][0]['itinerary']['departure_utc']
                    ?: $left['total_price_cents'] <=> $right['total_price_cents'],
                'arrival' => $left['legs'][count($left['legs']) - 1]['itinerary']['arrival_utc'] <=> $right['legs'][count($right['legs']) - 1]['itinerary']['arrival_utc']
                    ?: $left['total_price_cents'] <=> $right['total_price_cents'],
                'duration' => $left['duration_minutes'] <=> $right['duration_minutes']
                    ?: $left['total_price_cents'] <=> $right['total_price_cents'],
                'best' => $left['best_score'] <=> $right['best_score']
                    ?: $left['total_price_cents'] <=> $right['total_price_cents'],
                default => $left['total_price_cents'] <=> $right['total_price_cents']
                    ?: $left['duration_minutes'] <=> $right['duration_minutes'],
            };
        });

        return $trips;
    }

    private function sortMultiCityTrips(array $trips, string $sort): array
    {
        usort($trips, function (array $left, array $right) use ($sort): int {
            return match ($sort) {
                'departure' => $left['legs'][0]['itinerary']['departure_utc'] <=> $right['legs'][0]['itinerary']['departure_utc']
                    ?: $left['total_price_cents'] <=> $right['total_price_cents'],
                'arrival' => $left['legs'][count($left['legs']) - 1]['itinerary']['arrival_utc'] <=> $right['legs'][count($right['legs']) - 1]['itinerary']['arrival_utc']
                    ?: $left['total_price_cents'] <=> $right['total_price_cents'],
                'duration' => $left['duration_minutes'] <=> $right['duration_minutes']
                    ?: $left['total_price_cents'] <=> $right['total_price_cents'],
                'best' => $left['best_score'] <=> $right['best_score']
                    ?: $left['total_price_cents'] <=> $right['total_price_cents'],
                default => $left['total_price_cents'] <=> $right['total_price_cents']
                    ?: $left['duration_minutes'] <=> $right['duration_minutes'],
            };
        });

        return $trips;
    }

    private function queuePriority(array $segments, int $totalPriceCents, string $sort): int
    {
        $firstSegment = $segments[0];
        $lastSegment = $segments[count($segments) - 1];

        return match ($sort) {
            'departure' => -strtotime($firstSegment['departure_utc']),
            'arrival' => -strtotime($lastSegment['arrival_utc']),
            'duration' => -$this->minutesBetween(
                new DateTimeImmutable($firstSegment['departure_utc']),
                new DateTimeImmutable($lastSegment['arrival_utc'])
            ),
            'best' => -$this->bestScoreForItinerary($segments, $totalPriceCents),
            default => -$totalPriceCents,
        };
    }

    private function bestScoreForTrip(array $itineraries): int
    {
        return array_sum(array_column($itineraries, 'best_score'));
    }

    private function bestScoreForItinerary(array $segments, int $totalPriceCents): int
    {
        $firstSegment = $segments[0];
        $lastSegment = $segments[count($segments) - 1];
        $departureUtc = new DateTimeImmutable($firstSegment['departure_utc']);
        $arrivalUtc = new DateTimeImmutable($lastSegment['arrival_utc']);
        $durationMinutes = $this->minutesBetween($departureUtc, $arrivalUtc);
        $stops = max(0, count($segments) - 1);
        $overnightPenalty = substr($firstSegment['departure_at'], 0, 10) === substr($lastSegment['arrival_at'], 0, 10)
            ? 0
            : self::BEST_OVERNIGHT_PENALTY_CENTS;

        return $totalPriceCents
            + ($durationMinutes * self::BEST_DURATION_MINUTE_WEIGHT_CENTS)
            + ($stops * self::BEST_STOP_PENALTY_CENTS)
            + $overnightPenalty;
    }

    /**
     * @return list<string>
     */
    private function resolveLocationCodes(string $code): array
    {
        if (isset($this->airportsByCode[$code])) {
            return [$code];
        }

        if (isset($this->airportCodesByCityCode[$code])) {
            return $this->airportCodesByCityCode[$code];
        }

        throw new InvalidArgumentException("Unknown airport code: {$code}");
    }

    /**
     * @return list<string>
     */
    private function nearbyAirportCodes(float $latitude, float $longitude, float $radiusKm): array
    {
        $matches = [];

        foreach ($this->airportsByCode as $code => $airport) {
            $distanceKm = $this->distanceKm(
                $latitude,
                $longitude,
                (float) $airport['latitude'],
                (float) $airport['longitude'],
            );

            if ($distanceKm <= $radiusKm) {
                $matches[] = [
                    'code' => $code,
                    'distance_km' => $distanceKm,
                ];
            }
        }

        usort(
            $matches,
            fn (array $left, array $right): int => $left['distance_km'] <=> $right['distance_km']
                ?: $left['code'] <=> $right['code'],
        );

        return array_column($matches, 'code');
    }

    private function distanceKm(float $fromLatitude, float $fromLongitude, float $toLatitude, float $toLongitude): float
    {
        $earthRadiusKm = 6371.0;
        $fromLatitudeRadians = deg2rad($fromLatitude);
        $toLatitudeRadians = deg2rad($toLatitude);
        $latitudeDelta = deg2rad($toLatitude - $fromLatitude);
        $longitudeDelta = deg2rad($toLongitude - $fromLongitude);

        $a = sin($latitudeDelta / 2) ** 2
            + cos($fromLatitudeRadians) * cos($toLatitudeRadians) * sin($longitudeDelta / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function assertIntegerOptionAtLeast(array $options, string $key, int $minimum): void
    {
        if (! array_key_exists($key, $options)) {
            return;
        }

        $value = $options[$key];

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException("{$key} must be an integer greater than or equal to {$minimum}.");
        }

        if ((int) $value < $minimum) {
            throw new InvalidArgumentException("{$key} must be greater than or equal to {$minimum}.");
        }
    }

    private function assertNonNegativeFloatOption(array $options, string $key): void
    {
        if (! array_key_exists($key, $options)) {
            return;
        }

        if (! is_numeric($options[$key]) || (float) $options[$key] < 0) {
            throw new InvalidArgumentException("{$key} must be greater than or equal to 0.");
        }
    }

    private function assertCoordinates(float $latitude, float $longitude, string $label): void
    {
        if ($latitude < -90 || $latitude > 90) {
            throw new InvalidArgumentException("{$label} latitude must be between -90 and 90.");
        }

        if ($longitude < -180 || $longitude > 180) {
            throw new InvalidArgumentException("{$label} longitude must be between -180 and 180.");
        }
    }

    private function assertChronologicalDates(string $firstDate, string $secondDate, string $message): void
    {
        $first = $this->parseDateOnly($firstDate);
        $second = $this->parseDateOnly($secondDate);

        if ($second < $first) {
            throw new InvalidArgumentException($message);
        }
    }

    private function assertAirportExists(string $code): void
    {
        if (! isset($this->airportsByCode[$code])) {
            throw new InvalidArgumentException("Unknown airport code: {$code}");
        }
    }

    private function assertDate(string $date): void
    {
        $this->parseDateOnly($date);
    }

    private function parseDateOnly(string $date): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if (! $parsed || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("Invalid date: {$date}. Expected YYYY-MM-DD.");
        }

        return $parsed;
    }

    private function priceToCents(string|int|float $price): int
    {
        return (int) round(((float) $price) * 100);
    }

    private function centsToPrice(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function addMinutes(DateTimeImmutable $dateTime, int $minutes): DateTimeImmutable
    {
        return $minutes === 0 ? $dateTime : $dateTime->add(new DateInterval("PT{$minutes}M"));
    }

    private function minutesBetween(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        return (int) floor(($end->getTimestamp() - $start->getTimestamp()) / 60);
    }
}
