## Context

See `proposal.md` for the motivation. The relevant current state:

- `lib/Db/Flow.php` is a plain `Entity` — one property per table column, all
  hydrated via `addType()` in the constructor, serialised explicitly in
  `jsonSerialize()`. There is no JSON-blob "extra fields" escape hatch; a
  new field means a new column.
- `lib/Db/FlowMapper.php::findAllFlows()`/`countFlows()` already take an
  optional `?string $app` and apply it as `andWhere(eq('app', ...))` only
  when non-empty — the exact shape a second optional filter needs to copy.
- `lib/Controller/FlowController.php::index()` reads `?app=` from the
  request, trims it, and passes `null` through when empty/absent (never an
  empty string) — same shape to copy for `?applicationSlug=`.
- `lib/Service/Flow/FlowService.php::applyEditableFields()` has an
  allowlist of plain string setters (`name`, `description`, `trigger`, …)
  that are copied from the payload only when the key is present, so a
  partial update never blanks an omitted field; `owner`/`organisation`/
  `uuid`/timestamps are deliberately excluded from this allowlist because
  they are server-stamped.
- The `openregister_flows` table has one precedent for an additive,
  backward-compatible column: `Version1Date20260812100000` added `comment`
  as `TEXT, notnull => false, default => null`, guarded by
  `hasColumn('comment')` so a rerun is a no-op.

## Goals / Non-Goals

**Goals:**
- Add `applicationSlug` as a first-class, independently-filterable field,
  following exactly the patterns above — no new mechanism.
- Keep it fully optional: every flow without one keeps working exactly as
  today, at every layer (storage, API read, API write, filtering).

**Non-Goals:**
- Seeding `applicationSlug` on any existing flow (hermiq's backfill is a
  separate, later change in the hermiq repo).
- Any OpenBuild-side consumption of the filter (separate change in the
  openbuild repo).
- Any change to `FlowEngine`/`FlowStepDispatcher` or how a flow runs.
- Validating `applicationSlug` against a real OpenBuild application
  registry — OpenRegister has no dependency on OpenBuild and MUST NOT grow
  one for this. The column is a plain string, exactly like `app`.

## Decisions

**Column type: `STRING`, length 255, nullable, no default (`null`).**
Same shape as `app` (`STRING`, length 64) rather than `comment`'s `TEXT` —
a slug is a short identifier, not free-form prose, and existing slug-like
columns in this table (`app`) are bounded strings. 255 rather than `app`'s
64 because `applicationSlug` is authored by an external system (OpenBuild)
this app does not control the id scheme of, and the cost of extra headroom
is negligible.

**No `notnull`/backfill.** `default => null` and `notnull => false`,
matching the `comment` precedent. A migration that defaulted existing rows
to `''` would make "no virtual-app" indistinguishable from "explicitly
empty", which the filter's `!== null && !== ''` treatment (mirroring `app`)
already relies on being able to skip.

**Filter semantics mirror `app` exactly, including composing with it.**
`findAllFlows()`/`countFlows()` gain `?string $applicationSlug = null`,
applied with the identical `andWhere(eq(...))`-when-non-empty pattern `app`
already uses, added as a second independent `andWhere` — so passing both
`app` and `applicationSlug` narrows by both (an AND, not an OR), which is
what "flows belonging to Hermiq AND to virtual-app hydra" needs. This was
chosen over introducing a generic multi-field filter mechanism: the
codebase's existing pattern for this mapper is one named parameter per
filter, and matching it keeps this change purely additive to a stable
signature rather than a redesign.

**Editable like `name`/`description`, not stamped like `owner`.**
`applicationSlug` is descriptive metadata the flow's author sets, not an
identity or authorization boundary — unlike `owner`/`organisation`, there
is no privilege-escalation concern in letting the client set it. It goes in
`applyEditableFields()`'s plain-string allowlist, with the same
present-key/explicit-null handling as `name` or `comment` (absent key =
untouched, explicit `null` = cleared).

**Add an index on `applicationSlug`, mirroring `app`'s existing shape.**
`app` has its own index (`or_flow_app_idx` on `(app, id)`); `applicationSlug`
gets an equivalent `or_flow_app_slug_idx` on `(applicationSlug, id)`. Decided
against the "revisit later" default because the primary consumer of this
filter (a later, separate OpenBuild change) queries by `applicationSlug`
alone on every app publish/pull cycle — a query pattern that exists from day
one of that change landing, not a hypothetical future one, so there is no
"measure first" period where the index would be premature.

## Risks / Trade-offs

- [A slug collision across two different virtual apps that happen to reuse
  the same string] → Out of this change's control: OpenRegister does not
  own the OpenBuild slug namespace. The filter does exactly what it is
  asked; namespace uniqueness is OpenBuild's concern, same as it already is
  for `applicationSlug` on `openbuild/automation` objects.
- [A future caller assumes `applicationSlug` implies `app`, e.g. filters by
  `applicationSlug` alone expecting it to also scope by owning Nextcloud
  app] → Documented explicitly in the spec delta scenario that composes
  both filters; the two remain orthogonal by design.

## Migration Plan

One additive migration, `Version1Date<new-timestamp>Date...php` (next free
timestamp after `Version1Date20260817120000`), following
`Version1Date20260812100000`'s shape: `hasTable('openregister_flows')` /
`hasColumn('applicationSlug')` guards so it is a no-op on rerun, adds the
column, then adds `or_flow_app_slug_idx` on `(applicationSlug, id)` guarded
by `hasIndex()` so it too is a no-op on rerun. No data migration. Rollback
is dropping the index then the column, which loses no other data since
nothing depends on either yet.

## Open Questions

None — the field name, shape, and filter mechanics all follow directly
from existing code (`app` filtering) and an existing precedent (`comment`
column migration), so there is nothing left to defer.
