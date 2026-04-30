# Architecture

## Goals

Trip Builder is modeled as an API-first Laravel application with a React SPA. The backend owns trip-search behavior so the UI can stay thin and so timezone, validation, and itinerary rules are testable outside the browser.

The app intentionally separates durable data from route calculation:

```text
Postgres
  durable airlines, airports, recurring flight templates, selected trips

FlightDataRepository
  loads the subset of templates needed for a search

TripPlannerV4
  builds in-memory route-pattern and schedule indexes, then materializes dated itineraries

React SPA
  submits search criteria and renders itineraries
```

Docker Compose mirrors this locally with separate Nginx, PHP-FPM, Postgres,
and Vite containers. The host PHP setup uses the same Laravel code and
Postgres schema, but runs PHP, Node, and Postgres directly on the developer
machine.

## Core Design

Flights are stored as recurring daily templates, not as one row per calendar date. This matches the assignment prompt: every flight is available every day of the week.

At search time, the planner combines:

```text
departure date + airport timezone + local flight time
```

to create real local and UTC datetimes. This is necessary because elapsed duration and connection validity must be calculated on UTC instants, while the user-facing schedule must remain in local airport time.

## Search Strategy

The production search path is not a database loop. The repository loads recurring flight templates, then `TripPlannerV4` indexes the data in memory:

```text
airportsByCode
airportCodesByCityCode
flightsByRoute
routeGraph
reverseRouteGraph
```

V4 searches route-first, schedule-second:

1. Collapse flight templates into unique airport-to-airport route edges.
2. Compute a destination lower-bound route score.
3. Generate a bounded set of candidate airport route patterns with an A*-scored route beam.
4. Materialize actual dated flight segments only along those route patterns.
5. Score, sort, and return complete itineraries.

This avoids scanning all timed flight options for hard routes. The important bounds are:

- `max_segments` / `max_stops` limit route depth.
- `max_route_patterns` limits candidate airport paths.
- `max_route_edges_per_airport` and `max_route_beam` keep hub expansion bounded.
- `max_flights_per_route` and `max_schedule_beam` keep schedule materialization bounded.
- `minimum_layover_minutes` rejects invalid connections.
- `max_duration_hours` prunes long itineraries.
- `max_results` limits returned results.

## Why Postgres And In-Memory Search?

Postgres is used for durable data and reviewable application behavior: airports,
airlines, recurring flight templates, selected trips, framework sessions, queues,
cache records, and short-lived search-pagination snapshots. It is not used as the
inner route-expansion engine.

At request time, the repository loads the compact route network and gives the
planner a lazy resolver for flight rows on specific airport pairs. The planner
can then search airport paths in memory and ask Postgres only for flights on
route edges that survived pruning. That keeps the data model production-shaped
without turning every connection step into a database query.

## Supported Trip Types

Implemented API routes currently expose:

- one-way
- round-trip
- nearby one-way
- open-jaw
- multi-city

The React SPA presents those capabilities as three user-facing workflows: one-way, round-trip, and multi-city. Nearby airports are an option within one-way search, while open-jaw is exposed as "different return airports" within round-trip search.

## Data Flow

```text
Raw OpenFlights / OurAirports data
  -> scripts/generate_trip_data.php
  -> data/generated/*.json
  -> php artisan trip-data:import
  -> Postgres tables
  -> FlightDataRepository
  -> TripPlannerV4
  -> JSON API
  -> React SPA
```

## Why Not Precompute Routes?

Full itinerary precomputation does not scale well because the combination space includes origins, destinations, dates, airlines, stops, connection airports, and sort/filter options. Most precomputed itineraries would never be requested.

The app instead precomputes route lookup structures in memory and computes requested itineraries on demand. Route patterns are selected before dated schedules are materialized, which gives predictable performance without filling storage with low-value combinations.

## Test Coverage Strategy

The tests focus on the parts most likely to fail in a trip planner:

- route correctness for one-way, round-trip, open-jaw, multi-city, and nearby-airport searches
- validation for unknown locations, invalid dates, return dates before departure, stops, duration, and result limits
- timezone behavior, including overnight flights and DST elapsed time
- API behavior against database-backed flight templates, including lazy route loading
- search-session pagination and expiry
- browser workflows for trip modes, autocomplete, calendars, paging, selectable legs, and flight details
- full-data checks against the generated 164k-flight dataset for dense hubs and remote-to-remote routes

## Production Considerations

For this assignment-scale application:

- Postgres is the required data store.
- Docker Compose is the recommended local setup because it provisions Postgres with the app.
- Cache, sessions, queues, and search pagination are database-backed to keep the assignment stack simple.
- One-way search pagination stores short-lived search-session snapshots in Postgres so later pages do not rerun the planner.
- Search results can be cached more aggressively later if traffic grows, but the route engine should still use in-memory graph traversal rather than repeated SQL joins.

## Known Scope Limits

This is not a real ticketing engine. It does not model:

- live inventory
- fare rules
- married segments
- taxes and fees
- cabin inventory
- ticket issuance
- supplier repricing
- airline/GDS/NDC integrations

Those are deliberately outside this assignment. The model focuses on schedule templates, timezone-correct dated itinerary construction, simple pricing, sorting, and bounded route navigation.
