# Architecture

## Goals

Trip Builder is modeled as an API-first Laravel application with a React SPA. The backend owns trip-search behavior so the UI can stay thin and so timezone, validation, and itinerary rules are testable outside the browser.

The app intentionally separates durable data from route calculation:

```text
Postgres
  durable airlines, airports, recurring flight templates, selected trips

FlightDataRepository
  loads the subset of templates needed for a search

TripPlanner
  builds in-memory airport and flight indexes, then searches those indexes

React SPA
  submits search criteria and renders itineraries
```

## Core Design

Flights are stored as recurring daily templates, not as one row per calendar date. This matches the assignment prompt: every flight is available every day of the week.

At search time, the planner combines:

```text
departure date + airport timezone + local flight time
```

to create real local and UTC datetimes. This is necessary because elapsed duration and connection validity must be calculated on UTC instants, while the user-facing schedule must remain in local airport time.

## Search Strategy

The search path is not a database loop. The repository performs bounded database reads, then `TripPlanner` indexes the data in memory:

```text
airportsByCode
airportCodesByCityCode
flightsByDepartureAirport
```

Queries traverse those maps with bounded expansion:

- `max_stops` limits segment depth.
- `minimum_layover_minutes` rejects invalid connections.
- `max_duration_hours` prunes long itineraries.
- `max_expansions` prevents runaway searches on large datasets.
- `max_results` limits returned results.

This avoids precomputing every route combination while still keeping lookup fast for reviewer-driven ad hoc searches.

## Supported Trip Types

Implemented API routes currently expose:

- one-way
- round-trip

The planner service also contains support for extra-credit search shapes such as open-jaw, multi-city, city-code expansion, and nearby-airport matching. Not all of those are exposed through HTTP endpoints yet.

## Data Flow

```text
Raw OpenFlights / OurAirports data
  -> scripts/generate_trip_data.php
  -> data/generated/*.json
  -> php artisan trip-data:import
  -> Postgres tables
  -> FlightDataRepository
  -> TripPlanner
  -> JSON API
  -> React SPA
```

## Why Not Precompute Routes?

Full route precomputation does not scale well because the combination space includes origins, destinations, dates, airlines, stops, connection airports, and sort/filter options. Most precomputed routes would never be requested.

The app instead precomputes lookup structures and computes the requested itineraries on demand. That gives predictable performance without filling storage with low-value combinations.

## Production Considerations

For this assignment-scale application:

- Postgres is the required data store.
- Redis is optional and not required for one reviewer at a time.
- Docker is useful for production parity, but local Postgres is enough for development.
- Search results can be cached later if traffic grows, but the route engine should still use in-memory graph traversal rather than repeated SQL joins.

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
