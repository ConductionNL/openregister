# Design: register-import-auto-create

## Context

`ImportService` has `importFromJson(..., ?Register $register = null)` plus
`importFromExcel`/`importFromCsv`, all taking an optional register. Register and
schema creation already exists via the configuration/register-import path (the
Repair-step register import and `ConfigurationService::importFromApp()` create
registers and schemas from a JSON envelope). Today `importFromJson` assumes the
register is resolved by the caller; when it is not, objects import without a
proper register home or the flow errors opaquely (#1487).

## Goals / Non-goals

**Goals:** a register bundle imports cleanly into an instance that lacks the
register (auto-create), and any un-createable missing-register case fails with a
clear, actionable message; re-import stays idempotent.

**Non-goals:** changing the object upsert semantics; inventing a new bundle
format (reuse the existing register/schema envelope); cross-instance transport
(that is the caller's concern).

## Decisions

### D1 — Detect bundle vs plain object array

`importFromJson` inspects the payload: a **bundle** carries a register
definition (envelope with register slug/title/description and a `schemas`
section, the same shape the register-import path already understands); a **plain
object list** is just objects for an already-resolved register. Only the bundle
form can auto-create.

### D2 — Reuse the existing register/schema creation path

When the target register is absent and the payload is a bundle, delegate
register + schema creation to the existing configuration/register-import
machinery (no duplicated creation logic — ADR-011 reuse). Then import objects
into the freshly-created register. This keeps a single source of truth for how
registers/schemas are materialised (union-merge, slug rules, magic-table
creation all handled there).

### D3 — Clear failure when auto-create is impossible

If the payload references a register that does not exist and is not a bundle
(no creatable register metadata), throw a domain error whose message names the
missing register slug and states the two remedies: create the register first,
or supply a full bundle. The controller surfaces this as an actionable
4xx, not a 500.

### D4 — Idempotency

If the register already exists, skip creation and proceed to the existing
schema/object upsert. Re-importing a bundle MUST NOT create a second register
with the same slug (guard on slug lookup before create). This matches the
existing idempotent import behaviour for schemas/objects.

## Risks / Trade-offs

- **Partial-bundle ambiguity** — a payload that half-describes a register is
  treated as un-creatable and takes the clear-error path (D3), never a
  best-effort partial create that could produce a malformed register.
- **Union-merge on existing register** — reusing the existing path inherits its
  union-merge semantics (and its known "diff vs merge base" caveat); this change
  does not alter them, only routes to them.

## Migration / Rollout

No migration. Additive create path; clearer errors. Existing
import-into-existing-register flows unchanged.
