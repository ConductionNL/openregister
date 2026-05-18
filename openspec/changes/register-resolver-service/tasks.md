# Tasks: Register Resolver Service

## Phase 1 — Core service

- [ ] 1.1 Add `lib/Service/RegisterResolverService.php` with the five public methods (`resolveRegisterId`, `resolveSchemaId`, `resolveRegister`, `resolveSchema`, `resolvePair`). Wire it into `lib/AppInfo/Application.php` as a public DI-resolvable service.
- [ ] 1.2 Add `lib/Service/Resolver/RegisterSchemaPair.php` — readonly value object holding `Register` + `Schema` + the two slug/ID strings (single getter per field, no setters).
- [ ] 1.3 Add the three exception classes under `lib/Service/Resolver/Exception/`: `MissingConfigException` (with `getAppId()` + `getConfigKey()`), `RegisterNotFoundException` (with `getAppId()` + `getConfigKey()` + `getResolvedValue()`), `SchemaNotFoundException` (same accessors as `RegisterNotFoundException`). All extend `\OCA\OpenRegister\Exception\OpenRegisterException`.
- [ ] 1.4 Implement request-scoped caching: `array<string,Register>` keyed by `"{appId}:{configKey}"` after the first lookup; same for schemas. Cache MUST clear if `MultiTenancyTrait` reports a tenant switch within the same request (rare; defensive).

## Phase 2 — Tenant awareness

- [ ] 2.1 Resolve entities through `RegisterMapper` / `SchemaMapper` via existing `findBy*` methods that already apply `applyOrganisationFilter`, so the active organisation context is respected. Add an optional second parameter `?string $organisationUuid = null` to all four resolve methods; when provided, scope the lookup to that organisation explicitly (used by cross-tenant admin tooling).
- [ ] 2.2 When the resolved Register exists but is NOT visible in the caller's organisation, throw `RegisterNotFoundException` (NOT `MissingConfigException`) — the config IS set, the entity IS missing in the caller's tenant. Distinct error path matters for admin diagnostics.

## Phase 3 — Convention check + diagnostics

- [ ] 3.1 Add `RegisterResolverService::enumerateAppConfigs(string $appId): array<string,string>` returning every `<context>_(register|schema)` key currently set for that app — single map; consumers use it for admin-UI inventory.
- [ ] 3.2 Add a `php occ openregister:resolver:list <app-id>` console command that prints the same enumeration so admins have a CLI diagnostic.

## Phase 4 — Spec + tests

- [ ] 4.1 Write `specs/register-resolver-service/spec.md` with five Requirements (one per public method) + the `enumerateAppConfigs` requirement, each with at least one Scenario (happy path) and one error Scenario. Document the canonical `<context>_register` / `<context>_schema` config-key naming in the same spec.
- [ ] 4.2 Add `tests/Unit/Service/RegisterResolverServiceTest.php` covering: happy path; missing config + no default; missing config + default; register exists but not in tenant; schema not found; request-scoped caching (mapper called once across two resolve calls); `resolvePair` convenience; organisation override param; cache clear on tenant switch.
- [ ] 4.3 Add a Newman collection `tests/integration/openregister-register-resolver.postman_collection.json` covering: control object create via the resolver path (round-trip); misconfigured app id (404 with structured error); invalid `<context>_register` key (400 with `MissingConfigException` shape); wrong-tenant scope (404 from `RegisterNotFoundException`).
- [ ] 4.4 Wire the new collection into `tests/newman/run-all.sh::DOMAIN_ORDER` between `crud` and `graphql` (the resolver is foundational; it should run early).

## Phase 5 — Documentation + per-app adoption hooks

- [ ] 5.1 Update `lib/AppInfo/Application.php` PHPDoc to list `RegisterResolverService` in the "public services" header comment so consumer apps see it via IDE autocomplete. Add `docs/services/register-resolver.md` with a 2-paragraph overview + the canonical config-key convention + a "consuming-app migration" snippet (before/after) showing the inline `getValueString` → service call.
- [ ] 5.2 Reference this change from each per-app adoption change's `tasks.md` (opencatalogi, pipelinq, docudesk, procest) so the apps' migrations all consume the service rather than reinvent. Reference this change in `hydra/openspec/architecture/adr-022-apps-consume-or-abstractions.md` under "absorbed abstractions" once shipped (mirrors the audit entry).
