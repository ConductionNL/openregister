---
kind: code
---

## Why

When an object operation carries a register context, a schema slug must resolve to
the schema **that register references**. Today it does — but only when the lookup
hits. Every one of the five scoped call sites falls back to the global
`SchemaMapper::find()` on a miss, and `ObjectService::setSchema()` says so in its
own comment: *"we transparently fall back to the global find for every legacy /
register-less caller."*

That fallback is not transparent. It resolves `LOWER(slug)` across every register
and every application on the instance, and returns the first row its tie-break
orders. Measured on the shared development instance (2026-08-16):

| Fact | Measured value |
|---|---|
| Registers total | 406 |
| Registers with an empty `schemas` list | 45 |
| Physical `oc_openregister_table_<reg>_<schema>` pairs | 3220 |
| Empty-list registers that nonetheless have physical tables | 17 |
| Empty-list registers holding live rows | **1** |

That one is DocuDesk's **Document Register (id 6)**. Its `schemas` list is `[]`,
yet it owns three populated tables — schemas 9173 `customDictionary`,
9174 `customDictionaryTerm`, 9177 `anonymizationLink`.

Because the list is empty, `findBySlugInIds()` can never match, so every slug
resolution against register 6 falls through. And the fallback does not merely pick
a *foreign app's* schema — the instance carries **nine** `docudesk`-owned schemas
with slug `anonymizationLink`. Replaying `find()`'s exact tie-break in SQL
(app-owned first, then lowest id) returns schema **5084**:

```
SELECT id FROM oc_openregister_schemas WHERE lower(slug)='anonymizationlink'
ORDER BY (CASE WHEN application IS NULL OR application='' THEN 1 ELSE 0 END), id LIMIT 1;
-- 5084
```

Schema 5084 has **no table under register 6** at all (only under registers 7 and
2483, both zero rows). The register's four real `anonymizationLink` objects live in
`oc_openregister_table_6_9177`.

So the present behaviour of a slug read against the Document Register is: **return
empty**. Not an error, not foreign data — an empty result set that is
indistinguishable from "this register has no objects". The four rows are simply
unreachable by slug.

The write direction is the dangerous half and is what surfaced this. DocuDesk's
2026-08-15 audit write resolved `generatedDocument` globally onto a foreign
schema and failed only because that schema's `required` fields were stricter. A
schema with looser `required` would have accepted the row silently, into a
register its owner never chose.

## What Changes

- **BREAKING (bounded):** when a register context is present, a schema-slug miss
  inside that register's schema list MUST NOT fall back to global resolution. It
  throws, naming the register and the slug. Register-less callers are untouched, so
  the blast radius is exactly "callers that already supplied a register".
- The five scoped call sites stop falling through:
  `ObjectService::setSchema()`, `ObjectService::searchObjectsBySlug()`,
  `Flow\Nodes\ObjectWriteNode`, `Flow\Nodes\ObjectReadNode`,
  `Controller\SchemasController`.
- A new `RegisterSchemaLinkageRepairService` reconstructs a register's `schemas`
  list from the physical `oc_openregister_table_<register>_<schema>` tables — the
  storage layout is itself an authoritative record of which pairs were ever used.
  **A schema row carries no register column**, so this is the only recoverable
  source; a schema-side back-fill is not possible.
- A new `occ openregister:registers:relink-schemas` command exposes it, **dry-run
  by default**, printing every register it would change and the ids it would add.
- The exception message names the register, the slug, and the candidate schemas
  that exist elsewhere — so the operator is told to run the repair rather than
  left to guess.

## Capabilities

### New Capabilities
- `register-scoped-slug-resolution`: how a schema slug resolves when a register
  context is present, what happens when it misses, and how a register whose
  `schemas` list was lost is repaired from physical storage.

### Modified Capabilities
- `objects-crud`: `searchObjectsBySlug` no longer resolves a schema slug outside
  the named register.

## Impact

- **Code**: `lib/Service/ObjectService.php`, `lib/Service/Flow/Nodes/ObjectWriteNode.php`,
  `lib/Service/Flow/Nodes/ObjectReadNode.php`, `lib/Controller/SchemasController.php`,
  new `lib/Service/RegisterSchemaLinkageRepairService.php`, new
  `lib/Command/RelinkRegisterSchemas.php`.
- **Data**: no migration. The repair is an explicit operator command, never automatic
  — a silent write to 17 registers is precisely the class of surprise this change exists
  to remove.
- **Consumers**: any app calling a register-scoped object API with a slug the register
  does not carry now receives an exception instead of a foreign or empty result.
  DocuDesk is the known affected consumer and is repaired by the command.
- **Not addressed here**: the duplicate-schema population itself (nine
  `anonymizationLink` rows). `occ openregister:schemas:dedup` already owns that
  problem. Register-scoped resolution makes the duplicates harmless without
  requiring them to be cleaned up first.
