<?php

namespace Tests\Unit\TripPlanner;

use App\Services\TripPlanner;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Support\BuildsFlightData;

class OneWaySearchTest extends TestCase
{
    use BuildsFlightData;

    public function test_it_returns_a_direct_one_way_from_sample_data_with_timezone_aware_times(): void
    {
        $data = json_decode(file_get_contents(__DIR__.'/../../../sample_data.json'), true);
        $planner = new TripPlanner($data, new DateTimeImmutable('2026-04-29 12:00:00 America/Montreal'));

        $results = $planner->searchOneWay('YUL', 'YVR', '2026-05-01');

        $this->assertCount(1, $results);
        $this->assertSame('one_way', $results[0]['type']);
        $this->assertSame('273.23', $results[0]['total_price']);
        $this->assertSame(0, $results[0]['stops']);
        $this->assertSame('2026-05-01T07:35:00-04:00', $results[0]['departure_at']);
        $this->assertSame('2026-05-01T10:05:00-07:00', $results[0]['arrival_at']);
        $this->assertSame(330, $results[0]['duration_minutes']);
        $this->assertSame('AC301', $results[0]['segments'][0]['flight_number']);
    }

    public function test_it_can_return_a_cheaper_layover_before_a_direct_flight(): void
    {
        $planner = new TripPlanner($this->data($this->easternCanadaAirports(), [
            $this->flight('AC', '100', 'YUL', '08:00', 'YYZ', '09:00', '100.00'),
            $this->flight('AC', '200', 'YYZ', '10:00', 'YVR', '11:30', '80.00'),
            $this->flight('AC', '300', 'YUL', '12:00', 'YVR', '14:00', '300.00'),
        ]), new DateTimeImmutable('2026-04-29 00:00:00 UTC'));

        $results = $planner->searchOneWay('YUL', 'YVR', '2026-05-01', [
            'max_stops' => 1,
            'minimum_layover_minutes' => 45,
        ]);

        $this->assertSame('180.00', $results[0]['total_price']);
        $this->assertSame(1, $results[0]['stops']);
        $this->assertSame(['AC100', 'AC200'], array_column($results[0]['segments'], 'flight_number'));
    }

    public function test_it_can_connect_to_a_next_day_flight_when_the_daily_connection_is_missed(): void
    {
        $planner = new TripPlanner($this->data($this->easternCanadaAirports(), [
            $this->flight('AC', '100', 'YUL', '22:00', 'YYZ', '23:00', '100.00'),
            $this->flight('AC', '200', 'YYZ', '08:00', 'YVR', '10:00', '80.00'),
        ]), new DateTimeImmutable('2026-04-29 00:00:00 UTC'));

        $results = $planner->searchOneWay('YUL', 'YVR', '2026-05-01', [
            'max_stops' => 1,
            'minimum_layover_minutes' => 60,
        ]);

        $this->assertCount(1, $results);
        $this->assertSame('2026-05-02T08:00:00-04:00', $results[0]['segments'][1]['departure_at']);
    }

    public function test_airline_filter_only_returns_matching_segments(): void
    {
        $planner = new TripPlanner($this->data($this->easternCanadaAirports(), [
            $this->flight('AC', '100', 'YUL', '08:00', 'YVR', '10:00', '200.00'),
            $this->flight('WS', '500', 'YUL', '09:00', 'YVR', '11:00', '100.00'),
        ]), new DateTimeImmutable('2026-04-29 00:00:00 UTC'));

        $results = $planner->searchOneWay('YUL', 'YVR', '2026-05-01', [
            'airline' => 'AC',
        ]);

        $this->assertCount(1, $results);
        $this->assertSame('AC100', $results[0]['segments'][0]['flight_number']);
    }

    public function test_max_stops_zero_limits_results_to_direct_flights(): void
    {
        $planner = new TripPlanner($this->data($this->easternCanadaAirports(), [
            $this->flight('AC', '100', 'YUL', '08:00', 'YYZ', '09:00', '50.00'),
            $this->flight('AC', '200', 'YYZ', '10:00', 'YVR', '12:00', '50.00'),
            $this->flight('AC', '300', 'YUL', '13:00', 'YVR', '15:00', '300.00'),
        ]), new DateTimeImmutable('2026-04-29 00:00:00 UTC'));

        $results = $planner->searchOneWay('YUL', 'YVR', '2026-05-01', [
            'max_stops' => 0,
        ]);

        $this->assertCount(1, $results);
        $this->assertSame(0, $results[0]['stops']);
        $this->assertSame('AC300', $results[0]['segments'][0]['flight_number']);
    }

