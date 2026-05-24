# openregister-app-manifest capability — retrofit delta (PHP backend)

## ADDED Requirements

### Requirement: REQ-OR-MAN-012 Backend `/api/manifest/{appId}` endpoint returns enriched manifest

OpenRegister SHALL expose `GET /index.php/apps/openregister/api/manifest/{appId}` returning the host app's bundled `src/manifest.json` enriched with a `runtime.user` block per REQ-OR-MAN-013 / 015 / 016.

The endpoint SHALL:

- Be marked `#[PublicPage]`, `#[NoAdminRequired]`, and `#[NoCSRFRequired]` — public access is intentional so unauthenticated callers can still load a manifest and receive `runtime.user = null` (the null is the signal nc-vue uses to gate public pages).
- Validate `appId` against `/^[a-z0-9_-]+$/i`; reject with HTTP 400 (`{"error": "Invalid app ID."}`) when the slug does not match.
- Resolve the calling app's bundle path via `IAppManager::getAppPath()` and load `<path>/src/manifest.json`; return HTTP 404 (`{"error": "Manifest not found for app \"{appId}\"."}`) when the app path resolution throws, the file is not readable, the file cannot be read, or the JSON does not decode.
- Catch any `Throwable` from the enrichment pipeline, log it via the PSR logger at `error` level with `{file, line, appId}` context, and return HTTP 500 (`{"error": "Internal server error."}`).
- Return HTTP 200 with the enriched manifest as JSON on the happy path.

#### Scenario: Endpoint returns enriched manifest for a valid app

- **WHEN** `GET /index.php/apps/openregister/api/manifest/decidesk` is called and `decidesk/src/manifest.json` exists and parses cleanly
- **THEN** the response is HTTP 200 with the manifest body and a `runtime.user` block per REQ-OR-MAN-013

#### Scenario: Invalid app ID is rejected

- **WHEN** the path segment contains a character outside `[a-z0-9_-]` (e.g. `../etc/passwd`)
- **THEN** the response is HTTP 400 and no path resolution is attempted

#### Scenario: Missing or unreadable manifest returns 404

- **WHEN** the app is not installed, the path does not exist, `src/manifest.json` is missing, the file is not readable, or the file content is not valid JSON
- **THEN** the response is HTTP 404 with `error: Manifest not found for app "{appId}".` and a debug-level log entry is written

#### Scenario: Enrichment failure is logged and returns 500

- **WHEN** `ManifestService::getEnrichedManifest()` throws (e.g. evaluator crash)
- **THEN** the response is HTTP 500 with `error: Internal server error.`, the `Throwable` is logged at `error` level with `{file, line, appId}` context, and the raw bundled manifest is NOT returned to the client

---

### Requirement: REQ-OR-MAN-013 Enrichment is no-op without `currentUserSchema`; anonymous request emits `runtime.user = null`

`ManifestService::getEnrichedManifest()` SHALL examine the manifest's top-level `currentUserSchema` key:

- When `currentUserSchema` is absent, null, or the empty string, the method SHALL return the input manifest unchanged (no `runtime.user` is injected).
- When `currentUserSchema` is set but the requesting user is not authenticated (`IUserSession::getUser()` returns `null`), the method SHALL set `manifest.runtime.user = null` and return.
- When the user is authenticated but has no matching profile object (per REQ-OR-MAN-015), the method SHALL set `manifest.runtime.user = {id: <uid>, roles: ["learner"]}` as a minimal fallback.

#### Scenario: Manifest with no currentUserSchema is returned untouched

- **WHEN** the input manifest does not declare `currentUserSchema`
- **THEN** the returned array is structurally identical to the input and no `runtime` block is introduced by this method

#### Scenario: Anonymous request gets null user context

- **WHEN** the manifest declares a valid `currentUserSchema` and `IUserSession::getUser()` returns null
- **THEN** the returned manifest has `runtime.user === null`

#### Scenario: Logged-in user with no profile gets minimal fallback

- **WHEN** the user is authenticated but `resolveUserProfile()` returns null
- **THEN** `runtime.user` is `{id: <nextcloud-uid>, roles: ["learner"]}`

---

### Requirement: REQ-OR-MAN-014 Schema-slug from the manifest is validated before any lookup

`ManifestService::getEnrichedManifest()` SHALL validate the `currentUserSchema` value before passing it to `SchemaMapper::findBySlug()` or the profile lookup. The validation SHALL enforce:

- Type is `string`
- Length ≤ 128 characters (constant `MAX_SLUG_LENGTH`)
- Matches `/^[A-Za-z0-9_\-]{1,128}$/` (constant `SLUG_PATTERN`)

A compromised or malformed manifest MUST NOT be able to steer schema or profile lookups at an attacker-controlled string. When validation fails, the method SHALL fail closed by setting `manifest.runtime.user = null`, returning the manifest, and emitting a `warning`-level log line tagged `[ManifestService] Rejected invalid currentUserSchema slug …`.

#### Scenario: Non-string slug is rejected

- **WHEN** `currentUserSchema` decodes as a number, array, or boolean
- **THEN** `runtime.user` is null, a warning is logged with `var_export($schemaSlug, true)` in the message, and no lookup is performed

