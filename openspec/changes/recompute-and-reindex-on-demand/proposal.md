---
kind: code
depends_on: [search-quality-operators-and-facets]
---

# Proposal: recompute-and-reindex-on-demand

## Summary

Derived values follow their sources: an administrator recomputes over a selection, and a parent's change reaches its children.

- Rows: 4.17 and 9.15 (not statutory; 9.15 checked applicable for materialised calculations).
- Wave 2, size M.
- Dependencies: `openregister/search-quality-operators-and-facets` (https://github.com/ConductionNL/openregister/issues/4398) supplies the recorded reindex run.
- No Ruben decision bears on it.
- Build rules: openspec/woo-build-rules.md

## Why

Two rows ask that derived values follow their sources on demand and on change. Our column (the round 1 baseline):

| row | capability | ours today |
|---|---|---|
| 4.17 | The product re-derives a computed field on demand over a selection, and says how many it changed | partial: `occ openregister:rematerialise-calculations` (`RematerialiseCalculationsCommand`) re-derives a whole register and schema and reports touched, unchanged and failed. occ only, whole schema only: no selection, no API, no screen |
| 9.15 | A metadata change on a parent record reindexes its children without an officer asking | partial: OpenRegister cascades some derived values (golden records through `SourceRecordRecomputeJob`), and nothing reindexes children on a parent change as a named behaviour |

The plan asked to check first whether any child index row stores parent metadata, and to re-rate 9.15 as not applicable if none does. Checked on `development` at 1dc6a4667:

- File chunks do not. `TextExtractionService::payloadFromText()` stores the file's path, name, type and size, and `owner` and `organisation` from the file row (`FileHandler`), which carries no object metadata; a content hit resolves its owning object at query time (ZKN-CONTENT-001). Nothing to reindex there.
- Child objects do. A calculation may read `@ref.<relation>.<property>` from a related object (`calculations-resolve-references-regardless-of-saver`, `ReferenceResolver`), and its result is materialised in the child's row and searched, sorted and faceted from there. When the parent's property changes, the child keeps the old value until someone runs the occ command.

So 9.15 applies, to materialised calculations that read a parent. That is what this change reindexes.

## What changes

- The rematerialise logic moves out of the command into `CalculationRematerialiser::run(Register, Schema, Selection, bool dryRun): RecomputeResult` (`touched`, `unchanged`, `failed` with ids and reasons). The command calls it unchanged.
- `POST /api/objects/{register}/{schema}/recompute` takes a selection (`ids`, or the object list filters) and `dryRun`. Up to 500 objects it answers synchronously with the counts; above that it queues a recorded run on the operations console (the run kind `search-quality-operators-and-facets` adds for reindex) and returns its id. Same rights as updating the objects.
- The object list gets a bulk action "Recompute calculated fields" over the selected or filtered objects, reporting the counts.
- Parent to child. On every object update, a `CalculationDependencyListener` looks up which schemas have calculations reading the changed schema through a relation, and only when a property those calculations read actually changed, it queues one recompute run for the children that point at the object, deduped per parent the way `SourceRecordRecomputeJob` dedupes per master. The run is visible on the operations console.

## What does not change

- The calculations and how they evaluate.
- Content search, which already resolves its owning object at query time.

## Dependencies and absent apps

- `search-quality-operators-and-facets` (amended, wave 1) supplies the recorded reindex run this change's large recomputes and child recomputes use.
- No other app is called.

## Wave and decision

Wave 2, size M. No decision bears on it. Closes 4.17 and 9.15 (9.15 checked applicable, for materialised calculations).
