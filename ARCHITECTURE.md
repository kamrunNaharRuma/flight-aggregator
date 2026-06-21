# Architecture

## Overview

The system is built around a clean separation of concerns: data fetching is isolated behind adapters, normalization is handled by a dedicated service, caching is a cross-cutting concern managed by the search service, and persistence is handled by Eloquent.

```
Client
  │
  ├── GET /api/flights/search
  │     └── FlightController
  │           └── FlightSearchService
  │                 ├── Cache::get() ──────── Redis (cache hit → return early)
  │                 ├── ProviderManager
  │                 │     ├── ProviderAAdapter → ProviderAController (mock data)
  │                 │     ├── ProviderBAdapter → ProviderBController (mock data)
  │                 │     └── ProviderCAdapter → ProviderCController (mock data)
  │                 ├── DeduplicationService
  │                 └── Cache::put() ──────── Redis (5-min TTL)
  │
  └── POST /api/bookings
        └── BookingController
              └── BookingService
                    ├── Cache lookup (flight must exist in search cache)
                    └── Booking::create() ─── PostgreSQL
```

---

## Route Flow Diagrams

### Route 1 — `GET /api/flights/search`

```
Client
  │
  ▼
FlightController::search()
  │  ← FlightSearchRequest validates: from(3), to(3), date(Y-m-d), sort, order, filter.*
  │
  ▼
FlightSearchService::search()
  │
  ├── CACHE HIT (Redis key: flights:DAC:DXB:2026-07-01)
  │     └── skip provider calls entirely ──────────────────────────────┐
  │                                                                     │
  └── CACHE MISS                                                        │
        │                                                               │
        ▼                                                               │
      ProviderManager::fetchAll()                                       │
        │                                                               │
        ├── ProviderAAdapter::fetch()                                   │
        │     └── ProviderAController()          raw format:            │
        │           └── FlightDTO[]              carrier / fare_usd     │
        │                                                               │
        ├── ProviderBAdapter::fetch()                                   │
        │     └── ProviderBController()          raw format:            │
        │           └── FlightDTO[]              airline_code / price{} │
        │                                                               │
        └── ProviderCAdapter::fetch()                                   │
              └── ProviderCController()          raw format:            │
                    └── FlightDTO[]              iata / Unix timestamps │
                                                                        │
        10 raw FlightDTOs (with duplicates across providers)            │
        │                                                               │
        ▼                                                               │
      DeduplicationService::deduplicate()                               │
        • key  = flight_number|departure_utc                            │
        • dupe = keep lowest price                                      │
        • id   = sha256(key)[0..15]  (16 hex chars, stable)            │
        │                                                               │
        6 unique FlightDTOs                                             │
        │                                                               │
        ▼                                                               │
      Redis::put("flights:DAC:DXB:2026-07-01", payload, ttl=300)       │
      flights:index updated with new cache key                          │
        │                                                               │
        └────────────────────────────────────────────────────────────┘
                                                                        │
                                                                        ▼
                                                              applyFilters()
                                                              ← filter[stops]
                                                              ← filter[max_price]
                                                              ← filter[airline]
                                                                        │
                                                                        ▼
                                                              applySort()
                                                              ← sort=price|duration|departure
                                                              ← order=asc|desc
                                                                        │
                                                                        ▼
                                                              applyPassengerPricing()
                                                              ← adds passengers + total_price
                                                                        │
                                                                        ▼
                                                              JSON 200 Response
                                                              {
                                                                meta: {
                                                                  providers_queried,
                                                                  providers_succeeded,
                                                                  providers_failed,
                                                                  cached,
                                                                  passengers,
                                                                  total_unique_flights,
                                                                  filtered_count
                                                                },
                                                                flights: [...]
                                                              }
```

---

### Route 2 — `POST /api/bookings`

```
Client
  │  body: { flight_id, passengers[] }
  │
  ▼
BookingController::store()
  │  ← CreateBookingRequest validates: flight_id, passengers[].type in adult|child|infant
  │
  ▼
BookingService::create()
  │
  ▼
resolveFlightFromCache(flight_id)
  │
  ├── Cache::get("flights:index")          → ["flights:DAC:DXB:2026-07-01", ...]
  │
  └── Cache::get("flights:DAC:DXB:...")   → scan flights[] for matching id
        │
        ├── NOT FOUND → throw InvalidArgumentException
        │     └── BookingController catches → JSON 422
        │           { message: "Flight ID [...] not found in cache. Please search first." }
        │
        └── FOUND → flight array (snapshot of price, route, times)
              │
              ▼
            total_price = flight.price × count(passengers)
              │
              ▼
            Booking::create()  →  PostgreSQL bookings table
            {
              reference:   "BK-" + random(6).toUpper(),
              flight_id:   "4c6a4745cc74c463",
              flight_data: { ...snapshot... },   ← JSONB
              passengers:  [...],                 ← JSONB
              total_price: 798.00,
              currency:    "USD",
              status:      "confirmed"
            }
              │
              ▼
            JSON 201 Response  (full Booking model)
```

