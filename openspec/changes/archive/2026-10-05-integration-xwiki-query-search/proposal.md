---
kind: code
status: proposed
---

## Why

The xWiki integration leaf is object-linked: every public path
(`XwikiLinksController` `index`/`link`/`createAndLink`/`destroy`, and the
`available` picker) is scoped to an OR object, or framed as a "pick a page to
link" browse. A consuming app that just wants to **search the knowledge base by
free text and render the hits** — e.g. pipelinq's xWiki dashboard widget — has
no clean, object-independent OR endpoint to call. Today pipelinq works around
this with a bespoke `OCA\Pipelinq\Service\XWikiService` that holds its own
`xwiki_direct_url`, talks XML to xWiki directly, and duplicates the base-URL
resolution OR already owns via the OpenConnector `xwiki` source.

`XwikiLinkService::getAvailablePages($search)` already resolves the base URL
through the OpenConnector source and free-text-searches pages — but it is
shaped as a link-picker (`{ results, total }`, no pagination) and routed at
`/api/integrations/xwiki/available`, semantically "pages I can link". It is the
right plumbing behind the wrong door.

## What Changes

- **Add a query-first search surface** to `XwikiLinkService`:
  `searchPages(?string $query, int $limit = 25, int $offset = 0)` — reuses the
  existing `XwikiProvider` browse path (base URL resolved from the OpenConnector
  `xwiki` source) but returns a paginated `{ results, total, limit, offset }`
  envelope and degrades null-safe to `{ unavailable, cause, … }` when the source
  is unconfigured or the upstream is down (AD-23). Object-independent: empty OR
  context, free-text query, `_limit`/`_page` paging passed to the source.
- **Expose it over HTTP** as `GET /api/integrations/xwiki/search?q=&limit=&offset=`
  on `XwikiLinksController::search()` (`@NoAdminRequired`, `@NoCSRFRequired`),
  mapping the unconfigured/down state to `503 { error, details.cause }` like the
  `available` picker.
- **No change** to the existing object-linked CRUD, the picker, the provider, or
  the router — this is a thin additional read-only door onto the plumbing that
  already exists.

## Capabilities

### Modified Capabilities
- `integration-xwiki`: gains a requirement for an object-independent, paginated,
  free-text page-search surface resolved through the OpenConnector `xwiki`
  source, exposed at `GET /api/integrations/xwiki/search`.

## Impact

- **Code:** `lib/Service/XwikiLinkService.php` (`searchPages()` + a small
  `paginate()` helper), `lib/Controller/XwikiLinksController.php` (`search()`),
  `appinfo/routes.php` (one app-global GET route).
- **Tests:** `tests/Unit/Service/XwikiLinkServiceTest.php` — search returns a
  paginated envelope; degrades to `{ unavailable, cause }` (with limit/offset)
  when OpenConnector is absent.
- **Consumers:** pipelinq's xWiki dashboard widget re-points `XWikiService` at
  this endpoint and drops `xwiki_direct_url` (future, separate pipelinq change).
- **Security:** read-only; resolves base URL only from the OpenConnector source
  (no caller-supplied URL); fails closed to empty/503, never fatal.
