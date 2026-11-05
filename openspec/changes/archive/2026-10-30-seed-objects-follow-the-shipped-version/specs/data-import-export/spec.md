# Data import and export

## ADDED Requirements

### Requirement: A stored seed object follows a higher shipped version @e2e exclude backend import path run by repair steps and occ, no UI: covered by PHPUnit

A seed object is an object listed in a configuration with a `@self.slug`. The
import SHALL find it by register, schema and slug. When the stored object
exists and the shipped `@self.version` is higher, the import SHALL update the
stored object to the shipped values. At the same or a lower version it SHALL
leave the object alone and count it as unchanged. Objects the configuration does
not list SHALL NOT be read or written.

The update SHALL store the shipped version on the object, so the next import
reads the seed as current. Any other update SHALL bump the patch version. A
version carried in `@self.version` SHALL count only for a save that runs
without RBAC (the import, an administrator, a system caller): a caller judged
by RBAC SHALL NOT set an object's version, so the seed channel cannot be
steered from the object API.

The import SHALL compare the shipped version with the version it LAST SHIPPED
for that seed, which it remembers apart from the object (per app id, never on
the object), not with the object's own version: every later save of the object
bumps its patch, so an edited seed would otherwise read as already current and
a changed shipped seed would never arrive. A seed stored before the import
remembered anything is compared with its stored version once, and remembered
at the version the file then ships.

#### Scenario: An upgraded instance receives the shipped case type

- **GIVEN** an instance holding the seed `woo-verzoek` at version `1`, without a handling team
- **WHEN** the app's configuration ships it at `1.4.7` with `handling.defaultGroup: behandelaars`
- **THEN** the stored object is updated to the shipped values
- **AND** its `@self.version` is `1.4.7`

#### Scenario: A seed edited on the instance still receives the next shipped version

- **GIVEN** a seed shipped at `1.4.7`, then saved once on the instance (its own version is now `1.4.8`)
- **WHEN** the configuration ships it at `1.4.8`
- **THEN** the stored object is updated to the shipped values, and `1.4.8` is remembered as the shipped version

#### Scenario: A seed at the shipped version is left alone

- **GIVEN** an instance holding the seed at the shipped version
- **WHEN** the configuration is imported
- **THEN** the object is not saved and is counted under `unchanged.objects`

### Requirement: A failed seed object is retried, named, and not forgotten @e2e exclude backend import path run by repair steps and occ, no UI: covered by PHPUnit

A listed object whose save fails SHALL be tried once more after every other
listed object of the import has been written. An object that fails again SHALL
be named in the result's `failed.objects` with its slug, register, schema, the
reason, and whether a save listener refused it.

When a save listener refused a listed object, the import SHALL NOT store its
content hash, so the next run imports again instead of fast-skipping. A failure
caused by the file itself SHALL NOT withhold the hash.

#### Scenario: A seed listed before the row it depends on

- **GIVEN** a case type listed before the flow it names, and a listener that refuses the case type while that flow is missing
- **WHEN** the configuration is imported
- **THEN** the flow is written, the case type is retried and saved
- **AND** `failed.objects` is empty and the content hash is stored

#### Scenario: A listener keeps refusing

- **WHEN** a listener refuses a listed object on both attempts
- **THEN** `failed.objects` names it with `refusedByListener: true`
- **AND** the content hash is stored empty, so the next run imports again

#### Scenario: The file itself is invalid

- **WHEN** a listed object fails schema validation on both attempts
- **THEN** `failed.objects` names it with `refusedByListener: false`
- **AND** the content hash is stored
