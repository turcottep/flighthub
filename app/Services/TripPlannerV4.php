<?php

namespace App\Services;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use SplPriorityQueue;

class TripPlannerV4
{
    private const DEFAULT_MAX_SEGMENTS = 6;

    private const DEFAULT_MAX_RESULTS = 20;

    private const DEFAULT_MIN_LAYOVER_MINUTES = 60;

    private const DEFAULT_MAX_DURATION_HOURS = 168;

    private const DEFAULT_MAX_ROUTE_PATTERNS = 80;

    private const DEFAULT_MAX_ROUTE_EXPANSIONS = 20000;

    private const DEFAULT_MAX_ROUTE_EDGES_PER_AIRPORT = 64;

    private const DEFAULT_MAX_ROUTE_BEAM = 1500;

    private const DEFAULT_MAX_FLIGHTS_PER_ROUTE = 8;

    private const DEFAULT_MAX_SCHEDULE_BEAM = 24;

    private const BEST_DURATION_MINUTE_WEIGHT_CENTS = 35;

    private const BEST_CONNECTION_PENALTY_CENTS = 7500;

    private const BEST_OVERNIGHT_PENALTY_CENTS = 10000;

    /** @var array<string, array<string, mixed>> */
    private array $airportsByCode = [];

    /** @var array<string, list<string>> */
    private array $airportCodesByCityCode = [];

    /** @var array<string, array<string, list<array<string, mixed>>>> */
    private array $flightsByRoute = [];

    /** @var (callable(string, string, int, ?string): list<array<string, mixed>>)|null */
    private $routeFlightResolver = null;

    /** @var array<string, list<array<string, mixed>>> */
    private array $routeFlightCache = [];

    /** @var array<string, list<array{to: string, weight: int, airline?: string}>> */
    private array $routeGraph = [];

    /** @var array<string, list<array{from: string, weight: int, airline?: string}>> */
    private array $reverseRouteGraph = [];

    private DateTimeImmutable $nowUtc;

