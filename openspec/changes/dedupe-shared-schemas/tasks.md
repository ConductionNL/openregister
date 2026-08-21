# Tasks: dedupe-shared-schemas

## 1. Detection & attribution

- [ ] 1.1 Query building: find every schema id referenced by >1 register (registers.schemas JSON arrays).
- [ ] 1.2 Canonical-owner attribution: match each referencing register's app configuration
      (register.json / register.d fragments, resolved the same way SettingsService merges them)
      against the current schema entity content; classify per schema: exactly-one-match /
      no-match / multi-match.
- [ ] 1.3 `--keep <registerId>` override for no-match / multi-match schemas; refuse `--write`
      for unattributed shared schemas without it.

## 2. Split & relink

- [ ] 2.1 Clone path A (preferred): create the non-canonical register's schema from its OWN app
      configuration definition, reusing the ImportHandler create path so the per-register
      slug-uniqueness behaviour applies.
- [ ] 2.2 Clone path B (fallback, no configuration available): copy the current entity content
      into a new schema row.
- [ ] 2.3 Rewrite register.schemas linkage old id → new id, preserving order.

## 3. Data migration

- [ ] 3.1 Move rows `table_{reg}_{oldId}` → `table_{reg}_{newId}` (create target table via the
      magic-table DDL for the restored definition; INSERT-SELECT with column mapping).
- [ ] 3.2 Report source columns without a destination column; never drop silently. `--strict`
      turns unmapped columns into a refusal.
- [ ] 3.3 Update object rows' `_schema` metadata and any denormalised schema references
      (folders, uri) the codebase keeps.

## 4. Command surface & safety

- [ ] 4.1 `occ openregister:registers:dedupe-shared-schemas` — dry-run default, `--write`,
      `--register`, `--keep`, `--strict`; output format mirroring relink-schemas.
- [ ] 4.2 Must-PASS control: instance with a shared schema pair → dry-run lists it, `--write`
      splits it, app reimport after the split does NOT re-share (regression guard on the
      ImportHandler fix).
- [ ] 4.3 Must-FAIL control: healthy instance → dry-run reports nothing and `--write` changes
      nothing (idempotence: second run is a no-op).
- [ ] 4.4 Unit tests for attribution matrix (one-match / no-match / multi-match) and column
      mapping edge cases.

## 5. Docs

- [ ] 5.1 Admin docs page next to relink-schemas: when drift happens, how to read the dry-run,
      the planix/pipelinq case as the worked example (openregister#2689).
