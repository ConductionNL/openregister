# ADR-005: OpenRegister seeds its own registers via Repair steps, not self-import

**Status**: accepted (documents the decision as implemented)

**Date**: 2026-07-07

## Context

Consuming apps ship a register descriptor JSON in `lib/Settings/` and
OpenRegister imports it when the app is installed. It is natural to assume
OpenRegister does the same for its *own* built-in registers (credential-broker,
DSAR, risk-level metadata). It does not — and that surprises app authors
repeatedly (it recurs as a cross-session "gotcha").

OpenRegister does not self-import its own register JSON at install/upgrade.
Instead, each OR-owned register is materialised by a dedicated `Repair` step
class under `lib/Repair/` (`ImportCredentialBrokerRegister`,
`ImportDsarRegisters`, `RegisterOpenRegisterWithDoriath`,
`RegisterRiskLevelMetadata`, `SeedAppVirtualSchemas`,
`SeedDirectoryVirtualSchemas`) that runs on `occ upgrade`.

The reason is a bootstrap ordering problem: the import machinery
(`ObjectService`, mappers, magic tables) is itself part of OpenRegister, so at
the moment OR's own app boots there is no already-initialised OR to import
into. Repair steps run at a point in the lifecycle where the schema/table
infrastructure is available and can be invoked idempotently.

## Decision

**OpenRegister-owned registers and schemas are seeded by idempotent `Repair`
step classes under `lib/Repair/`, invoked on `occ upgrade`. OpenRegister does
not self-import its own `lib/Settings/*_register.json` at app boot.**

### Numbered rules

#### Rule 1 — OR-owned register JSON is materialised by a Repair step

Any new OR-owned register descriptor MUST be accompanied by a `lib/Repair/`
step that imports it (via `importFromFilePath()` or equivalent). Shipping the
JSON alone does nothing at runtime.

#### Rule 2 — Repair steps are idempotent and slug-matched

Steps MUST be safe to run on every upgrade: match existing registers/schemas by
slug, create-or-update, never duplicate. A second `occ upgrade` must be a no-op.

#### Rule 3 — Descriptor is the source of truth; the step is the delivery

The register JSON in `lib/Settings/` remains the declarative source (reviewable,
diffable). The Repair step is only the delivery mechanism — it MUST NOT embed a
second, divergent copy of the schema inline.

#### Rule 4 — Consuming apps are unaffected

This decision is OR-internal. Consuming apps continue to ship descriptors that
OR imports for them; they do not write Repair steps.

## Consequences

- (+) Avoids the boot-time chicken-and-egg; seeding happens when infrastructure
  is ready.
- (+) Idempotent upgrades; predictable re-seed behaviour.
- (−) An OR-owned register that ships JSON without a Repair step silently never
  appears — a recurring author error this ADR exists to prevent.
- Relationship to ADR-037 (canonical req-id / modular-config): this ADR is about
  *who imports OR's own registers*; ADR-037 is about config fragment structure.
  They are complementary, not overlapping.
