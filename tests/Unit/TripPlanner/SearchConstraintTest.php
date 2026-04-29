<?php

namespace Tests\Unit\TripPlanner;

use App\Services\TripPlanner;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\BuildsFlightData;

class SearchConstraintTest extends TestCase
{
    use BuildsFlightData;

    public function test_it_rejects_unknown_origin_airports(): void
    {
        $planner = new TripPlanner($this->data($this->easternCanadaAirports(), []));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown airport code: AAA');

        $planner->searchOneWay('AAA', 'YVR', '2026-05-01');
    }

    public function test_it_rejects_unknown_destination_airports(): void
    {
        $planner = new TripPlanner($this->data($this->easternCanadaAirports(), []));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown airport code: AAA');

        $planner->searchOneWay('YUL', 'AAA', '2026-05-01');
    }

    public function test_it_rejects_invalid_departure_dates(): void
    {
        $planner = new TripPlanner($this->data($this->easternCanadaAirports(), []));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected YYYY-MM-DD');

        $planner->searchOneWay('YUL', 'YVR', '2026-02-31');
    }

    public function test_it_rejects_invalid_sort_options(): void
    {
        $planner = new TripPlanner($this->data($this->easternCanadaAirports(), []));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sort must be');

        $planner->searchOneWay('YUL', 'YVR', '2026-05-01', ['sort' => 'comfort']);
    }

    public function test_trip_must_depart_after_creation_time_when_searching_today(): void
    {
        $planner = new TripPlanner($this->data($this->easternCanadaAirports(), [
            $this->flight('AC', '100', 'YUL', '08:00', 'YVR', '10:00', '100.00'),
            $this->flight('AC', '200', 'YUL', '12:00', 'YVR', '14:00', '200.00'),
        ]), new DateTimeImmutable('2026-05-01 10:00:00 America/Montreal'));

        $results = $planner->searchOneWay('YUL', 'YVR', '2026-05-01');

        $this->assertCount(1, $results);
        $this->assertSame('AC200', $results[0]['segments'][0]['flight_number']);
    }

    public function test_trip_must_depart_no_later_than_365_days_after_creation_time(): void
    {
        $planner = new TripPlanner($this->data($this->easternCanadaAirports(), [
            $this->flight('AC', '100', 'YUL', '08:00', 'YVR', '10:00', '100.00'),
        ]), new DateTimeImmutable('2026-04-29 00:00:00 America/Montreal'));

        $this->assertCount(1, $planner->searchOneWay('YUL', 'YVR', '2027-04-28'));
        $this->assertSame([], $planner->searchOneWay('YUL', 'YVR', '2027-04-30'));
    }

    public function test_overnight_flights_arrive_on_the_next_valid_arrival_date(): void
    {
        $planner = new TripPlanner($this->data($this->easternCanadaAirports(), [
            $this->flight('AC', '900', 'YVR', '23:30', 'YUL', '07:15', '150.00'),
        ]), new DateTimeImmutable('2026-04-29 00:00:00 UTC'));

        $results = $planner->searchOneWay('YVR', 'YUL', '2026-05-01');

        $this->assertCount(1, $results);
        $this->assertSame('2026-05-01T23:30:00-07:00', $results[0]['departure_at']);
        $this->assertSame('2026-05-02T07:15:00-04:00', $results[0]['arrival_at']);
    }

    public function test_spring_forward_dst_duration_uses_real_elapsed_time(): void
    {
        $airports = [
            $this->airport('JFK', 'America/New_York', 40.6413, -73.7781),
            $this->airport('BOS', 'America/New_York', 42.3656, -71.0096),
        ];
        $planner = new TripPlanner($this->data($airports, [
            $this->flight('AC', '100', 'JFK', '01:30', 'BOS', '03:30', '100.00'),
        ]), new DateTimeImmutable('2026-03-01 00:00:00 UTC'));

        $results = $planner->searchOneWay('JFK', 'BOS', '2026-03-08');

        $this->assertCount(1, $results);
        $this->assertSame('2026-03-08T01:30:00-05:00', $results[0]['departure_at']);
        $this->assertSame('2026-03-08T03:30:00-04:00', $results[0]['arrival_at']);
        $this->assertSame(60, $results[0]['duration_minutes']);
    }

