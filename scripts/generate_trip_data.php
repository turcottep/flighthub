#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Build Trip Builder seed data from OpenFlights plus lightweight enrichment.
 *
 * OpenFlights provides airlines, airports, and route pairs. It does not provide
 * scheduled flight numbers, times, or prices, so those are generated
 * deterministically from each route.
 */

const DEFAULT_OUTPUT = __DIR__ . '/../data/generated/trip_data.json';
const DEFAULT_MAX_ROUTES = 5000;

$options = getopt('', [
    'raw-dir::',
    'output::',
    'max-routes::',
    'airline::',
    'country::',
    'frequency::',
    'help',
]);

if (isset($options['help'])) {
    fwrite(STDOUT, <<<TXT
Usage:
  php scripts/generate_trip_data.php [options]

Options:
  --raw-dir=PATH      Directory containing downloaded raw data. Default: data/raw
  --output=PATH       Output JSON path. Default: data/generated/trip_data.json
  --max-routes=N      Maximum routes to turn into flights. Default: 5000, use 0 for all
  --airline=CODE      Restrict routes to one airline IATA code, e.g. AC
  --country=CODE      Restrict airports/routes to one ISO country code, e.g. CA
  --frequency=MODE    Flight templates per route: single or realistic. Default: single
  --help              Show this help

TXT);
    exit(0);
}

$rawDir = $options['raw-dir'] ?? (__DIR__ . '/../data/raw');
$outputPath = $options['output'] ?? DEFAULT_OUTPUT;
$maxRoutes = isset($options['max-routes']) ? max(0, (int) $options['max-routes']) : DEFAULT_MAX_ROUTES;
$airlineFilter = isset($options['airline']) ? strtoupper((string) $options['airline']) : null;
$countryFilter = isset($options['country']) ? strtoupper((string) $options['country']) : null;
$frequencyMode = $options['frequency'] ?? 'single';

if (! in_array($frequencyMode, ['single', 'realistic'], true)) {
    fail('Invalid --frequency value. Expected single or realistic.');
}

$paths = [
    'airlines' => $rawDir . '/openflights_airlines.dat',
    'airports' => $rawDir . '/openflights_airports.dat',
    'routes' => $rawDir . '/openflights_routes.dat',
    'countries' => $rawDir . '/openflights_countries.dat',
    'ourairports' => $rawDir . '/ourairports_airports.csv',
];

foreach (['airlines', 'airports', 'routes'] as $required) {
    if (!is_readable($paths[$required])) {
        fail("Missing required raw file: {$paths[$required]}");
    }
}

$countryNameToCode = is_readable($paths['countries']) ? loadCountryCodes($paths['countries']) : [];
$airportEnrichment = is_readable($paths['ourairports']) ? loadOurAirports($paths['ourairports']) : [];
$airlines = loadAirlines($paths['airlines']);
$airports = loadAirports($paths['airports'], $countryNameToCode, $airportEnrichment);
$routes = loadRoutes($paths['routes']);

$selectedRoutes = [];
$selectedAirportCodes = [];
$selectedAirlineCodes = [];

foreach ($routes as $route) {
    if ($airlineFilter !== null && $route['airline'] !== $airlineFilter) {
        continue;
    }

    if (!isset($airlines[$route['airline']], $airports[$route['source']], $airports[$route['destination']])) {
        continue;
    }

    if ($countryFilter !== null) {
        $sourceCountry = $airports[$route['source']]['country_code'];
        $destinationCountry = $airports[$route['destination']]['country_code'];
        if ($sourceCountry !== $countryFilter || $destinationCountry !== $countryFilter) {
            continue;
        }
    }

    $selectedRoutes[] = $route;
    $selectedAirportCodes[$route['source']] = true;
    $selectedAirportCodes[$route['destination']] = true;
    $selectedAirlineCodes[$route['airline']] = true;

    if ($maxRoutes > 0 && count($selectedRoutes) >= $maxRoutes) {
        break;
    }
}

