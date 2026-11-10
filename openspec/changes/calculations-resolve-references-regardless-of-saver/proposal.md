---
kind: code
---

# Proposal: calculations-resolve-references-regardless-of-saver

## Why

A resident withdrew a dossiq case on the portal. The case lost its deadline,
statutory term and public status label. An administrator's recompute from the
command line put them back.

The cause sits in OpenRegister. A calculation that reads `@ref.caseType` or
`@ref.statusType` resolved that reference under the saver's RBAC and
multitenancy scope. A portal write is an anonymous web request, so the case
type and status type were filtered out. The reference resolved empty, every
calculation reading it evaluated to null, and the listener wrote that null
over the stored value.

The same save gave a different answer depending on who made it. The audit
trail on the integration instance shows both sides: entry 9027 (the
withdrawal, written as System) nulls the fields, entry 10652 (the command-line
recompute) restores them.

## What changes

- References are read as the system: `ReferenceResolver` calls
  `ObjectService::find()` and `findAll()` with `_rbac: false` and
  `_multitenancy: false`.
- A new `ReferenceTenantGuard` keeps the organisation boundary that the
  session used to keep. A referenced object outside the saving object's
  tenant scope resolves empty. The rule is in `design.md`.
- A calculation that reads a reference which had something to resolve, and
  still came back empty, is skipped. The stored value stays. The rule run log
  records an `error` verdict naming the reference.
- The temporal sweep applies the same skip, so it does not report a change
  the save would not make.

## Impact

- `lib/Service/Calculation/ReferenceResolver.php`,
  `lib/Service/Calculation/ReferenceTenantGuard.php` (new),
  `lib/Service/Calculation/CalculationPayloadBuilder.php`,
  `lib/Listener/CalculationOnSaveListener.php`,
  `lib/Service/Calculation/TemporalCalculationSweepService.php`,
  `lib/Listener/LifecycleInitialStateListener.php` and
  `lib/Command/RematerialiseCalculationsCommand.php` (pass the organisation).
- `ReferenceResolver::resolveAll()` now takes the saving object's
  organisation. All three callers in this repository pass it.
- An object with no organisation can no longer resolve a reference to an
  organisation-owned object. Before, that only worked from the command line.
