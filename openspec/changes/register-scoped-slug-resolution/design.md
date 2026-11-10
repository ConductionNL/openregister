## Context

The scoped resolvers already exist. `SchemaMapper::findBySlugInIds()` and
`findByApplicationAndSlug()` were added with the cross-app slug-collision work, and
their docblocks describe the defect correctly. What did not land is the refusal:
every caller treats a scoped miss as "try harder" rather than "stop".

Two structural facts constrain any fix, and both were verified against the live
schema rather than assumed:

1. **`oc_openregister_schemas` has no register column.** The register→schema
   relation is stored one-directionally, as a JSON id list on the register row. A
   lost list therefore cannot be rebuilt from the schema side.
2. **Objects are stored in per-pair tables**, `oc_openregister_table_<reg>_<schema>`.
   3220 such tables exist. The table *name* is a durable record of a
   register/schema pairing that was actually used.

A third fact shapes the error semantics. The defect's observable symptom on the
one affected register is not corrupted data — it is an **empty result set**.
Schema `5084` (what global `find()` returns for `anonymizationLink`) has no table
under register `6`, so a slug read returns nothing while four rows sit in
`oc_openregister_table_6_9177`. An empty set is indistinguishable from "this
register has no objects", which is why the defect survived unnoticed.

## Goals / Non-Goals

**Goals:**

- A named register is a boundary. Having named one, a caller cannot be served a
  schema from outside it.
- The failure is loud and actionable — it names the register, the slug, and the
  repair command.
- A register whose linkage was lost can be repaired from evidence, not guesswork.
- Register-less callers are unaffected, keeping the blast radius minimal.

**Non-Goals:**

- Cleaning up duplicate schemas. Nine `anonymizationLink` rows exist and
  `occ openregister:schemas:dedup` already owns that problem. Register scoping makes
  the duplicates *harmless*, which is a better property than requiring them gone.
- Changing `SchemaMapper::find()`'s global behaviour or its tie-break. Callers with
  no register still need it, and its ordering was deliberately chosen.
- Automatic repair. See the decision below.
- Preventing an app from linking a schema to more than one register. That is legal
  and used.

## Decisions

### D1 — Refuse at the call site, not inside `SchemaMapper`

`SchemaMapper::find()` has no idea whether its caller holds a register. Pushing the
refusal into the mapper would require threading register context through every
overload and would change behaviour for callers that legitimately have none.

The refusal therefore lives at the five sites that already *have* the register in
hand and already call `findBySlugInIds()`. Each one currently follows the scoped
call with a fallback; the change is to replace that fallback with a throw.

This is why the spec enumerates the five call sites by name rather than stating the
rule generally: a rule stated generally would be satisfied by four of five, and the
fifth would silently keep the defect for whichever consumer uses it.

### D2 — A new exception type, extending `DoesNotExistException`

`SchemaNotInRegisterException extends DoesNotExistException`. Extending it preserves
Nextcloud's existing dispatcher mapping to `404`, so no controller changes and no
API contract change. Introducing a distinct type is what lets tests assert the
*reason* rather than merely that something failed — and lets a caller distinguish
"this register does not carry that slug" from "no such register".

### D3 — Rebuild linkage from table names, and only from table names

The repair enumerates `pg_stat_user_tables` (and the MySQL equivalent) for
`oc_openregister_table_<register>_<schema>` and reports every schema id paired with
the target register.

Rejected alternatives:

- *Match on slug similarity* — the reason we are here. Nine same-slug candidates.
- *Match on `application` ownership* — all nine duplicates are owned by `docudesk`.
  Ownership does not disambiguate.
- *Re-import from the app's `{app}_register.json`* — plausible, but it describes
  what the app *intends* now, not what the register actually holds. It would miss a
  schema whose objects exist but which the app has since dropped from its manifest,
  and that is precisely the data we must not orphan.

Table names are the only source that records what was *actually used*.

### D4 — Additive only, never subtractive

The repair may add schema ids to a register's list; it may never remove one. A
schema can legitimately be linked before its first object is written, so "no
physical table" is not evidence of "not linked". Making the repair subtractive
would let it delete correct configuration on the basis of an empty register.

### D5 — Dry-run by default, `--write` to apply

17 registers are eligible. Repairing them silently as a side effect of running a
command is the same shape of surprise as resolving a slug into a foreign register.
The operator sees the full list — register, ids to add, live row count per id — and
then opts in.

Row counts are printed because they distinguish strong evidence (a table with rows)
from weak (an empty table left by a schema that was attached and never used). Both
are recovered, but the operator can see which is which.

### D6 — The error message carries the remedy

The message names the register (id and slug), the requested slug, and how many
same-slug schemas exist elsewhere, then names
`occ openregister:registers:relink-schemas`.

Without the count, a developer hitting this reads "schema not found" and concludes
the slug is wrong — which is exactly the wrong conclusion when nine copies exist and
the register's list is empty. The count converts a dead end into a diagnosis.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Register-scoped slug resolution | **Imperative** | This is OpenRegister's own resolution core, below the layer `x-openregister-*` dialects operate on. A declarative dialect cannot express "how this platform resolves an identifier" without circularity. |
| Linkage repair | **Imperative** | Operator-invoked maintenance reading the physical storage catalogue. Not a derived field, lifecycle, aggregation, notification, relation or widget. |

Neither behaviour matches an ADR-031 declarative category, so no schema-register
patch is appropriate and no `lib/Settings/*_register.json` is touched.

## Seed Data (ADR-001)

**None.** This change introduces and modifies no OpenRegister schemas, so there are
no objects to seed and no `_registers.json` entries to generate. The fixtures it
does need are test-only: a register with a populated `schemas` list, a register with
an empty one, and a set of same-slug schemas — all constructed in PHPUnit, not
seeded into an instance.

## Risks / Trade-offs

**A consumer relying on the fallback breaks.** Intended, and bounded to callers that
supplied a register. The one known consumer is DocuDesk against register `6`, which
the repair command fixes. Mitigation is ordering: repair first, then the refusal
takes effect — and because the repair is a separate operator command, an
administrator can run it before deploying.

**The repair is Postgres-and-MySQL-specific.** It reads the table catalogue, so it
needs a per-platform query. Contained to one service with a platform switch;
verified on the Postgres instance available here, and the MySQL arm must be marked
unverified rather than claimed if it is not exercised.

**17 registers is a measurement of one instance, not a fleet fact.** Another
deployment may have more or none. The command is written to report before acting
precisely so this number never has to be trusted in advance.

**An empty result set is a poor tripwire.** Nothing in the current test suite would
have caught this, because "returns nothing" is a valid outcome. The new tests must
assert the *identity* of the resolved schema, not merely that a call succeeded —
asserting success would pass both before and after the fix.
