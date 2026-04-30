<?php

namespace App\Http\Controllers;

use App\Services\FlightDataRepository;
use App\Services\TripPlanner;
use App\Services\TripPlannerV3;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TripSearchController extends Controller
{
    public function __construct(private readonly FlightDataRepository $flightData) {}

    public function oneWay(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'origin' => ['required', 'string', 'size:3'],
            'destination' => ['required', 'string', 'size:3'],
            'departure_date' => ['required', 'date_format:Y-m-d'],
            'airline' => ['nullable', 'string', 'size:2'],
            'sort' => ['nullable', 'string', 'in:best,price,departure,arrival,duration'],
            'max_stops' => ['nullable', 'integer', 'min:0', 'max:4'],
            'max_segments' => ['nullable', 'integer', 'min:1', 'max:8'],
            'max_results' => ['nullable', 'integer', 'min:1', 'max:100'],
            'minimum_layover_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'max_duration_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
            'max_connections_scanned' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);

        $origin = strtoupper($validated['origin']);
        $destination = strtoupper($validated['destination']);
        $this->locationAirportCodes($origin, 'origin');
        $this->locationAirportCodes($destination, 'destination');
        $planner = new TripPlannerV3($this->flightData->loadFromDatabase());

        return response()->json([
            'data' => $planner->searchOneWay(
                $origin,
                $destination,
                $validated['departure_date'],
                $this->plannerOptions($validated),
            ),
        ]);
    }

    public function roundTrip(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'origin' => ['required', 'string', 'size:3'],
            'destination' => ['required', 'string', 'size:3'],
            'departure_date' => ['required', 'date_format:Y-m-d'],
            'return_date' => ['required', 'date_format:Y-m-d'],
            'airline' => ['nullable', 'string', 'size:2'],
            'sort' => ['nullable', 'string', 'in:best,price,departure,arrival,duration'],
            'max_stops' => ['nullable', 'integer', 'min:0', 'max:4'],
            'max_results' => ['nullable', 'integer', 'min:1', 'max:100'],
            'minimum_layover_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'max_duration_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
        ]);

        $maxStops = (int) ($validated['max_stops'] ?? 0);
        $origin = strtoupper($validated['origin']);
        $destination = strtoupper($validated['destination']);
        $outboundOrigins = $this->locationAirportCodes($origin, 'origin');
        $returnOrigins = $this->locationAirportCodes($destination, 'destination');
        $planner = new TripPlanner($this->flightData->loadFromDatabaseForDepartures([
            ...$this->flightData->departureAirportCodesReachableFromDatabase($outboundOrigins, $maxStops),
            ...$this->flightData->departureAirportCodesReachableFromDatabase($returnOrigins, $maxStops),
        ]));

        return response()->json([
            'data' => $planner->searchRoundTrip(
                $origin,
                $destination,
                $validated['departure_date'],
                $validated['return_date'],
                $this->plannerOptions($validated),
            ),
        ]);
    }

    public function oneWayNearby(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'origin_latitude' => ['required', 'numeric', 'between:-90,90'],
            'origin_longitude' => ['required', 'numeric', 'between:-180,180'],
            'destination_latitude' => ['required', 'numeric', 'between:-90,90'],
            'destination_longitude' => ['required', 'numeric', 'between:-180,180'],
            'departure_date' => ['required', 'date_format:Y-m-d'],
            'radius_km' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'airline' => ['nullable', 'string', 'size:2'],
            'sort' => ['nullable', 'string', 'in:best,price,departure,arrival,duration'],
            'max_stops' => ['nullable', 'integer', 'min:0', 'max:4'],
            'max_results' => ['nullable', 'integer', 'min:1', 'max:100'],
            'minimum_layover_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'max_duration_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
        ]);

        $originLatitude = (float) $validated['origin_latitude'];
        $originLongitude = (float) $validated['origin_longitude'];
        $radiusKm = (float) ($validated['radius_km'] ?? 50);
        $originCodes = $this->flightData->nearbyAirportCodesFromDatabase($originLatitude, $originLongitude, $radiusKm);
        $planner = new TripPlanner($this->flightData->loadFromDatabaseForDepartures(
            $this->flightData->departureAirportCodesReachableFromDatabase(
                $originCodes,
                (int) ($validated['max_stops'] ?? 0),
            ),
        ));

        return response()->json([
            'data' => $planner->searchOneWayNear(
                $originLatitude,
                $originLongitude,
                (float) $validated['destination_latitude'],
                (float) $validated['destination_longitude'],
                $validated['departure_date'],
                $this->plannerOptions($validated),
            ),
        ]);
    }

    public function openJaw(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'origin' => ['required', 'string', 'size:3'],
            'outbound_destination' => ['required', 'string', 'size:3'],
            'return_origin' => ['required', 'string', 'size:3'],
            'final_destination' => ['required', 'string', 'size:3'],
            'departure_date' => ['required', 'date_format:Y-m-d'],
            'return_date' => ['required', 'date_format:Y-m-d'],
            'airline' => ['nullable', 'string', 'size:2'],
            'sort' => ['nullable', 'string', 'in:best,price,departure,arrival,duration'],
            'max_stops' => ['nullable', 'integer', 'min:0', 'max:4'],
            'max_results' => ['nullable', 'integer', 'min:1', 'max:100'],
            'minimum_layover_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'max_duration_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
        ]);

        $origin = strtoupper($validated['origin']);
        $outboundDestination = strtoupper($validated['outbound_destination']);
        $returnOrigin = strtoupper($validated['return_origin']);
        $finalDestination = strtoupper($validated['final_destination']);
        $maxStops = (int) ($validated['max_stops'] ?? 0);
        $outboundOrigins = $this->locationAirportCodes($origin, 'origin');
        $this->locationAirportCodes($outboundDestination, 'outbound_destination');
        $returnOrigins = $this->locationAirportCodes($returnOrigin, 'return_origin');
        $this->locationAirportCodes($finalDestination, 'final_destination');
        $planner = new TripPlanner($this->flightData->loadFromDatabaseForDepartures([
            ...$this->flightData->departureAirportCodesReachableFromDatabase($outboundOrigins, $maxStops),
            ...$this->flightData->departureAirportCodesReachableFromDatabase($returnOrigins, $maxStops),
        ]));

        return response()->json([
            'data' => $planner->searchOpenJaw(
                $origin,
                $outboundDestination,
                $returnOrigin,
                $finalDestination,
                $validated['departure_date'],
                $validated['return_date'],
                $this->plannerOptions($validated),
            ),
        ]);
    }

    public function multiCity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'legs' => ['required', 'array', 'min:1', 'max:5'],
            'legs.*.origin' => ['required', 'string', 'size:3'],
            'legs.*.destination' => ['required', 'string', 'size:3'],
            'legs.*.departure_date' => ['required', 'date_format:Y-m-d'],
            'airline' => ['nullable', 'string', 'size:2'],
            'sort' => ['nullable', 'string', 'in:best,price,departure,arrival,duration'],
            'max_stops' => ['nullable', 'integer', 'min:0', 'max:4'],
            'max_results' => ['nullable', 'integer', 'min:1', 'max:100'],
            'minimum_layover_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'max_duration_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
        ]);

        $maxStops = (int) ($validated['max_stops'] ?? 0);
        $legs = [];
        $departureAirportCodes = [];

        foreach ($validated['legs'] as $index => $leg) {
            $origin = strtoupper($leg['origin']);
            $destination = strtoupper($leg['destination']);
            $originCodes = $this->locationAirportCodes($origin, "legs.{$index}.origin");
            $this->locationAirportCodes($destination, "legs.{$index}.destination");
            $departureAirportCodes = [
                ...$departureAirportCodes,
                ...$this->flightData->departureAirportCodesReachableFromDatabase($originCodes, $maxStops),
            ];

            $legs[] = [
                'origin' => $origin,
                'destination' => $destination,
                'departure_date' => $leg['departure_date'],
            ];
        }

        $planner = new TripPlanner($this->flightData->loadFromDatabaseForDepartures($departureAirportCodes));

        return response()->json([
            'data' => $planner->searchMultiCity($legs, $this->plannerOptions($validated)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function plannerOptions(array $validated): array
    {
        $options = [];

        foreach (['sort', 'max_stops', 'max_segments', 'max_results', 'minimum_layover_minutes', 'max_duration_hours', 'radius_km', 'max_connections_scanned'] as $key) {
            if (array_key_exists($key, $validated)) {
                $options[$key] = $validated[$key];
            }
        }

        if (! empty($validated['airline'])) {
            $options['airline'] = strtoupper((string) $validated['airline']);
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    private function locationAirportCodes(string $code, string $field): array
    {
        $airportCodes = $this->flightData->resolveLocationCodesFromDatabase(strtoupper($code));

        if ($airportCodes === []) {
            throw ValidationException::withMessages([
                $field => "Unknown airport or city code: {$code}",
            ]);
        }

        return $airportCodes;
    }
}
