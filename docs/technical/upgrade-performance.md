---
title: Upgrade and Install Performance
sidebar_position: 21
description: What an upgrade of OpenRegister, or of an app built on it, costs on an instance with many registers and schemas, and why
keywords:
  - Open Register
  - Upgrade
  - Migration
  - Performance
  - MariaDB
---

# Upgrade and Install Performance

An upgrade of OpenRegister, or of an app that ships its configuration through it, used to take hours on an instance with many registers and schemas, and could write tens of gigabytes to `nextcloud.log`. This page says where that time and log went, what changed, and what is still there. Issues: [#10612](https://github.com/ConductionNL/openregister/issues/10612), [#9945](https://github.com/ConductionNL/openregister/issues/9945), [#3776](https://github.com/ConductionNL/openregister/issues/3776).

## Measured

One instance: Nextcloud 35, MariaDB 10.6, PHP 8.3 with Xdebug in `develop` mode, 1,384 tables of which 1,067 are magic tables, 1,020 schemas. `occ upgrade` always runs at log level 0.

| Scenario | Before | After |
|---|---|---|
| One read of the database schema by Nextcloud | 235 to 290 s | 16 s |
| `occ upgrade` of OpenRegister, no pending migrations | six schema reads, about 26 min; 25 MB log | 105 to 111 s, 4.7 MB log |
| The same, the first time: it converts the tables | | 7 min 15 s |
| `occ upgrade` from release 2.1.0: 78 pending migrations | about 16 hours (extrapolated: 240 schema reads) | 7 min 32 s, of which 15 s for the migrations |
| One migration step of another app (keepiq) | about 14 min ([#10612](https://github.com/ConductionNL/openregister/issues/10612)) | 48 to 54 s |
| Import of an app configuration with 538 schemas, forced | stopped after 15 min at 4.85 GB of log with 167 schemas through the first of two passes | 19 s, 41 MB log |
| First install of that app: 538 schemas, 538 new tables | not measured; the same log flood, plus 3.5 minutes of index statements | 87 s, 99 MB log |
| Peak PHP memory in those runs | 253 MB | 238 to 277 MB |

## Where the time went: reading the schema

Nextcloud reads the whole database schema for every migration step of every app, three times, and its own repair steps and schema check read it six times in every `occ upgrade`. The read covers every table in the database.

On MariaDB a `JSON` column is `LONGTEXT` with a `json_valid` check. To tell the two apart, Doctrine runs a subquery per `longtext` column that scans the constraints of every table. A magic table had thirteen such columns, so the read cost `longtext columns × tables`: 15,716 × 1,384 here.

- **JSON columns of magic tables are `MEDIUMTEXT` with the same check on MariaDB.** Validation and JSON behaviour are unchanged, and the column falls outside Doctrine's subquery. A value is limited to 16 MB, which is also MariaDB's default `max_allowed_packet`. MySQL, PostgreSQL and SQLite keep their native type.
- **Existing tables are converted by the upgrade, before its migrations run** (`NarrowMagicJsonColumns`, a pre-migration repair step): one `ALTER TABLE` per table, in strict SQL mode so a value that does not fit refuses the statement rather than being cut short. Converting the 1,067 tables took 58 seconds. A table with more than 256 MB of data is left alone, and the step stops starting on new tables after five minutes. It reports what it left and never fails the upgrade.
- **`occ openregister:tables:narrow-json`** converts whatever is left, whatever its size. Each table is rebuilt and locked for writes meanwhile. `--dry-run` lists the tables and their size.

## Where the time went: OpenRegister's own migrations

Three schema reads per step is Nextcloud's design, and an upgrade from 2.1.0 runs 78 steps. A step that extends `TargetedMigrationStep` is handed a schema that reads only the tables the step names, and applies the difference itself. See `lib/Migration/NAMING.md`.

The 78 steps added since 2.1.0 were run both ways on copies of the same database, starting from the 2.1.0 schema: the table definitions afterwards are identical, on MariaDB 10.6 (149 tables) and on PostgreSQL 18 (148 tables, 540 indexes).

Setting `'openregister.targeted_migrations' => false` in `config.php` leaves every step to Nextcloud's own path.

## Where the log went: stack traces of an import

Nextcloud logs a stack trace for every read of a table that the same request wrote to moments before. At log level 0 it writes the arguments of every frame, five levels deep. A configuration import passes the whole configuration down its call chain, so each of those entries held the configuration several times over: 6.8 MB an entry for a 538-schema app, four entries a schema. Of the 901 seconds measured, 895 went to writing them.

- **An import runs with `zend.exception_ignore_args` on**, and puts the setting back afterwards. The entries are still written, at about 8 KB each. PHP's production `php.ini` sets this for the whole process; a container without a `php.ini` does not.
- **The second schema pass writes only what it resolved.** A schema whose definition is identical to what the first pass wrote is not written again.

## Where the time went: a first install

A new magic table got its ten standard indexes in ten statements, and each index statement is a DDL transaction of its own on MariaDB: 0.4 s a table, 206 of the 270 seconds it took to install an app with 538 schemas. On MariaDB a table created just now gets them in one `ALTER TABLE` statement, 0.04 s. Existing tables and other databases keep one statement per index.

## Other databases on the same server

`information_schema` describes every database on the server. Eleven of OpenRegister's catalogue queries did not say which one they meant, so an instance listed the magic tables of a neighbouring Nextcloud as its own: here 38 tables of a second instance were swept, and failed, in every upgrade, and one background job wrote 40,202 "Error searching table" entries once a neighbouring database held a thousand magic tables. Every catalogue query is now limited to the connection's own schema, and `CatalogueQueryScopeTest` fails one that is not.

## What is still there

- **Every schema read still costs about 16 seconds here**, and Nextcloud core makes six of them in an upgrade that changes nothing. What remains is 440 `longtext` columns outside the magic tables, 328 of them in OpenRegister's own tables, and a cost that grows with the number of tables. On a copy of this database a read took 9.8 s with OpenRegister's own columns converted too, and 3.1 s with the 602 empty magic tables dropped as well.
- **Another app's migration still makes Nextcloud's three reads**, and holds three copies of the schema in memory while it does: about 63 MB each here, 275 MB at the peak of one keepiq step. That grows with the number of magic tables, so an instance with about twice as many would pass a 512 MB limit in such a step. The apps built on OpenRegister can extend `TargetedMigrationStep` as well; OpenRegister's own steps no longer hold a schema copy at all.
- **The first `occ upgrade` that delivers these changes pays one slow read**, because a repair step of Nextcloud core reads the schema before any app's step runs.
- **Nextcloud 35's schema check lists every magic table as an unexpected table** after an upgrade. It is right that no migration created them; nothing is wrong with them.
- **An app configuration that changed in one schema still has every schema rewritten by the second pass**: the first pass skips the unchanged ones, so the second cannot know they are identical. About 20 ms a schema.
