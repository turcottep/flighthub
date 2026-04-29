<?php

namespace App\Console\Commands;

use Generator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportTripData extends Command
{
    protected $signature = 'trip-data:import
        {path=data/generated/trip_data_full.json : Generated trip data JSON path}
        {--fresh : Truncate trip builder tables before importing}
        {--chunk=1000 : Insert chunk size}';

    protected $description = 'Import generated airlines, airports, and recurring flight templates into the database.';

    public function handle(): int
    {
        $path = base_path($this->argument('path'));
        $chunkSize = max(1, (int) $this->option('chunk'));

        if (! is_file($path)) {
            $this->error("Trip data file does not exist: {$path}");

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->truncateTripData();
        }

        $this->importAirlines($path, $chunkSize);
        $this->importAirports($path, $chunkSize);
        $this->importFlights($path, $chunkSize);

        $this->newLine();
        $this->components->info('Trip data import complete.');

        return self::SUCCESS;
    }

    private function truncateTripData(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('TRUNCATE trip_flights, trips, flights, airports, airlines RESTART IDENTITY CASCADE');

            return;
        }

        DB::table('trip_flights')->delete();
        DB::table('trips')->delete();
        DB::table('flights')->delete();
        DB::table('airports')->delete();
        DB::table('airlines')->delete();
    }

    private function importAirlines(string $path, int $chunkSize): void
    {
        $count = $this->importSection(
            $path,
            'airlines',
            $chunkSize,
            fn (array $airline): array => [
                'code' => $airline['code'],
                'name' => $airline['name'],
            ],
            fn (array $rows) => $this->upsertAirlines($rows),
        );
        $this->line(" Imported {$count} airlines.");
    }

    private function importAirports(string $path, int $chunkSize): void
    {
        $count = $this->importSection(
            $path,
            'airports',
            $chunkSize,
            fn (array $airport): array => [
                'code' => $airport['code'],
                'city_code' => $airport['city_code'],
                'name' => $airport['name'],
                'city' => $airport['city'],
                'country_code' => $airport['country_code'],
                'region_code' => $airport['region_code'] !== '' ? $airport['region_code'] : null,
                'latitude' => $airport['latitude'],
                'longitude' => $airport['longitude'],
                'timezone' => $airport['timezone'],
            ],
            fn (array $rows) => $this->upsertAirports($rows),
        );
        $this->line(" Imported {$count} airports.");
    }

    private function importFlights(string $path, int $chunkSize): void
    {
        $count = $this->importSection(
            $path,
            'flights',
            $chunkSize,
            fn (array $flight): array => [
                'airline_code' => $flight['airline'],
                'number' => $flight['number'],
                'departure_airport_code' => $flight['departure_airport'],
                'departure_time' => $flight['departure_time'],
                'arrival_airport_code' => $flight['arrival_airport'],
                'arrival_time' => $flight['arrival_time'],
                'price' => $flight['price'],
            ],
            fn (array $rows) => $this->upsertFlights($rows),
        );
        $this->line(" Imported {$count} flights.");
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $map
     * @param callable(list<array<string, mixed>>): void $flush
     */
    private function importSection(string $path, string $section, int $chunkSize, callable $map, callable $flush): int
    {
        $rows = [];
        $count = 0;
        $bar = $this->output->createProgressBar();
        $bar->start();

        foreach ($this->streamSection($path, $section) as $item) {
            $rows[] = $map($item);
            $count++;

            if (count($rows) >= $chunkSize) {
                $flush($rows);
                $rows = [];
            }

            $bar->advance();
        }

        if ($rows !== []) {
            $flush($rows);
        }

        $bar->finish();

        return $count;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function upsertAirlines(array $rows): void
    {
        DB::table('airlines')->upsert($rows, ['code'], ['name']);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function upsertAirports(array $rows): void
    {
        DB::table('airports')->upsert($rows, ['code'], [
            'city_code',
            'name',
            'city',
            'country_code',
            'region_code',
            'latitude',
            'longitude',
            'timezone',
        ]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function upsertFlights(array $rows): void
    {
        DB::table('flights')->upsert($rows, ['airline_code', 'number'], [
            'departure_airport_code',
            'departure_time',
            'arrival_airport_code',
            'arrival_time',
            'price',
        ]);
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function streamSection(string $path, string $section): Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Could not open {$path}");
        }

        try {
            $this->seekToSectionArray($handle, $section);

            $buffer = '';
            $depth = 0;
            $inString = false;
            $escaped = false;

            while (($char = fgetc($handle)) !== false) {
                if ($depth === 0) {
                    if ($char === ']') {
                        break;
                    }

                    if ($char !== '{') {
                        continue;
                    }

                    $buffer = '{';
                    $depth = 1;
                    continue;
                }

                $buffer .= $char;

                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($char === '\\' && $inString) {
                    $escaped = true;
                    continue;
                }

                if ($char === '"') {
                    $inString = ! $inString;
                    continue;
                }

                if ($inString) {
                    continue;
                }

                if ($char === '{') {
                    $depth++;
                } elseif ($char === '}') {
                    $depth--;

                    if ($depth === 0) {
                        $decoded = json_decode($buffer, true, flags: JSON_THROW_ON_ERROR);

                        if (! is_array($decoded)) {
                            throw new \RuntimeException("Invalid object in {$section} section.");
                        }

                        yield $decoded;
                        $buffer = '';
                    }
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     */
    private function seekToSectionArray($handle, string $section): void
    {
        $target = "\"{$section}\"";
        $matched = 0;
        $targetLength = strlen($target);

        while (($char = fgetc($handle)) !== false) {
            if ($char === $target[$matched]) {
                $matched++;

                if ($matched === $targetLength) {
                    break;
                }

                continue;
            }

            $matched = $char === $target[0] ? 1 : 0;
        }

        if ($matched !== $targetLength) {
            throw new \RuntimeException("Could not find {$section} section.");
        }

        while (($char = fgetc($handle)) !== false) {
            if ($char === '[') {
                return;
            }
        }

        throw new \RuntimeException("Could not find {$section} array.");
    }
}
