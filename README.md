# FlightHub Trip Builder

Full-stack coding assignment scaffolded as a production-like Laravel API with a React/Vite SPA.

## Stack

- PHP 8.3+ locally or PHP 8.4 in Docker, Laravel 13
- React 19, TypeScript, Vite, Tailwind CSS
- Docker Compose with Nginx, PHP-FPM, Postgres, and Vite
- Optional host setup with local Postgres or Postgres.app

## Local Setup

The quickest cross-platform setup is Docker. It works on macOS, Windows with Docker Desktop/WSL2, and Linux without installing PHP, Composer, Node, or Postgres directly on the host machine.

Prerequisites:

- Docker Desktop on macOS/Windows, or Docker Engine on Linux
- Git

Start the app:

```bash
git clone https://github.com/turcottep/flighthub.git
cd flighthub
cp .env.docker.example .env.docker
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
docker compose exec app php artisan trip-data:import data/generated/trip_data_full.json --fresh --chunk=2000
```

Open the app:

```text
http://localhost:8080
```

Useful Docker commands:

```bash
docker compose exec app php artisan test
docker compose exec app php artisan optimize:clear
docker compose down
```

## Host PHP Setup

Use this path if you prefer running PHP, Node, and Postgres directly on your machine instead of Docker.

Prerequisites:

- PHP 8.3+ with the `pdo_pgsql`, `intl`, and `zip` extensions
- Composer
- Node 20+
- PostgreSQL 16+ or Postgres.app
- Git

Install and run:

```bash
git clone https://github.com/turcottep/flighthub.git
cd flighthub
cp .env.example .env
composer install
npm ci
php artisan key:generate
createdb -h 127.0.0.1 -p 5432 -U postgres flighthub
php artisan migrate
php artisan trip-data:import data/generated/trip_data_full.json --fresh --chunk=2000
npm run build
php artisan serve
```

Open the app:

```text
http://localhost:8000
```

If your local Postgres user is not `postgres`, update `DB_USERNAME` and `DB_PASSWORD` in `.env` before running migrations. Postgres.app on macOS often uses your macOS username with no password.

For hot reload, keep Laravel running and start Vite in another terminal:

```bash
npm run dev
```

Useful commands:

```bash
php artisan test
php artisan migrate:fresh
php artisan trip-data:import data/generated/trip_data_full.json --fresh --chunk=2000
npm run build
php -d memory_limit=1536M scripts/benchmark_trip_planner_matrix.php --planner=v4 --per-group=5 --repeats=2 --warmups=1
```

Frontend flight data source:

```env
VITE_FLIGHT_DATA_SOURCE=backend
```

Use `backend` to call the Laravel/Postgres APIs. Use `mock` to keep all search
logic in the browser against `data/generated/trip_data_full.json` for fast UI
iteration without a database.

If you cannot run Postgres locally, SQLite still works as a temporary fallback by setting:

```env
DB_CONNECTION=sqlite
```

Docker app URL: `http://localhost:8080`
Docker Postgres host port: `15432`

## Assignment References

- Prompt: `docs/prompt.md`
- Architecture: `docs/architecture.md`
- API: `docs/api.md`
- Data model and imports: `docs/data-model.md`
- Deployment: `docs/deployment.md`
- Sample data: `sample_data.json`
- Expanded raw data: `data/raw/`
