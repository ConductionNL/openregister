## ADDED Requirements

### Requirement: The translations sidecar MUST expose a REST surface for search, per-object retrieval, status promotion, and bulk machine-translation

`TranslationController` MUST provide the HTTP surface over the translation sidecar (the `register-i18n` storage layer covers the data model; this requirement covers the API).

- `search` MUST accept optional `query` (full-text), `language`, `status`, `objectUuid`, and `limit` (clamped to 1..1000, default 100), delegate to `TranslationStatusService::search()`, and return `{results, count}`.
- `showByObject` MUST return every translation slot for one object via `TranslationMapper::findByObject($uuid)`, plus a `completeness` block computed against the schema's translatable-property total when a `schema` ref (id/uuid/slug) is supplied and resolvable; the response MUST be `{translations, completeness}`. An unresolvable or absent schema MUST yield an empty `completeness` (non-fatal).
- `setStatus` MUST promote / change the workflow status of one `(uuid, property, language)` slot via `TranslationStatusService::setStatus()`, returning HTTP 400 when `status` is missing or when the service throws `InvalidArgumentException`, otherwise the updated row.
- `bulkTranslate` MUST require `from` and `to` language codes (HTTP 400 otherwise), load the object by uuid (HTTP 404 when not found), delegate to `BulkTranslationService::translateObject()` with the optional `properties` whitelist, and return `{uuid, from, to, translated, skipped}`.

#### Scenario: Search clamps the limit
- **GIVEN** a `search` request with `limit=99999`
- **WHEN** the controller builds the query
- **THEN** the effective limit MUST be clamped to 1000 before calling `TranslationStatusService::search()`
- **AND** the response MUST be `{results, count}`

#### Scenario: Per-object slots with completeness
- **GIVEN** an object with translation slots and a resolvable `schema` ref
- **WHEN** `showByObject` is called with that `schema`
- **THEN** the response MUST include `translations` (serialized slots) and a non-empty `completeness` block
- **AND** when `schema` is omitted or unresolvable, `completeness` MUST be empty and the call MUST still succeed

#### Scenario: Status promotion validates input
- **GIVEN** a `setStatus` request with no `status`
- **THEN** the response MUST be HTTP 400 with `{error: "status is required"}`
- **AND** an `InvalidArgumentException` from the service MUST also map to HTTP 400 with the exception message

#### Scenario: Bulk translate requires from and to and an existing object
- **GIVEN** a `bulkTranslate` request missing `from` or `to`
- **THEN** the response MUST be HTTP 400 with `{error: "from and to are required"}`
- **AND** a request for a non-existent object uuid MUST return HTTP 404 with `{error: "object not found", uuid}`
- **AND** a valid request MUST return `{uuid, from, to, translated, skipped}`

## Notes

- All four endpoints are `@NoCSRFRequired` and carry no explicit RBAC gate in the controller; ownership/visibility is governed by the mappers/services and the route registration. `resolveSchema` deliberately calls `SchemaMapper::find(... _rbac: false, _multitenancy: false)` so completeness can be computed regardless of the caller's schema RBAC — this is read-only metadata (translatable-property count) so the exposure is limited, but worth noting that the schema lookup bypasses tenant scoping.
- `bulkTranslate` updates the sidecar in-place via the service but does NOT persist the translated values onto the object JSON — the docblock states the caller must persist the returned `translated` map to make the object JSONB authoritative. This is a deliberate two-step contract, not a bug.
