# Tasks: or-silent-field-loss

- [x] `SchemaMapper::find()` — deterministic tie-break (owned before unattributed,
      then lowest id), 2-row read, ambiguity warning naming every candidate.
- [x] `DatabaseConstraintException` — recognise both the pre- and post-2026-07-23
      slug index names.
- [x] `MagicMapper::prepareObjectDataForTable()` — report every discarded
      undeclared property; skip envelope/metadata keys.
- [x] `ImportService` — per-row `warnings` for keys the pre-save filter drops.
- [x] Tests, each with a positive control.
- [ ] Operator: run `occ openregister:schemas:dedup` against the live `agentflow`
      duplicate (5012 / 5020). Data repair, not a code change.