    /**
     * @param  array{
     *     airports: array<int, array<string, mixed>>,
     *     flights?: array<int, array<string, mixed>>,
     *     routes?: array<int, array{from: string, to: string, weight: int, airline?: string}>,
     *     route_flight_resolver?: callable(string, string, int, ?string): list<array<string, mixed>>
     * }  $data
     */
    public function __construct(array $data, ?DateTimeImmutable $now = null)
    {
        foreach ($data['airports'] ?? [] as $airport) {
            $this->airportsByCode[$airport['code']] = $airport;
            $this->airportCodesByCityCode[$airport['city_code']][] = $airport['code'];
        }

        if (isset($data['route_flight_resolver']) && is_callable($data['route_flight_resolver'])) {
            $this->routeFlightResolver = $data['route_flight_resolver'];
        }

        if (isset($data['routes'])) {
            foreach ($data['routes'] as $route) {
                if (! isset($this->airportsByCode[$route['from']], $this->airportsByCode[$route['to']])) {
                    continue;
                }

                $this->routeGraph[$route['from']][] = [
                    'to' => $route['to'],
                    'weight' => $route['weight'],
                    ...(isset($route['airline']) ? ['airline' => $route['airline']] : []),
                ];
                $this->reverseRouteGraph[$route['to']][] = [
                    'from' => $route['from'],
                    'weight' => $route['weight'],
                    ...(isset($route['airline']) ? ['airline' => $route['airline']] : []),
                ];
            }
        }

        $bestRouteWeights = [];
        foreach ($data['flights'] ?? [] as $flight) {
            if (! isset($this->airportsByCode[$flight['departure_airport']], $this->airportsByCode[$flight['arrival_airport']])) {
                continue;
            }

            $from = $flight['departure_airport'];
            $to = $flight['arrival_airport'];
            $weight = $this->typicalFlightWeight($flight);

            $this->flightsByRoute[$from][$to][] = $flight;
            $bestRouteWeights[$from][$to][$flight['airline']] = min($bestRouteWeights[$from][$to][$flight['airline']] ?? PHP_INT_MAX, $weight);
        }

        foreach ($this->flightsByRoute as $from => $routes) {
            foreach ($routes as $to => $flights) {
                usort(
                    $this->flightsByRoute[$from][$to],
                    fn (array $left, array $right): int => $this->typicalFlightWeight($left) <=> $this->typicalFlightWeight($right)
                        ?: $left['departure_time'] <=> $right['departure_time'],
                );

                foreach ($bestRouteWeights[$from][$to] as $airline => $weight) {
                    $this->routeGraph[$from][] = ['to' => $to, 'weight' => $weight, 'airline' => $airline];
                    $this->reverseRouteGraph[$to][] = ['from' => $from, 'weight' => $weight, 'airline' => $airline];
                }
            }
        }

        foreach ($this->routeGraph as &$edges) {
            usort($edges, fn (array $left, array $right): int => $left['weight'] <=> $right['weight']);
        }
        unset($edges);

        $this->nowUtc = ($now ?? new DateTimeImmutable)->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchOneWay(string $origin, string $destination, string $departureDate, array $options = []): array
    {
        $this->assertDate($departureDate);
        $options = $this->normalizeOptions($options);
        $originCodes = $this->resolveLocationCodes(strtoupper($origin));
        $destinationCodes = $this->resolveLocationCodes(strtoupper($destination));

        return $this->searchOneWayBetweenAirportSets($originCodes, $destinationCodes, $departureDate, $options);
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

        return $this->searchOneWayBetweenAirportSets($originCodes, $destinationCodes, $departureDate, $options);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchRoundTrip(string $origin, string $destination, string $departureDate, string $returnDate, array $options = []): array
    {
        $this->assertChronologicalDates($departureDate, $returnDate, 'return date must be on or after departure date.');

        $searchOptions = $this->normalizeOptions($options);
        $outboundItineraries = $this->searchOneWay($origin, $destination, $departureDate, $searchOptions);
        $returnItineraries = $this->searchOneWay($destination, $origin, $returnDate, $searchOptions);

        if ($outboundItineraries === [] || $returnItineraries === []) {
            return [];
        }

        return [[
            'type' => 'round_trip',
            'origin' => $origin,
            'destination' => $destination,
            'departure_date' => $departureDate,
            'return_date' => $returnDate,
            'legs' => [
                $this->formatOptionLeg('outbound', $origin, $destination, $departureDate, $outboundItineraries),
                $this->formatOptionLeg('return', $destination, $origin, $returnDate, $returnItineraries),
            ],
        ]];
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
        $outboundItineraries = $this->searchOneWay($origin, $outboundDestination, $departureDate, $searchOptions);
        $returnItineraries = $this->searchOneWay($returnOrigin, $finalDestination, $returnDate, $searchOptions);

        if ($outboundItineraries === [] || $returnItineraries === []) {
            return [];
        }

        return [[
            'type' => 'open_jaw',
            'origin' => $origin,
            'outbound_destination' => $outboundDestination,
            'return_origin' => $returnOrigin,
            'final_destination' => $finalDestination,
            'departure_date' => $departureDate,
            'return_date' => $returnDate,
            'legs' => [
                $this->formatOptionLeg('outbound', $origin, $outboundDestination, $departureDate, $outboundItineraries),
                $this->formatOptionLeg('return', $returnOrigin, $finalDestination, $returnDate, $returnItineraries),
            ],
        ]];
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
        $resultLegs = [];

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

            if ($legItineraries === []) {
                return [];
            }

            $resultLegs[] = $this->formatOptionLeg(
                'leg_'.($index + 1),
                $leg['origin'],
                $leg['destination'],
                $leg['departure_date'],
                $legItineraries,
            );
        }

        return [[
            'type' => 'multi_city',
            'origin' => $legs[0]['origin'],
            'destination' => $legs[count($legs) - 1]['destination'],
            'legs' => $resultLegs,
        ]];
    }

    /**
     * @param list<array<string, mixed>> $options
     * @return array{type: string, origin: string, destination: string, departure_date: string, options: list<array<string, mixed>>, option_count: int}
     */
    private function formatOptionLeg(string $type, string $origin, string $destination, string $departureDate, array $options): array
    {
        return [
            'type' => $type,
            'origin' => $origin,
            'destination' => $destination,
            'departure_date' => $departureDate,
            'options' => $options,
            'option_count' => count($options),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchOneWayBetweenAirportSets(array $originCodes, array $destinationCodes, string $departureDate, array $options): array
    {
        $destinationCodeSet = array_fill_keys($destinationCodes, true);
        $lowerBounds = $this->remainingRouteLowerBounds($destinationCodes);
        $routePatterns = $this->candidateRoutePatterns($originCodes, $destinationCodeSet, $lowerBounds, $options);
        $results = [];

        foreach ($routePatterns as $routePattern) {
            foreach ($this->materializeRoutePattern($routePattern['airports'], $departureDate, $options) as $itinerary) {
                $results[] = $itinerary;
            }
        }

        return array_slice($this->sortItineraries($results, $options['sort']), 0, $options['max_results']);
    }

    /**
     * @param  list<string>  $originCodes
     * @param  array<string, true>  $destinationCodeSet
     * @param  array<string, int>  $lowerBounds
     * @return list<array{airports: list<string>, route_score: int}>
     */
    private function candidateRoutePatterns(array $originCodes, array $destinationCodeSet, array $lowerBounds, array $options): array
    {
        $frontier = [];
        foreach ($originCodes as $originCode) {
            if (! isset($lowerBounds[$originCode])) {
                continue;
            }

            $frontier[] = [
                'airport' => $originCode,
                'airports' => [$originCode],
                'route_score' => 0,
                'estimated_total' => $lowerBounds[$originCode],
            ];
        }

        $patterns = [];
        $expansions = 0;
        $seenCompletePaths = [];

        for ($depth = 0; $depth < $options['max_segments'] && $frontier !== [] && count($patterns) < $options['max_route_patterns']; $depth++) {
            $nextFrontier = [];

            foreach ($frontier as $path) {
                if ($expansions >= $options['max_route_expansions']) {
                    break 2;
                }

                $expansions++;

                $candidateEdges = [];

                foreach ($this->routeGraph[$path['airport']] ?? [] as $edge) {
                    if ($options['airline'] !== null && ($edge['airline'] ?? null) !== $options['airline']) {
                        continue;
                    }

                    $nextAirport = $edge['to'];
                    if (! isset($lowerBounds[$nextAirport])) {
                        continue;
                    }

                    if (in_array($nextAirport, $path['airports'], true) && ! isset($destinationCodeSet[$nextAirport])) {
                        continue;
                    }

                    $candidateEdges[] = [
                        ...$edge,
                        'estimated_total' => $edge['weight'] + $lowerBounds[$nextAirport],
                    ];
                }

                usort(
                    $candidateEdges,
                    fn (array $left, array $right): int => $left['estimated_total'] <=> $right['estimated_total']
                        ?: $left['weight'] <=> $right['weight'],
                );

                foreach (array_slice($candidateEdges, 0, $options['max_route_edges_per_airport']) as $edge) {
                    $nextAirport = $edge['to'];

                    $airportPath = [...$path['airports'], $nextAirport];
                    $routeScore = $path['route_score'] + $edge['weight'];

                    if (isset($destinationCodeSet[$nextAirport])) {
                        $key = implode('>', $airportPath);
                        if (! isset($seenCompletePaths[$key])) {
                            $seenCompletePaths[$key] = true;
                            $patterns[] = [
                                'airports' => $airportPath,
                                'route_score' => $routeScore,
                            ];

                            if (count($patterns) >= $options['max_route_patterns']) {
                                break 2;
                            }
                        }

                        continue;
                    }

                    $nextFrontier[] = [
                        'airport' => $nextAirport,
                        'airports' => $airportPath,
                        'route_score' => $routeScore,
                        'estimated_total' => $routeScore + $lowerBounds[$nextAirport],
                    ];

                    if (count($nextFrontier) > $options['max_route_beam'] * 2) {
                        $nextFrontier = $this->trimRouteFrontier($nextFrontier, $options['max_route_beam']);
                    }
                }
            }

            $frontier = $this->trimRouteFrontier($nextFrontier, $options['max_route_beam']);
        }

        usort($patterns, fn (array $left, array $right): int => $left['route_score'] <=> $right['route_score']);

        return $patterns;
    }

    /**
     * @param list<array{airport: string, airports: list<string>, route_score: int, estimated_total: int}> $frontier
     * @return list<array{airport: string, airports: list<string>, route_score: int, estimated_total: int}>
     */
    private function trimRouteFrontier(array $frontier, int $limit): array
    {
        usort(
            $frontier,
            fn (array $left, array $right): int => $left['estimated_total'] <=> $right['estimated_total']
                ?: $left['route_score'] <=> $right['route_score'],
        );

        return array_slice($frontier, 0, $limit);
    }

    /**
     * @param  list<string>  $airportPath
     * @return list<array<string, mixed>>
     */
    private function materializeRoutePattern(array $airportPath, string $departureDate, array $options): array
    {
        if (count($airportPath) < 2) {
            return [];
        }

        $originTimezone = new DateTimeZone($this->airportsByCode[$airportPath[0]]['timezone']);
        $departureWindowStartLocal = new DateTimeImmutable("{$departureDate} 00:00:00", $originTimezone);
        $departureWindowEndLocal = $departureWindowStartLocal->modify('+1 day');
        $departureWindowStartUtc = $departureWindowStartLocal->setTimezone(new DateTimeZone('UTC'));
        $departureWindowEndUtc = $departureWindowEndLocal->setTimezone(new DateTimeZone('UTC'));
        $latestAllowedDepartureUtc = $this->nowUtc->add(new DateInterval('P365D'));
        $availableAfterUtc = $departureWindowStartUtc > $this->nowUtc ? $departureWindowStartUtc : $this->nowUtc;

        $states = [[
            'available_after_utc' => $availableAfterUtc,
            'segments' => [],
            'total_price_cents' => 0,
            'first_departure_utc' => null,
        ]];

        for ($index = 0; $index < count($airportPath) - 1; $index++) {
            $from = $airportPath[$index];
            $to = $airportPath[$index + 1];
            $nextStates = [];

            foreach ($states as $state) {
                $earliestDepartureUtc = count($state['segments']) === 0
                    ? $state['available_after_utc']
                    : $this->addMinutes($state['available_after_utc'], $options['minimum_layover_minutes']);

                foreach ($this->flightsForRoute($from, $to, $options) as $flight) {
                    $occurrence = $this->nextFlightOccurrence($flight, $earliestDepartureUtc);

                    if (count($state['segments']) === 0) {
                        if ($occurrence['departure_utc'] < $departureWindowStartUtc || $occurrence['departure_utc'] >= $departureWindowEndUtc) {
                            continue;
                        }

                        if ($occurrence['departure_utc'] < $this->nowUtc || $occurrence['departure_utc'] > $latestAllowedDepartureUtc) {
                            continue;
                        }
                    }

                    $firstDepartureUtc = $state['first_departure_utc'] ?? $occurrence['departure_utc'];
                    $durationMinutes = $this->minutesBetween($firstDepartureUtc, $occurrence['arrival_utc']);

                    if ($durationMinutes > $options['max_duration_hours'] * 60) {
                        continue;
                    }

                    $segment = $this->formatSegment($flight, $occurrence);
                    $segments = [...$state['segments'], $segment];
                    $totalPriceCents = $state['total_price_cents'] + $this->priceToCents($flight['price']);

                    $nextStates[] = [
                        'available_after_utc' => $occurrence['arrival_utc'],
                        'segments' => $segments,
                        'total_price_cents' => $totalPriceCents,
                        'first_departure_utc' => $firstDepartureUtc,
                        'score' => $this->bestScoreForItinerary($segments, $totalPriceCents),
                    ];
                }
            }

            usort(
                $nextStates,
                fn (array $left, array $right): int => $left['score'] <=> $right['score']
                    ?: $left['available_after_utc']->getTimestamp() <=> $right['available_after_utc']->getTimestamp(),
            );

            $states = array_slice($nextStates, 0, $options['max_schedule_beam']);
            if ($states === []) {
                return [];
            }
        }

        return array_map(
            fn (array $state): array => $this->formatItinerary($state['segments'], $state['total_price_cents']),
            $states,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function flightsForRoute(string $from, string $to, array $options): array
    {
        if ($this->routeFlightResolver === null) {
            return $this->rankedRouteFlights(
                $this->flightsByRoute[$from][$to] ?? [],
                $options['max_flights_per_route'],
                $options['airline'],
            );
        }

        $cacheKey = implode('|', [
            $from,
            $to,
            (string) $options['max_flights_per_route'],
            (string) ($options['airline'] ?? ''),
        ]);

        if (! array_key_exists($cacheKey, $this->routeFlightCache)) {
            $this->routeFlightCache[$cacheKey] = ($this->routeFlightResolver)(
                $from,
                $to,
                $options['max_flights_per_route'],
                $options['airline'],
            );
        }

        return $this->routeFlightCache[$cacheKey];
    }

    /**
     * @param list<array<string, mixed>> $flights
     * @return list<array<string, mixed>>
     */
    private function rankedRouteFlights(array $flights, int $limit, ?string $restrictedAirline): array
    {
        if ($limit < 1 || $flights === []) {
            return [];
        }

        if ($restrictedAirline !== null) {
            $flights = array_values(array_filter(
                $flights,
                fn (array $flight): bool => $flight['airline'] === $restrictedAirline,
            ));
        }

        usort(
            $flights,
            fn (array $left, array $right): int => $this->typicalFlightWeight($left) <=> $this->typicalFlightWeight($right)
                ?: $left['departure_time'] <=> $right['departure_time'],
        );

        return array_slice($flights, 0, $limit);
    }

    /**
     * @param  list<string>  $destinationCodes
     * @return array<string, int>
     */
    private function remainingRouteLowerBounds(array $destinationCodes): array
    {
        $distances = [];
        $queue = new SplPriorityQueue;
        $queue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);

        foreach ($destinationCodes as $destinationCode) {
            $distances[$destinationCode] = 0;
            $queue->insert($destinationCode, 0);
        }

        while (! $queue->isEmpty()) {
            /** @var array{data: string, priority: int|float} $item */
            $item = $queue->extract();
            $airport = $item['data'];
            $distance = -(int) $item['priority'];

            if ($distance !== $distances[$airport]) {
                continue;
            }

            foreach ($this->reverseRouteGraph[$airport] ?? [] as $edge) {
                $candidate = $distance + $edge['weight'];

                if (! isset($distances[$edge['from']]) || $candidate < $distances[$edge['from']]) {
                    $distances[$edge['from']] = $candidate;
                    $queue->insert($edge['from'], -$candidate);
                }
            }
        }

        return $distances;
    }

    private function typicalFlightWeight(array $flight): int
    {
        return $this->priceToCents($flight['price'])
            + ($this->typicalFlightDurationMinutes($flight) * self::BEST_DURATION_MINUTE_WEIGHT_CENTS)
            + self::BEST_CONNECTION_PENALTY_CENTS;
    }

    private function typicalFlightDurationMinutes(array $flight): int
    {
        $departureAirport = $this->airportsByCode[$flight['departure_airport']];
        $arrivalAirport = $this->airportsByCode[$flight['arrival_airport']];
        $departureTimezone = new DateTimeZone($departureAirport['timezone']);
        $arrivalTimezone = new DateTimeZone($arrivalAirport['timezone']);
        $departureLocal = new DateTimeImmutable('2026-01-15 '.$flight['departure_time'].':00', $departureTimezone);
        $arrivalLocal = new DateTimeImmutable('2026-01-15 '.$flight['arrival_time'].':00', $arrivalTimezone);
        $departureUtc = $departureLocal->setTimezone(new DateTimeZone('UTC'));
        $arrivalUtc = $arrivalLocal->setTimezone(new DateTimeZone('UTC'));

        if ($arrivalUtc <= $departureUtc) {
            $arrivalUtc = $arrivalLocal->modify('+1 day')->setTimezone(new DateTimeZone('UTC'));
        }

        return $this->minutesBetween($departureUtc, $arrivalUtc);
    }

    private function flightOccurrenceOnLocalDate(array $flight, string $localDate): array
    {
        $departureAirport = $this->airportsByCode[$flight['departure_airport']];
        $arrivalAirport = $this->airportsByCode[$flight['arrival_airport']];
        $departureTimezone = new DateTimeZone($departureAirport['timezone']);
        $arrivalTimezone = new DateTimeZone($arrivalAirport['timezone']);

        $departureLocal = new DateTimeImmutable(
            $localDate.' '.$flight['departure_time'].':00',
            $departureTimezone,
        );
        $departureUtc = $departureLocal->setTimezone(new DateTimeZone('UTC'));
        $arrivalLocal = new DateTimeImmutable(
            $departureLocal->format('Y-m-d').' '.$flight['arrival_time'].':00',
            $arrivalTimezone,
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

    private function nextFlightOccurrence(array $flight, DateTimeImmutable $availableFromUtc): array
    {
        $departureTimezone = new DateTimeZone($this->airportsByCode[$flight['departure_airport']]['timezone']);
        $availableFromLocal = $availableFromUtc->setTimezone($departureTimezone);
        $occurrence = $this->flightOccurrenceOnLocalDate($flight, $availableFromLocal->format('Y-m-d'));

        if ($occurrence['departure_utc'] < $availableFromUtc) {
            $occurrence = $this->flightOccurrenceOnLocalDate($flight, $availableFromLocal->modify('+1 day')->format('Y-m-d'));
        }

        return $occurrence;
    }

    private function formatSegment(array $flight, array $occurrence): array
    {
        $departureAirport = $this->airportsByCode[$flight['departure_airport']];
        $arrivalAirport = $this->airportsByCode[$flight['arrival_airport']];
        $priceCents = $this->priceToCents($flight['price']);

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
            'price' => $this->centsToPrice($priceCents),
            'price_cents' => $priceCents,
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

    private function bestScoreForItinerary(array $segments, int $totalPriceCents): int
    {
        $firstSegment = $segments[0];
        $lastSegment = $segments[count($segments) - 1];
        $departureUtc = new DateTimeImmutable($firstSegment['departure_utc']);
        $arrivalUtc = new DateTimeImmutable($lastSegment['arrival_utc']);
        $durationMinutes = $this->minutesBetween($departureUtc, $arrivalUtc);
        $connections = max(0, count($segments) - 1);
        $overnightPenalty = substr($firstSegment['departure_at'], 0, 10) === substr($lastSegment['arrival_at'], 0, 10)
            ? 0
            : self::BEST_OVERNIGHT_PENALTY_CENTS;

        return max(0, $totalPriceCents
            + ($durationMinutes * self::BEST_DURATION_MINUTE_WEIGHT_CENTS)
            + ($connections * self::BEST_CONNECTION_PENALTY_CENTS)
            + $overnightPenalty);
    }

    private function normalizeOptions(array $options): array
    {
        $sort = $options['sort'] ?? 'best';

        if (! in_array($sort, ['price', 'departure', 'arrival', 'duration', 'best'], true)) {
            throw new InvalidArgumentException('Sort must be one of price, departure, arrival, duration, or best.');
        }

        $maxSegments = (int) ($options['max_segments'] ?? (
            array_key_exists('max_stops', $options) ? ((int) $options['max_stops']) + 1 : self::DEFAULT_MAX_SEGMENTS
        ));

        foreach ([
            'max_segments' => $maxSegments,
            'max_results' => (int) ($options['max_results'] ?? self::DEFAULT_MAX_RESULTS),
            'minimum_layover_minutes' => (int) ($options['minimum_layover_minutes'] ?? self::DEFAULT_MIN_LAYOVER_MINUTES),
            'max_duration_hours' => (int) ($options['max_duration_hours'] ?? self::DEFAULT_MAX_DURATION_HOURS),
            'max_route_patterns' => (int) ($options['max_route_patterns'] ?? self::DEFAULT_MAX_ROUTE_PATTERNS),
            'max_route_expansions' => (int) ($options['max_route_expansions'] ?? self::DEFAULT_MAX_ROUTE_EXPANSIONS),
            'max_route_edges_per_airport' => (int) ($options['max_route_edges_per_airport'] ?? self::DEFAULT_MAX_ROUTE_EDGES_PER_AIRPORT),
            'max_route_beam' => (int) ($options['max_route_beam'] ?? self::DEFAULT_MAX_ROUTE_BEAM),
            'max_flights_per_route' => (int) ($options['max_flights_per_route'] ?? self::DEFAULT_MAX_FLIGHTS_PER_ROUTE),
            'max_schedule_beam' => (int) ($options['max_schedule_beam'] ?? self::DEFAULT_MAX_SCHEDULE_BEAM),
        ] as $key => $value) {
            $minimum = $key === 'minimum_layover_minutes' ? 0 : 1;

            if ($value < $minimum) {
                throw new InvalidArgumentException("{$key} must be greater than or equal to {$minimum}.");
            }
        }

        return [
            'max_segments' => $maxSegments,
            'max_results' => (int) ($options['max_results'] ?? self::DEFAULT_MAX_RESULTS),
            'minimum_layover_minutes' => (int) ($options['minimum_layover_minutes'] ?? self::DEFAULT_MIN_LAYOVER_MINUTES),
            'max_duration_hours' => (int) ($options['max_duration_hours'] ?? self::DEFAULT_MAX_DURATION_HOURS),
            'max_route_patterns' => (int) ($options['max_route_patterns'] ?? self::DEFAULT_MAX_ROUTE_PATTERNS),
            'max_route_expansions' => (int) ($options['max_route_expansions'] ?? self::DEFAULT_MAX_ROUTE_EXPANSIONS),
            'max_route_edges_per_airport' => (int) ($options['max_route_edges_per_airport'] ?? self::DEFAULT_MAX_ROUTE_EDGES_PER_AIRPORT),
            'max_route_beam' => (int) ($options['max_route_beam'] ?? self::DEFAULT_MAX_ROUTE_BEAM),
            'max_flights_per_route' => (int) ($options['max_flights_per_route'] ?? self::DEFAULT_MAX_FLIGHTS_PER_ROUTE),
            'max_schedule_beam' => (int) ($options['max_schedule_beam'] ?? self::DEFAULT_MAX_SCHEDULE_BEAM),
            'airline' => $options['airline'] ?? null,
            'sort' => $sort,
        ];
    }

    private function sortItineraries(array $itineraries, string $sort): array
    {
        usort($itineraries, function (array $left, array $right) use ($sort): int {
            return match ($sort) {
                'departure' => $left['departure_utc'] <=> $right['departure_utc']
                    ?: $left['best_score'] <=> $right['best_score'],
                'arrival' => $left['arrival_utc'] <=> $right['arrival_utc']
                    ?: $left['best_score'] <=> $right['best_score'],
                'duration' => $left['duration_minutes'] <=> $right['duration_minutes']
                    ?: $left['best_score'] <=> $right['best_score'],
                'price' => $left['total_price_cents'] <=> $right['total_price_cents']
                    ?: $left['best_score'] <=> $right['best_score'],
                default => $left['best_score'] <=> $right['best_score']
                    ?: $left['total_price_cents'] <=> $right['total_price_cents'],
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

    private function bestScoreForTrip(array $itineraries): int
    {
        return array_sum(array_column($itineraries, 'best_score'));
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

    private function assertDate(string $date): void
    {
        $this->parseDateOnly($date);
    }

    private function assertChronologicalDates(string $firstDate, string $secondDate, string $message): void
    {
        $first = $this->parseDateOnly($firstDate);
        $second = $this->parseDateOnly($secondDate);

        if ($second < $first) {
            throw new InvalidArgumentException($message);
        }
    }

    private function parseDateOnly(string $date): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if (! $parsed || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("Invalid date: {$date}. Expected YYYY-MM-DD.");
        }

        return $parsed;
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
