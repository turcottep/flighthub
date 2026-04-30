# Deployment

Recommended host: Render.

Deploy the app as a Docker web service with `Dockerfile.prod` and a managed Render Postgres database in the same region.

## Render Web Service

- Runtime: Docker
- Dockerfile path: `Dockerfile.prod`
- Health check path: `/health`

## Environment

Set these on the Render web service:

```env
APP_NAME=FlightHub Trip Builder
APP_ENV=production
APP_DEBUG=false
APP_URL=https://YOUR_RENDER_SERVICE.onrender.com
APP_KEY=base64:PASTE_GENERATED_KEY

LOG_CHANNEL=stderr
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_URL=PASTE_RENDER_INTERNAL_DATABASE_URL

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

VITE_FLIGHT_DATA_SOURCE=backend
```

Generate the app key locally:

```bash
php artisan key:generate --show
```

## After First Deploy

Run this in the Render web service shell:

```bash
php artisan migrate --force && php artisan trip-data:import data/generated/trip_data_full.json --fresh && php artisan optimize
```

Then open:

```text
https://YOUR_RENDER_SERVICE.onrender.com
```
