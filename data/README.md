# Trip Data

Raw data lives in `data/raw/`:

- `openflights_airlines.dat`: airline names and IATA codes.
- `openflights_airports.dat`: airport names, cities, coordinates, IATA codes, and timezones.
- `openflights_routes.dat`: directional airline route pairs.
- `openflights_countries.dat`: country-name to ISO country-code mapping.
- `ourairports_airports.csv`: airport enrichment for ISO country and region codes.

Generate sample-layout seed data with:

```sh
php scripts/generate_trip_data.php
```

Useful variants:

```sh
php scripts/generate_trip_data.php --airline=AC --country=CA
php scripts/generate_trip_data.php --max-routes=0
php scripts/generate_trip_data.php --max-routes=0 --frequency=realistic --output=data/generated/trip_data_full.json
php scripts/generate_trip_data.php --output=data/generated/trip_data.small.json --max-routes=250
```

Import generated data into the database after running migrations:

```sh
php artisan migrate
php artisan trip-data:import data/generated/trip_data_full.json --fresh --chunk=2000
```

OpenFlights provides airlines, airports, and route pairs, but not scheduled flight
numbers, departure times, arrival times, or prices. The generator creates those
missing flight fields deterministically from each route so repeated runs produce
stable output.

The `realistic` frequency mode creates multiple recurring daily flight templates
per route using distance and major-airport heuristics. It does not materialize
365 calendar days because the assignment model says each flight is available
every day of the week.
