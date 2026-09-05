# Migration naming convention (OPS-12)

OpenRegister migrations MUST be named:

```
Version1Date<YYYYMMDDHHMMSS>.php   ->  class Version1Date<YYYYMMDDHHMMSS>
```

i.e. the literal prefix `Version1Date` followed by a 14-digit UTC timestamp. New
migrations should always follow this scheme.

## Known inconsistency — do NOT "fix" by renaming

Four historical migrations use a different prefix scheme:

- `Version002003000Date20251013000000.php`
- `Version002004000Date20251013000000.php`
- `Version002005000Date20251013000000.php`
- `Version002006000Date20251013000000.php`

Nextcloud orders migrations with `version_compare()` on the class name. Under
`version_compare`, every `Version002...` name sorts **after** every `Version1...`
name (because `"002..." ` is compared as a higher major than `"1"`). On existing
installs this ordering is already baked in, and Nextcloud records the *applied
class name* in `oc_migrations`.

Renaming these four classes on an existing install would make Nextcloud believe
the renamed migrations have never run and attempt to re-apply them — a data
hazard. **These files are therefore intentionally left as-is.** This is a
documentation-only note (per OPS-12 in `CODE-REVIEW-IMPROVEMENT-PLAN.md`): record
the inconsistency, standardise *going forward*, and never rename already-applied
migrations.

## A migration without a version bump reaches nobody

**Every change that adds a file here MUST also move `<version>` in
`appinfo/info.xml`, in the same change.** Not in the release bump PR that
follows it — in the same change.

Nextcloud decides whether to read an app's migration directory at all by
comparing `<version>` against the `installed_version` it recorded for that app.
Equal versions mean "already up to date": `occ upgrade` answers
`No upgrade required.`, **exits 0**, and never opens a single migration file.
Nothing is logged. Nothing fails. The table the feature needs is simply not
there, and the feature is absent with no error anywhere.

Measured 2026-09-05 on a throwaway Nextcloud 34.0.3 rig running openregister
`2.0.15-unstable.20260905134511` from its release tarball:

| step | result |
|---|---|
| add a migration creating a table, leave `<version>` alone, `occ upgrade` | `No upgrade required.`, exit 0 |
| … table created? | no |
| … row in `oc_migrations`? | no |
| change **nothing but `<version>`**, `occ upgrade` again | `Updated <openregister> to 2.0.16` |
| … table created, migration recorded? | yes |

The code was byte-identical across those two runs. One line of XML is the
entire gate.

This is not hypothetical: on 2026-09-05 `development` carried four migrations
added since `<version>` last moved, one of them the run-lock table
openregister#3444 depends on, and a live instance updated to that code got none
of them.

`scripts/check-migration-version-bump.php` enforces the rule. It runs in
`Merge Hygiene` on every push and PR, in `composer check:strict`, and as a
warning from `.githooks/pre-commit`.

## Do not trust `occ migrations:status` — it will tell you nothing is pending

The command is Nextcloud's, and three of its five counting fields are wrong.
On the rig above, with one migration genuinely unrun, it reported:

```
 >> Executed Migrations:              204
 >> Executed Unavailable Migrations:  204
 >> Available Migrations:             205
 >> New Migrations:                   205
 >> Pending Migrations:               None
```

Three separate defects, all in core:

- **`Pending Migrations: None`** — `MigrationService::describeMigrationStep()`
  builds the list as `if ($migration->name())`, and
  `SimpleMigrationStep::name()` returns `''` unless a step overrides it. None of
  openregister's 204 migrations override it, so this field reads `None` for this
  app **always**, whatever the state of the database.
- **`New Migrations` and `Executed Unavailable Migrations`** —
  `StatusCommand::getMigrationsInfos()` calls `array_keys()` on
  `getAvailableVersions()`, which returns a **list**, so both fields diff
  version strings against the integers `0..n` and report the full count on every
  run. Hence 204 and 205 on an instance where nothing is wrong with either.
- `describeMigrationStep('lastest')` is called with the alias misspelled. Benign
  here only by luck: `sortMigrations()` falls through to `strnatcmp`, and
  `Version…` sorts below `lastest`, so nothing is filtered out.

**The only honest pair in that output is `Executed Migrations` against
`Available Migrations`.** 204 of 205 means one migration has not run, no matter
what the line underneath says. Read those two numbers and ignore the rest.

To recover an instance that is already in this state, either bump `<version>`
and run `occ upgrade`, or run the migration directly with
`occ migrations:migrate openregister` (both `migrations:*` commands need
`debug` set to `true` in `config.php`, or they are not registered at all and
`occ` answers `Command "migrations:status" is not defined`).
