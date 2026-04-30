<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class FlightDataRepository
{
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
     * Return departure airport codes that can appear on a bounded path from origin to destination.
     *
     * @param list<string> $originAirportCodes
     * @param list<string> $destinationAirportCodes
     * @return list<string>
     */
    public function departureAirportCodesBetweenLocationsFromDatabase(array $originAirportCodes, array $destinationAirportCodes, int $maxSegments): array
    {
        $maxSegments = max(1, $maxSegments);
        $originAirportCodes = array_values(array_unique(array_map('strtoupper', $originAirportCodes)));
        $destinationAirportCodes = array_values(array_unique(array_map('strtoupper', $destinationAirportCodes)));
        $canReachDestinationWithin = $this->airportCodesThatCanReachDestinationByDepth($destinationAirportCodes, $maxSegments);
        $known = array_fill_keys($originAirportCodes, true);
        $frontier = $originAirportCodes;
        $departureAirportCodes = [];

        for ($depth = 0; $depth < $maxSegments; $depth++) {
            $remainingSegments = $maxSegments - $depth;

            foreach ($frontier as $airportCode) {
                if (isset($canReachDestinationWithin[$remainingSegments][$airportCode])) {
                    $departureAirportCodes[$airportCode] = true;
                }
            }

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

        return array_keys($departureAirportCodes);
    }

    /**
     * @param list<string> $destinationAirportCodes
     * @return array<int, array<string, true>>
     */
    private function airportCodesThatCanReachDestinationByDepth(array $destinationAirportCodes, int $maxSegments): array
    {
        $known = array_fill_keys($destinationAirportCodes, true);
        $frontier = array_keys($known);
        $byDepth = [0 => $known];

        for ($depth = 1; $depth <= $maxSegments; $depth++) {
            if ($frontier === []) {
                $byDepth[$depth] = $known;

                continue;
            }

            $previous = DB::table('flights')
                ->whereIn('arrival_airport_code', $frontier)
                ->distinct()
                ->pluck('departure_airport_code')
                ->all();

            $frontier = [];
            foreach ($previous as $airportCode) {
                if (! isset($known[$airportCode])) {
                    $known[$airportCode] = true;
                    $frontier[] = $airportCode;
                }
            }

            $byDepth[$depth] = $known;
        }

        return $byDepth;
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
