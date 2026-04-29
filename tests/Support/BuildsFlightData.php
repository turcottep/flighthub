<?php

namespace Tests\Support;

trait BuildsFlightData
{
    protected function airport(
        string $code,
        string $timezone = 'America/Montreal',
        float $latitude = 45.0,
        float $longitude = -73.0,
        ?string $cityCode = null,
    ): array {
        return [
            'code' => $code,
            'city_code' => $cityCode ?? $code,
            'name' => "{$code} Airport",
            'city' => $code,
            'country_code' => 'CA',
            'region_code' => 'QC',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'timezone' => $timezone,
        ];
    }

    protected function flight(
        string $airline,
        string $number,
        string $departureAirport,
        string $departureTime,
        string $arrivalAirport,
        string $arrivalTime,
        string $price,
    ): array {
        return [
            'airline' => $airline,
            'number' => $number,
            'departure_airport' => $departureAirport,
            'departure_time' => $departureTime,
            'arrival_airport' => $arrivalAirport,
            'arrival_time' => $arrivalTime,
            'price' => $price,
        ];
    }

    protected function data(array $airports, array $flights, array $airlines = []): array
    {
        return [
            'airlines' => $airlines ?: [
                [
                    'code' => 'AC',
                    'name' => 'Air Canada',
                ],
                [
                    'code' => 'WS',
                    'name' => 'WestJet',
                ],
            ],
            'airports' => $airports,
            'flights' => $flights,
        ];
    }

    protected function easternCanadaAirports(): array
    {
        return [
            $this->airport('YUL', 'America/Montreal', 45.457714, -73.749908, 'YMQ'),
            $this->airport('YHU', 'America/Montreal', 45.5175, -73.4169, 'YMQ'),
            $this->airport('YYZ', 'America/Toronto', 43.6772, -79.6306, 'YTO'),
            $this->airport('YTZ', 'America/Toronto', 43.6275, -79.3962, 'YTO'),
            $this->airport('YVR', 'America/Vancouver', 49.194698, -123.179192),
            $this->airport('LAX', 'America/Los_Angeles', 33.9416, -118.4085),
        ];
    }
}
