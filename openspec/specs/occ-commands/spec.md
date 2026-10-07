# occ-commands Specification

## Purpose

An administrator manages OpenRegister from the command line through Nextcloud's `occ` runner. This spec describes the command surface as it exists on `development` (7 Oct 2026): 27 commands under the `openregister:` namespace, declared in `appinfo/info.xml` (`<commands>`, lines 469-518) and implemented in `lib/Command/`. Each command's own behaviour is specified by the feature spec its `@spec` tag names; this spec holds what the commands share: how they are found, how a command that changes data previews before it writes, and how it reports failure.

Written after the fact for parity row `op-cli` (spec round part 2, decision 81). It describes existing behaviour and asks for no new code.

## Requirements

### Requirement: Commands are registered with Nextcloud and named under openregister

Every OpenRegister command SHALL be declared in `appinfo/info.xml` under `<commands>` so `occ list openregister` shows it, and its name MUST start with `openregister:`. The registered set covers five jobs:

| Job | Commands |
|---|---|
| Inspect the instance | `openregister:descriptors:list`, `openregister:resolver:list`, `openregister:declared-groups` |
| Repair registers and schemas | `openregister:registers:dedupe`, `openregister:registers:relink-schemas`, `openregister:schemas:dedup`, `openregister:schemas:prune-retired`, `openregister:schema:reset-to-shipped`, `openregister:configurations:dedupe`, `openregister:migrate-application`, `openregister:tables:reconcile`, `openregister:tables:search-index` |
| Move or backfill data | `openregister:migrate-storage`, `openregister:files:move-to-account`, `openregister:backfill-system-owner`, `openregister:translations:backfill-source-language`, `openregister:rematerialise-calculations`, `openregister:encrypt-field`, `openregister:organisations:adopt`, `openregister:approval:rollback-to-steps` |
| Import and sync | `openregister:vocabulary:import-csv`, `openregister:tables:sync`, `openregister:time:reconcile`, `openregister:contextchat:reindex` |
| Security and retention | `openregister:objects:purge`, `openregister:rechain-audit-trail`, `openregister:web-push:generate-vapid` |

`lib/Command/DedupeSharedSchemasCommand.php` (`openregister:registers:dedupe-shared-schemas`) exists but is not declared, so `occ` does not offer it.

#### Scenario: an administrator lists the commands

- **GIVEN** OpenRegister is enabled
- **WHEN** the administrator runs `occ list openregister`
- **THEN** the output MUST list the 27 declared commands, each with the description its `configure()` sets
- @e2e exclude {occ surface with no page; the declaration is read from appinfo/info.xml}

### Requirement: A command that changes data shows what it would do before it writes

A repair or destructive command SHALL either default to a preview and write only behind an explicit option, or offer a `--dry-run` option that reports without writing. The preview MUST name what would change.

- Preview by default, write behind an option: `openregister:objects:purge` (`--apply`, `lib/Command/PurgeObjectCommand.php:105-109`), `openregister:registers:relink-schemas` (`--write`, `lib/Command/RelinkRegisterSchemasCommand.php:69-117`), `openregister:schema:reset-to-shipped` (`--apply`, `lib/Command/ResetSchemaToShippedCommand.php:78`).
- Preview on request: `--dry-run` on `openregister:migrate-application` (`lib/Command/MigrateSchemaApplicationCommand.php:70,112`), `openregister:rechain-audit-trail`, `openregister:encrypt-field`, `openregister:backfill-system-owner` and `openregister:rematerialise-calculations`.

#### Scenario: relink without --write changes nothing

- **GIVEN** a register whose schemas list is missing a schema that has an object table
- **WHEN** the administrator runs `occ openregister:registers:relink-schemas` without `--write`
- **THEN** the command MUST print the schemas it would link and a `DRY RUN` notice that nothing was changed and that `--write` applies it
- **AND** the register MUST be unchanged
- @e2e exclude {occ surface with no page}

#### Scenario: a reset records who did it

- **GIVEN** a schema part that differs from what its app shipped
- **WHEN** the administrator runs `occ openregister:schema:reset-to-shipped` with `--apply` and no `--actor`, or with an `--actor` who is not an administrator
- **THEN** the command MUST refuse with `--apply needs --actor=UID of an administrator` and exit with failure (`lib/Command/ResetSchemaToShippedCommand.php:116-118`)
- **AND** with a valid administrator as `--actor` the reset MUST run as that user, so the change is recorded under that name
- @e2e exclude {occ surface with no page}

### Requirement: Purging a retained or live record needs --force

`openregister:objects:purge` SHALL permanently destroy object rows named by UUID, or every object an import job created (`--import-job`). It MUST refuse an object on a schema that declares `x-openregister-archival`, and an object that is still live rather than in the trash, unless `--force` is given (`lib/Command/PurgeObjectCommand.php:262-276`). The HTTP delete routes refuse archival records outright, so this command is the only path to destroy one, and the shell history is the record of who did it.

#### Scenario: an archival record is refused without --force

- **GIVEN** an object in the trash on a schema that declares `x-openregister-archival`
- **WHEN** the administrator runs `occ openregister:objects:purge <uuid> --apply`
- **THEN** the object MUST NOT be destroyed and the output MUST say the schema declares `x-openregister-archival` and that `--force` is needed
- **AND** the command MUST exit with 1
- @e2e exclude {occ surface with no page}

### Requirement: Commands exit non-zero when they fail

A command SHALL return a non-zero exit code when it refuses its input or any item fails, so a script or a cron wrapper can tell success from failure. `openregister:objects:purge` returns 1 when no UUID or import job is named and when any named object is refused or fails (`lib/Command/PurgeObjectCommand.php:118-155`); `openregister:schema:reset-to-shipped` returns `Command::FAILURE` when the reset is refused or throws (`lib/Command/ResetSchemaToShippedCommand.php:116-136`).

#### Scenario: purge with nothing named fails

- **GIVEN** OpenRegister is enabled
- **WHEN** the administrator runs `occ openregister:objects:purge` with no UUID and no `--import-job`
- **THEN** the command MUST print `Name at least one object UUID, or an import job with --import-job.` and exit with 1
- @e2e exclude {occ surface with no page}
