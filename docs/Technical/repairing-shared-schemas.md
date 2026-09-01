# Repairing schemas shared by several registers

`occ openregister:registers:dedupe-shared-schemas` splits schema entities that
more than one register co-owns, giving each register its own entity and moving
its object rows with it.

It is the counterpart to `occ openregister:registers:relink-schemas`: that
command **adds** linkage a register lost, this one **splits** linkage a register
was never meant to have. Both are dry-run by default.

## When this drift happens

A schema row carries no register column. The relation exists only as a JSON id
list on the register, so nothing on the schema side records who owns it.

Before the per-register slug-uniqueness fix in the import path, an import that
resolved a schema slug **globally** re-used whatever schema row already carried
that slug. Two apps declaring, say, `timeEntry` could therefore end up pointing
at one entity — and from then on every import of either app rewrote the
definition for both. Last import wins, instance-wide.

The symptom is an app whose schema silently changes shape when an unrelated app
is installed or updated: properties disappear, `required` flips, and creates
start failing validation for a field the app never declared.

### The worked example (openregister#2689)

On the shared development instance, registers `planix` (19) and `pipelinq` (16)
both referenced schema entities `task=74`, `project=159` and `timeEntry=161`.

Schema 161 held planix's six-property `timeEntry`. Pipelinq's own definition —
`hours`, `billingCategory`, `client`, `project`, and the WIP/billing-sync fields
its billing features depend on — was gone from the instance entirely. A planix
schema extension had transparently changed pipelinq's `task` definition too.

The import-side fix stops *new* sharing. It does not repair what already
happened; that is what this command is for.

## Reading the dry run

```
occ openregister:registers:dedupe-shared-schemas
```

```
3 schema(s) are shared by more than one register:

  schema 161 (timeEntry) — referenced by registers [16, 19]
      attribution: one-match — owner: register 19 (configuration)
      - register 16 (pipelinq) -> new schema from configuration (openregister_table_16_161, 42 row(s))
          5 column(s) would have no destination: approved, date, description, duration, employee

DRY RUN — nothing was changed. Re-run with --write to apply.
```

Line by line:

- **`referenced by registers [16, 19]`** — every register whose `schemas` list
  carries this id. Ids stored as strings count too.
- **`attribution:`** — how the owner was determined. See below.
- **`-> new schema from configuration`** — the split will rebuild register 16's
  schema from its own `register.json`. `from clone` means the app ships no
  configuration for it any more, so the current entity content is copied
  verbatim instead.
- **`42 row(s)`** — how many object rows will move. `no table` means the pairing
  was never materialised, so there is nothing to migrate.
- **`column(s) would have no destination`** — source columns the restored
  definition has no place for. They are **never dropped silently**: the source
  table is kept (see *What happens to the old table*).

## How attribution works

For each shared schema the command reads the **current entity content** and
compares it against what each referencing register's own app configuration
declares for that slug — `lib/Settings/<appId>_register.json` plus any
`lib/Settings/register.d/*.json` fragments, merged the way the settings loader
merges them.

The comparison is on the **property-name set and the `required` list**, not on
byte equality. The import path stamps defaults, folds `$ref`s and rewrites
descriptions, so byte equality would match nothing. A *lost property* — which is
exactly what the overwrite did — still registers as a difference.

Three outcomes:

| Status | Meaning | Result |
| --- | --- | --- |
| `one-match` | Exactly one register's configuration matches the entity | That register keeps it; the others are split off |
| `no-match` | No configuration matches (both apps have moved on) | **Unattributed** — skipped until you decide |
| `multi-match` | Several configurations match (both declare the same shape) | **Unattributed** — skipped until you decide |

Deliberately *not* used as evidence: the schema's `application` column (on a
shared entity it names whichever app overwrote it last, not the owner) and
"lowest register id" (not evidence at all). The older
`occ openregister:schemas:dedup` command uses both, and therefore always picks a
side.

### Naming an owner yourself

`--write` **refuses** while any schema is unattributed. Guessing an owner is what
produced the damage in the first place, so the command will not do it for you.

```bash
# pin one schema
occ openregister:registers:dedupe-shared-schemas --keep 161:19 --write

# pin several
occ openregister:registers:dedupe-shared-schemas --keep 161:19 --keep 74:19 --write

# one register owns everything attribution could not settle
occ openregister:registers:dedupe-shared-schemas --keep 19 --write
```

The per-schema form always outranks the bare one. A `--keep` naming a register
that does not actually reference the schema is ignored, and the schema stays
unattributed.

## Applying the repair

```bash
occ openregister:registers:dedupe-shared-schemas --write
```

For every non-canonical register the command, in one transaction:

1. **Creates its own schema** — preferably from that register's own
   configuration, through the same import path the app uses. That is what makes
   the split durable: the app's next import finds the register's own entity and
   updates *that*, instead of forking the shared one again. With no configuration
   available, the current entity content is cloned.
2. **Relinks** `register.schemas`, replacing the old id with the new one in
   place. Order is preserved and entries it does not understand are copied
   verbatim.
3. **Moves the object rows** from `openregister_table_<register>_<old>` into the
   table built for the new schema, as a column-mapped `INSERT ... SELECT`.
4. **Restamps** the moved rows: `_schema` and the schema id embedded in `_uri`.
   Without this the rows stay attributed to the register that kept the shared
   schema — the very bleed being repaired.

`_id` is not copied. It is an autoincrement primary key, and carrying the values
over would leave the new table's sequence behind the highest copied id. `_uuid`
is the identity relations actually store, and it does move.

### Refusing on unmapped columns

```bash
occ openregister:registers:dedupe-shared-schemas --write --strict
```

`--strict` turns "this source column has no destination" into a refusal for that
split, decided **before** anything is written. Use it when you would rather stop
and look than accept that some columns only survive in the backup table.

## What happens to the old table

The source table is **not dropped**. It is renamed with a `_predupe` suffix:

```
oc_openregister_table_16_161  ->  oc_openregister_table_16_161_predupe
```

Two reasons:

- It is the only route back to a column the mapping could not carry across.
- The suffix stops the name matching the shard pattern
  `openregister_table_<int>_<int>`. Left under its original name, a later
  `occ openregister:registers:relink-schemas --write` would read it as evidence
  of a pairing and re-link the register to the schema this command just split it
  away from — quietly undoing the repair.

Drop the backup tables yourself once you are satisfied nothing is missing.

## Options

| Option | Effect |
| --- | --- |
| *(none)* | Dry run. Reports the full plan and changes nothing. |
| `--write` | Apply. Refused while any schema is unattributed. |
| `--register <id>` | Limit to shared schemas involving this register. |
| `--keep <schemaId>:<registerId>` | Name the owner of one schema. Repeatable. |
| `--keep <registerId>` | Name the owner of every unattributed schema. |
| `--strict` | Refuse any split whose source table has an unmapped column. |

Exit code is `0` on success (including "nothing to do"), and `1` when a write was
refused or a split failed.

## After the repair

Re-run the app's configuration import. It should now update the register's own
schema rather than the shared one, and a second dry run of this command should
report nothing — the repair is idempotent.

## Related

- `occ openregister:registers:relink-schemas` — rebuilds a register's lost
  `schemas` list from its physical object tables.
- `occ openregister:tables:reconcile` — creates magic-table columns that a schema
  property gained without a subsequent write.
- `occ openregister:schemas:dedup` — the older, heuristic split (owner by
  `application`, else lowest register id) that predates evidence-based
  attribution.