$outputAirlines = [];
foreach (array_keys($selectedAirlineCodes) as $code) {
    $outputAirlines[] = $airlines[$code];
}
usort($outputAirlines, fn (array $a, array $b): int => $a['code'] <=> $b['code']);

$outputAirports = [];
foreach (array_keys($selectedAirportCodes) as $code) {
    $outputAirports[] = $airports[$code];
}
usort($outputAirports, fn (array $a, array $b): int => $a['code'] <=> $b['code']);

$outputDirectory = dirname($outputPath);
if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
    fail("Could not create output directory: {$outputDirectory}");
}

$flightCount = writeGeneratedData($outputPath, $outputAirlines, $outputAirports, $selectedRoutes, $airports, $frequencyMode);

fwrite(STDOUT, sprintf(
    "Wrote %s with %d airlines, %d airports, %d flights.\n",
    $outputPath,
    count($outputAirlines),
    count($outputAirports),
    $flightCount
));

/**
 * @param list<array{code: string, name: string}> $airlines
 * @param list<array<string, mixed>> $airports
 * @param list<array{airline: string, source: string, destination: string}> $routes
 * @param array<string, array<string, mixed>> $airportsByCode
 */
function writeGeneratedData(
    string $outputPath,
    array $airlines,
    array $airports,
    array $routes,
    array $airportsByCode,
    string $frequencyMode,
): int {
    $handle = fopen($outputPath, 'wb');
    if ($handle === false) {
        fail("Could not open output file for writing: {$outputPath}");
    }

    fwrite($handle, "{\n");
    writeJsonArray($handle, 'airlines', $airlines, 2);
    fwrite($handle, ",\n");
    writeJsonArray($handle, 'airports', $airports, 2);
    fwrite($handle, ",\n  \"flights\": [\n");

    $flightCountersByAirline = [];
    $flightCount = 0;

    foreach ($routes as $route) {
        $source = $airportsByCode[$route['source']];
        $destination = $airportsByCode[$route['destination']];
        $distanceKm = distanceKm($source['latitude'], $source['longitude'], $destination['latitude'], $destination['longitude']);
        $frequency = flightsPerRoute($route, $source, $destination, $distanceKm, $frequencyMode);

        for ($sequence = 0; $sequence < $frequency; $sequence++) {
            $airline = $route['airline'];
            $flightCountersByAirline[$airline] = ($flightCountersByAirline[$airline] ?? 100) + 1;

            $departureTime = generatedDepartureTime($route, $sequence, $frequency);
            $durationMinutes = generatedDurationMinutes($distanceKm);
            $arrivalTime = localArrivalTime($departureTime, $durationMinutes, $source['timezone'], $destination['timezone']);

            $flight = [
                'airline' => $airline,
                'number' => (string) $flightCountersByAirline[$airline],
                'departure_airport' => $route['source'],
                'departure_time' => $departureTime,
                'arrival_airport' => $route['destination'],
                'arrival_time' => $arrivalTime,
                'price' => generatedPrice($distanceKm, $sequence),
            ];

            if ($flightCount > 0) {
                fwrite($handle, ",\n");
            }

            fwrite($handle, '    ' . jsonEncode($flight));
            $flightCount++;
        }
    }

    fwrite($handle, "\n  ]\n}\n");
    fclose($handle);

    return $flightCount;
}

/**
 * @param list<array<string, mixed>> $items
 */
function writeJsonArray($handle, string $key, array $items, int $indent): void
{
    $prefix = str_repeat(' ', $indent);
    $itemPrefix = str_repeat(' ', $indent + 2);

    fwrite($handle, "{$prefix}\"{$key}\": [");
    if ($items === []) {
        fwrite($handle, ']');
        return;
    }

    fwrite($handle, "\n");
    foreach ($items as $index => $item) {
        if ($index > 0) {
            fwrite($handle, ",\n");
        }

        fwrite($handle, $itemPrefix . jsonEncode($item));
    }

    fwrite($handle, "\n{$prefix}]");
}

/**
 * @param array<string, mixed> $item
 */
