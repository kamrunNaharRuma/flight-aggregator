# Flight Search Aggregator

A Laravel 10 REST API that queries three flight providers simultaneously, deduplicates overlapping results (lowest price wins), caches the unified dataset in Redis, and exposes clean endpoints for searching and booking flights.

Built as a take-home assessment for **iBox Lab — Senior Software Engineer (Backend)**.

---

## What it does

Three mock providers each return the same flights in completely different JSON formats — different field names, different date formats, one uses Unix timestamps. The system:

1. **Fetches** from all three providers
2. **Normalises** each into a canonical `FlightDTO` via the Adapter pattern
3. **Deduplicates** by `flight_number + departure_utc` — keeps the cheapest price when a flight appears in multiple providers
4. **Caches** the unified result in Redis for 5 minutes
5. **Applies** filters and sorting on top of the cache — so `?filter[stops]=0` and `?filter[stops]=1` both serve from the same cache entry

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| **Framework** | Laravel 10 (PHP 8.1) |
| **Database** | PostgreSQL |
| **Cache** | Redis |
| **Tests** | PHPUnit — 30 tests, 111 assertions |

---

## Prerequisites

- PHP 8.1+
- Composer
- PostgreSQL
- Redis

Start both services before running the app:

```bash
sudo service postgresql start
sudo service redis-server start
```

---

## Quick Start

```bash
# 1. Install dependencies
composer install

# 2. Set up environment
cp .env.example .env
# Edit .env — fill in DB_DATABASE, DB_USERNAME, DB_PASSWORD

# 3. Generate app key and run migrations
php artisan key:generate
php artisan migrate

# 4. Start the server
php artisan serve --port=8000
```

The API is now available at `http://localhost:8000`.

---

## API Reference

### Search Flights

```
GET /api/flights/search
```

| Parameter | Required | Type | Description |
|---|---|---|---|
| `from` | ✅ | string | 3-char IATA airport code (e.g. `DAC`) |
| `to` | ✅ | string | 3-char IATA airport code (e.g. `DXB`) |
| `date` | ✅ | string | Date in `Y-m-d` format |
| `passengers` | | integer | Number of passengers — default `1` |
| `sort` | | string | `price` · `duration` · `departure` |
| `order` | | string | `asc` · `desc` — default `asc` |
| `filter[stops]` | | integer | `0` = non-stop, `1` = one stop, etc. |
| `filter[max_price]` | | number | Maximum price **per person** |
| `filter[airline]` | | string | 2-char IATA airline code (e.g. `EK`) |

**Example request:**

```
GET /api/flights/search?from=DAC&to=DXB&date=2026-07-01&passengers=2&sort=price&order=asc&filter[stops]=0
```

**Example response:**

```json
{
  "meta": {
    "providers_queried": 3,
    "providers_succeeded": 3,
    "providers_failed": [],
    "cached": false,
    "passengers": 2,
    "total_unique_flights": 6,
    "filtered_count": 3
  },
  "flights": [
    {
      "id": "a90afce9fbd40ec9",
      "flight_number": "AA205",
      "airline": "AA",
      "from": "DAC",
      "to": "DXB",
      "departure_at": "2026-07-01T22:10:00+00:00",
      "arrival_at": "2026-07-02T02:40:00+00:00",
      "duration_min": 270,
      "stops": 0,
      "price": 280,
      "passengers": 2,
      "total_price": 560,
      "currency": "USD",
      "source": "provider_a"
    }
  ]
}
```

**Meta fields explained:**

| Field | Meaning |
|---|---|
| `providers_queried` | How many providers were called |
| `providers_succeeded` | How many responded successfully |
| `providers_failed` | Names of providers that errored — results are partial if non-empty |
| `cached` | `true` = served from Redis, `false` = freshly fetched |
| `total_unique_flights` | Full deduplicated count — **never affected by filters** |
| `filtered_count` | How many flights matched your current filters |
| `passengers` | Passenger count used for `total_price` calculation |

> If `providers_failed` is non-empty, the response is partial. You may want to retry or inform the user.

---

### Create a Booking

