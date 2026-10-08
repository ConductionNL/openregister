---
kind: code
---

# Proposal: expired-object-sweep

## Summary

Let an app put an expiry date on an ordinary object and have OpenRegister remove it, or strip it down, when that date passes. A schema opts in and says what expiry means for its objects: delete, or anonymise while keeping a named set of fields. A daily job does the work, skips anything under a legal hold, and writes one audit entry per object. Portaliq uses it to keep a completed submission 30 days, an unfinished draft 30 days and a failed one 90 days.

Rows covered: portaliq `ops-submission-retention` (decision 104). This is OpenRegister's half of portaliq's `form-governance-availability-retention-and-routing` (merged in portaliq #1387).

## Why

Portaliq's change says: "On every state change the submission object gets an expiry date from the binding ... A daily job deletes expired objects through OpenRegister, or with `method: anonymise` replaces the answers with an empty object and keeps reference, binding, dates and state." And: "OpenRegister owns deletion and export of objects. Portaliq sets the expiry on each submission object" (ADR-022). Open Formulieren 4.0.1 sets removal limits per kind of submission (`src/openforms/forms/models/form.py:335-395`) and sweeps them daily (`src/openforms/data_removal/tasks.py:21`).

What OpenRegister has:

- An `expires` column on every object (`ObjectEntity::$expires`, `_expires` on magic tables) and `purgeExpiredObjectsRaw()`, which hard-deletes expired rows written through the raw append path only, without audit or legal-hold check (`data-import-export` spec, raw append).
- The DSAR retention sweep (`dsar-retention-sweep`), which handles data-subject-request cases only.
- Archival destruction (`retention-management`), which runs on archiefactiedatum with lists and approval. That is the Archiefwet path for records; a resident's unfinished draft is not a record and needs no destruction list.
- `AnonymisationService` for profile-driven field anonymisation.

Nothing sweeps an ordinary object whose `expires` has passed.

## What changes

- **A schema opts in.** `x-openregister.expiry: { "action": "delete" | "anonymise", "keep": ["reference", "binding", "state", "submittedAt"] }`. Without it, `expires` keeps meaning what it means today.
- **`@self.expires` is writable on save** for a schema that opts in, by a caller who may update the object.
- **A daily sweep.** For every opted-in schema, objects whose `expires` is past are deleted, or anonymised (every property outside `keep` emptied, files removed, `expires` cleared). An object under an active legal hold is skipped and reported. Each action writes one audit entry with the schema's expiry action; a run writes one summary line.
- **A dry run** that reports what the next run would do, per schema.

## Out of scope

- Deciding the dates: portaliq sets `expires` from the binding on each state change.
- Archival records on the Archiefwet path: `retention-management` keeps that.

## Impact

- Specs: new capability `expired-object-sweep`.
- New: `lib/BackgroundJob/ExpiredObjectSweepJob.php`, `lib/Service/Retention/ExpiredObjectSweeper.php`, `lib/Command/ExpirySweepCommand.php` (dry run).
- Changed: schema validation of `x-openregister.expiry`, `lib/Service/Object/SaveObject.php` (accept `@self.expires` for opted-in schemas), `appinfo/info.xml`.

## Cross-project dependencies

- portaliq `form-governance-availability-retention-and-routing` opts `portalIntakeSubmission` in, writes the binding's `retention.method` into the object's action field (design D2), and writes `expires` on each state change.
