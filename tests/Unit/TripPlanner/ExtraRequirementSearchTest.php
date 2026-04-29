<?php

namespace Tests\Unit\TripPlanner;

use App\Services\TripPlanner;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\BuildsFlightData;

class ExtraRequirementSearchTest extends TestCase
{
    use BuildsFlightData;

    public function test_it_supports_departure_and_arrival_airports_near_requested_coordinates(): void
    {
        $planner = new TripPlanner($this->data($this->easternCanadaAirports(), [
            $this->flight('AC', '100', 'YHU', '08:00', 'YTZ', '09:00', '100.00'),
            $this->flight('AC', '200', 'YUL', '08:30', 'YVR', '10:30', '300.00'),
        ]), new DateTimeImmutable('2026-04-29 00:00:00 UTC'));

        $results = $planner->searchOneWayNear(
            originLatitude: 45.457714,
            originLongitude: -73.749908,
            destinationLatitude: 43.6532,
            destinationLongitude: -79.3832,
            departureDate: '2026-05-01',
            options: ['radius_km' => 30],
        );

        $this->assertCount(1, $results);
        $this->assertSame('YHU', $results[0]['origin']);
        $this->assertSame('YTZ', $results[0]['destination']);
    }

    public function test_it_expands_city_codes_to_matching_airports(): void
    {
        $planner = new TripPlanner($this->data($this->easternCanadaAirports(), [
            $this->flight('AC', '100', 'YHU', '08:00', 'YTZ', '09:00', '100.00'),
            $this->flight('AC', '200', 'YUL', '08:30', 'YYZ', '09:30', '120.00'),
        ]), new DateTimeImmutable('2026-04-29 00:00:00 UTC'));

        $results = $planner->searchOneWay('YMQ', 'YTO', '2026-05-01');

        $this->assertCount(2, $results);
        $this->assertSame(['YHU', 'YUL'], array_column($results, 'origin'));
        $this->assertSame(['YTZ', 'YYZ'], array_column($results, 'destination'));
    }

    public function test_it_supports_open_jaw_trips(): void
    {
        $planner = new TripPlanner($this->data($this->easternCanadaAirports(), [
            $this->flight('AC', '100', 'YUL', '08:00', 'YVR', '10:00', '100.00'),
            $this->flight('AC', '200', 'LAX', '12:00', 'YUL', '20:00', '150.00'),
        ]), new DateTimeImmutable('2026-04-29 00:00:00 UTC'));

        $results = $planner->searchOpenJaw(
            origin: 'YUL',
            outboundDestination: 'YVR',
            returnOrigin: 'LAX',
            finalDestination: 'YUL',
            departureDate: '2026-05-01',
            returnDate: '2026-05-08',
        );

        $this->assertCount(1, $results);
        $this->assertSame('open_jaw', $results[0]['type']);
        $this->assertSame('250.00', $results[0]['total_price']);
    }

    public function test_it_supports_multi_city_trips_up_to_five_one_way_legs(): void
    {
        $planner = new TripPlanner($this->data($this->easternCanadaAirports(), [
            $this->flight('AC', '100', 'YUL', '08:00', 'YYZ', '09:00', '100.00'),
            $this->flight('AC', '200', 'YYZ', '10:00', 'YVR', '12:00', '100.00'),
            $this->flight('AC', '300', 'YVR', '13:00', 'LAX', '16:00', '100.00'),
        ]), new DateTimeImmutable('2026-04-29 00:00:00 UTC'));

        $results = $planner->searchMultiCity([
            ['origin' => 'YUL', 'destination' => 'YYZ', 'departure_date' => '2026-05-01'],
            ['origin' => 'YYZ', 'destination' => 'YVR', 'departure_date' => '2026-05-03'],
            ['origin' => 'YVR', 'destination' => 'LAX', 'departure_date' => '2026-05-05'],
        ]);

        $this->assertCount(1, $results);
        $this->assertSame('multi_city', $results[0]['type']);
        $this->assertSame('300.00', $results[0]['total_price']);
        $this->assertCount(3, $results[0]['legs']);
    }

    public function test_multi_city_rejects_more_than_five_legs(): void
    {
        $planner = new TripPlanner($this->data($this->easternCanadaAirports(), []));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('up to 5');

        $planner->searchMultiCity([
            ['origin' => 'YUL', 'destination' => 'YYZ', 'departure_date' => '2026-05-01'],
            ['origin' => 'YYZ', 'destination' => 'YVR', 'departure_date' => '2026-05-02'],
            ['origin' => 'YVR', 'destination' => 'LAX', 'departure_date' => '2026-05-03'],
            ['origin' => 'LAX', 'destination' => 'YVR', 'departure_date' => '2026-05-04'],
            ['origin' => 'YVR', 'destination' => 'YYZ', 'departure_date' => '2026-05-05'],
            ['origin' => 'YYZ', 'destination' => 'YUL', 'departure_date' => '2026-05-06'],
        ]);
    }
}