#### Scenario: Over-length slug is rejected

- **WHEN** `currentUserSchema` is a string longer than 128 characters
- **THEN** the method short-circuits to `runtime.user = null`

#### Scenario: Slug with disallowed characters is rejected

- **WHEN** `currentUserSchema` contains characters outside `[A-Za-z0-9_-]` (e.g. `/`, `..`, whitespace, non-printable bytes)
- **THEN** the method short-circuits to `runtime.user = null`

---

### Requirement: REQ-OR-MAN-015 User-profile resolution narrows magic table by `ncUserId` with RBAC + multitenancy preserved

`ManifestService::resolveUserProfile()` SHALL locate the requesting user's profile object for a given schema slug by querying the OR object store via `ObjectService::findAll()` with:

- `limit: 1`
- `filters: { schema: <slug>, ncUserId: <nextcloud-uid> }`

The method SHALL NOT disable RBAC or multitenancy when performing this lookup. The `(schema, ncUserId)` filter is a narrowing filter on top of the tenant scope, not a substitute for it. Disabling tenant scoping here would silently return profile objects from other tenants whenever the same Nextcloud UID exists in more than one tenant.

The method SHALL handle:

- Schema not found via `SchemaMapper::findBySlug()` → return `null` and emit a `debug` log line
- Schema lookup throws → catch, emit a `warning`, return `null`
- Profile lookup throws → catch, emit a `warning`, return `null`
- Zero matching profile objects → return `null`
- ≥ 1 matching profile object → return the first

#### Scenario: Profile is found by schema slug + ncUserId

- **WHEN** the schema exists and exactly one object has `ncUserId === <uid>`
- **THEN** the method returns that `ObjectEntity`

#### Scenario: Schema slug not found returns null

- **WHEN** `SchemaMapper::findBySlug(slug)` returns an empty list
- **THEN** the method returns null and logs `[ManifestService] Schema "<slug>" not found` at debug

#### Scenario: Lookup exceptions are caught and logged

- **WHEN** either the schema lookup or the object findAll throws a `Throwable`
- **THEN** the method returns null and emits a `[ManifestService] … failed for …` warning; the exception does NOT propagate to the caller

#### Scenario: RBAC and multitenancy are not disabled

- **WHEN** `resolveUserProfile()` constructs the `findAll` config
- **THEN** no `rbac: false`, `multitenancy: false`, or equivalent override flag is set; the call relies on default tenant/RBAC scoping

---

### Requirement: REQ-OR-MAN-016 `runtime.user` is populated from an explicit allowlist plus non-materialised calculations

`ManifestService::buildUserContext()` SHALL build `runtime.user` from:

1. An explicit field allowlist sourced from the schema configuration key `x-openregister-manifest-user-fields` (constant `FIELD_ALLOWLIST_KEY`). When absent, only the default safe fields (`id`, `roles`) are baseline-allowed.
2. The keys of `x-openregister-calculations` whose entries have `materialise: true` — these are derived values the schema author has already declared safe to surface.
3. Non-materialised `x-openregister-calculations` entries are evaluated at read-time via `CalculationEvaluator::evaluate()` against the profile payload (with a `@self` metadata block matching the listener's shape: `id`, `uuid`, `register`, `schema`, `owner`, `created`, `updated`).

The method SHALL NOT merge `ObjectEntity::getObject()` verbatim into `runtime.user`. The raw payload may contain PII or schema-internal fields; surfacing it unfiltered was the leak path flagged in the original review.

The method SHALL:

- Always overlay the Nextcloud user ID as `runtime.user.id` (overriding any same-named field from the profile)
- Skip non-`array` calculation specs (defensive)
- Catch `EvaluationException` from each calculation independently, log a warning, and continue with remaining calculations
- Pass calculation results through `serialise()`, which converts `DateTimeInterface` values to `DATE_ATOM` strings and otherwise returns the value unchanged

#### Scenario: Allowlist filters the profile payload

- **WHEN** the schema declares `x-openregister-manifest-user-fields: ["displayName", "department"]` and the profile object has additional fields
- **THEN** `runtime.user` contains only `displayName`, `department`, the materialised calc keys, and the always-on `id` — never the un-listed payload keys

#### Scenario: No allowlist surfaces only defaults plus calculations

- **WHEN** the schema configuration has no `x-openregister-manifest-user-fields` key
- **THEN** `runtime.user` contains only `id` (always), any `roles` in the payload (default), and materialised calculation keys

#### Scenario: Non-materialised calculations are evaluated at read-time

- **WHEN** `x-openregister-calculations.fullName` has `materialise: false` and `expression: "firstName ~ ' ' ~ lastName"`
- **THEN** `runtime.user.fullName` is computed from the profile payload and injected via `serialise()`

#### Scenario: Calculation failure does not abort the block

- **WHEN** one calculation expression throws `EvaluationException`
- **THEN** a warning is logged with the calc name and user UID, the failed key is omitted from `runtime.user`, and other calculations still evaluate

#### Scenario: DateTime calculation result is ISO-8601

- **WHEN** a calculation evaluates to a `DateTimeInterface` instance
- **THEN** the serialised value is the `DATE_ATOM`-formatted string of that instant
