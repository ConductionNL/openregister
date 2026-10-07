# Tasks — integration-xwiki-query-search

## 1. Service

- [x] 1.1 Add `XwikiLinkService::searchPages(?string $query, int $limit = 25, int $offset = 0)`
      — reuse the `XwikiProvider` browse path (empty OR context, `_search` /
      `_limit` / `_page` filters), return `{ results, total, limit, offset }`.
- [x] 1.2 Clamp `limit` to 1..100 and `offset` to >= 0.
- [x] 1.3 Degrade null-safe to `{ unavailable, cause, results: [], total: 0, limit, offset }`
      (via `paginate()` helper) when OpenConnector / the source / the upstream
      is unavailable.

## 2. Controller + route

- [x] 2.1 Add `XwikiLinksController::search()` (`@NoAdminRequired`,
      `@NoCSRFRequired`) reading `q`/`search`, `limit`, `offset`; map the
      unconfigured/down descriptor to `503 { error, details.cause }`.
- [x] 2.2 Register `GET /api/integrations/xwiki/search` in `appinfo/routes.php`
      (app-global, before the object-scoped `/xwiki` routes).

## 3. Tests + quality

- [x] 3.1 Unit: `searchPages` returns a paginated envelope on success.
- [x] 3.2 Unit: `searchPages` degrades to `{ unavailable, cause, limit, offset }`
      when OpenConnector is absent.
- [x] 3.3 Unit: limit/offset clamping.
- [x] 3.4 phpcs/lint clean on all touched files (fix what we touch).

## 4. Verify

- [x] 4.1 Live: `GET /api/integrations/xwiki/search?q=test` responds (non-fatal:
      empty/503 against the dormant placeholder source).
