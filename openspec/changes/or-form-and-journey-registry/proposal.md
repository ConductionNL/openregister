---
kind: mixed
---

# Proposal: or-form-and-journey-registry

## Summary

Add a `forms` register carrying `form`, `journey` and `journeyRun`, the API to
drive a run (start / answer / resume / submit), the named formats that replace
per-app validator forks, and the retention job that purges expired runs.

Chain link 4 of `hydra/openspec/changes/portaliq-phase-two`. `kind: mixed`
because it is schemas **plus** a run API and a background job — the schemas
alone would be an inert data model.

## Motivation

`tilburg-woo-ui` implements citizen and supplier intake as ~13,640 lines of
hand-written React across seven wizards, each re-implementing step state,
per-step validation, progress display and submission. None is resumable.

The declarative half already exists: manifest v2 has `config.steps[]`,
`$defs.fieldValidation` and `$defs.visibleWhen`, and `CnFormPage` renders them.
What is missing is the primitive that spans forms — multiple objects, branching
between forms, lookup-and-prefill, a review step, and resumability — and a
place to keep a half-finished submission. That is what a `journey` and a
`journeyRun` are.

The name matters: OpenRegister already owns `flow` for the automation engine
(ADR-065, ~40 change specs, `FlowController`, flow runs, a visual canvas). A
journey is a UI-facing sequence; it may trigger a flow, and it is not one.

## Affected Projects

- [ ] `openregister` — `forms` register (`form`, `journey`, `journeyRun`); a
      journey-run controller and service; `EmailFormat`, `WebsiteFormat`,
      `NlPhoneFormat` beside the existing `BsnFormat` / `Iso8601DateTimeFormat`
      / `UserFormat`; a retention background job.

## Design notes

**A `form` body is a manifest-v2 form-page config**, validated by the same
`validateManifestV2()` path an app manifest uses. Not a subset, not a copy — if
the two can drift, they will.

**A `journey` declares** ordered steps (`form` / `review` / `confirmation`),
`next` rules using `$defs.visibleWhen` verbatim, `writes[]` per step, and
`access` (`anonymous` | `authenticated` | `minTrust`).

**A `journeyRun` stages everything.** Answers accumulate there; objects are
written only at steps declaring `writes[]`. This preserves the property the
React wizards have by accident — an abandoned registration leaves no
half-built organisation — while adding the resumability they lack.

**Retention ships with the schema, not after it.** A `journeyRun` holds names,
addresses, e-mail, phone numbers and uploads before any of it is a record.

## Risks

- **`journeyRun` is a personal-data store outside the register's normal
  lifecycle.** The purge is part of this change and its first run is verified by
  row count. The precedent is the audit retention purge on this instance, which
  had never run — 0 of 3,254,448 rows — while looking exactly like a purge with
  nothing to do.
- **A resume token is a bearer credential for someone's half-filled form.** It
  must be unguessable, single-purpose, and must not act as an existence oracle.
- **`writes[]` makes authoring a privileged action.** A journey author can cause
  writes into any register the mapping names; mappings validate against the
  target schema at author time.
- **Anonymous submission is an unauthenticated write path.** It is explicit,
  throttled (ADR-082), stamps no ownership, and fails closed when `access` is
  absent.