---

### Route 3 — `GET /api/bookings/{reference}`

```
Client
  │  e.g. GET /api/bookings/BK-HOGXPN
  │
  ▼
BookingController::show()
  │
  ▼
BookingService::findByReference("BK-HOGXPN")
  │
  └── Booking::where('reference', 'BK-HOGXPN')->firstOrFail()
        │
        ├── NOT FOUND → ModelNotFoundException
        │     └── BookingController catches → JSON 404
        │           { message: "Booking [BK-HOGXPN] not found." }
        │
        └── FOUND
              │
              ▼
            JSON 200 Response  (full Booking model)
            {
              id, reference, flight_id,
              flight_data: { ...snapshot... },
              passengers:  [...],
              total_price, currency, status,
              created_at, updated_at
            }
```

---

## Design Patterns

### Adapter Pattern — `app/FlightProviders/`

Each external flight provider has a completely different JSON schema:

| Provider | Field names used |
|----------|-----------------|
| Provider A | `carrier`, `fare_usd`, `flight_no`, ISO 8601 times |
| Provider B | `airline_code`, `price.amount`, `number`, `Y-m-d H:i` times |
| Provider C | `iata`, `total_price`, `code`, Unix timestamps, nested route object |

The `FlightProviderInterface` defines a single contract:

```php
public function fetch(string $from, string $to, string $date): FlightDTO[];
public function getName(): string;
```

Each adapter (`ProviderAAdapter`, `ProviderBAdapter`, `ProviderCAdapter`) implements this interface and translates its provider's raw format into the canonical `FlightDTO`. The rest of the system never sees the raw provider format.

**Why this matters:** Adding a 4th provider means writing one new adapter class and registering it in `AppServiceProvider`. Nothing else changes.

### Service Container — `app/Providers/AppServiceProvider.php`

The `ProviderManager` is bound as a **singleton** in Laravel's service container:

```php
$this->app->singleton(ProviderManager::class, function () {
    return new ProviderManager([
        new ProviderAAdapter(),
        new ProviderBAdapter(),
        new ProviderCAdapter(),
    ]);
});
```

**Why singleton?** `ProviderManager` holds the list of registered adapters. Creating it once per request (not per class instantiation) ensures:
- The same adapter instances are reused — no redundant object construction
- The list of providers is defined in one place, not scattered across controllers or services
- Swapping out or adding a provider requires a change in exactly one file

**Why here and not in the controller?** Wiring dependencies in `AppServiceProvider` is Laravel's idiomatic way of separating *what* a class needs from *how* it gets it. `FlightController` declares it needs a `FlightSearchService`, which needs a `ProviderManager` — the container resolves the full chain automatically. None of those classes need to know how the others are constructed.

---

### Data Transfer Objects (DTOs) — `app/DTOs/`

`FlightDTO` uses `readonly` properties (PHP 8.1):

```php
public readonly string $id;
public readonly float $price;
public readonly Carbon $departureAt;
// ...
```

`readonly` makes the DTO immutable after construction — a flight's data cannot silently change as it passes through the system. This is a hard guarantee enforced by the language, not a convention.

`toArray()` on `FlightDTO` produces the API response shape, including `duration_min` derived from departure/arrival times and ISO 8601 date strings for unambiguous parsing across timezones.

---

### Service Layer — `app/Services/`

**`FlightSearchService`** orchestrates the full search pipeline:

1. Check Redis cache — return immediately on hit
2. Call `ProviderManager::fetchAll()` — collect all provider results
3. Pass to `DeduplicationService::deduplicate()` — normalize to unique flights
4. Store in Redis with 5-minute TTL
5. Apply filters post-cache (so one cache entry serves all filter combinations)
6. Apply sorting post-cache
7. Apply passenger pricing post-cache

**`DeduplicationService`** merges flights using a keyed map:

```
key = flight_number + "|" + departure_utc
```

On collision (same flight from multiple providers), the lowest price wins. After deduplication, each flight gets a stable ID:

```
id = sha256(flight_number|departure_utc)[0..15]   (16 hex chars)
```

The ID is deterministic — the same flight always produces the same ID across requests, making it safe to use as a booking reference.

**`BookingService`** enforces a business rule: you can only book a flight that was returned by a recent search. It looks the `flight_id` up in the Redis search cache before persisting. This prevents orphan bookings for non-existent flights and ensures the booked price matches what the user saw.

