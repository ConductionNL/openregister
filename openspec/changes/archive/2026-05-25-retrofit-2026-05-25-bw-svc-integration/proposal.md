# Retrofit — backend coverage: Service/Integration (annotation-only)

## Why

Annotation-only (Bucket 1 style) coverage pass over the
`lib/Service/Integration/` cluster (ADR-019 pluggable integration
registry). The scanner flagged 93 uncovered methods across the provider
contract, the registry, the external-call router, the pagination /
reference / query-time helpers, and 23 concrete `IntegrationProvider`
implementations (5 builtin + 18 leaf providers).

This is almost entirely an annotation pass: **no new capabilities are
minted, and exactly one REQ is added** to the merged
`generic-integrations` spec — the "canonical pagination envelope" requirement, retroactively specifying the
`PaginatedResult` envelope-normalisation behavior, which is genuinely
uncovered in the merged specs (the in-flight `pluggable-integration-registry`
change describes endpoint pagination, but the merged `generic-integrations`
spec has no pagination/envelope requirement). Every OTHER behavior in this
cluster is already owned by an existing change or spec:

- the **provider contract + registry + router + helpers** are owned by
  the in-flight `pluggable-integration-registry` umbrella (tasks 1-19),
  whose generic contract is also captured in the merged
  `generic-integrations` and `integration-shares` specs;
- each **leaf provider** is owned by its own `integration-{name}`
  change (e.g. `integration-xwiki`, `integration-deck`,
  `integration-email`, …) or, for the activity provider, by
  `retrofit-2026-05-24-activity-provider`.

Per the guardrail "don't duplicate the registry REQs already in the
in-flight change", the contract REQs are NOT re-authored here — the
flagged methods are retroactively annotated to the change/task that
already owns them, the single genuinely-uncovered behavior
(`PaginatedResult`) gets one new REQ, and the repetitive per-provider
boilerplate is `@spec exclude`d with a reason.

## What Changes

- **ADD** one REQ to the merged `generic-integrations` spec — the
  "canonical pagination envelope" requirement reverse-specifying
  `PaginatedResult` envelope normalisation.
- **ANNOTATE** 55 behavioral methods with `@spec` pointers to the
  change/task that already owns them (`pluggable-integration-registry`,
  the per-leaf `integration-*` changes, `retrofit-2026-05-24-activity-provider`).
- **EXCLUDE** 37 boilerplate methods (`@spec exclude <reason>`).
- No production code changes — docblock-only edits.

## Outcome

- **93 methods classified**: every method ends with either
  `@spec openspec/...` (annotated to a REQ or an owning change) or
  `@spec exclude <reason>` (boilerplate).
- **55 annotated** — the genuinely behavioral entrypoints: registry
  registration/lookup/filtering, external-call routing + failure
  classification, pagination-envelope normalisation (`PaginatedResult`
  `fromMixed` + `toArray` → the new pagination-envelope requirement), reference-type
  validation, query-time 501 translation, and each leaf provider's
  `list` / `get` / `create` / `update` / `delete` / `authRequirements`
  read+write path.
- **37 excluded** as boilerplate: the `IntegrationProvider` interface
  method declarations (contract shape, no behavior), the
  `AbstractIntegrationProvider` default-throwing CRUD / trivial-default
  stubs, the `FilesProvider::create` / `TagsProvider::create`
  NotImplemented stubs, the `MarkerLookupTrait` LIKE-scan helper, the
  per-leaf `health` descriptors that only echo a static enabled/disabled
  status, the `isEnabled` predicate, and `SharesProvider`'s anonymous
  PSR-11 adapter `get` / `has`.
- **1 new REQ** (`generic-integrations`, "canonical pagination envelope") — the
  `PaginatedResult` envelope normalisation, genuinely uncovered in the
  merged specs; all other contract behavior is owned upstream (see above).

## Annotated (55 methods)

### pluggable-integration-registry (contract + registry + helpers)

