---
status: proposed
---

# data-import-export

## ADDED Requirements

### Requirement: An installation is seeded from an operator's mounted directory without the admin (REQ-SMD-001)

When `config.php` holds `openregister.seed_directory`, OpenRegister SHALL import every `*.json` file directly in that directory, in lexical order, through `ImportHandler::importFromJson()` as the system context with write cause `seed`. It SHALL do so from a repair step on install and upgrade, from a background job every five minutes when the SHA-256 over the directory's file names and contents differs from the last imported digest, and from `occ openregister:seed:import`. Registers, schemas and objects SHALL be matched by slug or uuid and updated or skipped, never duplicated. A file resolving outside the directory or larger than 50 MB SHALL be refused and named; a file that does not parse SHALL be named and the other files SHALL still be imported. When the setting is absent nothing SHALL happen. A path that is not absolute or not readable SHALL be reported as such and nothing imported.

#### Scenario: a fresh container comes up with the organisation's data
- **GIVEN** a new instance whose `config.php` names `/seed`, and a mounted `/seed` holding `10-registers.json` with a register and two schemas and `20-objects.json` with 25 objects
- **WHEN** the instance is installed and cron has run once
- **THEN** the register, both schemas and the 25 objects exist, created by `system` with cause `seed`, and nobody opened the admin

#### Scenario: a restart does not duplicate
<!-- @e2e exclude Covered by PHPUnit SeedDirectoryImporterTest::testASecondRunUpdatesOrSkips. -->

- **GIVEN** the seeded instance above
- **WHEN** the job runs again with the directory unchanged, and again after one object's title changed
- **THEN** the first run imports nothing and the second updates exactly one object

#### Scenario: a symlink out of the directory is refused
<!-- @e2e exclude Covered by PHPUnit SeedDirectoryImporterTest::testASymlinkOutsideTheDirectoryIsRefused. -->

- **GIVEN** `/seed/99-secrets.json` linking to `/etc/passwd`
- **WHEN** the seed runs
- **THEN** that file is refused and named, and the others import

### Requirement: Every seed run reports what it did, and a partial run says it is partial (REQ-SMD-002)

Each seed run SHALL produce a report: the digest, the moment, and per file the counts of created, updated, skipped and failed registers, schemas and objects with each failure named (file, entity, slug, reason). The run status SHALL be `success` only when nothing failed, otherwise `partial`, or `failed` when nothing imported. The last report SHALL be returned by `GET /api/settings/seed` (administrator only), shown on the operations console, logged, and printed by the occ command, whose exit code SHALL be non-zero for `partial` and `failed`.

#### Scenario: a schema without a slug is named, not hidden
- **GIVEN** a seed file holding a schema with no slug and ten objects of a valid schema
- **WHEN** the seed runs
- **THEN** the report says `partial`, names the schema without a slug as failed and counts the ten objects as created, and the occ command exits non-zero
