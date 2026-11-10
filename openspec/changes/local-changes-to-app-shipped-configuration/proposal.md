---
kind: code
depends_on: [configuration-as-a-deployment]
---

# Proposal: local-changes-to-app-shipped-configuration

## Summary

A municipality adds one field to a case type that arrived with the app. The
next release of that app runs its repair step, matches by slug, creates or
updates, and the field is gone. The alternative failure is the mirror image:
nobody dares update, and the instance is frozen two releases behind. What is
missing between the two is the record that the local instance diverged, and
an update that says so instead of choosing for you.

## The row this closes

### Row 11.36, local change to a case type shipped as code, with the divergence detectable, rated `no`

- source (ledger `source` field, verbatim): `dossiq#2314, published as 11.28`
- corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`
  in ConductionNL/market-intelligence, table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **11.36** | 11.28 | Local change to a case type shipped as code, with the divergence detectable | no | unread |  |
```

- ledger note, verbatim:

> Row 11.2 exports and imports case types. Nothing tracks that a municipality changed a published one, so an update either overwrites the local change or is never applied.

## What the competitor evidence is

The row is one of the 98 promoted under decision D1, and the corpus states
what its competitor columns hold, verbatim:

> Every competitor column is `unread`, and none of them is `no`. ... `no` is
> a reading of a product somebody opened, and filling these cells with it
> would fabricate thirty readings per row.

No competitor claim is made here, and the row carries no cross-reference in
the register.

## ADRs

- ADR-005 (openregister, register import via repair steps) is the ADR this
  change answers. Its Rule 2 says repair steps "MUST be safe to run on every
  upgrade: match existing registers/schemas by slug, create-or-update, never
  duplicate", and its Rule 3 says "Descriptor is the source of truth". Read
  together, a local edit is overwritten on the next `occ upgrade` and
  nothing records that it existed.
- ADR-031 (schema-declarative business logic): the baseline and the
  divergence are data on the register, not a migration script per app.
- ADR-022 (apps consume OpenRegister abstractions): the guard belongs to the
  import machinery, so every app that ships a descriptor gets it without
  writing a repair step of its own.
- ADR-012 (deduplication): `specs/schema-import` already specifies exactly
  this guard for standards-imported schemas. This change generalises that
  requirement to the app-shipped path rather than writing a second one.

## What openregister builds

- A stored baseline. When a descriptor is imported, the shipped definition
  is kept beside the live one, with the app, the app version and the moment.
  The baseline is what makes a later comparison possible; without it, local
  and shipped are indistinguishable.
- A divergence report. One read answers, per register and schema, which
  parts differ from the shipped baseline, who changed them and when. An
  administrator can see the instance's local configuration in one place, and
  so can support.
- An upgrade that refuses to choose silently. When a new app version ships a
  changed descriptor, the import applies the parts nobody touched locally,
  preserves local additions, and reports as conflicts the parts that changed
  on both sides. A conflict is applied only on an explicit per-part
  decision, and the decision is recorded.
- An unattended upgrade that is safe. Where no person is present to decide,
  the conflicting parts are left as they are and reported. An `occ upgrade`
  never silently discards local configuration, and never blocks on a
  question nobody is there to answer.
- A way back. A diverged part can be reset to the shipped baseline
  deliberately, as a recorded act, which is what an administrator wants
  after a local change turns out to be a mistake.

## What dossiq consumes

dossiq ships case types as code and is the app this row was written about.
It reads the divergence report into its case-type editor, so an administrator
editing a shipped case type can see that they are diverging before they
save. The register names no dossiq slug for this row and none exists on
dossiq `development`, so the consuming half is to be specified in dossiq.
The build plan names `CaseTypePublishService` as dossiq's leaf half of the
configuration-as-code cluster, and this is the half underneath it. Beside
dossiq: every app that ships a register descriptor, which is the whole
fleet.

## Size

M. A baseline store, a comparison, and the guard on an import path that
already exists for a neighbouring case.

## The specs this extends

- `specs/schema-import`, requirement "Imported schemas MUST record
  provenance and support guarded update-from-source", which specifies the
  diff preview, the preserved local additions and the reported conflicts for
  standards-imported schemas. This change gives the app-shipped descriptor
  path the same guarantees.
- `configuration-as-a-deployment` (REQ-CAD-003, REQ-CAD-004), which explains
  the effective configuration by layer and reports a bundle override as an
  exception, and does not compare anything against what an app shipped.
