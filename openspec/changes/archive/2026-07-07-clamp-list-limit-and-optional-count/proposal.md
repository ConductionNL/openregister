---
kind: fix
depends_on: []
adr: openspec/architecture/adr-009-performance-invariants.md
---

## Why

Two list-endpoint issues — one a DoS/OOM vector, one wasted work per request:

1. **Unbounded `_limit` (HIGH — trivial DoS/OOM).**
   `ObjectsController` reads `$limit = (int) ($params['limit'] ?? $params['_limit'] ?? 20)`
   (`lib/Controller/ObjectsController.php:655`) with no upper clamp, and
   `QueryHandler` only enforces a lower bound: `$limit = max(0, (int) ($query['_limit'] ?? 20))`
   (`lib/Service/Object/QueryHandler.php:344`). A request with `?_limit=1000000`
   forces loading a million rows plus render/extend in one request — a trivial
   way for any authenticated user to OOM the worker on every list route.

2. **Mandatory COUNT on every list call (MED).**
   `QueryHandler` always builds `$countQuery` and calls `searchObjectsPaginated(...)`
   with it (`lib/Service/Object/QueryHandler.php:367-386`); there is no way to skip
   the total. A filtered `COUNT(*)` on a large object table runs on every page
   fetch — including infinite-scroll/streaming clients that never display a total —
   roughly doubling DB work per list request.

## What Changes

- Clamp the effective page size to a hard maximum (e.g. `min($limit, 1000)`),
  applied centrally (in `getConfig()` / `QueryHandler`) so every list route is
  protected regardless of the entry controller.
- Support a `_count=false` (a.k.a. `_noTotal`) flag that skips the COUNT query and
  returns `total: null`, so clients that don't need the total don't pay for it.

## Impact

- Affected: `lib/Controller/ObjectsController.php`, `lib/Service/Object/QueryHandler.php`.
- Behavioural change: `_limit` above the cap is silently clamped (document the
  max) — a client wanting more must page. `_count=false` is additive/opt-in.
- Risk: none material; the clamp only affects abusive/oversized requests. Pick the
  cap to comfortably exceed legitimate page sizes (1000 is generous for UI lists).