function jsonEncode(array $item): string
{
    $json = json_encode($item, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        fail('Could not encode generated data: ' . json_last_error_msg());
    }

    return $json;
}

/**
 * @return array<string, array{code: string, name: string}>
 */
function loadAirlines(string $path): array
{
    $airlines = [];
    foreach (csvRows($path) as $row) {
        if (count($row) < 8) {
            continue;
        }

        $iata = cleanCode($row[3] ?? '');
        $name = cleanValue($row[1] ?? '');
        $active = strtoupper(cleanValue($row[7] ?? ''));

        if (!isIataCode($iata, 2) || $name === '' || $active === 'N') {
            continue;
        }

        $airlines[$iata] = [
            'code' => $iata,
            'name' => $name,
        ];
    }

    return $airlines;
}

/**
 * @param array<string, string> $countryNameToCode
 * @param array<string, array{country_code: string, region_code: string, timezone: string}> $airportEnrichment
 * @return array<string, array<string, mixed>>
 */
function loadAirports(string $path, array $countryNameToCode, array $airportEnrichment): array
{
    $airports = [];
    foreach (csvRows($path) as $row) {
        if (count($row) < 14) {
            continue;
        }

        $iata = cleanCode($row[4] ?? '');
        if (!isIataCode($iata, 3)) {
            continue;
        }

        $countryName = cleanValue($row[3] ?? '');
        $enrichment = $airportEnrichment[$iata] ?? null;
        $countryCode = $enrichment['country_code'] ?? ($countryNameToCode[$countryName] ?? '');
        $regionCode = $enrichment['region_code'] ?? '';
        $timezoneValue = ($enrichment !== null && $enrichment['timezone'] !== '')
            ? $enrichment['timezone']
            : cleanValue($row[11] ?? '');
        $timezone = timezoneForAirport($iata, cleanTimezone($timezoneValue));

        $airports[$iata] = [
            'code' => $iata,
            'city_code' => cityCodeForAirport($iata),
            'name' => cleanAirportName(cleanValue($row[1] ?? '')),
            'city' => cleanValue($row[2] ?? ''),
            'country_code' => $countryCode,
            'region_code' => $regionCode,
            'latitude' => (float) ($row[6] ?? 0),
            'longitude' => (float) ($row[7] ?? 0),
            'timezone' => $timezone,
        ];
    }

    return $airports;
}

/**
 * @return array<int, array{airline: string, source: string, destination: string}>
 */
function loadRoutes(string $path): array
{
    $routes = [];
    $seen = [];

    foreach (csvRows($path) as $row) {
        if (count($row) < 9) {
            continue;
        }

        $airline = cleanCode($row[0] ?? '');
        $source = cleanCode($row[2] ?? '');
        $destination = cleanCode($row[4] ?? '');
        $stops = cleanValue($row[7] ?? '');

        if (!isIataCode($airline, 2) || !isIataCode($source, 3) || !isIataCode($destination, 3) || $stops !== '0') {
            continue;
        }

        $key = "{$airline}:{$source}:{$destination}";
        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $routes[] = [
            'airline' => $airline,
            'source' => $source,
            'destination' => $destination,
        ];
    }

    usort($routes, function (array $a, array $b): int {
        return [$a['airline'], $a['source'], $a['destination']] <=> [$b['airline'], $b['source'], $b['destination']];
    });

    return $routes;
}

/**
 * @return array<string, string>
 */
function loadCountryCodes(string $path): array
{
    $countries = [];
    foreach (csvRows($path) as $row) {
        if (count($row) < 2) {
            continue;
        }

        $name = cleanValue($row[0] ?? '');
        $isoCode = cleanCode($row[1] ?? '');
        if ($name !== '' && strlen($isoCode) === 2) {
            $countries[$name] = $isoCode;
        }
    }

    return $countries;
}

/**
 * @return array<string, array{country_code: string, region_code: string, timezone: string}>
 */
