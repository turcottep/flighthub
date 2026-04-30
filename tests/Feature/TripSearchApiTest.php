<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TripSearchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_way_search_uses_database_flight_data(): void
    {
        $this->seedTripData();

        $response = $this->getJson('/api/trips/search/one-way?origin=YUL&destination=YVR&departure_date=2026-05-01&max_results=5');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.type', 'one_way')
            ->assertJsonPath('data.0.segments.0.airline', 'AC')
            ->assertJsonPath('data.0.segments.0.departure_airport', 'YUL')
            ->assertJsonPath('data.0.segments.0.arrival_airport', 'YVR');
    }

    public function test_round_trip_search_uses_database_flight_data(): void
    {
        $this->seedTripData();

        $response = $this->getJson('/api/trips/search/round-trip?origin=YUL&destination=YVR&departure_date=2026-05-01&return_date=2026-05-08&max_results=5');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.type', 'round_trip')
            ->assertJsonPath('data.0.legs.0.type', 'outbound')
            ->assertJsonPath('data.0.legs.1.type', 'return');
    }

    public function test_nearby_one_way_search_uses_nearby_airports(): void
    {
        $this->seedTripData();

        $response = $this->getJson('/api/trips/search/one-way-nearby?origin_latitude=45.4706&origin_longitude=-73.7408&destination_latitude=49.1939&destination_longitude=-123.1840&departure_date=2026-05-01&radius_km=25&max_results=5');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.type', 'one_way')
            ->assertJsonPath('data.0.segments.0.departure_airport', 'YUL')
            ->assertJsonPath('data.0.segments.0.arrival_airport', 'YVR');
    }

    public function test_open_jaw_search_uses_database_flight_data(): void
    {
        $this->seedTripData();

        $response = $this->getJson('/api/trips/search/open-jaw?origin=YUL&outbound_destination=YVR&return_origin=YVR&final_destination=YYZ&departure_date=2026-05-01&return_date=2026-05-08&max_results=5');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.type', 'open_jaw')
            ->assertJsonPath('data.0.legs.0.type', 'outbound')
            ->assertJsonPath('data.0.legs.1.type', 'return')
            ->assertJsonPath('data.0.legs.1.itinerary.destination', 'YYZ');
    }

    public function test_multi_city_search_uses_database_flight_data(): void
    {
        $this->seedTripData();

        $query = http_build_query([
            'legs' => [
                [
                    'origin' => 'YUL',
                    'destination' => 'YVR',
                    'departure_date' => '2026-05-01',
                ],
                [
                    'origin' => 'YVR',
                    'destination' => 'YYZ',
                    'departure_date' => '2026-05-03',
                ],
            ],
            'max_results' => 5,
        ]);

        $response = $this->getJson('/api/trips/search/multi-city?'.$query);

        $response
            ->assertOk()
            ->assertJsonPath('data.0.type', 'multi_city')
            ->assertJsonPath('data.0.legs.0.type', 'leg_1')
            ->assertJsonPath('data.0.legs.1.type', 'leg_2')
            ->assertJsonPath('data.0.destination', 'YYZ');
    }

    public function test_one_way_search_finds_multi_connection_remote_destination_with_v3(): void
    {
        $this->seedTripData(includeWbmRoute: true);

        $response = $this->getJson('/api/trips/search/one-way?origin=YUL&destination=WBM&departure_date=2026-05-01&max_results=5');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.destination', 'WBM')
            ->assertJsonPath('data.0.stops', 3);
    }

    public function test_one_way_search_returns_validation_error_for_unknown_destination(): void
    {
        $this->seedTripData();

        $response = $this->getJson('/api/trips/search/one-way?origin=YUL&destination=ZZZ&departure_date=2026-05-01&max_results=5');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['destination']);
    }

    private function seedTripData(bool $includeWbmRoute = false): void
    {
        DB::table('airlines')->insert([
            ['code' => 'AC', 'name' => 'Air Canada'],
        ]);

        $airports = [
            [
                'code' => 'YUL',
                'city_code' => 'YMQ',
                'name' => 'Pierre Elliott Trudeau International',
                'city' => 'Montreal',
                'country_code' => 'CA',
                'region_code' => 'QC',
                'latitude' => 45.4706001282,
                'longitude' => -73.7407989502,
                'timezone' => 'America/Montreal',
            ],
            [
                'code' => 'YVR',
                'city_code' => 'YVR',
                'name' => 'Vancouver International',
                'city' => 'Vancouver',
                'country_code' => 'CA',
                'region_code' => 'BC',
                'latitude' => 49.193901062,
                'longitude' => -123.183998108,
                'timezone' => 'America/Vancouver',
            ],
            [
                'code' => 'YYZ',
                'city_code' => 'YTO',
                'name' => 'Toronto Pearson International',
                'city' => 'Toronto',
                'country_code' => 'CA',
                'region_code' => 'ON',
                'latitude' => 43.6772003174,
                'longitude' => -79.6305999756,
                'timezone' => 'America/Toronto',
            ],
        ];

        if ($includeWbmRoute) {
            array_push(
                $airports,
                [
                    'code' => 'AAA',
                    'city_code' => 'AAA',
                    'name' => 'AAA Airport',
                    'city' => 'AAA',
                    'country_code' => 'US',
                    'region_code' => 'TX',
                    'latitude' => 32.8998,
                    'longitude' => -97.0403,
                    'timezone' => 'America/Chicago',
                ],
                [
                    'code' => 'BBB',
                    'city_code' => 'BBB',
                    'name' => 'BBB Airport',
                    'city' => 'BBB',
                    'country_code' => 'AU',
                    'region_code' => 'QLD',
                    'latitude' => -27.3842,
                    'longitude' => 153.1175,
                    'timezone' => 'Australia/Brisbane',
                ],
                [
                    'code' => 'POM',
                    'city_code' => 'POM',
                    'name' => 'Port Moresby',
                    'city' => 'Port Moresby',
                    'country_code' => 'PG',
                    'region_code' => 'NCD',
                    'latitude' => -9.4433,
                    'longitude' => 147.22,
                    'timezone' => 'Pacific/Port_Moresby',
                ],
                [
                    'code' => 'WBM',
                    'city_code' => 'WBM',
                    'name' => 'Wapenamanda',
                    'city' => 'Wapenamanda',
                    'country_code' => 'PG',
                    'region_code' => 'EPW',
                    'latitude' => -5.6433000564575195,
                    'longitude' => 143.89500427246094,
                    'timezone' => 'Pacific/Port_Moresby',
                ],
            );
        }

        DB::table('airports')->insert($airports);

        $flights = [
            [
                'airline_code' => 'AC',
                'number' => '301',
                'departure_airport_code' => 'YUL',
                'departure_time' => '07:35',
                'arrival_airport_code' => 'YVR',
                'arrival_time' => '10:05',
                'price' => '273.23',
            ],
            [
                'airline_code' => 'AC',
                'number' => '302',
                'departure_airport_code' => 'YVR',
                'departure_time' => '11:30',
                'arrival_airport_code' => 'YUL',
                'arrival_time' => '19:11',
                'price' => '220.63',
            ],
            [
                'airline_code' => 'AC',
                'number' => '303',
                'departure_airport_code' => 'YVR',
                'departure_time' => '12:30',
                'arrival_airport_code' => 'YYZ',
                'arrival_time' => '20:00',
                'price' => '198.45',
            ],
        ];

        if ($includeWbmRoute) {
            array_push(
                $flights,
                [
                    'airline_code' => 'AC',
                    'number' => '401',
                    'departure_airport_code' => 'YUL',
                    'departure_time' => '08:00',
                    'arrival_airport_code' => 'AAA',
                    'arrival_time' => '10:00',
                    'price' => '100.00',
                ],
                [
                    'airline_code' => 'AC',
                    'number' => '402',
                    'departure_airport_code' => 'AAA',
                    'departure_time' => '11:00',
                    'arrival_airport_code' => 'BBB',
                    'arrival_time' => '18:00',
                    'price' => '100.00',
                ],
                [
                    'airline_code' => 'AC',
                    'number' => '403',
                    'departure_airport_code' => 'BBB',
                    'departure_time' => '19:00',
                    'arrival_airport_code' => 'POM',
                    'arrival_time' => '21:00',
                    'price' => '100.00',
                ],
                [
                    'airline_code' => 'AC',
                    'number' => '404',
                    'departure_airport_code' => 'POM',
                    'departure_time' => '22:00',
                    'arrival_airport_code' => 'WBM',
                    'arrival_time' => '23:00',
                    'price' => '100.00',
                ],
            );
        }

        DB::table('flights')->insert($flights);
    }
}