    public function test_max_results_limits_the_returned_itineraries(): void
    {
        $planner = new TripPlanner($this->data($this->easternCanadaAirports(), [
            $this->flight('AC', '100', 'YUL', '08:00', 'YVR', '10:00', '100.00'),
            $this->flight('AC', '101', 'YUL', '09:00', 'YVR', '11:00', '110.00'),
            $this->flight('AC', '102', 'YUL', '10:00', 'YVR', '12:00', '120.00'),
        ]), new DateTimeImmutable('2026-04-29 00:00:00 UTC'));

        $results = $planner->searchOneWay('YUL', 'YVR', '2026-05-01', [
            'max_results' => 2,
        ]);

        $this->assertCount(2, $results);
    }

    public function test_price_sort_orders_by_total_price_then_arrival(): void
    {
        $results = $this->sortablePlanner()->searchOneWay('YUL', 'YVR', '2026-05-01', [
            'sort' => 'price',
            'max_results' => 3,
        ]);

        $this->assertSame(['AC200', 'AC300', 'AC100'], array_map(
            fn (array $itinerary): string => $itinerary['segments'][0]['flight_number'],
            $results,
        ));
    }

    public function test_departure_sort_orders_by_departure_time(): void
    {
        $results = $this->sortablePlanner()->searchOneWay('YUL', 'YVR', '2026-05-01', [
            'sort' => 'departure',
            'max_results' => 3,
        ]);

        $this->assertSame(['AC100', 'AC200', 'AC300'], array_map(
            fn (array $itinerary): string => $itinerary['segments'][0]['flight_number'],
            $results,
        ));
    }

    public function test_arrival_sort_orders_by_arrival_time(): void
    {
        $results = $this->sortablePlanner()->searchOneWay('YUL', 'YVR', '2026-05-01', [
            'sort' => 'arrival',
            'max_results' => 3,
        ]);

        $this->assertSame(['AC100', 'AC300', 'AC200'], array_map(
            fn (array $itinerary): string => $itinerary['segments'][0]['flight_number'],
            $results,
        ));
    }

    public function test_duration_sort_orders_by_elapsed_time(): void
    {
        $results = $this->sortablePlanner()->searchOneWay('YUL', 'YVR', '2026-05-01', [
            'sort' => 'duration',
            'max_results' => 3,
        ]);

        $this->assertSame(['AC300', 'AC100', 'AC200'], array_map(
            fn (array $itinerary): string => $itinerary['segments'][0]['flight_number'],
            $results,
        ));
    }

    public function test_best_sort_balances_price_duration_and_stops(): void
    {
        $planner = new TripPlanner($this->data($this->easternCanadaAirports(), [
            $this->flight('AC', '100', 'YUL', '06:00', 'YYZ', '07:00', '50.00'),
            $this->flight('AC', '200', 'YYZ', '16:00', 'YVR', '18:00', '50.00'),
            $this->flight('AC', '300', 'YUL', '09:00', 'YVR', '11:00', '180.00'),
            $this->flight('AC', '400', 'YUL', '10:00', 'YVR', '15:00', '130.00'),
        ]), new DateTimeImmutable('2026-04-29 00:00:00 UTC'));

        $results = $planner->searchOneWay('YUL', 'YVR', '2026-05-01', [
            'sort' => 'best',
            'max_stops' => 1,
            'max_results' => 3,
        ]);

        $this->assertSame('AC300', $results[0]['segments'][0]['flight_number']);
        $this->assertArrayHasKey('best_score', $results[0]);
        $this->assertLessThan($results[1]['best_score'], $results[0]['best_score']);
    }

    public function test_it_returns_no_results_when_no_route_exists(): void
    {
        $planner = new TripPlanner($this->data($this->easternCanadaAirports(), [
            $this->flight('AC', '100', 'YUL', '08:00', 'YYZ', '09:00', '100.00'),
        ]), new DateTimeImmutable('2026-04-29 00:00:00 UTC'));

        $this->assertSame([], $planner->searchOneWay('YUL', 'YVR', '2026-05-01'));
    }

    private function sortablePlanner(): TripPlanner
    {
        return new TripPlanner($this->data($this->easternCanadaAirports(), [
            $this->flight('AC', '100', 'YUL', '08:00', 'YVR', '10:00', '300.00'),
            $this->flight('AC', '200', 'YUL', '09:00', 'YVR', '12:00', '100.00'),
            $this->flight('AC', '300', 'YUL', '10:00', 'YVR', '11:00', '200.00'),
        ]), new DateTimeImmutable('2026-04-29 00:00:00 UTC'));
    }
}