function loadOurAirports(string $path): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return [];
    }

    $header = fgetcsv($handle, null, ',', '"', '');
    if ($header === false) {
        fclose($handle);
        return [];
    }

    $indexes = array_flip($header);
    $airports = [];

    while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
        $iata = cleanCode($row[$indexes['iata_code']] ?? '');
        if (!isIataCode($iata, 3)) {
            continue;
        }

        $isoRegion = cleanValue($row[$indexes['iso_region']] ?? '');
        $regionParts = explode('-', $isoRegion, 2);

        $airports[$iata] = [
            'country_code' => cleanCode($row[$indexes['iso_country']] ?? ''),
            'region_code' => $regionParts[1] ?? '',
            'timezone' => '',
        ];
    }

    fclose($handle);

    return $airports;
}

/**
 * @return Generator<int, array<int, string>>
 */
function csvRows(string $path): Generator
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        fail("Could not open {$path}");
    }

    while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
        yield $row;
    }

    fclose($handle);
}

function cleanValue(string $value): string
{
    $trimmed = trim($value);
    return $trimmed === '\\N' ? '' : $trimmed;
}

function cleanCode(string $value): string
{
    return strtoupper(cleanValue($value));
}

function isIataCode(string $value, int $length): bool
{
    return (bool) preg_match('/^[A-Z0-9]{' . $length . '}$/', $value);
}

function cleanAirportName(string $name): string
{
    $name = preg_replace('/^.+\s\/\s/', '', $name) ?? $name;
    return preg_replace('/\s+Airport$/', '', $name) ?? $name;
}

function cleanTimezone(string $timezone): string
{
    try {
        new DateTimeZone($timezone);
        return $timezone;
    } catch (Exception) {
        return 'UTC';
    }
}

function timezoneForAirport(string $airportCode, string $timezone): string
{
    $overrides = [
        'YUL' => 'America/Montreal',
        'YHU' => 'America/Montreal',
    ];

    return cleanTimezone($overrides[$airportCode] ?? $timezone);
}

function cityCodeForAirport(string $airportCode): string
{
    $metroCodes = [
        'YUL' => 'YMQ',
        'YHU' => 'YMQ',
        'YYZ' => 'YTO',
        'YTZ' => 'YTO',
        'LGA' => 'NYC',
        'JFK' => 'NYC',
        'EWR' => 'NYC',
        'LHR' => 'LON',
        'LGW' => 'LON',
        'LCY' => 'LON',
        'LTN' => 'LON',
        'STN' => 'LON',
        'ORY' => 'PAR',
        'CDG' => 'PAR',
        'BVA' => 'PAR',
        'HND' => 'TYO',
        'NRT' => 'TYO',
        'ITM' => 'OSA',
        'KIX' => 'OSA',
        'GMP' => 'SEL',
        'ICN' => 'SEL',
        'SHA' => 'SHA',
        'PVG' => 'SHA',
        'PEK' => 'BJS',
        'PKX' => 'BJS',
        'FCO' => 'ROM',
        'CIA' => 'ROM',
        'MXP' => 'MIL',
        'LIN' => 'MIL',
        'BGY' => 'MIL',
        'DCA' => 'WAS',
        'IAD' => 'WAS',
        'BWI' => 'WAS',
        'ORD' => 'CHI',
        'MDW' => 'CHI',
        'SFO' => 'QSF',
        'OAK' => 'QSF',
        'SJC' => 'QSF',
        'LAX' => 'LAX',
        'BUR' => 'LAX',
        'LGB' => 'LAX',
        'SNA' => 'LAX',
        'MIA' => 'MIA',
        'FLL' => 'MIA',
        'PBI' => 'MIA',
    ];

    return $metroCodes[$airportCode] ?? $airportCode;
}

