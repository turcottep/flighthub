<?php

namespace Tests\Unit\TripPlanner;

use App\Services\TripPlanner;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Support\BuildsFlightData;

class RoundTripSearchTest extends TestCase
{
    use BuildsFlightData;

    public function test_it_combines_outbound_and_return_one_way_itineraries(): void
    {
        $planner = new TripPlanner($this->data($this->easternCanadaAirports(), [
            $this->flight('AC', '301', 'YUL', '08:00', 'YVR', '10:00', '273.23'),
            $this->flight('AC', '302', 'YVR', '11:30', 'YUL', '19:11', '220.63'),
        ]), new DateTimeImmutable('2026-04-29 00:00:00 UTC'));

        $results = $planner->searchRoundTrip('YUL', 'YVR', '2026-05-01', '2026-05-08');

        $this->assertCount(1, $results);
        $this->assertSame('round_trip', $results[0]['type']);
        $this->assertSame('493.86', $results[0]['total_price']);
        $this->assertSame('outbound', $results[0]['legs'][0]['type']);
        $this->assertSame('return', $results[0]['legs'][1]['type']);
    }

    public function test_round_trip_legs_can_each_include_layovers(): void
    {
        $planner = new TripPlanner($this->data($this->easternCanadaAirports(), [
            $this->flight('AC', '100', 'YUL', '08:00', 'YYZ', '09:00', '100.00'),
            $this->flight('AC', '200', 'YYZ', '10:00', 'YVR', '12:00', '80.00'),
            $this->flight('AC', '300', 'YVR', '08:00', 'YYZ', '15:00', '90.00'),
            $this->flight('AC', '400', 'YYZ', '16:00', 'YUL', '17:00', '70.00'),
        ]), new DateTimeImmutable('2026-04-29 00:00:00 UTC'));

        $results = $planner->searchRoundTrip('YUL', 'YVR', '2026-05-01', '2026-05-08', [
            'max_stops' => 1,
        ]);

        $this->assertCount(1, $results);
        $this->assertSame(1, $results[0]['legs'][0]['itinerary']['stops']);
        $this->assertSame(1, $results[0]['legs'][1]['itinerary']['stops']);
        $this->assertSame('340.00', $results[0]['total_price']);
    }

    public function test_return_must_depart_after_outbound_arrival(): void
    {
        $planner = new TripPlanner($this->data($this->easternCanadaAirports(), [
            $this->flight('AC', '301', 'YUL', '23:00', 'YVR', '01:00', '100.00'),
            $this->flight('AC', '302', 'YVR', '10:00', 'YUL', '18:00', '100.00'),
        ]), new DateTimeImmutable('2026-04-29 00:00:00 UTC'));

        $this->assertSame([], $planner->searchRoundTrip('YUL', 'YVR', '2026-05-01', '2026-05-01'));
    }

    public function test_round_trip_max_results_limits_combined_trips(): void
    {
        $planner = new TripPlanner($this->data($this->easternCanadaAirports(), [
            $this->flight('AC', '100', 'YUL', '08:00', 'YVR', '10:00', '100.00'),
            $this->flight('AC', '101', 'YUL', '09:00', 'YVR', '11:00', '110.00'),
            $this->flight('AC', '200', 'YVR', '12:00', 'YUL', '20:00', '100.00'),
            $this->flight('AC', '201', 'YVR', '13:00', 'YUL', '21:00', '110.00'),
        ]), new DateTimeImmutable('2026-04-29 00:00:00 UTC'));

        $results = $planner->searchRoundTrip('YUL', 'YVR', '2026-05-01', '2026-05-08', [
            'max_results' => 2,
        ]);

        $this->assertCount(2, $results);
    }
}
