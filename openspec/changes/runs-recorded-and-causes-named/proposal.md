---
kind: code
depends_on: [import-preview-and-conflict-policy, audit-trail-shipped-and-purpose-bound]
---

# Proposal: runs-recorded-and-causes-named

## Summary

Three questions a data steward asks, and cannot answer here. Who or what
made this change: a person, a nightly job, a migration, or a cascade from
another write. How healthy is this set of records, measured against a rule
set and a tolerance somebody agreed. And what happened in the load we ran
last month, row by row. Each of the three is a record that is written and
then kept, and none of them is.

## The rows this closes

### Row 10.16, audit entry attributed to its cause: a person, a job, an import or a cascade, rated `partial`

- source (ledger `source` field, verbatim): `dossiq#2314, published as 10.12`
- corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`
  in ConductionNL/market-intelligence, table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **10.16** | 10.12 | Audit entry attributed to its cause: a person, a job, an import or a cascade | partial | unread | corpus 10.9 |
```

- ledger note, verbatim:

> The beschikking state machine log carries actorType, employee or systeem, and trigger, manual or automatic, written by BeschikkingService and read by AuditPacketBuilder. It is a two-value axis on one subsystem, with no import, migration or cascade cause, and the general trail is OpenRegister's.

### Row 10.19, data quality audit: a defined population, rules over it, and a scored tolerance, rated `partial`

- source (ledger `source` field, verbatim): `dossiq#2314, published as 10.15`
- corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`,
  table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **10.19** | 10.15 | Data quality audit: a defined population, rules over it, and a scored tolerance | partial | unread | discovery D-request-tracker-21 |
```

- ledger note, verbatim:

> Row 11.18 detects configuration problems and is yes. Nothing defines a population of records, runs rules over it and scores the result against a tolerance.

### Row 11.39, import as a durable record with an outcome per row, rated `no`

- source (ledger `source` field, verbatim): `dossiq#2314, published as 11.31`
- corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`,
  table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **11.39** | 11.31 | Import as a durable record with an outcome per row | no | unread | corpus 5.11 |
```

- ledger note, verbatim:

> An import runs and reports at the end. Nothing survives it, so nobody can go back to a load from last month and see which rows failed and why.

## What the competitor evidence is

All three rows are among the 98 promoted under decision D1, and the corpus
states, verbatim, what its competitor columns hold:

> Every competitor column is `unread`, and none of them is `no`. ... `no` is
> a reading of a product somebody opened, and filling these cells with it
> would fabricate thirty readings per row.

No competitor claim is made here. The register's cross-references are
`corpus 10.9` for 10.16, the discovery finding `D-request-tracker-21` for
10.19, and `corpus 5.11` for 11.39.

## ADRs

- ADR-003 (openregister, immutable hash-chained audit trail): the cause is a
  field of the entry the chain already covers, so it cannot be edited after
  the fact and it is carried into every export.
- ADR-005 (security): the cause is derived on the server from the acting
  context. A client cannot assert that its write was a migration.
- ADR-031 (schema-declarative business logic): the population, the rules and
  the tolerance of a quality audit are declared, not coded per register.
- ADR-022 (apps consume OpenRegister abstractions): one import record and
  one quality audit for the fleet.
- ADR-009 (openregister, performance invariants): the import record and the
  audit results are paged and pruned, never assembled in memory.

## What openregister builds

- A cause on every audit entry. The entry names how the write happened: a
  person acting, a scheduled job, an import run, a migration, a rule, or a
  cascade from another write. A cause that is a run names the run, so the
  entry points at the import or the job that made it. The trail is
  filterable by cause, which is what turns "who changed these eight hundred
  records" from an afternoon into a query.
- A cause that survives the queue. A write made by a deferred job carries
  the cause and the actor that set it going, rather than reading as the
  system. The forwarding mechanism already exists for the actor; the cause
  rides with it.
- A data quality audit. A named audit declares its population as a query,
  the rules to evaluate over it, and the tolerance the result is scored
  against. It runs on a schedule or on demand, records the score, the
  failing records and the rules they failed, and reports pass or fail
  against the tolerance. The per-object scoring of
  `specs/data-quality-scoring` is the rule evaluator it reuses; what is new
  is the population, the tolerance and the run.
- A trend, because one number means nothing. Each run is kept, so the score
  of this month is readable beside the last, and a drop has a date.
- An import that survives itself. A run is a record: the file, its
  fingerprint, the mapping, the policy, the actor, the moment, and one
  outcome per row with its reason. It is readable a month later, the failed
  rows are exportable for correction, and a retry of only the failed rows
  names the run it came from.

## What dossiq consumes

dossiq reads the cause on the case history so the timeline can say a change
came from an import rather than from a colleague, declares its quality
audits over its own case populations, and renders the import runs of its
migrations. No dossiq slug exists for these three on dossiq `development`,
so the consuming halves are to be specified in dossiq. Beside dossiq:
integriq, which holds the adapters and is where most imports originate,
stackiq and opencatalogi for the quality audits, and every app for the
cause.

## Size

L. Three records, each with a write path, a read path and a retention
policy, sharing the run identity that ties them together.

## The specs this extends

- `specs/enhanced-audit-trail` and `specs/audit-trail-immutable`, which
  record the actor and the action and do not record the cause. The change
  `audit-trail-shipped-and-purpose-bound` adds the token, its owner and its
  consumer (REQ-ATS-002), which is one cause of several.
- `specs/data-quality-scoring`, requirement "Declarative per-object
  data-quality scoring", which computes a score per object on save and
  defines no population and no tolerance.
- `specs/data-import-export` and the change
  `import-preview-and-conflict-policy` (REQ-IPC-002, REQ-IPC-003), which
  preview an import and report its counts, and keep nothing afterwards.
- `changes/actor-forwarded-listener-jobs`, which forwards the acting
  context to background jobs and is where the cause travels.
