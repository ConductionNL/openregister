---
kind: code
depends_on: [config-import-seed-objects]
---

# Proposal: seed-from-a-mounted-directory

## Why

Row 13.31, "An installation is seeded from data files at start-up, without anyone using the admin", is `partial` in our column (`baseline/openwoo.tsv`). opencatalogi's `InitializeSettings` repair step imports the app's own shipped `publication_register.json` and its fragments at install and after migration, with no admin action. Those are the app's files. There is no directory an operator mounts with the installation's own registers, schemas and objects to be loaded at start-up; that data goes in through the import API or the admin screen.

A municipality deploying with Docker or Kubernetes wants its themes, its organisation records and its own registers present on the first start, from files in its own repository, the way it provisions everything else.

## What changes

- The operator names a directory in Nextcloud's `config.php` as `openregister.seed_directory` (an absolute path), typically a mounted volume.
- Every `*.json` file in it (not recursive, lexical order, so `10-registers.json` before `20-objects.json`) is imported through the same `ImportHandler::importFromJson()` path as the import API, as the system context with write cause `seed`. With `config-import-seed-objects` both the top-level `objects` array and `components.objects` are read.
- It runs on three occasions so no person has to act: a repair step on install and upgrade, a background job every five minutes that imports only when the directory's content digest changed, and `occ openregister:seed:import` for operators who script it.
- Imports are idempotent: registers, schemas and objects match by slug or uuid and are updated or skipped, never duplicated.
- Every run writes a report: per file, the counts of created, updated, skipped and failed registers, schemas and objects, with each failure named. A run with any failure is `partial` and says so; it is never reported as success. The last report is on `GET /api/settings/seed`, on the operations console and in the log.
- Safety: a file that resolves outside the directory (symlink) or is larger than 50 MB is refused and named; a malformed file is named and the others still import.

## What does not change

- The import API and the admin import screen.
- Apps' own shipped register files and their repair steps.

## Dependencies and absent apps

- Waits on `config-import-seed-objects` (open, 0 of 10), which makes the importer read top-level `objects`; without it seed objects authored that way are dropped, and this change's report would count them as failed, not as imported.
- No other app is called.

## Wave and decision

Wave 2, size S. No decision bears on it. Closes 13.31.