---

## Caching Strategy

### What is cached

The full deduplicated flight list for a `from + to + date` combination is cached as a single Redis entry:

```
Key:   flights:DAC:DXB:2026-07-01
Value: { meta: { providers_queried, providers_succeeded, providers_failed }, flights: [...] }
TTL:   300 seconds (5 minutes)
```

### What is NOT cached

- Filters and sorting — applied after retrieval so one cache entry serves `filter[stops]=0`, `filter[stops]=1`, `sort=duration`, etc. without separate cache entries per combination
- Passenger count — `total_price` and `passengers` fields are computed at response time
- `filtered_count` — always recomputed from the live filtered result

### Cache index

A secondary `flights:index` key holds a list of all active search cache keys. `BookingService` uses this to scan for a flight by ID without relying on Redis `KEYS` commands (which would couple the code to Redis and break with other cache drivers).

---

## Database Schema

```sql
CREATE TABLE bookings (
  id          BIGINT          PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
  reference   VARCHAR(12)     UNIQUE NOT NULL,   -- BK-XXXXXX
  flight_id   VARCHAR(16)     NOT NULL,          -- SHA-256 truncated to 16 chars
  flight_data JSONB           NOT NULL,          -- snapshot at booking time
  passengers  JSONB           NOT NULL,          -- array of passenger objects
  total_price DECIMAL(10, 2)  NOT NULL,
  currency    CHAR(3)         NOT NULL DEFAULT 'USD',
  status      VARCHAR         NOT NULL DEFAULT 'confirmed',
  created_at  TIMESTAMP,
  updated_at  TIMESTAMP
);
```

### Why JSONB for `flight_data`

Flight details (price, schedule) are stored as a snapshot at the time of booking. If a provider changes its prices or the cache expires, the booking record still reflects exactly what the customer saw and agreed to pay. JSONB also allows GIN indexing on individual keys if analytical queries are needed later.

### Why auto-increment `id` instead of UUID

The `id` is never exposed to end users — the `reference` (`BK-XXXXXX`) is the customer-facing identifier. For an internal primary key with no exposure requirement, auto-increment BigInt is simpler and performs better on range scans and joins.

---

## API Response Design

### Search response envelope

```json
{
  "meta": {
    "providers_queried": 3,
    "providers_succeeded": 3,
    "providers_failed": [],
    "cached": false,
    "passengers": 2,
    "total_unique_flights": 6,
    "filtered_count": 6
  },
  "flights": [ ... ]
}
```

`total_unique_flights` — always the full deduplicated count across all providers, unaffected by filters. Communicates the true size of the dataset.

`filtered_count` — how many flights matched the current filter combination. A user seeing `filtered_count: 1` but `total_unique_flights: 6` knows 5 other flights exist outside their filter.

`providers_failed` — lists provider names that threw errors. The client knows results are partial and may choose to retry or alert the user.

`cached` — tells the client whether this is a live result or a cached one. Useful for displaying a "last updated" timestamp on the frontend.

---

## Trade-offs and Known Limitations

| Area | Current Approach | Production Alternative |
|------|-----------------|----------------------|
| Mock providers | Direct PHP controller calls | Real HTTP calls to external APIs |
| Cache scanning | `flights:index` list | Dedicated `FlightRepository` abstracting cache access |
| Booking validation | Lookup in search cache | `FlightRepository::findById()` with provider fallback if cache misses |
| Provider calls | Sequential `foreach` | `Http::pool()` for parallel execution |
| Flight ID | 16-char SHA-256 prefix | Full UUID if collision risk is a concern at scale |
| Currency | USD only — all mock data returns USD; `FlightDTO` carries the field and `BookingService` stores it, so the infrastructure is currency-aware | Exchange rate service to convert and normalise across currencies |
| Auth | None | API key or OAuth2 per partner |
| Rate limiting | None | Per-provider circuit breaker + global rate limiter |

---

## What Would Be Built Next

1. **Rate limiting per provider** — prevent hammering a slow provider; fall back gracefully with a warning in meta
2. **Circuit breaker** — if a provider fails repeatedly, skip it entirely for N minutes rather than waiting for a timeout each time
3. **Currency conversion** — normalize all prices to a single currency using an exchange rate service
4. **Webhook on booking status change** — push `booking.confirmed` / `booking.cancelled` events to registered partner URLs
5. **FlightRepository** — decouple `BookingService` from cache internals; allow booking a flight by re-fetching from providers if cache has expired
6. **Pagination** — for routes with many results, return `page` and `per_page` in meta
7. **GIN index on `flight_data`** — enable fast PostgreSQL queries on booking analytics (e.g. most booked airline)