    public function test_fall_back_dst_duration_uses_real_elapsed_time(): void
    {
        $airports = [
            $this->airport('JFK', 'America/New_York', 40.6413, -73.7781),
            $this->airport('BOS', 'America/New_York', 42.3656, -71.0096),
        ];
        $planner = new TripPlanner($this->data($airports, [
            $this->flight('AC', '100', 'JFK', '00:30', 'BOS', '02:30', '100.00'),
        ]), new DateTimeImmutable('2026-10-25 00:00:00 UTC'));

        $results = $planner->searchOneWay('JFK', 'BOS', '2026-11-01');

        $this->assertCount(1, $results);
        $this->assertSame('2026-11-01T00:30:00-04:00', $results[0]['departure_at']);
        $this->assertSame('2026-11-01T02:30:00-05:00', $results[0]['arrival_at']);
        $this->assertSame(180, $results[0]['duration_minutes']);
    }

    public function test_minimum_layover_rejects_too_tight_connections(): void
    {
        $planner = new TripPlanner($this->data($this->easternCanadaAirports(), [
            $this->flight('AC', '100', 'YUL', '08:00', 'YYZ', '09:00', '50.00'),
            $this->flight('AC', '200', 'YYZ', '09:30', 'YVR', '11:00', '50.00'),
            $this->flight('AC', '300', 'YUL', '12:00', 'YVR', '14:00', '300.00'),
        ]), new DateTimeImmutable('2026-04-29 00:00:00 UTC'));

        $results = $planner->searchOneWay('YUL', 'YVR', '2026-05-01', [
            'max_stops' => 1,
            'minimum_layover_minutes' => 45,
            'max_duration_hours' => 12,
        ]);

        $this->assertSame('AC300', $results[0]['segments'][0]['flight_number']);
        $this->assertSame(0, $results[0]['stops']);
    }

    public function test_max_duration_prunes_long_itineraries(): void
    {
        $planner = new TripPlanner($this->data($this->easternCanadaAirports(), [
            $this->flight('AC', '100', 'YUL', '08:00', 'YYZ', '09:00', '50.00'),
            $this->flight('AC', '200', 'YYZ', '23:00', 'YVR', '01:00', '50.00'),
            $this->flight('AC', '300', 'YUL', '12:00', 'YVR', '14:00', '300.00'),
        ]), new DateTimeImmutable('2026-04-29 00:00:00 UTC'));

        $results = $planner->searchOneWay('YUL', 'YVR', '2026-05-01', [
            'max_stops' => 1,
            'max_duration_hours' => 8,
        ]);

        $this->assertSame('AC300', $results[0]['segments'][0]['flight_number']);
    }

    public function test_it_rejects_negative_search_bounds_instead_of_clamping_them(): void
    {
        $planner = new TripPlanner($this->data($this->easternCanadaAirports(), []));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('max_stops');

        $planner->searchOneWay('YUL', 'YVR', '2026-05-01', [
            'max_stops' => -1,
        ]);
    }

    public function test_it_rejects_invalid_nearby_coordinates(): void
    {
        $planner = new TripPlanner($this->data($this->easternCanadaAirports(), []));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('latitude');

        $planner->searchOneWayNear(
            originLatitude: 100,
            originLongitude: -73,
            destinationLatitude: 45,
            destinationLongitude: -73,
            departureDate: '2026-05-01',
        );
    }

    public function test_it_rejects_negative_nearby_radius(): void
    {
        $planner = new TripPlanner($this->data($this->easternCanadaAirports(), []));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('radius_km');

        $planner->searchOneWayNear(
            originLatitude: 45,
            originLongitude: -73,
            destinationLatitude: 45,
            destinationLongitude: -73,
            departureDate: '2026-05-01',
            options: ['radius_km' => -1],
        );
    }

    public function test_round_trip_rejects_return_dates_before_departure_dates(): void
    {
        $planner = new TripPlanner($this->data($this->easternCanadaAirports(), []));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('return date');

        $planner->searchRoundTrip('YUL', 'YVR', '2026-05-10', '2026-05-01');
    }

    public function test_multi_city_rejects_legs_that_go_backwards_in_date_order(): void
    {
        $planner = new TripPlanner($this->data($this->easternCanadaAirports(), [
            $this->flight('AC', '100', 'YUL', '08:00', 'YYZ', '09:00', '100.00'),
        ]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('chronological');

        $planner->searchMultiCity([
            ['origin' => 'YUL', 'destination' => 'YYZ', 'departure_date' => '2026-05-10'],
            ['origin' => 'YYZ', 'destination' => 'YVR', 'departure_date' => '2026-05-01'],
        ]);
    }
}
