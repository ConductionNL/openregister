---
kind: code
depends_on: []
---

## Why

`x-openregister-dedup` match rules and blocking keys currently resolve `field`
against the **top level only** of an object's payload (`$data[$key]` in
`DuplicateDetectionService::blockingTokenFor()` and the pair-compare path). Any
app whose duplicate-relevant data sits under a nested key — pipelinq's
`goldenRecord.name`, `goldenRecord.email` — cannot declare that path directly.
ADR-045 follow-on #2 (`pipelinq-mdm-consume-or-surface`) worked around this by
adding a flattening projection that copies nested fields onto synthetic
top-level `match*` keys before calling into OR, purely to satisfy this
top-level-only read. That projection is app-side plumbing that exists only to
compensate for a gap in OR's declarative resolver — the opposite of ADR-022
(apps consume OR abstractions, not work around their limits).

OpenRegister already treats dotted field paths as the norm for declarative
field references elsewhere in the same idiom — `x-openregister-quality`
rules and the calculation engine's `@self.<path>` tokens both resolve nested
paths. Duplicate detection is the one declarative-field consumer still
top-level-only.

## What Changes

- `DuplicateDetectionService` resolves both blocking-key tokens and match-rule
  field values via a dot-path-aware accessor instead of a direct array-key
  read. A plain key (`"name"`) behaves exactly as before; a dotted key
  (`"goldenRecord.name"`) traverses the nested payload and returns `null` on
  any missing segment (never throws).
- No new annotation shape, no new service, no route/API change — this is a
  resolution-semantics fix inside the existing `findDuplicates()` contract.
- Unblocks a follow-up pipelinq change to delete its `match*` flattening
  projection and declare `goldenRecord.*` paths directly.

This change is **additive and backward-compatible**: existing top-level-only
`x-openregister-dedup` annotations keep working unchanged; only dotted paths
gain new behaviour.

## Capabilities

### New Capabilities

<!-- none -->

### Modified Capabilities

- `duplicate-detection`

## Non-Goals (Deferred)

- **Array-index / wildcard paths** (e.g. `items[0].sku`, `items[*].sku`). Only
  plain dot-separated object-key traversal is in scope, matching the existing
  `x-openregister-quality` / calculation-engine dot-path idiom.
- **Migrating pipelinq's flattening projection.** That is a separate,
  app-side change once this ships.
