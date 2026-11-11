---
kind: code
depends_on: []
---

# Proposal: mdm-merge-relinks-every-reference

## Summary

A data steward merges two records that describe the same application, and
every record that pointed at the one that goes away now points at the one that
stays: suites, contracts, connections, reviews. When the steward reverses the
merge inside the window, those references go back where they were. A steward
who clicks "Find duplicates" in an app lands on OpenRegister's duplicates page
with the right register and schema already chosen.

## Halves this closes

Two halves of stackiq's merged change `operations-record-reconciliation`
(stackiq `development` d22033a). Neither has a row in OpenRegister's matrix;
the owner moves pass of 28 Sep 2026 handed them here. Stackiq writes, under
Out of scope:

- "Relinking every reference inside OpenRegister's merge unit, so that a
  reversal restores them too. That is OpenRegister's half (ADR-045: relink and
  reverse on any schema). Until it lands, stackiq's listener re-points
  references after the merge (D3)."
- "Opening OpenRegister's Duplicate candidates page on a given register and
  schema from a link. The page has no query parameters today; the steward picks
  the register and schema there. That is OpenRegister's half."

And in its Risks: "Until OpenRegister relinks inside the merge unit, a
reversal restores the two records but leaves the references stackiq
re-pointed on the survivor." Stackiq's design D3 counts eleven places that
reference a module in its register.

Hydra ADR-045 lists "Reversible merge + audit: Snapshot → relink → recompute →
audit → reverse, on any OR schema." as OpenRegister's.

## What changes

- A merge relinks every reference to a losing record, across the instance,
  onto the survivor: scalar `$ref` fields, arrays of references (dropping a
  duplicate the replacement creates) and relation rows, under the merging
  user's rights.
- Each move is kept in the merge snapshot, and a reversal puts each reference
  back where nobody has changed it since.
- The merge preview lists the references that will move, by schema and count.
- `/duplicates` accepts `?register=<id or slug>&schema=<id or slug>` and opens
  on that pair.

## Out of scope

- App-specific follow-up such as stackiq's `mergedInto` field. Apps keep their
  `ObjectsMergedEvent` listeners for that.

## Impact

- `lib/Service/Merge/MergeService.php` (`executeMerge()` at `:261`,
  `reverseMerge()` at `:453`, beside `relinkReverseFk()` at `:684`).
- New `lib/Service/Merge/ReferenceRelinker.php`.
- `src/views/quality/DuplicatesIndex.vue` and the quality store.