> **Important:** You must search first. The `flight_id` is validated against the Redis cache — you cannot book a flight that hasn't been searched for in the current session.

```
POST /api/bookings
Content-Type: application/json
```

**Request body:**

```json
{
  "flight_id": "4c6a4745cc74c463",
  "passengers": [
    {
      "first_name": "Rahim",
      "last_name": "Chowdhury",
      "type": "adult",
      "document_number": "BD1234567"
    },
    {
      "first_name": "Mina",
      "last_name": "Chowdhury",
      "type": "child",
      "document_number": "BD7654321"
    }
  ]
}
```

Passenger `type` must be one of: `adult` · `child` · `infant`

**Response — 201 Created:**

```json
{
  "reference": "BK-HOGXPN",
  "flight_id": "4c6a4745cc74c463",
  "flight_data": { "flight_number": "EK585", "price": 399, "...": "..." },
  "passengers": ["..."],
  "total_price": 798,
  "currency": "USD",
  "status": "confirmed",
  "created_at": "2026-07-01T10:09:51.000000Z"
}
```

`total_price` = `price per person × number of passengers`

`flight_data` is stored as a **snapshot** — the booked price is locked in at booking time regardless of future cache expiry or provider changes.

**Error responses:**

| Status | Reason |
|---|---|
| `422` | Validation failed — missing or invalid fields |
| `422` | `flight_id` not found in cache — search first |

---

### Retrieve a Booking

```
GET /api/bookings/{reference}
```

**Example:** `GET /api/bookings/BK-HOGXPN`

Returns the full booking record. Returns `404` with a message if the reference does not exist.

---

## Running Tests

```bash
php artisan test
```

```
Tests: 30 passed (111 assertions)
```

| Suite | What's covered |
|---|---|
| Unit · Adapters | Each provider's field mapping, empty response handling |
| Unit · Deduplication | EK585/AA101/BS220 overlap resolution, lowest price wins, stable ID |
| Feature · Search | Full pipeline, caching, filters, sorting, passenger pricing, validation |
| Feature · Provider failure | Partial results when one provider errors, correct meta reporting |
| Feature · Booking | Create, retrieve, price calculation, 404/422 error cases |

---

## Project Structure

```
app/
├── DTOs/
│   ├── FlightDTO.php           # Canonical, immutable flight object
│   └── PassengerDTO.php
├── FlightProviders/
│   ├── Contracts/
│   │   └── FlightProviderInterface.php   # fetch() + getName()
│   ├── Adapters/
│   │   ├── ProviderAAdapter.php          # Maps carrier/fare_usd format
│   │   ├── ProviderBAdapter.php          # Maps airline_code/price.amount format
│   │   └── ProviderCAdapter.php          # Maps iata/Unix timestamps format
│   └── ProviderManager.php               # Calls all adapters, collects failures
├── Services/
│   ├── FlightSearchService.php           # Cache → fetch → dedup → filter → sort
│   ├── DeduplicationService.php          # Merge + lowest price + stable SHA-256 ID
│   └── BookingService.php                # Validate, persist, retrieve bookings
├── Http/
│   ├── Controllers/
│   │   ├── FlightController.php
│   │   ├── BookingController.php
│   │   └── Mock/                         # Simulated external provider endpoints
│   └── Requests/
│       ├── FlightSearchRequest.php
│       └── CreateBookingRequest.php
└── Models/
    └── Booking.php
```

---

## API Documentation

Interactive documentation (all endpoints, parameters, and request/response examples):

**[https://documenter.getpostman.com/view/10334454/2sBXwvKUJt](https://documenter.getpostman.com/view/10334454/2sBXwvKUJt)**

**Recommended test flow:**
1. **Search Flights** — populates Redis cache, copy any `flight_id` from the response
2. **Create Booking** — paste the `flight_id`, submit with passenger details
3. Copy the `reference` from the booking response
4. **Get Booking by Reference** — paste the reference and run

---

For architecture decisions, design patterns, trade-offs, and what would be built next — see [ARCHITECTURE.md](ARCHITECTURE.md).
