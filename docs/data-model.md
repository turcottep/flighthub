# Data Model

## Tables

### `airlines`

Stores airline identity.

| Column | Notes |
| --- | --- |
| `code` | Primary key, IATA airline code. |
| `name` | Airline display name. |

### `airports`

Stores airport and city-code metadata used for search and timezone conversion.

| Column | Notes |
| --- | --- |
| `code` | Primary key, IATA airport code. |
| `city_code` | IATA city code. May differ from airport code. Indexed. |
| `name` | Airport display name. |
| `city` | City name. |
| `country_code` | ISO country code. Indexed. |
| `region_code` | Province/state/region code. Nullable, indexed. |
| `latitude` | Decimal latitude. |
| `longitude` | Decimal longitude. |
| `timezone` | IANA timezone, such as `America/Montreal`. |

### `flights`

Stores recurring daily flight templates.

| Column | Notes |
| --- | --- |
| `id` | Internal numeric key. |
| `airline_code` | Foreign key to `airlines.code`. |
| `number` | Flight number unique within airline. |
| `departure_airport_code` | Foreign key to `airports.code`. Indexed. |
| `departure_time` | Local departure time in the departure airport timezone. |
| `arrival_airport_code` | Foreign key to `airports.code`. |
| `arrival_time` | Local arrival time in the arrival airport timezone. |
| `price` | Neutral-currency single-passenger price. |

Important indexes:

- unique `airline_code, number`
- `departure_airport_code`
- `departure_airport_code, arrival_airport_code`
- `airline_code`

### `trips` and `trip_flights`

These tables are reserved for persisted selected trips. Search results are assembled on demand and do not need to be written unless the user chooses or saves a trip.

### `search_sessions` and `search_session_results`

These tables back one-way search pagination.

| Table | Notes |
| --- | --- |
| `search_sessions` | Stores a UUID search id, search type, normalized params hash, total result count, and expiry timestamp. |
| `search_session_results` | Stores ordered itinerary JSON for each search id and page position. |

Search sessions expire after 5 minutes. Expired rows can be pruned with:

```bash
php artisan trip-search-sessions:prune
```

## Import Pipeline

Generated data can be imported with:

```bash
php artisan trip-data:import data/generated/trip_data_full.json --fresh --chunk=2000
```

The importer upserts:

- airlines by `code`
- airports by `code`
- flights by `airline_code, number`

Use a different `--chunk` value to tune insert batch size:

```bash
php artisan trip-data:import data/generated/trip_data_full.json --fresh --chunk=2000
```

## Generating Mock Data

Raw data lives under `data/raw`. Generate deterministic assignment-style JSON with:

```bash
php scripts/generate_trip_data.php --max-routes=0 --frequency=realistic --output=data/generated/trip_data_full.json
```

The raw datasets do not include real schedules, flight numbers, or fares. The generator creates those fields deterministically so repeated runs produce stable results.

## Why Store Templates?

The prompt states that each flight is available every day. Storing a row per flight per date would create unnecessary volume and make date ranges harder to maintain. A template row plus a requested departure date is enough to construct dated segments.

At search time:

```text
flight template + requested departure date + airport timezone
  -> local departure datetime
  -> UTC departure instant
  -> local/UTC arrival datetime
```

## Future Scaling Options

If the mock dataset grows substantially:

- add a composite index on `departure_airport_code, departure_time`
- add a covering index for `departure_airport_code, arrival_airport_code, departure_time`
- consider PostGIS for nearby-airport radius searches
- cache the in-memory graph per data version

Do not precompute full itineraries unless there is a proven repeated market/date pattern. Precompute lookup structures, then assemble requested trips on demand.
