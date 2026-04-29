# FlightHub Trip Builder

Full-stack coding assignment scaffolded as a production-like Laravel API with a React/Vite SPA.

## Stack

- PHP 8.5 locally, PHP 8.4 container runtime, Laravel 13
- React 19, TypeScript, Vite, Tailwind CSS
- Local Postgres via Postgres.app
- Optional Docker Compose with Nginx, PHP-FPM, Postgres, Redis, and Vite

## Local Development

The default local setup uses host PHP, Composer, Node, and local Postgres. This keeps local and production database behavior aligned without requiring Docker image downloads.

Prerequisites:

- PHP 8.3+
- Composer
- Node 20+
- Postgres.app or another local Postgres server

Start the app locally:

```bash
cp .env.example .env
createdb -h 127.0.0.1 -p 5432 flighthub
php artisan key:generate
php artisan migrate
npm run build
php artisan serve
```

Open the app:

```text
http://localhost:8000
```

For hot reload, run Vite in another terminal:

```bash
npm run dev
```

Useful commands:

```bash
php artisan test
php artisan migrate:fresh --seed
npm run build
```

Frontend flight data source:

```env
VITE_FLIGHT_DATA_SOURCE=backend
```

Use `backend` to call the Laravel/Postgres APIs. Use `mock` to keep all search
logic in the browser against `data/generated/trip_data_ac_ca.json` for fast UI
iteration without a database.

If you cannot run Postgres locally, SQLite still works as a temporary fallback by setting:

```env
DB_CONNECTION=sqlite
```

## Docker Development

The repo also includes a more production-like Docker Compose setup with Nginx, PHP-FPM, Postgres, Redis, and Vite. Use this when network conditions are good enough to pull base images.

```bash
cp .env.docker.example .env
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

Docker app URL: `http://localhost:8080`

## Assignment References

- Prompt: `docs/prompt.md`
- Architecture: `docs/architecture.md`
- API: `docs/api.md`
- Data model and imports: `docs/data-model.md`
- Sample data: `sample_data.json`
- Expanded raw data: `data/raw/`
