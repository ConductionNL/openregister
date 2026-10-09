---
kind: code
depends_on: []
---

# Proposal: notification-links-in-releases-and-case-insensitive-order

## Summary

Two things the round 4 cloud check (cloud.conduction.nl, 9 October 2026) found
that OpenRegister owns:

1. **A pipelinq notification still led to a dashboard.** "Client changed" had a
   link and an Open action, but the link was OpenRegister's old hash route
   (`/apps/openregister/#/registers/20/schemas/42/objects/<uuid>`), which opens
   the OpenRegister dashboard, and the Open action went to
   `/index.php/apps/pipelinq#/registers/...`, which pipelinq redirects to its
   own dashboard. On :8099 the same notification reached the client page.
   Three causes:
   - The deep link registry was empty on the cloud. pipelinq declares its deep
     links in `src/manifest.json`, which `GenericDeepLinkRegistrationListener`
     reads from disk. The fleet release workflow leaves `/src` out of the app
     package, so a released app has no manifest on disk: on the cloud
     `GET /apps/openregister/api/manifest/pipelinq` answers 404, on :8099
     (a git checkout) it answers 200. The listener registered nothing and said
     nothing. It now logs a warning when the leaf app ships no manifest, so the
     next miss is visible. The package fix itself is in ConductionNL/.github
     (ship `src/manifest.json`), outside this repository.
   - The registry is filled once, in OpenRegister's `boot()`. A process that
     never boots OpenRegister (`occ background-job:worker` does not load apps)
     resolves against an empty registry. The registry now asks the apps for
     their deep links itself the first time it is read empty.
   - Both fallbacks pointed at routes that no longer exist. OpenRegister moved
     to history mode, so its object view is
     `/apps/openregister/objects/{register}/{schema}/{uuid}`, and a leaf app's
     `#/registers/...` hash was never a route. The notifier and the dispatcher
     now fall back to OpenRegister's object view.
2. **Ordering by a text property was case-sensitive.** "Leverancier
   IBAN-wijziging" sorted before "Leverancier accreditatie" on PostgreSQL,
   while OpenRegister's own registers list sorts without regard to case.
   Ordering by a plain or translatable string property, and by the name,
   description and summary metadata, now compares lower-cased values on
   PostgreSQL, MySQL/MariaDB and SQLite.

3. **A translatable field left its placeholder in a subject.** "Task changed:
   {{subject}}" and "Lead changed: {{title}}": the templating skipped every
   value that is not a scalar, and a translatable property is a language map.
   It now renders in the recipient's language, then the register's default
   language, then its first value.
4. **Related on a lead page was slow.** `/uses` loaded every register and
   every schema (326) and queried tables one by one; `/used` queried all 333
   magic tables. Both now ask one cross-table lookup which tables hold a match
   and read only those, through the same filtered query as before.

## Why

A notification that opens a dashboard is a notification that leads nowhere.
An order that splits capitals from lower case reads as unsorted.

## Out of scope

- Shipping `src/manifest.json` in release packages: a separate PR on
  ConductionNL/.github (`release.yml`), because the package is built there.
- Accent folding: SQLite's `LOWER()` folds ASCII only; PostgreSQL and MySQL
  follow their collation.
