---
kind: code
---

## Why

A demo of a whole municipality needs data that hangs together across apps: the
resident who files a Woo request in the portal is the same person the KCC
employee finds in the contact register, with the same BSN in the BRP mock and
the same Nextcloud account. Today every app seeds its own corner. portaliq has
the best working pattern (`occ portaliq:example-resident:install zuiddrecht`:
objects per register and schema, lookups by business key, relative dates,
references to earlier objects, an install record, exact removal and a
read-back proof), but it lives in one app and covers one resident.

ADR-111 gives every app generated mock data, which is deliberately not a story.
A story needs a cast that lives once, and one loader that reads every app's
part of it.

Decision of 13 Oct 2026 (Ruben): coherent demo data for a municipality is
loaded by one generic loader in OpenRegister. The cast lives once in
OpenRegister; every app ships its own fragment that refers to cast members by
key and to other apps' objects only by lookup on a business key.

## What Changes

- New occ commands: `openregister:example-set:install <set>` (with `--check`,
  `--password-from-env`, `--adopt`), `openregister:example-set:remove <set>` and
  `openregister:example-set:list`.
- A set is a cast plus fragments. Each enabled app may ship
  `lib/Settings/example-sets/<set>.json`. Only OpenRegister's file may carry the
  `cast`; it is also OpenRegister's own fragment (BRP and KVK rows of the cast).
- Fragments are ordered by `after: [appid, ...]`; an absent app is skipped and
  named; a cycle fails before anything is written.
- Placeholders `{{cast:key.field}}`, `{{date:n}}`, `{{datetime:n HH:MM}}`,
  `{{object:key.field}}` and `{{lookup:name}}`. An unresolved placeholder is an
  error naming file, object key and placeholder, never an empty string.
- Nextcloud accounts and groups come from the cast. Passwords are never in a
  file or a log; a generated one is printed once, or one is read from `OC_PASS`.
  An existing account the loader did not create is refused unless the
  administrator names it with `--adopt`, and an adopted account is never
  deleted.
- Every object is validated against its target schema by OpenRegister's own
  validator before anything is written, whether or not the schema has hard
  validation on. `--check` runs only this pass.
- An install record in OpenRegister's app config makes a second install write
  nothing and makes `remove` exact.
- After an install the loader reads every created object back. Exit codes:
  0 complete, 1 nothing written, 2 something missing (each named).
- `requires` in the cast file: OpenRegister registers the set needs are
  imported from OpenRegister's own descriptors before the check when the
  instance lacks them, recorded, and removed by `remove` only when this set
  imported them.
- `changes` in a fragment: set or append values on an object the set did not
  create (found by business key), recorded so remove restores exactly that.
- A cast or fragment group `admin` refuses the set.
- `jsonFields`: fields a schema keeps as JSON text are sent as text.
- The cast file for set `zuiddrecht`, requiring `brp` and `kvk`.

## Capabilities

### New Capabilities

- `example-sets`: install, check, list and remove a story example set across apps.

### Modified Capabilities

<!-- none -->

## Impact

- New code under `lib/Service/ExampleSet/` and three commands in `lib/Command/`,
  registered in `appinfo/info.xml`.
- New data file `lib/Settings/example-sets/zuiddrecht.json`.
- No schema, migration, route or frontend change. Nothing installs itself: the
  loader runs only when an administrator calls it (ADR-111 rule 3).
- Other apps add fragments in their own repositories later. portaliq's
  `example-resident` keeps working unchanged; see design.md for how Sanne de
  Vries moves onto the cast.
