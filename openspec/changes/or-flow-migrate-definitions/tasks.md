# Tasks: or-flow-migrate-definitions

Migration is performed **by hand** (decided 2026-08-04 — 14 flows, one instance,
one team; see proposal.md for what that trades away). The dual is the
specification each conversion is checked against, not code that executes it.

- [ ] Export all 14 flows from `oc_openregister_flows` with an UNSCOPED read.
      Measured: an org-scoped read returns 13 — "E2E smoke graph" is in another
      organisation — and one flow is owned by `__system__`. Confirm the export
      has 14 rows before starting.
- [ ] Convert each flow by the graph dual: each old edge becomes a node carrying
      its type/config; nodes connect wherever their edges met at a place; the
      place's id/name becomes the new edge's title.
- [ ] Preserve `initial` by mapping named places to the edges leaving them.
- [ ] Mark any non-terminal sink node `exit: true`, so no migrated flow is
      refused as a dead end.
- [ ] Validate every converted flow through `POST /api/flow/validate` and record
      the verdict per flow. A flow that does not validate is not migrated.
- [ ] Diff each converted flow against its original: same step types, same
      count, same order along every path, same splits and merges.
- [ ] Confirm no run is `running` or `suspended` before writing anything —
      markings reference place names this conversion changes.
- [ ] Back up the 14 original documents before the first write, so any
      conversion can be reverted individually.
- [ ] Write the converted flows back, one at a time, re-reading each after write.
- [ ] Run the Hydra sequencer end-to-end after migration and compare its trace to
      a pre-migration run — it is the flow with splits, merges and terminal
      steps in one document.

## Acceptance criteria

- All 14 flows migrated, including the other-organisation and `__system__`-owned ones.
- Every migrated flow validates against the live engine.
- Step sequence, splits, merges and initial positions unchanged, evidenced by a per-flow diff.
- Place labels survive as edge titles.
- Originals backed up and individually revertible.

## Quality checklist

- The unscoped export is confirmed at 14 rows BEFORE conversion — an org-scoped
  read silently returns 13 and would leave one flow refused at run time.
- Depends on `or-flow-action-nodes`; the engine must accept the new shape before
  any flow is written in it.
- If a second instance with its own flows appears, revisit this decision rather
  than repeating the exercise by hand.
