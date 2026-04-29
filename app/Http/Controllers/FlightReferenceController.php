<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class FlightReferenceController extends Controller
{
    public function airports(): JsonResponse
    {
        return response()->json([
            'data' => DB::table('airports')
                ->orderBy('city')
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
                ->all(),
        ]);
    }

    public function airlines(): JsonResponse
    {
        return response()->json([
            'data' => DB::table('airlines')
                ->orderBy('name')
                ->get(['code', 'name'])
                ->map(fn (object $airline): array => [
                    'code' => $airline->code,
                    'name' => $airline->name,
                ])
                ->all(),
        ]);
    }
}
