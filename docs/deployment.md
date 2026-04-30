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
APP_NAME="FlightHub Trip Builder"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://YOUR_RENDER_SERVICE.onrender.com
APP_KEY=base64:PASTE_GENERATED_KEY

LOG_CHANNEL=stderr
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_URL=PASTE_RENDER_INTERNAL_DATABASE_URL

SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync

VITE_FLIGHT_DATA_SOURCE=backend
```

Generate the app key locally:

```bash
php artisan key:generate --show
```

## After First Deploy

Run this in the Render web service shell:

```bash
php artisan migrate --force && php artisan trip-data:import data/generated/trip_data_full.json --fresh --chunk=2000 && php artisan optimize
```

Trip result pagination uses 5-minute Postgres-backed search sessions. To prune expired pagination snapshots on Render, add a Render Cron Job or run the command from a worker on a schedule:

```bash
php artisan trip-search-sessions:prune
```

The app also enforces expiry on read, so stale `search_id` requests fail even if the prune job has not run yet.

Then open:

```text
https://YOUR_RENDER_SERVICE.onrender.com
```
