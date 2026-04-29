<?php

namespace App\Services;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use SplPriorityQueue;

class TripPlannerV3
{
    private const DEFAULT_MAX_SEGMENTS = 6;

    private const DEFAULT_MAX_RESULTS = 20;

    private const DEFAULT_MIN_LAYOVER_MINUTES = 60;

    private const DEFAULT_MAX_DURATION_HOURS = 168;

    private const DEFAULT_MAX_CONNECTIONS_SCANNED = 750000;

    private const BEST_DURATION_MINUTE_WEIGHT_CENTS = 35;

    private const BEST_CONNECTION_PENALTY_CENTS = 7500;

    private const BEST_OVERNIGHT_PENALTY_CENTS = 10000;

    private const LABELS_PER_AIRPORT_LIMIT = 24;

    /** @var array<string, array<string, mixed>> */
    private array $airportsByCode = [];

    /** @var array<string, list<string>> */
    private array $airportCodesByCityCode = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $flightsByDepartureAirport = [];

    /** @var array<string, list<array{from: string, weight: int}>> */
    private array $reverseWeightedEdges = [];

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
            if (! isset($this->airportsByCode[$flight['departure_airport']], $this->airportsByCode[$flight['arrival_airport']])) {
                continue;
            }

            $this->flightsByDepartureAirport[$flight['departure_airport']][] = $flight;
            $this->addReverseWeightedEdge($flight);
        }

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
    private function searchOneWayBetweenAirportSets(array $originCodes, array $destinationCodes, string $departureDate, array $options): array
    {
        $destinationCodeSet = array_fill_keys($destinationCodes, true);
        $remainingScoreLowerBounds = $this->remainingScoreLowerBounds($destinationCodes);
        $latestAllowedDepartureUtc = $this->nowUtc->add(new DateInterval('P365D'));
        $labelsByAirport = [];
        $scanWindowStartUtc = null;
        $scanWindowEndUtc = null;

        foreach ($originCodes as $originCode) {
            if (! isset($remainingScoreLowerBounds[$originCode])) {
                continue;
            }

            $originTimezone = new DateTimeZone($this->airportsByCode[$originCode]['timezone']);
            $departureWindowStartLocal = new DateTimeImmutable("{$departureDate} 00:00:00", $originTimezone);
            $departureWindowEndLocal = $departureWindowStartLocal->modify('+1 day');
            $departureWindowStartUtc = $departureWindowStartLocal->setTimezone(new DateTimeZone('UTC'));
            $departureWindowEndUtc = $departureWindowEndLocal->setTimezone(new DateTimeZone('UTC'));
            $availableAfterUtc = $departureWindowStartUtc > $this->nowUtc ? $departureWindowStartUtc : $this->nowUtc;
            $scanWindowStartUtc = $scanWindowStartUtc === null || $availableAfterUtc < $scanWindowStartUtc
                ? $availableAfterUtc
                : $scanWindowStartUtc;
            $candidateWindowEndUtc = $departureWindowEndUtc->add(new DateInterval('PT'.$options['max_duration_hours'].'H'));
            $scanWindowEndUtc = $scanWindowEndUtc === null || $candidateWindowEndUtc > $scanWindowEndUtc
                ? $candidateWindowEndUtc
                : $scanWindowEndUtc;

            $labelsByAirport[$originCode][] = [
                'airport' => $originCode,
                'available_after_utc' => $availableAfterUtc,
                'segments' => [],
                'total_price_cents' => 0,
                'score_so_far' => 0,
                'visited_airports' => [$originCode => true],
                'first_departure_utc' => null,
                'departure_window_start_utc' => $departureWindowStartUtc,
                'departure_window_end_utc' => $departureWindowEndUtc,
            ];
        }

        if ($scanWindowStartUtc === null || $scanWindowEndUtc === null) {
            return [];
        }

        $results = [];
        $connectionsScanned = 0;
        $reachableAirportSet = array_fill_keys(array_keys($remainingScoreLowerBounds), true);

        while ($connectionsScanned < $options['max_connections_scanned']) {
            $connection = $this->nextScannableConnection($labelsByAirport, $reachableAirportSet, $options);

            if ($connection === null || $connection['departure_utc'] > $scanWindowEndUtc) {
                break;
            }

            $connectionsScanned++;

            $departureLabels = $labelsByAirport[$connection['departure_airport']] ?? [];
            if ($departureLabels === []) {
                continue;
            }

            foreach ($departureLabels as $label) {
                if (count($label['segments']) >= $options['max_segments']) {
                    continue;
                }

                if ($connection['departure_utc'] < $label['available_after_utc']) {
                    continue;
                }

                if (count($label['segments']) === 0) {
                    if ($connection['departure_utc'] < $label['departure_window_start_utc'] || $connection['departure_utc'] >= $label['departure_window_end_utc']) {
                        continue;
                    }

                    if ($connection['departure_utc'] < $this->nowUtc || $connection['departure_utc'] > $latestAllowedDepartureUtc) {
                        continue;
                    }
                } elseif ($connection['departure_utc'] < $this->addMinutes($label['available_after_utc'], $options['minimum_layover_minutes'])) {
                    continue;
                }

                if (isset($label['visited_airports'][$connection['arrival_airport']]) && ! isset($destinationCodeSet[$connection['arrival_airport']])) {
                    continue;
                }

                $firstDepartureUtc = $label['first_departure_utc'] ?? $connection['departure_utc'];
                $durationMinutes = $this->minutesBetween($firstDepartureUtc, $connection['arrival_utc']);

                if ($durationMinutes > $options['max_duration_hours'] * 60) {
                    continue;
                }

                $segment = $this->formatSegment($connection['flight'], $connection);
                $segments = [...$label['segments'], $segment];
                $totalPriceCents = $label['total_price_cents'] + $this->priceToCents($connection['flight']['price']);
                $scoreSoFar = $this->bestScoreForItinerary($segments, $totalPriceCents);
                $segmentCount = count($segments);
                $arrivalAirport = $connection['arrival_airport'];

                if ($this->isDominated($labelsByAirport[$arrivalAirport] ?? [], $connection['arrival_utc'], $totalPriceCents, $segmentCount, $scoreSoFar)) {
                    continue;
                }

                $visitedAirports = $label['visited_airports'];
                $visitedAirports[$arrivalAirport] = true;

                $newLabel = [
                    'airport' => $arrivalAirport,
                    'available_after_utc' => $connection['arrival_utc'],
                    'segments' => $segments,
                    'total_price_cents' => $totalPriceCents,
                    'score_so_far' => $scoreSoFar,
                    'visited_airports' => $visitedAirports,
                    'first_departure_utc' => $firstDepartureUtc,
                    'departure_window_start_utc' => $label['departure_window_start_utc'],
                    'departure_window_end_utc' => $label['departure_window_end_utc'],
                ];

                $labelsByAirport[$arrivalAirport] = $this->addLabel(
                    $labelsByAirport[$arrivalAirport] ?? [],
                    $connection['arrival_utc'],
                    $totalPriceCents,
                    $segmentCount,
                    $scoreSoFar,
                    $newLabel,
                );

                if (isset($destinationCodeSet[$arrivalAirport])) {
                    $results[] = $this->formatItinerary($segments, $totalPriceCents);
                }
            }
        }

        return array_slice($this->sortItineraries($results, $options['sort']), 0, $options['max_results']);
    }

    private function nextScannableConnection(array &$labelsByAirport, array $reachableAirportSet, array $options): ?array
    {
        $bestConnection = null;
        $bestDepartureAirport = null;
        $bestFlightIndex = null;

        foreach ($labelsByAirport as $departureAirport => $labels) {
            if ($labels === [] || ! isset($reachableAirportSet[$departureAirport])) {
                continue;
            }

            foreach ($this->flightsByDepartureAirport[$departureAirport] ?? [] as $flightIndex => $flight) {
                if ($options['airline'] !== null && $flight['airline'] !== $options['airline']) {
                    continue;
                }

                if (! isset($reachableAirportSet[$flight['arrival_airport']])) {
                    continue;
                }

                $nextScanAfterUtc = $this->nextScanAfterUtc($labels, $flightIndex);
                $earliestCatchUtc = $this->earliestCatchTimeForFlight($labels, $options);

                if ($nextScanAfterUtc instanceof DateTimeImmutable && $nextScanAfterUtc > $earliestCatchUtc) {
                    $earliestCatchUtc = $nextScanAfterUtc;
                }

                $occurrence = $this->nextFlightOccurrence($flight, $earliestCatchUtc);
                $connection = [
                    ...$occurrence,
                    'flight' => $flight,
                    'departure_airport' => $flight['departure_airport'],
                    'arrival_airport' => $flight['arrival_airport'],
                ];

                if (
                    $bestConnection === null
                    || $connection['departure_utc'] < $bestConnection['departure_utc']
                    || ($connection['departure_utc'] == $bestConnection['departure_utc'] && $connection['arrival_utc'] < $bestConnection['arrival_utc'])
                ) {
                    $bestConnection = $connection;
                    $bestDepartureAirport = $departureAirport;
                    $bestFlightIndex = $flightIndex;
                }
            }
        }

        if ($bestConnection !== null && $bestDepartureAirport !== null && $bestFlightIndex !== null) {
            foreach ($labelsByAirport[$bestDepartureAirport] as &$label) {
                $label['next_scan_after_utc'][$bestFlightIndex] = $bestConnection['departure_utc']->add(new DateInterval('PT1M'));
            }
            unset($label);
        }

        return $bestConnection;
    }

    private function nextScanAfterUtc(array $labels, int $flightIndex): ?DateTimeImmutable
    {
        $next = null;

        foreach ($labels as $label) {
            $candidate = $label['next_scan_after_utc'][$flightIndex] ?? null;

            if ($candidate instanceof DateTimeImmutable && ($next === null || $candidate > $next)) {
                $next = $candidate;
            }
        }

        return $next;
    }

    private function earliestCatchTimeForFlight(array $labels, array $options): DateTimeImmutable
    {
        $earliest = null;

        foreach ($labels as $label) {
            if (count($label['segments']) >= $options['max_segments']) {
                continue;
            }

            $candidate = count($label['segments']) === 0
                ? $label['available_after_utc']
                : $this->addMinutes($label['available_after_utc'], $options['minimum_layover_minutes']);

            if ($earliest === null || $candidate < $earliest) {
                $earliest = $candidate;
            }
        }

        return $earliest ?? new DateTimeImmutable('@'.PHP_INT_MAX);
    }

    /**
     * @param  list<string>  $destinationCodes
     * @return array<string, int>
     */
    private function remainingScoreLowerBounds(array $destinationCodes): array
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

            foreach ($this->reverseWeightedEdges[$airport] ?? [] as $edge) {
                $candidate = $distance + $edge['weight'];

                if (! isset($distances[$edge['from']]) || $candidate < $distances[$edge['from']]) {
                    $distances[$edge['from']] = $candidate;
                    $queue->insert($edge['from'], -$candidate);
                }
            }
        }

        return $distances;
    }

    private function addReverseWeightedEdge(array $flight): void
    {
        $from = $flight['departure_airport'];
        $to = $flight['arrival_airport'];
        $weight = $this->priceToCents($flight['price'])
            + ($this->typicalFlightDurationMinutes($flight) * self::BEST_DURATION_MINUTE_WEIGHT_CENTS)
            + self::BEST_CONNECTION_PENALTY_CENTS;

        $this->reverseWeightedEdges[$to][] = [
            'from' => $from,
            'weight' => $weight,
        ];
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

        return $totalPriceCents
            + ($durationMinutes * self::BEST_DURATION_MINUTE_WEIGHT_CENTS)
            + ($connections * self::BEST_CONNECTION_PENALTY_CENTS)
            + $overnightPenalty;
    }

    private function isDominated(array $labels, DateTimeImmutable $arrivalUtc, int $priceCents, int $segmentCount, int $score): bool
    {
        foreach ($labels as $label) {
            if (
                $label['arrival_ts'] <= $arrivalUtc->getTimestamp()
                && $label['price_cents'] <= $priceCents
                && $label['segment_count'] <= $segmentCount
                && $label['score'] <= $score
            ) {
                return true;
            }
        }

        return false;
    }

    private function addLabel(array $labels, DateTimeImmutable $arrivalUtc, int $priceCents, int $segmentCount, int $score, array $state): array
    {
        $arrivalTimestamp = $arrivalUtc->getTimestamp();
        $labels = array_values(array_filter(
            $labels,
            fn (array $label): bool => ! (
                $arrivalTimestamp <= $label['arrival_ts']
                && $priceCents <= $label['price_cents']
                && $segmentCount <= $label['segment_count']
                && $score <= $label['score']
            ),
        ));

        $labels[] = [
            'arrival_ts' => $arrivalTimestamp,
            'price_cents' => $priceCents,
            'segment_count' => $segmentCount,
            'score' => $score,
            ...$state,
        ];

        usort($labels, fn (array $left, array $right): int => $left['score'] <=> $right['score']);

        return array_slice($labels, 0, self::LABELS_PER_AIRPORT_LIMIT);
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
            'max_connections_scanned' => (int) ($options['max_connections_scanned'] ?? self::DEFAULT_MAX_CONNECTIONS_SCANNED),
        ] as $key => $value) {
            $minimum = $key === 'max_segments' || $key === 'max_results' || $key === 'max_duration_hours' || $key === 'max_connections_scanned' ? 1 : 0;

            if ($value < $minimum) {
                throw new InvalidArgumentException("{$key} must be greater than or equal to {$minimum}.");
            }
        }

        return [
            'max_segments' => $maxSegments,
            'max_results' => (int) ($options['max_results'] ?? self::DEFAULT_MAX_RESULTS),
            'minimum_layover_minutes' => (int) ($options['minimum_layover_minutes'] ?? self::DEFAULT_MIN_LAYOVER_MINUTES),
            'max_duration_hours' => (int) ($options['max_duration_hours'] ?? self::DEFAULT_MAX_DURATION_HOURS),
            'max_connections_scanned' => (int) ($options['max_connections_scanned'] ?? self::DEFAULT_MAX_CONNECTIONS_SCANNED),
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

    private function assertDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if (! $parsed || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("Invalid date: {$date}. Expected YYYY-MM-DD.");
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
