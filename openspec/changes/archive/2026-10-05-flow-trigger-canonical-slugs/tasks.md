# Tasks

## 1. Canonical slug seam
- [x] 1.1 Add `FlowTriggerSlugs`: id/uuid/slug in, slug out, pass-through on no resolution
- [x] 1.2 Resolve the fired subject's register/schema to slugs in `FlowTriggerListener`
- [x] 1.3 Normalise derived triggers to slugs in `FlowTriggerIndex` before `replaceFor()`

## 2. Repair
- [x] 2.1 Confirm `BackfillFlowTriggerIndex` (registered post-migration) rewrites existing rows through the normalising writer

## 3. Tests
- [x] 3.1 Listener-level: an object arriving with ids matches a trigger declared with slugs
- [x] 3.2 Write-side: a trigger node holding ids indexes as slug rows (and the repair rebuild proves the rewrite)
- [x] 3.3 `FlowTriggerSlugs` unit: id resolves, slug is idempotent, unresolvable passes through
