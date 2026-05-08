# Design: Integration — OpenProject

> Umbrella decisions apply
>
> **Cross-repo note**: file paths under `nextcloud-vue/src/...` or bare component names (`CnXxxTab`, `CnXxxCard`) are **expected locations** in the `@conduction/nextcloud-vue` shared library, not binding spec. The frontend implementation PR lands in that separate repo and MAY choose different paths. This is the first external-storage leaf — extra care around the OpenConnector boundary.

## Approach

`OpenProjectProvider` declares `storage='external'`. CRUD delegates to `ExternalIntegrationRouter` (umbrella dispatch helper), which resolves the OpenConnector source `openproject` and invokes operations with object context. No local state in OR beyond link-ids (which are OpenProject WP ids, stored transiently if at all — OpenConnector may provide pairing).

```
CnOpenProjectTab ──► /api/objects/.../openproject
                         │
                         ▼
              IntegrationRegistry.get('openproject')
                         │
              storage='external' → ExternalIntegrationRouter
                         │
              OpenConnector source 'openproject' (OAuth2)
                         │
                         ▼
              OpenProject REST API
```

## Architecture Decisions

### AD-1: OpenConnector source name is conventional (`openproject`)

**Decision**: `OpenProjectProvider::getOpenConnectorSource()` returns `'openproject'`. If a user names their OpenConnector source differently, they re-alias. The provider does not probe for multiple candidate names.

**Why**: Predictable auth configuration for admins. "Which source does the integration use?" has one answer.

### AD-2: No local caching beyond request-scope

**Decision**: `ExternalIntegrationRouter` caches responses within a single request (via `RequestScopedCache`) but does not persist WP metadata across requests.

**Why**: OpenProject WP state (status, assignee) changes independently of OR. Any persistence risks staleness. OpenConnector may add its own caching layer later; the provider doesn't duplicate it.

**Trade-off**: Every dashboard render fetches fresh. Mitigated by request-scope cache (multiple same-object references hit once) and reasonable pagination.

### AD-3: Auth expiry triggers a clear UI state, not silent failure

**Decision**: When OpenConnector reports `authStatus: 'expired'`, the tab shows "Authorisation expired — reconnect in OpenConnector" with a link-out. Calls don't silently 401-fail.

**Why**: OAuth expiry is a common and fixable state. Surfacing it is essential — silent failure creates debugging mystery.

### AD-4: Provider contract additions — `getOpenConnectorSource()`

**Decision**: Add `getOpenConnectorSource(): ?string` to providers with `storage='external'`. Returns the OpenConnector source id. Non-external providers return null.

**Why**: The registry needs this for routing. Putting it on the interface (vs a separate external-provider sub-interface) keeps the dispatch logic uniform.

**Trade-off**: Minor — a method that returns null for most providers. Acceptable; well-scoped.

**Umbrella coordination**: This adds a method to the umbrella's `IntegrationProvider` interface. Logged as a micro-update to umbrella tasks.

## Files Affected

### Umbrella coordination
- Update umbrella `IntegrationProvider` interface to add `getOpenConnectorSource(): ?string` (tracked in umbrella tasks.md)

### Backend (new)
- `lib/Service/Integration/Providers/OpenProjectProvider.php`
- OpenConnector source config template `config/openconnector-sources/openproject.yaml`
- Unit tests (with OpenConnector client mocked)

### Backend (modified)
- `Application.php`, `routes.php` (the umbrella already supports external routing — no new routes needed; existing sub-resource endpoint dispatches via registry)

### Frontend (new)
- `CnOpenProjectTab/*` — list, link-by-id, link-by-URL, status badges, assignee
- `CnOpenProjectCard/*` — 4 surfaces (dashboards show open WPs; detail shows linked WPs + progress; single-entity is WP chip with status badge)
- `src/integrations/builtin/openproject.js` — registration
- Barrels + tests

## Risks

| Risk | Mitigation |
|---|---|
| OpenConnector source missing / misconfigured | Provider's `isEnabled()` returns false; tab hidden; admin UI prompts setup |
| OpenProject API version drift | OpenConnector owns the adapter; provider stays thin |
| Rate limits on OpenProject API | OpenConnector handles rate limiting; surface as `degraded` health if hit |
| Large WP lists on dashboard surface | Server-side filter + pagination via OpenConnector parameters |
| OAuth token refresh races | OpenConnector manages; provider assumes tokens are valid or `expired` is surfaced |

## Open questions

1. **Create WP from OR?** — First iteration: no. Link-existing only. Create-WP is a Wave 3.5 leaf if demand appears.
2. **Bi-directional link (OpenProject shows OR object too)?** — Out of scope here. Could be a follow-up that uses OpenProject custom fields with OR object URI.
