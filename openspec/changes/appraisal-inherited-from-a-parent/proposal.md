---
kind: code
depends_on: []
---

# Proposal: appraisal-inherited-from-a-parent

## Why

Supporting change: it closes no row by itself. Row 11.14, "Marking a subject as an archive hotspot keeps every publication under it permanently", is `no` in our column and belongs to `opencatalogi/theme-archive-hotspot` (wave 2). That change needs one thing from the engine: a destruction run that honours an appraisal inherited from a parent object, here the subject a publication sits under.

Today `DestructionCheckJob` asks `RetentionService::findEligibleForDestruction()`, and `isEligibleForDestruction()` reads only the object's own `retention.archiefnominatie` against `Appraisal::RETAIN_PERMANENTLY_ALIASES`. A subject marked as a hotspot changes nothing for the publications under it.

Decision D5 (Ruben, 2026-10-05) settled 11.14 as row wins outside the defaults mechanism: a hotspot is its own rule reaching publications already filed, and opencatalogi's RET-004 keeps governing defaults. So the inheritance is a destruction-time rule in the engine, not a rewrite of each publication's own retention.

## What changes

- A schema declares `x-openregister-retention.inheritAppraisalFrom`: a list of reference properties whose target objects can impose permanent retention.
- `RetentionService::isEligibleForDestruction()` follows those references (up to five levels, cycle-guarded) and treats an object as not eligible when any ancestor reached that way carries a retain-permanently appraisal.
- The pre-destruction notifications in `DestructionCheckJob` skip the same objects.
- An ancestor that cannot be read (deleted, unresolvable, unreadable) makes the object not eligible and is reported, never ignored.
- A destruction list and a dry-run report name the inherited reason for every object held back.

## What does not change

- An object's own `archiefnominatie` and the default retention rules (opencatalogi RET-004).
- Legal holds and pending destruction lists, which keep their current precedence.

## Dependencies

- None. Consumed by `opencatalogi/theme-archive-hotspot` (wave 2), which declares `inheritAppraisalFrom: ["subjects"]` on the publication schema and sets the subject's appraisal. Without opencatalogi, no shipped schema declares the key and behaviour is unchanged.

## Wave and decision

Wave 1, size S. Supports 11.14 under D5.