function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadiusKm = 6371.0;
    $latDelta = deg2rad($lat2 - $lat1);
    $lonDelta = deg2rad($lon2 - $lon1);

    $a = sin($latDelta / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;

    return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * @param array{airline: string, source: string, destination: string} $route
 */
/**
 * @param array{airline: string, source: string, destination: string} $route
 * @param array<string, mixed> $source
 * @param array<string, mixed> $destination
 */
function flightsPerRoute(array $route, array $source, array $destination, float $distanceKm, string $frequencyMode): int
{
    if ($frequencyMode === 'single') {
        return 1;
    }

    if ($distanceKm < 500) {
        $frequency = 4;
    } elseif ($distanceKm < 1500) {
        $frequency = 3;
    } elseif ($distanceKm < 3500) {
        $frequency = 2;
    } else {
        $frequency = 1;
    }

    if (($source['country_code'] ?? '') !== ($destination['country_code'] ?? '')) {
        $frequency = max(1, $frequency - 1);
    }

    if (isMajorAirport($route['source']) && isMajorAirport($route['destination'])) {
        $frequency++;
    }

    if (unsignedCrc32("freq:{$route['airline']}:{$route['source']}:{$route['destination']}") % 5 === 0) {
        $frequency++;
    }

    return min(6, max(1, $frequency));
}

function isMajorAirport(string $airportCode): bool
{
    static $majorAirports = [
        'ATL', 'PEK', 'PVG', 'LAX', 'DXB', 'HND', 'ORD', 'LHR', 'CDG', 'DFW',
        'CAN', 'AMS', 'FRA', 'IST', 'DEN', 'SIN', 'ICN', 'BKK', 'JFK', 'SFO',
        'SEA', 'LAS', 'MIA', 'YYZ', 'YVR', 'YUL', 'MEX', 'GRU', 'SYD', 'MEL',
        'MAD', 'BCN', 'FCO', 'MUC', 'ZRH', 'DOH', 'AUH', 'KUL', 'HKG', 'NRT',
    ];

    return in_array($airportCode, $majorAirports, true);
}

function generatedDepartureTime(array $route, int $sequence = 0, int $frequency = 1): string
{
    $hash = unsignedCrc32("{$route['airline']}:{$route['source']}:{$route['destination']}");
    $baseSlot = $hash % 144; // 12 hours of five-minute slots.
    $spreadMinutes = $frequency > 1 ? (int) floor((16 * 60) / $frequency) : 0;
    $minutesAfterMidnight = 5 * 60 + ($baseSlot * 5) + ($sequence * $spreadMinutes);

    return formatMinutes($minutesAfterMidnight);
}

function generatedDurationMinutes(float $distanceKm): int
{
    if ($distanceKm < 400) {
        $cruiseSpeedKmH = 500;
    } elseif ($distanceKm < 1500) {
        $cruiseSpeedKmH = 650;
    } else {
        $cruiseSpeedKmH = 850;
    }

    $gateToGateMinutes = 30 + (($distanceKm / $cruiseSpeedKmH) * 60);
    return max(35, (int) round($gateToGateMinutes / 5) * 5);
}

function localArrivalTime(string $departureTime, int $durationMinutes, string $sourceTimezone, string $destinationTimezone): string
{
    [$hour, $minute] = array_map('intval', explode(':', $departureTime));
    $departure = (new DateTimeImmutable('2026-01-15 00:00:00', new DateTimeZone($sourceTimezone)))
        ->setTime($hour, $minute);
    $arrival = $departure
        ->modify("+{$durationMinutes} minutes")
        ->setTimezone(new DateTimeZone($destinationTimezone));

    return $arrival->format('H:i');
}

function generatedPrice(float $distanceKm, int $sequence = 0): string
{
    $price = 49 + ($distanceKm * 0.11);
    if ($distanceKm > 2500) {
        $price += 65;
    }
    if ($distanceKm > 6000) {
        $price += 125;
    }

    $price *= 1 + (($sequence % 4) * 0.04);

    return number_format(round($price, 2), 2, '.', '');
}

function formatMinutes(int $minutesAfterMidnight): string
{
    $minutesAfterMidnight %= 24 * 60;
    $hour = intdiv($minutesAfterMidnight, 60);
    $minute = $minutesAfterMidnight % 60;

    return sprintf('%02d:%02d', $hour, $minute);
}

function unsignedCrc32(string $value): int
{
    return (int) sprintf('%u', crc32($value));
}

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
