# API

The API returns JSON and is mounted under `/api`.

Dates use `YYYY-MM-DD`. Airport and city codes use IATA-style uppercase codes. The backend accepts lowercase input but normalizes internally.

## One-Way Search

```http
GET /api/trips/search/one-way
```

### Query Parameters

| Parameter | Required | Description |
| --- | --- | --- |
| `origin` | yes | 3-character airport or city code, such as `YUL` or `YMQ`. |
| `destination` | yes | 3-character airport or city code, such as `YVR`. |
| `departure_date` | yes | Departure date in `YYYY-MM-DD` format. |
| `airline` | no | 2-character preferred airline code, such as `AC`. |
| `sort` | no | `best`, `price`, `departure`, `arrival`, or `duration`. |
| `max_stops` | no | Maximum connections. `0` means direct only. |
| `max_results` | no | Maximum returned itineraries, 1-100. |
| `minimum_layover_minutes` | no | Minimum connection time. |
| `max_duration_hours` | no | Maximum elapsed itinerary duration. |

### Example

```bash
curl "http://localhost:8000/api/trips/search/one-way?origin=YUL&destination=YVR&departure_date=2026-05-01&max_stops=1&sort=best"
```

### Response

```json
{
  "data": [
    {
      "type": "one_way",
      "origin": "YUL",
      "destination": "YVR",
      "stops": 0,
      "segment_count": 1,
      "total_price": "273.23",
      "total_price_cents": 27323,
      "best_score": 38873,
      "departure_at": "2026-05-01T07:35:00-04:00",
      "arrival_at": "2026-05-01T10:05:00-07:00",
      "departure_utc": "2026-05-01T11:35:00+00:00",
      "arrival_utc": "2026-05-01T17:05:00+00:00",
      "duration_minutes": 330,
      "segments": [
        {
          "airline": "AC",
          "number": "301",
          "flight_number": "AC301",
          "departure_airport": "YUL",
          "arrival_airport": "YVR",
          "departure_timezone": "America/Montreal",
          "arrival_timezone": "America/Vancouver",
          "departure_at": "2026-05-01T07:35:00-04:00",
          "arrival_at": "2026-05-01T10:05:00-07:00",
          "departure_utc": "2026-05-01T11:35:00+00:00",
          "arrival_utc": "2026-05-01T17:05:00+00:00",
          "duration_minutes": 330,
          "price": "273.23",
          "price_cents": 27323
        }
      ]
    }
  ]
}
```

## Round-Trip Search

```http
GET /api/trips/search/round-trip
```

### Query Parameters

| Parameter | Required | Description |
| --- | --- | --- |
| `origin` | yes | 3-character airport or city code. |
| `destination` | yes | 3-character airport or city code. |
| `departure_date` | yes | Outbound date in `YYYY-MM-DD` format. |
| `return_date` | yes | Return date in `YYYY-MM-DD` format. |
| `airline` | no | 2-character preferred airline code. |
| `sort` | no | `best`, `price`, `departure`, `arrival`, or `duration`. |
| `max_stops` | no | Maximum connections per one-way leg. |
| `max_results` | no | Maximum returned round trips, 1-100. |
| `minimum_layover_minutes` | no | Minimum connection time. |
| `max_duration_hours` | no | Maximum elapsed duration for each one-way leg. |

### Example

```bash
curl "http://localhost:8000/api/trips/search/round-trip?origin=YUL&destination=YVR&departure_date=2026-05-01&return_date=2026-05-08&sort=price"
```

### Response Shape

```json
{
  "data": [
    {
      "type": "round_trip",
      "origin": "YUL",
      "destination": "YVR",
      "departure_date": "2026-05-01",
      "return_date": "2026-05-08",
      "total_price": "493.86",
      "total_price_cents": 49386,
      "duration_minutes": 791,
      "best_score": 77121,
      "legs": [
        {
          "type": "outbound",
          "itinerary": {
            "type": "one_way",
            "origin": "YUL",
            "destination": "YVR",
            "segments": []
          }
        },
        {
          "type": "return",
          "itinerary": {
            "type": "one_way",
            "origin": "YVR",
            "destination": "YUL",
            "segments": []
          }
        }
      ]
    }
  ]
}
```

