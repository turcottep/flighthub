<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FlightDataRepository
{
    private const BEST_DURATION_MINUTE_WEIGHT_CENTS = 35;

    private const BEST_CONNECTION_PENALTY_CENTS = 7500;

    public function __construct(private readonly ?string $path = null) {}

    /**
     * @return array{airlines: array<int, array<string, mixed>>, airports: array<int, array<string, mixed>>, flights: array<int, array<string, mixed>>}
     */
    public function load(): array
    {
        if ($this->path === null) {
            return $this->loadFromDatabase();
        }

        if (! is_file($this->path)) {
            throw new RuntimeException("Flight data file does not exist: {$this->path}");
        }

        $contents = file_get_contents($this->path);

        if ($contents === false) {
            throw new RuntimeException("Flight data file could not be read: {$this->path}");
        }

        $data = json_decode($contents, true);

        if (! is_array($data)) {
            throw new RuntimeException("Flight data file is not valid JSON: {$this->path}");
        }

        foreach (['airlines', 'airports', 'flights'] as $key) {
            if (! isset($data[$key]) || ! is_array($data[$key])) {
                throw new RuntimeException("Flight data file is missing a valid {$key} collection.");
            }
        }

        return $data;
    }

    /**
     * @return array{airlines: array<int, array<string, mixed>>, airports: array<int, array<string, mixed>>, flights: array<int, array<string, mixed>>}
     */
    public function loadFromDatabase(): array
    {
        return [
            'airlines' => $this->loadAirlinesFromDatabase(),
            'airports' => $this->loadAirportsFromDatabase(),
            'flights' => $this->loadFlightsFromDatabase(),
        ];
    }

    /**
     * Load all airports/airlines and only flights from the supplied departure airports.
     *
     * @param list<string> $departureAirportCodes
     * @return array{airlines: array<int, array<string, mixed>>, airports: array<int, array<string, mixed>>, flights: array<int, array<string, mixed>>}
     */
    public function loadFromDatabaseForDepartures(array $departureAirportCodes): array
    {
        $departureAirportCodes = array_values(array_unique(array_map('strtoupper', $departureAirportCodes)));

        return [
            'airlines' => $this->loadAirlinesFromDatabase(),
            'airports' => $this->loadAirportsFromDatabase(),
            'flights' => $this->loadFlightsFromDatabase($departureAirportCodes),
        ];
    }

    /**
     * Load the compact route network needed for route search. Timed flights are fetched lazily per route pair.
     *
     * @return array{
     *     airports: array<int, array<string, mixed>>,
     *     routes: array<int, array{from: string, to: string, weight: int}>,
     *     route_flight_resolver: callable(string, string, int, ?string): list<array<string, mixed>>
     * }
     */
    public function loadRouteNetworkFromDatabase(): array
    {
        $airports = $this->loadAirportsFromDatabase();
        $airportsByCode = [];

        foreach ($airports as $airport) {
            $airportsByCode[$airport['code']] = $airport;
        }

        return [
            'airports' => $airports,
            'routes' => $this->loadRouteSummariesFromDatabase($airportsByCode),
            'route_flight_resolver' => fn (string $from, string $to, int $limit, ?string $airline): array => $this->loadFlightsForRouteFromDatabase(
                $from,
                $to,
                $limit,
                $airline,
            ),
        ];
    }

    /**
     * Resolve an airport code or city code to matching airport codes.
     *
     * @return list<string>
     */
    public function resolveLocationCodesFromDatabase(string $code): array
    {
        $code = strtoupper($code);

        $airportCodes = DB::table('airports')
            ->where('code', $code)
            ->orWhere('city_code', $code)
            ->orderBy('code')
            ->pluck('code')
            ->all();

        return array_values(array_unique($airportCodes));
    }

    /**
     * Return departure airport codes needed to search from a set of origins with a bounded number of stops.
     *
     * @param list<string> $originAirportCodes
     * @return list<string>
     */
    public function departureAirportCodesReachableFromDatabase(array $originAirportCodes, int $maxStops): array
    {
        $known = array_fill_keys(array_values(array_unique(array_map('strtoupper', $originAirportCodes))), true);
        $frontier = array_keys($known);

        for ($depth = 0; $depth < $maxStops; $depth++) {
            if ($frontier === []) {
                break;
            }

            $next = DB::table('flights')
                ->whereIn('departure_airport_code', $frontier)
                ->distinct()
                ->pluck('arrival_airport_code')
                ->all();

            $frontier = [];
            foreach ($next as $airportCode) {
                if (! isset($known[$airportCode])) {
                    $known[$airportCode] = true;
                    $frontier[] = $airportCode;
                }
            }
        }

        return array_keys($known);
    }

    /**
     * @return list<string>
     */
    public function nearbyAirportCodesFromDatabase(float $latitude, float $longitude, float $radiusKm): array
    {
        $matches = DB::table('airports')
            ->get(['code', 'latitude', 'longitude'])
            ->map(function (object $airport) use ($latitude, $longitude, $radiusKm): ?array {
                $distanceKm = $this->distanceKm(
                    $latitude,
                    $longitude,
                    (float) $airport->latitude,
                    (float) $airport->longitude,
                );

                if ($distanceKm > $radiusKm) {
                    return null;
                }

                return [
                    'code' => $airport->code,
                    'distance_km' => $distanceKm,
                ];
            })
            ->filter()
            ->sortBy([
                ['distance_km', 'asc'],
                ['code', 'asc'],
            ])
            ->values();

        return $matches->pluck('code')->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadAirlinesFromDatabase(): array
    {
        return DB::table('airlines')
            ->orderBy('code')
            ->get(['code', 'name'])
            ->map(fn (object $airline): array => [
                'code' => $airline->code,
                'name' => $airline->name,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadAirportsFromDatabase(): array
    {
        return DB::table('airports')
            ->orderBy('code')
            ->get([
                'code',
                'city_code',
                'name',
                'city',
                'country_code',
                'region_code',
                'latitude',
                'longitude',
                'timezone',
            ])
            ->map(fn (object $airport): array => [
                'code' => $airport->code,
                'city_code' => $airport->city_code,
                'name' => $airport->name,
                'city' => $airport->city,
                'country_code' => $airport->country_code,
                'region_code' => $airport->region_code ?? '',
                'latitude' => (float) $airport->latitude,
                'longitude' => (float) $airport->longitude,
                'timezone' => $airport->timezone,
            ])
            ->all();
    }

    /**
     * @param list<string>|null $departureAirportCodes
     * @return array<int, array<string, mixed>>
     */
    private function loadFlightsFromDatabase(?array $departureAirportCodes = null): array
    {
        $query = DB::table('flights')
            ->orderBy('departure_airport_code')
            ->orderBy('departure_time')
            ->orderBy('airline_code');

        if ($departureAirportCodes !== null) {
            if ($departureAirportCodes === []) {
                return [];
            }

            $query->whereIn('departure_airport_code', $departureAirportCodes);
        }

        return $query
            ->get([
                'airline_code',
                'number',
                'departure_airport_code',
                'departure_time',
                'arrival_airport_code',
                'arrival_time',
                'price',
            ])
            ->map(fn (object $flight): array => [
                'airline' => $flight->airline_code,
                'number' => $flight->number,
                'departure_airport' => $flight->departure_airport_code,
                'departure_time' => substr((string) $flight->departure_time, 0, 5),
                'arrival_airport' => $flight->arrival_airport_code,
                'arrival_time' => substr((string) $flight->arrival_time, 0, 5),
                'price' => number_format((float) $flight->price, 2, '.', ''),
            ])
            ->all();
    }

    /**
     * @param array<string, array<string, mixed>> $airportsByCode
     * @return list<array{from: string, to: string, weight: int}>
     */
    private function loadRouteSummariesFromDatabase(array $airportsByCode): array
    {
        $routes = [];
        $rows = DB::table('flights')
            ->select([
                'departure_airport_code',
                'arrival_airport_code',
                DB::raw('MIN(price) as minimum_price'),
            ])
            ->groupBy('departure_airport_code', 'arrival_airport_code')
            ->orderBy('departure_airport_code')
            ->orderBy('arrival_airport_code')
            ->cursor();

        foreach ($rows as $row) {
            $from = $row->departure_airport_code;
            $to = $row->arrival_airport_code;

            if (! isset($airportsByCode[$from], $airportsByCode[$to])) {
                continue;
            }

            $estimatedDurationMinutes = max(30, (int) round($this->distanceKm(
                (float) $airportsByCode[$from]['latitude'],
                (float) $airportsByCode[$from]['longitude'],
                (float) $airportsByCode[$to]['latitude'],
                (float) $airportsByCode[$to]['longitude'],
            ) / 850 * 60));

            $routes[] = [
                'from' => $from,
                'to' => $to,
                'weight' => $this->priceToCents($row->minimum_price)
                    + ($estimatedDurationMinutes * self::BEST_DURATION_MINUTE_WEIGHT_CENTS)
                    + self::BEST_CONNECTION_PENALTY_CENTS,
            ];
        }

        return $routes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadFlightsForRouteFromDatabase(string $from, string $to, int $limit, ?string $airline): array
    {
        if ($limit < 1) {
            return [];
        }

        $flights = $this->routeFlightQuery($from, $to)
            ->limit($limit)
            ->get([
                'airline_code',
                'number',
                'departure_airport_code',
                'departure_time',
                'arrival_airport_code',
                'arrival_time',
                'price',
            ])
            ->map(fn (object $flight): array => $this->formatFlightRow($flight))
            ->all();

        if ($airline !== null) {
            $preferredFlights = $this->routeFlightQuery($from, $to)
                ->where('airline_code', strtoupper($airline))
                ->limit($limit)
                ->get([
                    'airline_code',
                    'number',
                    'departure_airport_code',
                    'departure_time',
                    'arrival_airport_code',
                    'arrival_time',
                    'price',
                ])
                ->map(fn (object $flight): array => $this->formatFlightRow($flight))
                ->all();

            foreach ($preferredFlights as $flight) {
                $flights[] = $flight;
            }
        }

        $uniqueFlights = [];
        foreach ($flights as $flight) {
            $uniqueFlights[$flight['airline'].'|'.$flight['number']] = $flight;
        }

        return array_values($uniqueFlights);
    }

    private function routeFlightQuery(string $from, string $to): Builder
    {
        return DB::table('flights')
            ->where('departure_airport_code', strtoupper($from))
            ->where('arrival_airport_code', strtoupper($to))
            ->orderBy('price')
            ->orderBy('departure_time')
            ->orderBy('airline_code')
            ->orderBy('number');
    }

    private function formatFlightRow(object $flight): array
    {
        return [
            'airline' => $flight->airline_code,
            'number' => $flight->number,
            'departure_airport' => $flight->departure_airport_code,
            'departure_time' => substr((string) $flight->departure_time, 0, 5),
            'arrival_airport' => $flight->arrival_airport_code,
            'arrival_time' => substr((string) $flight->arrival_time, 0, 5),
            'price' => number_format((float) $flight->price, 2, '.', ''),
        ];
    }

    private function priceToCents(mixed $price): int
    {
        return (int) round((float) $price * 100);
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
}
