# Pluggable Integration Registry

## Why

`LinkedEntityService::TYPE_COLUMN_MAP` is a hardcoded PHP constant
listing the 8 NC entity types that can be linked to OR objects (files,
notes, tasks, calendar events, mail, contacts, deck cards, talk
conversations). `@conduction/nextcloud-vue::CnObjectSidebar` is a Vue
component with 5 hardcoded tabs — a glaring asymmetry the audit's
ADR-019 review confirmed: of the 8 backend-supported types, only 5
have sidebar UI and only 2 have widget components. Adding a new
integration today means modifying both core OR and the shared
component library.

Hydra ADR-019 (Proposed, 2026-04-21) ratifies a two-sided
**integration registry** as the canonical way to declare "things that
can be linked to or rendered alongside an OpenRegister object."
This change is the umbrella implementation cited in ADR-019's
"Implementation reference" section.

## What Changes

- Add `OCA\OpenRegister\Service\Integration\IntegrationProvider`
  PHP interface with: `getId(): string`, `getLabel(): string`,
  `getIcon(): string`, `isEnabled(): bool`,
  `getStorageStrategy(): 'native'|'external'`, `authRequirements():
  array`, `linkedColumnName(): ?string`, `query(string $objectUuid):
  iterable`, `mutate(string $objectUuid, array $payload): void`.
- Add `OCA\OpenRegister\Service\Integration\IntegrationRegistry` —
  DI-resolvable service exposing `register(IntegrationProvider $p)`,
  `listAll(): iterable<IntegrationProvider>`, `listEnabled(): iterable`,
  `listIds(): array<string>`, `getById(string $id): ?IntegrationProvider`,
  `requireById(string $id): IntegrationProvider`. Registry is
  auto-populated from DI tag `IntegrationProvider`.
- Migrate the 8 built-in NC types
  (files / notes / tasks / calendar / mail / contacts / deck / talk)
  into 8 `IntegrationProvider` implementations under
  `lib/Service/Integration/Builtin/`. Delete `TYPE_COLUMN_MAP`.
- Add `OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter`
  for `getStorageStrategy() === 'external'` providers. Routes through
  OpenConnector for credentials (OR does not own them).
- Update `Schema::validateLinkedTypesValue()` to consult
  `IntegrationRegistry::listIds()` instead of the hardcoded constant.
  Validation is permissive on read (warn-only for unknown ids), strict
  on write.
- Add OCS capability advertising registered integrations:
  `GET /ocs/v2.php/cloud/capabilities` MUST include
  `openregister.integrations: ['files', 'notes', ...]`. Mobile +
  partner integrations discover supported types here.
- Add CLI tools: `php occ openregister:integrations:list` (prints
  registered providers) and `bash scripts/scaffold-integration.sh
  <id>` (generates a skeleton — provider PHP, FE registration stub,
  spec delta, tests).
- Add `bash scripts/check-integration-parity.sh` as a CI gate: every
  backend `IntegrationProvider` MUST have a matching FE registration
  (sidebar tab + dashboard widget). Tab-only or widget-only =
  CI-failure. Same script wired into `hydra/scripts/run-hydra-gates.sh`
  as a new gate.
- Companion FE work in `@conduction/nextcloud-vue` (separate change):
  expose `OCA.OpenRegister.integrations.register({...})`, refactor
  `CnObjectSidebar` to consume the registry, add the four widget
  surfaces (`user-dashboard`, `app-dashboard`, `detail-page`,
  `single-entity`).

## Problem

The current "linked things" model is closed; every new integration
costs a synchronised PR across OR core + the shared component
library, and it can't accommodate external services (OpenProject,
XWiki, third-party connectors). The asymmetry between backend-
supported and frontend-rendered types is a chronic source of "why
doesn't this show up?" debugging.

## Proposed Solution

Two-sided registry: PHP interface + DI tag on the backend, JS
register-call on the frontend, paired by `id`. Enforced by a parity
gate so the two sides cannot drift. The 8 built-in types are migrated
in this change; future integrations land as standalone "leaf" changes
hanging off this contract.

## Out of scope

- The companion FE `@conduction/nextcloud-vue` change (separate spec
  in that repo, paired via shared `id`).
- Specific external-service providers (OpenProject, XWiki, …) — each
  ships as its own leaf change.
- Migrating the relations system (`RelationsService` object↔object) to
  this registry — a future change once integrations stabilise.

## See also

- Hydra ADR-019 (integration registry pattern)
- Hydra ADR-022 (apps consume OR abstractions)
- `.claude/audit-2026-05-03/05-adr-staleness.md` — flagged ADR-019 as
  legitimately STILL-OPEN; this is the change that closes it.
