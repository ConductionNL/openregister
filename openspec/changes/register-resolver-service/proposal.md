# Register Resolver Service

## Why

Across the Conduction app fleet, every consumer of OpenRegister
hand-rolls the same boilerplate: read an `IAppConfig` value of the form
`<context>_register` / `<context>_schema`, fall back to a sensible
default, look up the matching `Register` / `Schema` entity through
`RegisterMapper` / `SchemaMapper`, cache the result, and proceed.

The OR-abstraction audit (2026-05-03, stream 4 — hardcoded
functionality) found this pattern duplicated in:

- **opencatalogi**: 5 controllers
  (`PublicationsController`, `ListingsController`,
  `CatalogiController`, `ThemesController`, `PagesController`) each
  invoke `getValueString($appName, '<context>_register' | '<context>_schema', '')`
  inline, then resolve the matching ID/slug.
- **pipelinq**: 8 services / background jobs
  (`QueueService`, `DefaultQueueService`, `ContactVcardService`,
  `ContactVcardWriterService`, …) call
  `$appConfig->getValueString(APP_ID, 'register', '')` directly.
- **docudesk**: an `OpenRegisterResolver` helper that shadows the
  same pattern.

Total: ~13 call sites across 3 apps. Every site pays for a stale
default, swallows missing-config errors silently, and lacks
multi-tenant scoping. When OR's `Register.languages` or
`Register.organisation` evolve (per ADR-025 i18n source-of-truth, per
the multi-tenancy enforcement in `MultiTenancyTrait`), every consumer
has to be updated separately.

A first-class `RegisterResolverService` in OR's public service layer
gives consumers one DI-friendly entry point with consistent behaviour:
clear errors when config is missing, optional lazy entity hydration,
tenant-aware resolution, and a single place to evolve the contract as
OR grows.

## What Changes

- Add `OCA\OpenRegister\Service\RegisterResolverService` exposing:
  - `resolveRegisterId(string $appId, string $configKey, ?string $default = null): string` — returns the configured register slug/ID, or throws `MissingConfigException` if unset and no default was provided.
  - `resolveSchemaId(string $appId, string $configKey, ?string $default = null): string` — same shape for schemas.
  - `resolveRegister(string $appId, string $configKey, ?string $default = null): Register` — eager-loads the `Register` entity, throws `RegisterNotFoundException` on lookup failure.
  - `resolveSchema(string $appId, string $configKey, ?string $default = null): Schema` — same shape for schemas.
  - `resolvePair(string $appId, string $registerKey, string $schemaKey): RegisterSchemaPair` — convenience for the common "register + schema" case (returns a value object with both entities).
- Add `MissingConfigException`, `RegisterNotFoundException`,
  `SchemaNotFoundException` as thin wrappers around the existing
  `\Exception` hierarchy so callers can catch domain-specific failures
  without grepping error messages.
- Honour the active organisation context (`MultiTenancyTrait`-style
  scoping) when resolving entities, so a multi-tenant install gets the
  caller's tenant view automatically.
- Cache resolved entities at the request scope (`array<string,Register>`
  / `array<string,Schema>` keyed by `($appId, $configKey)`) to avoid
  repeated mapper hits within a single request.
- Document the canonical `<context>_register` / `<context>_schema`
  config key naming convention in OR's `register-resolver-service`
  capability spec; consumers MUST use that shape so admin UIs and
  diagnostic tooling can enumerate them uniformly.
- Provide a Newman collection covering the success path, the
  missing-config path, the wrong-tenant path, and a register/schema
  that exists in the caller's tenant but not in the requested
  organisation override.

## Problem

Every Conduction app re-implements the same 4–6-line resolver. The
duplication has tangible costs:

1. **Drift** — opencatalogi's resolver caches; pipelinq's doesn't.
   docudesk silently returns empty string on misconfig; opencatalogi
   throws. Consumers see different failure modes for the same root
   cause.
2. **No tenant awareness** — none of the inline call sites know about
   `MultiTenancyTrait`. Today this is masked by the server's session
   stamping, but ADR-025's i18n source-of-truth contract and the
   coming "App Builder" admin UI need explicit multi-tenant resolver
   semantics.
3. **No diagnostics surface** — admins can't enumerate "which
   `<context>_register` configs are set for app X?" without grepping
   PHP source. A standard naming convention + service-level inventory
   query enables admin tooling.
4. **Refactor friction** — when a `Register` field is added (e.g. the
   pending `register-i18n` source-of-truth column), every consumer
   must be updated; with the service, OR controls the call shape.

ADR-022 ("apps consume OR abstractions") covers this in principle.
The audit's top 5 hardcoded-functionality finding ("register/schema
slug resolvers") is the concrete instance to absorb first.

## Proposed Solution

A small, focused new service in `openregister/lib/Service/`. ~150 lines
of PHP plus three exception classes and a `RegisterSchemaPair` value
object. No DB migration; no breaking change to the existing
`RegisterMapper` / `SchemaMapper` API surface.

The five public methods cover the patterns observed in the 13 audited
call sites. The convenience `resolvePair()` matches the "register +
schema together" idiom that opencatalogi controllers and pipelinq
services use most often.

Adoption is opt-in per consumer; existing inline code keeps working.
The per-app adoption changes (`{opencatalogi, pipelinq, docudesk,
procest}-or-adoption`) carry the migrations from inline to service.

## Out of scope

- Admin-UI inventory of all configured `<context>_register` keys
  (future change, gated on real demand once the service has consumers).
- A backwards-compatibility adapter that monkeypatches consumer code
  (consumers migrate via their own opsx changes; OR doesn't need to
  reach in).
- A frontend equivalent in `@conduction/nextcloud-vue` (the resolver is
  PHP-side; the FE consumes resolved values via the existing OR REST
  surface, so no FE change is needed in this proposal).

## See also

- Hydra ADR-022 (apps consume OR abstractions) — the principle this
  realises for the register/schema-resolution pattern.
- `.claude/audit-2026-05-03/04-hardcoded.md` — full audit listing of
  the 13 call sites in opencatalogi (5 controllers), pipelinq (8
  services / jobs), and docudesk (`OpenRegisterResolver`).
- `lib/Db/MultiTenancyTrait.php` — the tenant-scoping behaviour the
  resolver wraps.