- `IntegrationRegistry` — `addProvider` (collision detection + external-source
  rejection, AD-13/AD-4, task-3), `withProviders` (test-seam replace, task-3),
  `list` / `listIds` / `get` / `getEnabled` (registry read surface + stage-1
  enabled filter, task-3)
- `ExternalIntegrationRouter` — `call` (dispatch + 3-cause failure classification,
  AD-23, task-4), `probe` (cheap health check for admin UI / OCS, task-4)
- `PaginatedResult` — `fromMixed` + `toArray` (permissive envelope
  normalisation onto the canonical `{items,total,nextCursor}` shape) →
  the new `generic-integrations` "canonical pagination envelope" requirement
- `PropertyReferenceTypeValidator` — `validate` / `validateAll` (`referenceType`
  marker validation against the registry, AD-18, task-10)
- `QueryTimeContract` — `buildHttpBody` (NotImplemented → HTTP 501 envelope for
  query-time mutation rejection, AD-22, task-6)

### Leaf provider read+write paths (per-leaf integration-* changes)

- `XwikiProvider` — `list` / `get` / `create` / `update` / `delete` /
  `authRequirements` → `integration-xwiki` (external, OpenConnector-routed)
- `OpenProjectProvider` — `list` / `get` / `create` / `update` / `delete` /
  `authRequirements` → `integration-openproject` (external)
- `DeckProvider` — `list` / `create` / `delete` → `integration-deck` (link-table CRUD)
- `EmailProvider` — `list` / `create` / `delete` → `integration-email`
- `FilesProvider` — `list` → pluggable-integration-registry task-12 (builtin, magic-column read)
- `NotesProvider` — `list` / `create` / `update` / `delete` → pluggable-integration-registry task-13 (builtin)
- `TasksProvider` — `list` / `create` / `update` / `delete` → pluggable-integration-registry task-14 (builtin)
- `TagsProvider` — `list` / `create` → pluggable-integration-registry task-15 (builtin)
- `AuditTrailProvider` — `list` → pluggable-integration-registry task-16 (builtin, query-time)
- `SharesProvider` — `list` / `delete` → `integration-shares` (query-time aggregation + read+revoke only)
- The query-time / link-table `list` reads on
  `AnalyticsProvider`, `BookmarksProvider`, `CollectivesProvider`, `CospendProvider`,
  `FlowProvider`, `FormsProvider`, `MapsProvider`, `PhotosProvider`, `PollsProvider`,
  `TalkProvider`, `TimeProvider` → their respective `integration-{name}` changes

## Excluded as boilerplate (37 methods)

Two reasons drive an exclude:

**(a) Trivial / contract-shape, no standalone behavior.** The
`IntegrationProvider` interface method *declarations* (7 — pure
contract, behavior lives in implementations), the
`AbstractIntegrationProvider` default-throwing CRUD stubs +
`authRequirements`/`requiresPermission` defaults (the
`NotImplementedException` defaults are owned by the registry contract,
not per-method behavior), the `isEnabled()` predicates (one-line
`return true` or `IAppManager::isInstalled()`), and per-leaf `health()`
descriptors that only echo a static `enabled?ok:unavailable` status.

**(b) Shared helper, no per-method contract.**
`MarkerLookupTrait::findByMarker` (a defensive LIKE-scan utility shared
across leaf `list()` impls — the contract is each leaf's `list`, not the
helper) and `SharesProvider`'s anonymous PSR-11 adapter `get` / `has`
(NC `\OCP\Server::get` shim).

The full per-method classification is in `tasks.md`.

## Impact

- **Specs**: `generic-integrations` gains one REQ (canonical pagination envelope); no other spec changes.
- **Code**: docblock-only `@spec`/`@spec exclude` edits across the `lib/Service/Integration/` cluster (provider contract, registry, router, helpers, 23 providers); no runtime behavior changes.
- **Risk**: none — annotation retrofit.

Source: `/tmp/or-scan/bw-svc-integration.json` (93 methods,
`lib/Service/Integration/`). See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
