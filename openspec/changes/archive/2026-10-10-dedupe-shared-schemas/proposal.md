---
kind: code
---

# Proposal: dedupe-shared-schemas

## Why

The slug-collision family is being closed on two fronts: resolution scoping
(`register-scoped-schema-slug-resolution`, `register-scoped-slug-resolution`,
`schema-slug-cross-app-scoping`) and the import side (the per-register
slug-uniqueness fix in `ImportHandler`, which stops NEW cross-register reuse).

Neither front repairs the damage already done. Registers that came to share a
schema entity in the pre-fix era keep co-owning its definition: every register
import that touches the shared entity rewrites it for all referencing registers
— last import wins, instance-wide. `occ openregister:registers:relink-schemas`
ADDS lost linkage but has no counterpart that SPLITS wrongly shared entities.

Observed on the shared dev instance (2026-08-21, openregister#2689): the
`planix` (19) and `pipelinq` (16) registers both referenced schema entities
task=74, project=159, timeEntry=161. Schema 161 held planix's 6-property
timeEntry; pipelinq's own definition (hours, billingCategory, client, project,
WIP/billing-sync — the model its billing features depend on) was gone from the
instance. A planix schema extension transparently changed pipelinq's `task`
definition. `relink-schemas` reported 47 registers with recoverable linkage on
the same instance — the same era of drift.

## What Changes

A repair command, `occ openregister:registers:dedupe-shared-schemas`, mirroring
`relink-schemas` in shape (dry-run by default, `--write`, `--register`):

1. **Detect**: every schema id referenced by more than one register.
2. **Attribute**: for each shared schema, determine the canonical owner — the
   register whose app configuration (register.json / register.d) declares a
   definition matching the current entity content; when no configuration
   matches (or several do), report and require an explicit
   `--keep <registerId>` per schema rather than guessing.
3. **Split**: every non-canonical register gets its own new schema entity,
   built from that register's own app configuration when available (the
   import-side fix then keeps it isolated forever), else cloned from the
   current entity content.
4. **Relink**: rewrite the register's schema linkage to the new id.
5. **Migrate data**: move the register's magic-table rows from
   `table_{reg}_{oldId}` to `table_{reg}_{newId}` with column mapping per the
   restored definition; report columns that have no destination instead of
   dropping them silently.
6. **Report**: per register/schema, what was split, what moved, what needs a
   follow-up app reimport.

## Validation of the algorithm

Steps 1–4 were executed manually on the shared dev instance for the
planix/pipelinq pair (step 5 was unnecessary — the affected rows were demo
seeds): unlinking 74/159/161 from register 16 and re-running pipelinq's
configuration import produced three new, correctly-defined, register-private
schemas (9463/9464/9465) with planix untouched — confirming the import-side
fix makes the split durable and the repair is mechanical.

## Impact

- New command class alongside `RelinkRegisterSchemasCommand`; no behaviour
  change for healthy instances (dry-run reports nothing).
- Instances repaired this way stop exhibiting cross-app schema bleed; app
  register imports become safe to re-run.
- Relates to: openregister#2689, the three resolution-scoping changes, and the
  ImportHandler per-register slug-uniqueness fix.
