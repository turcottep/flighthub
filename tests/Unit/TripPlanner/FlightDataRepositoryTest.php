<?php

namespace Tests\Unit\TripPlanner;

use App\Services\FlightDataRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FlightDataRepositoryTest extends TestCase
{
    public function test_it_loads_the_sample_data_format(): void
    {
        $data = (new FlightDataRepository(__DIR__.'/../../../sample_data.json'))->load();

        $this->assertArrayHasKey('airlines', $data);
        $this->assertArrayHasKey('airports', $data);
        $this->assertArrayHasKey('flights', $data);
        $this->assertSame('AC', $data['airlines'][0]['code']);
        $this->assertSame('YUL', $data['flights'][0]['departure_airport']);
    }

    public function test_it_rejects_a_missing_file(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        (new FlightDataRepository(__DIR__.'/missing.json'))->load();
    }

    public function test_it_rejects_invalid_json(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'flight-data-');
        file_put_contents($path, '{not valid json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not valid JSON');

        try {
            (new FlightDataRepository($path))->load();
        } finally {
            unlink($path);
        }
    }

    public function test_it_rejects_missing_top_level_collections(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'flight-data-');
        file_put_contents($path, json_encode(['airports' => [], 'flights' => []]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('airlines');

        try {
            (new FlightDataRepository($path))->load();
        } finally {
            unlink($path);
        }
    }
}