The abbreviated `segments` arrays above have the same segment shape as the one-way response.

## Nearby One-Way Search

```http
GET /api/trips/search/one-way-nearby
```

Searches from airports within `radius_km` of the origin coordinates to airports within `radius_km` of the destination coordinates.

| Parameter | Required | Description |
| --- | --- | --- |
| `origin_latitude` | yes | Origin latitude, `-90..90`. |
| `origin_longitude` | yes | Origin longitude, `-180..180`. |
| `destination_latitude` | yes | Destination latitude, `-90..90`. |
| `destination_longitude` | yes | Destination longitude, `-180..180`. |
| `departure_date` | yes | Departure date in `YYYY-MM-DD` format. |
| `radius_km` | no | Nearby airport radius. Defaults to `50`. |
| `airline`, `sort`, `max_stops`, `max_results`, `minimum_layover_minutes`, `max_duration_hours` | no | Same meaning as one-way search. |

```bash
curl "http://localhost:8000/api/trips/search/one-way-nearby?origin_latitude=45.4706&origin_longitude=-73.7408&destination_latitude=49.1939&destination_longitude=-123.1840&departure_date=2026-05-01&radius_km=25"
```

The response is the same shape as one-way search.

## Open-Jaw Search

```http
GET /api/trips/search/open-jaw
```

| Parameter | Required | Description |
| --- | --- | --- |
| `origin` | yes | Outbound origin airport or city code. |
| `outbound_destination` | yes | Outbound destination airport or city code. |
| `return_origin` | yes | Return leg origin airport or city code. |
| `final_destination` | yes | Return leg destination airport or city code. |
| `departure_date` | yes | Outbound date in `YYYY-MM-DD` format. |
| `return_date` | yes | Return date in `YYYY-MM-DD` format. |
| `airline`, `sort`, `max_stops`, `max_results`, `minimum_layover_minutes`, `max_duration_hours` | no | Same meaning as round-trip search. |

```bash
curl "http://localhost:8000/api/trips/search/open-jaw?origin=YUL&outbound_destination=YVR&return_origin=YVR&final_destination=YYZ&departure_date=2026-05-01&return_date=2026-05-08"
```

The response uses `type: "open_jaw"` and has two `legs`: `outbound` and `return`.

## Multi-City Search

```http
GET /api/trips/search/multi-city
```

Pass `legs` as a query array. Between 1 and 5 legs are supported.

| Parameter | Required | Description |
| --- | --- | --- |
| `legs[*][origin]` | yes | Leg origin airport or city code. |
| `legs[*][destination]` | yes | Leg destination airport or city code. |
| `legs[*][departure_date]` | yes | Leg departure date in `YYYY-MM-DD` format. |
| `airline`, `sort`, `max_stops`, `max_results`, `minimum_layover_minutes`, `max_duration_hours` | no | Same meaning as one-way search. |

```bash
curl -G "http://localhost:8000/api/trips/search/multi-city" \
  --data-urlencode "legs[0][origin]=YUL" \
  --data-urlencode "legs[0][destination]=YVR" \
  --data-urlencode "legs[0][departure_date]=2026-05-01" \
  --data-urlencode "legs[1][origin]=YVR" \
  --data-urlencode "legs[1][destination]=YYZ" \
  --data-urlencode "legs[1][departure_date]=2026-05-03"
```

The response uses `type: "multi_city"` and has one `legs` item per requested leg.

## Validation Behavior

Invalid requests return Laravel validation responses with HTTP `422`.

Common validation cases:

- missing origin, destination, or date
- invalid date format
- invalid sort key
- `max_stops` outside `0..4`
- `max_results` outside `1..100`
- departure date before creation time
- departure date more than 365 days after creation time
- return date before departure date

No-route searches return HTTP `200` with an empty `data` array.
