---
status: in-progress
---

# register-slug-resolution Specification

**OpenSpec changes**
- register-slug-resolution

## Purpose

Defines how a caller finds out which slug a register actually answers to on THIS
instance, so a cross-app reference resolves rather than assumes.

Nine fleet apps ship a repair step that renames their register's slug:
`openconnector` to `integriq`, `openbuild` to `buildiq`, `voorzieningen` to
`stackiq`, and six more. The rename is per-instance and runs at repair time, so
both the old and the new slug are live across the estate simultaneously,
whichever a given instance has last repaired. A consumer that hardcodes either
one is therefore wrong on half the estate.

The behaviour matters because its failure is quiet, and quieter than a 404. A
register slug that does not resolve does not raise; it produces an empty result
set, which is indistinguishable from a register that holds no objects. Measured
on this repository 2026-09-10: `AppHost\Scheduling\ScheduleReconciler` pinned
`openconnector` and `openbuild` as register slugs across five call sites. On an
instance that has run either repair step, `loadManagedJobs()` returns no rows
and `loadVirtualApplications()` returns none, so the reconciler enumerates
nothing, upserts nothing and garbage-collects nothing. Every AppHost schedule
stops firing, and the only trace is an `info` line saying the register is
"unavailable".

## Requirements

### Requirement: A register MUST be resolvable by any slug it has answered to

The system MUST expose a resolver that takes a canonical register slug, reads
which of that register's known slugs exist on this instance, and returns the one
that does.

Candidate slugs MUST be declared, never derived from app-id history. The two are
not the same string: `stackiq` renamed the register `voorzieningen`, having
never used its own former app id `softwarecatalog` as a slug at all. A resolver
keyed on the app rename map answers `softwarecatalog`, which no register on any
instance has ever carried.

#### Scenario: A migrated instance resolves to the new slug

- **GIVEN** an instance whose register row carries slug `buildiq`
- **WHEN** a caller resolves the canonical slug `buildiq`
- **THEN** the resolver MUST return `buildiq`

#### Scenario: An unmigrated instance resolves to the old slug

- **GIVEN** an instance whose register row still carries slug `openbuild`
- **WHEN** a caller resolves the canonical slug `buildiq`
- **THEN** the resolver MUST return `openbuild`

#### Scenario: A slug that is not the old app id still resolves

- **GIVEN** an instance whose register row carries slug `voorzieningen`
- **WHEN** a caller resolves the canonical slug `stackiq`
- **THEN** the resolver MUST return `voorzieningen`
- **AND** MUST NOT offer `softwarecatalog` as a candidate

### Requirement: An unresolved register MUST be reported as unresolved, never as the canonical slug

When no candidate slug exists on this instance the resolver MUST report the
absence explicitly and MUST NOT return a slug.

Returning the canonical slug on a miss is the failure this resolver exists to
remove: the caller passes it to a read, the read returns zero rows, and the
caller records "no data". The absence MUST be a value the caller has to branch
on.

#### Scenario: An absent register yields no slug

- **GIVEN** an instance carrying neither `buildiq` nor `openbuild`
- **WHEN** a caller resolves the canonical slug `buildiq`
- **THEN** the resolver MUST report the register as absent
- **AND** MUST NOT return `buildiq`

#### Scenario: Both slugs present is reported, not silently merged

- **GIVEN** an instance carrying BOTH `buildiq` and `openbuild`, which the
  repair step refuses to merge
- **WHEN** a caller resolves the canonical slug `buildiq`
- **THEN** the resolver MUST report the resolution as ambiguous
- **AND** MUST return the canonical slug so reads stay on the migrated row
- **AND** MUST log a warning naming both slugs

### Requirement: The resolver MUST read the register table, not the app manager

Register slugs live in `openregister_registers`. Whether an app is installed
says nothing about which slug its register carries: an instance can run
`buildiq` with a register row still slugged `openbuild`, because the app id and
the register slug are migrated by different steps and either can run first.

#### Scenario: An installed app with an unmigrated register

- **GIVEN** an instance where the app id has moved to `buildiq` but the register
  row still carries `openbuild`
- **WHEN** a caller resolves the canonical slug `buildiq`
- **THEN** the resolver MUST return `openbuild`

### Requirement: No code under lib/ MUST pin a superseded register slug

A string literal equal to a superseded register slug, used in register position,
MUST be rejected. The guard MUST run over the whole of `lib/` rather than over a
changed diff, because the references it catches were written before the rename
and will never appear in one.

#### Scenario: A pinned old slug is detected

- **GIVEN** a source file under `lib/` naming `openbuild` as a register
- **WHEN** the guard runs
- **THEN** it MUST fail and name the file, the line and the canonical slug to
  resolve instead
