# Trigger matching speaks slugs on both sides

## Why

An object event fires with the object's NUMERIC register and schema ids
(`16`/`26`), while the trigger index and the flow trigger columns hold what
the flow's authoring surface wrote — for an imported `x-openregister-flows`
declaration, SLUGS (`dossiq`/`case`), because an app shipping a flow cannot
know an instance's row ids. `FlowTriggerMapper::flowUuidsFor()` and
`FlowMapper::findByTrigger()` compare the two literally, so `16` never equals
`dossiq` and the trigger never fires.

Measured 2026-09-01 on a clean instance (dossiq 0.3.11-unstable, openregister
2.0.13-unstable): three cases created with the imported flow enabled and owned
queued NOTHING and logged nothing. Hand-inserting an index row keyed `16/26`
made the next creation queue a run — proving the match, not the engine, was
the broken seam. The shipped `Bezwaar advies` flow has the same defect.

## What changes

The slug is canonical on the matching surface — the index column is literally
`schema_slug`, and every matcher documents its parameters as slugs — so both
sides are resolved to slugs at their single seams:

- `FlowTriggerSlugs` is the one resolver: id, uuid or slug in, slug out,
  pass-through (never dropped) when the identifier resolves to nothing.
- `FlowTriggerListener` resolves the fired object's ids through it, so the
  subject a run is queued with carries slugs.
- `FlowTriggerIndex` normalises every derived trigger through it before
  writing, so a builder-authored node holding numeric ids still produces slug
  rows, and two nodes naming one triple through different identifiers collapse
  to one row.
- The registered `BackfillFlowTriggerIndex` repair step (post-migration, every
  upgrade) rebuilds the index through the same writer, which rewrites existing
  id-keyed rows into the slug vocabulary — already-imported flows start firing
  without being re-saved.
- `EventCatalogListener` is retired. It was registered for the SAME seven
  object events as `FlowTriggerListener`, each handler calling
  `FlowTriggerService::fire()` — a double-fire that was invisible only while
  both spoke the wrong vocabulary and matched nothing. Fixing the vocabulary
  with both registered would queue every matched flow twice per event.
  `FlowTriggerListener` is the superset (user attribution, transition
  context), so it is the one path kept.

## Impact

- Affected specs: flow-engine (trigger matching)
- Affected code: `lib/Listener/FlowTriggerListener.php`,
  `lib/Service/Flow/FlowTriggerSlugs.php` (new),
  `lib/Service/Flow/FlowTriggerIndex.php`,
  `lib/Repair/BackfillFlowTriggerIndex.php` (docs only; behaviour follows the
  writer it already calls), `lib/Listener/EventCatalogListener.php` (removed),
  `lib/AppInfo/Application.php` (seven duplicate registrations removed)
