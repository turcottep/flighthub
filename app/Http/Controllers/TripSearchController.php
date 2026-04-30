<?php

namespace App\Http\Controllers;

use App\Services\FlightDataRepository;
use App\Services\TripPlannerV4;
use App\Services\TripSearchSessionStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TripSearchController extends Controller
{
    public function __construct(
        private readonly FlightDataRepository $flightData,
        private readonly TripSearchSessionStore $searchSessions,
    ) {}

    public function oneWay(Request $request): JsonResponse
    {
        if ($cachedResponse = $this->cachedSearchPage($request)) {
            return $cachedResponse;
        }

        $validated = $request->validate([
            'origin' => ['required', 'string', 'size:3'],
            'destination' => ['required', 'string', 'size:3'],
            'departure_date' => ['required', 'date_format:Y-m-d'],
            ...$this->searchOptionRules(),
            ...$this->v4SearchOptionRules(),
            ...$this->paginationRules(),
        ]);

        $origin = strtoupper($validated['origin']);
        $destination = strtoupper($validated['destination']);
        $this->locationAirportCodes($origin, 'origin');
        $this->locationAirportCodes($destination, 'destination');
        $planner = $this->planner();

        $results = $planner->searchOneWay(
            $origin,
            $destination,
            $validated['departure_date'],
            $this->plannerOptions($validated),
        );

        return response()->json($this->searchSessions->create(
            'one_way',
            $this->searchParams($validated, compact('origin', 'destination')),
            $results,
            $this->page($validated),
            $this->perPage($validated),
        ));
    }

    public function roundTrip(Request $request): JsonResponse
    {
        if ($cachedResponse = $this->cachedSearchPage($request)) {
            return $cachedResponse;
        }

        $validated = $request->validate([
            'origin' => ['required', 'string', 'size:3'],
            'destination' => ['required', 'string', 'size:3'],
            'departure_date' => ['required', 'date_format:Y-m-d'],
            'return_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:departure_date'],
            ...$this->searchOptionRules(),
            ...$this->v4SearchOptionRules(),
            ...$this->paginationRules(),
        ]);

        $origin = strtoupper($validated['origin']);
        $destination = strtoupper($validated['destination']);
        $this->locationAirportCodes($origin, 'origin');
        $this->locationAirportCodes($destination, 'destination');
        $planner = $this->planner();

        $options = $this->plannerOptions($validated);

        return response()->json($this->tripOptionsResponse('round_trip', [
            $this->tripOptionsLeg(
                id: 'outbound',
                type: 'outbound',
                label: 'Outbound',
                origin: $origin,
                destination: $destination,
                departureDate: $validated['departure_date'],
                options: $planner->searchOneWay($origin, $destination, $validated['departure_date'], $options),
            ),
            $this->tripOptionsLeg(
                id: 'return',
                type: 'return',
                label: 'Return',
                origin: $destination,
                destination: $origin,
                departureDate: $validated['return_date'],
                options: $planner->searchOneWay($destination, $origin, $validated['return_date'], $options),
            ),
        ]));
    }

    public function oneWayNearby(Request $request): JsonResponse
    {
        if ($cachedResponse = $this->cachedSearchPage($request)) {
            return $cachedResponse;
        }

        $validated = $request->validate([
            'origin_latitude' => ['required', 'numeric', 'between:-90,90'],
            'origin_longitude' => ['required', 'numeric', 'between:-180,180'],
            'destination_latitude' => ['required', 'numeric', 'between:-90,90'],
            'destination_longitude' => ['required', 'numeric', 'between:-180,180'],
            'departure_date' => ['required', 'date_format:Y-m-d'],
            'radius_km' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            ...$this->searchOptionRules(),
            ...$this->v4SearchOptionRules(),
            ...$this->paginationRules(),
        ]);

        $originLatitude = (float) $validated['origin_latitude'];
        $originLongitude = (float) $validated['origin_longitude'];
        $planner = $this->planner();

        $results = $planner->searchOneWayNear(
            $originLatitude,
            $originLongitude,
            (float) $validated['destination_latitude'],
            (float) $validated['destination_longitude'],
            $validated['departure_date'],
            $this->plannerOptions($validated),
        );

        return response()->json($this->searchSessions->create(
            'one_way_nearby',
            $this->searchParams($validated),
            $results,
            $this->page($validated),
            $this->perPage($validated),
        ));
    }

    public function openJaw(Request $request): JsonResponse
    {
        if ($cachedResponse = $this->cachedSearchPage($request)) {
            return $cachedResponse;
        }

        $validated = $request->validate([
            'origin' => ['required', 'string', 'size:3'],
            'outbound_destination' => ['required', 'string', 'size:3'],
            'return_origin' => ['required', 'string', 'size:3'],
            'final_destination' => ['required', 'string', 'size:3'],
            'departure_date' => ['required', 'date_format:Y-m-d'],
            'return_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:departure_date'],
            ...$this->searchOptionRules(),
            ...$this->v4SearchOptionRules(),
            ...$this->paginationRules(),
        ]);

        $origin = strtoupper($validated['origin']);
        $outboundDestination = strtoupper($validated['outbound_destination']);
        $returnOrigin = strtoupper($validated['return_origin']);
        $finalDestination = strtoupper($validated['final_destination']);
        $this->locationAirportCodes($origin, 'origin');
        $this->locationAirportCodes($outboundDestination, 'outbound_destination');
        $this->locationAirportCodes($returnOrigin, 'return_origin');
        $this->locationAirportCodes($finalDestination, 'final_destination');
        $planner = $this->planner();

        $options = $this->plannerOptions($validated);

        return response()->json($this->tripOptionsResponse('open_jaw', [
            $this->tripOptionsLeg(
                id: 'outbound',
                type: 'outbound',
                label: 'Outbound',
                origin: $origin,
                destination: $outboundDestination,
                departureDate: $validated['departure_date'],
                options: $planner->searchOneWay($origin, $outboundDestination, $validated['departure_date'], $options),
            ),
            $this->tripOptionsLeg(
                id: 'return',
                type: 'return',
                label: 'Return',
                origin: $returnOrigin,
                destination: $finalDestination,
                departureDate: $validated['return_date'],
                options: $planner->searchOneWay($returnOrigin, $finalDestination, $validated['return_date'], $options),
            ),
        ]));
    }

    public function multiCity(Request $request): JsonResponse
    {
        if ($cachedResponse = $this->cachedSearchPage($request)) {
            return $cachedResponse;
        }

        $validated = $request->validate([
            'legs' => ['required', 'array', 'min:1', 'max:5'],
            'legs.*.origin' => ['required', 'string', 'size:3'],
            'legs.*.destination' => ['required', 'string', 'size:3'],
            'legs.*.departure_date' => ['required', 'date_format:Y-m-d'],
            ...$this->searchOptionRules(),
            ...$this->v4SearchOptionRules(),
            ...$this->paginationRules(),
        ]);

        $legs = [];

        foreach ($validated['legs'] as $index => $leg) {
            $origin = strtoupper($leg['origin']);
            $destination = strtoupper($leg['destination']);
            $this->locationAirportCodes($origin, "legs.{$index}.origin");
            $this->locationAirportCodes($destination, "legs.{$index}.destination");

            $legs[] = [
                'origin' => $origin,
                'destination' => $destination,
                'departure_date' => $leg['departure_date'],
            ];
        }

        $planner = $this->planner();
        $options = $this->plannerOptions($validated);

        return response()->json($this->tripOptionsResponse(
            'multi_city',
            array_map(
                fn (array $leg, int $index): array => $this->tripOptionsLeg(
                    id: 'leg-'.($index + 1),
                    type: 'leg_'.($index + 1),
                    label: 'Flight '.($index + 1),
                    origin: $leg['origin'],
                    destination: $leg['destination'],
                    departureDate: $leg['departure_date'],
                    options: $planner->searchOneWay($leg['origin'], $leg['destination'], $leg['departure_date'], $options),
                ),
                $legs,
                array_keys($legs),
            ),
        ));
    }

    private function cachedSearchPage(Request $request): ?JsonResponse
    {
        if (! $request->filled('search_id')) {
            return null;
        }

        $validated = $request->validate([
            'search_id' => ['required', 'uuid'],
            ...$this->paginationRules(),
        ]);

        $page = $this->searchSessions->page(
            (string) $validated['search_id'],
            $this->page($validated),
            $this->perPage($validated),
        );

        if ($page === null) {
            return response()->json([
                'message' => 'These search results expired. Run the search again to see current trips.',
            ], 410);
        }

        return response()->json($page);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function searchOptionRules(): array
    {
        return [
            'airline' => ['nullable', 'string', 'size:2'],
            'sort' => ['nullable', 'string', 'in:best,price,departure,arrival,duration'],
            'max_stops' => ['nullable', 'integer', 'min:0', 'max:4'],
            'max_segments' => ['nullable', 'integer', 'min:1', 'max:8'],
            'max_results' => ['nullable', 'integer', 'min:1', 'max:100'],
            'minimum_layover_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'max_duration_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function v4SearchOptionRules(): array
    {
        return [
            'max_connections_scanned' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'max_route_patterns' => ['nullable', 'integer', 'min:1', 'max:500'],
            'max_route_expansions' => ['nullable', 'integer', 'min:1', 'max:200000'],
            'max_route_edges_per_airport' => ['nullable', 'integer', 'min:1', 'max:500'],
            'max_route_beam' => ['nullable', 'integer', 'min:1', 'max:20000'],
            'max_flights_per_route' => ['nullable', 'integer', 'min:1', 'max:50'],
            'max_schedule_beam' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function paginationRules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.TripSearchSessionStore::MAX_PER_PAGE],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function plannerOptions(array $validated): array
    {
        $options = [];

        foreach ([
            'sort',
            'max_stops',
            'max_segments',
            'max_results',
            'minimum_layover_minutes',
            'max_duration_hours',
            'radius_km',
            'max_connections_scanned',
            'max_route_patterns',
            'max_route_expansions',
            'max_route_edges_per_airport',
            'max_route_beam',
            'max_flights_per_route',
            'max_schedule_beam',
        ] as $key) {
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

    private function planner(): TripPlannerV4
    {
        return new TripPlannerV4($this->flightData->loadRouteNetworkFromDatabase());
    }

    /**
     * @param list<array<string, mixed>> $legs
     * @return array{data: array{type: string, legs: list<array<string, mixed>>}}
     */
    private function tripOptionsResponse(string $type, array $legs): array
    {
        return [
            'data' => [
                'type' => $type,
                'legs' => $legs,
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $options
     * @return array<string, mixed>
     */
    private function tripOptionsLeg(
        string $id,
        string $type,
        string $label,
        string $origin,
        string $destination,
        string $departureDate,
        array $options,
    ): array {
        return [
            'id' => $id,
            'type' => $type,
            'label' => $label,
            'origin' => $origin,
            'destination' => $destination,
            'departure_date' => $departureDate,
            'option_count' => count($options),
            'options' => $options,
        ];
    }

    /**
     * @param array<string, mixed> $validated
     */
    private function page(array $validated): int
    {
        return (int) ($validated['page'] ?? 1);
    }

    /**
     * @param array<string, mixed> $validated
     */
    private function perPage(array $validated): int
    {
        return (int) ($validated['per_page'] ?? TripSearchSessionStore::DEFAULT_PER_PAGE);
    }

    /**
     * @param array<string, mixed> $validated
     * @param array<string, mixed> $normalized
     * @return array<string, mixed>
     */
    private function searchParams(array $validated, array $normalized = []): array
    {
        unset($validated['page'], $validated['per_page']);

        return [
            ...$validated,
            'normalized' => $normalized,
        ];
    }
}
