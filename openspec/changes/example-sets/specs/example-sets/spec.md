## ADDED Requirements

### Requirement: A set is gathered from every enabled app in a declared order (REQ-EXS-001)

The loader SHALL read `lib/Settings/example-sets/<set>.json` from every app that Nextcloud's app manager reports as enabled. Each file is that app's fragment. OpenRegister's file SHALL carry the `cast` and SHALL be ordered first; a `cast` in any other app's file SHALL refuse the set. The remaining fragments SHALL be ordered so that every app named in a fragment's `after` list comes before it, with ties broken by app id. An app named in `after` (or in the cast file's `apps` list) that is not enabled SHALL be skipped and named in the output. A cycle in `after` SHALL refuse the set before anything is written, naming the apps in the cycle.

#### Scenario: Fragments are ordered by `after`
- **GIVEN** enabled apps `alpha` with `after: ["beta"]` and `beta` with no `after`, both shipping a fragment of set `demo`
- **WHEN** the set is gathered
- **THEN** the order SHALL be `openregister`, `beta`, `alpha`

#### Scenario: An absent app is skipped and named
- **GIVEN** a fragment with `after: ["gamma"]` and no enabled app `gamma`
- **WHEN** the set is gathered
- **THEN** the set SHALL still load, and the output SHALL name `gamma` as skipped

#### Scenario: A cycle refuses the set
- **GIVEN** `alpha` with `after: ["beta"]` and `beta` with `after: ["alpha"]`
- **WHEN** the set is gathered
- **THEN** the command SHALL exit 1 with a message naming `alpha` and `beta`, and SHALL write nothing

#### Scenario: Only OpenRegister carries the cast
- **GIVEN** an app other than openregister whose fragment has a `cast` key
- **WHEN** the set is gathered
- **THEN** the set SHALL be refused with a message naming that app's file

### Requirement: Every placeholder resolves or the set is refused (REQ-EXS-002)

Declared values SHALL support `{{cast:<key>.<field>}}` (a dot path into a cast person, organisation or group), `{{date:<n>}}` and `{{datetime:<n> HH:MM}}` (n days from the install day in the set's time zone), `{{object:<key>.<field>}}` (a field of an object declared earlier in the SAME fragment) and `{{lookup:<name>}}` (the id of the one row a lookup by business key finds, in any register). A value that is exactly one placeholder SHALL take the type of what it stands for; a placeholder inside a longer string SHALL be interpolated as text. A placeholder that cannot be resolved SHALL be an error naming the file, the object key and the placeholder; it SHALL NEVER become an empty string.

#### Scenario: A cast field fills a value
- **GIVEN** a cast person `sanne` with `bsn` `999994001`
- **WHEN** a declared value is `"{{cast:sanne.bsn}}"`
- **THEN** the written value SHALL be `"999994001"`

#### Scenario: A relative date follows the install day
- **GIVEN** an install on 2026-10-13 in Europe/Amsterdam
- **WHEN** a declared value is `"{{date:-2}}"`
- **THEN** the written value SHALL be `"2026-10-11"`

#### Scenario: An unknown cast key is an error that names its place
- **GIVEN** fragment file `dossiq/lib/Settings/example-sets/demo.json` with object `case-1` declaring `"{{cast:nobody.uid}}"`
- **WHEN** the set is checked
- **THEN** the refusal SHALL name that file, `case-1` and `{{cast:nobody.uid}}`

#### Scenario: An object reference does not cross fragments
- **GIVEN** fragment `alpha` with object `a1`, and fragment `beta` declaring `"{{object:a1.id}}"`
- **WHEN** the set is checked
- **THEN** the placeholder SHALL be refused; `beta` must use a lookup on a business key

### Requirement: Accounts and groups come from the cast and are never taken over (REQ-EXS-003)

The loader SHALL create a Nextcloud account for every cast person with a `uid`, with the cast's display name and e-mail, and every group the cast declares. A generated password SHALL be printed once on the console and SHALL NOT be written to a file, the install record or a log; `--password-from-env` SHALL read one password for every new account from `OC_PASS` instead. An account that exists and that the install record does not list as created by this set SHALL refuse the install before anything is written, unless the administrator names its uid with `--adopt`; an adopted account SHALL be recorded as adopted and SHALL NOT be deleted by remove. A group that already exists SHALL be used and SHALL NOT be deleted by remove; only memberships this set added are removed.

#### Scenario: A new account prints its password once
- **WHEN** an install creates the account `henk.bakker`
- **THEN** the output SHALL show its password once, and a second install SHALL show no password

#### Scenario: A foreign account refuses the install
- **GIVEN** an existing account `sanne.devries` that the record does not list
- **WHEN** the set is installed without `--adopt sanne.devries`
- **THEN** the command SHALL exit 1 naming the account, and SHALL write no account, group or object

#### Scenario: An adopted account survives remove
- **GIVEN** the set was installed with `--adopt sanne.devries`
- **WHEN** the set is removed
- **THEN** the account `sanne.devries` SHALL still exist

### Requirement: Check validates every object against its real schema without writing (REQ-EXS-004)

`--check` SHALL resolve every placeholder and lookup, verify each target register and schema exists on the instance, check every cast `bsn` against the eleven test, and validate every object with OpenRegister's own object validator against its target schema, whether or not that schema has hard validation on. It SHALL write nothing. It SHALL exit non-zero when anything is refused and name each refusal. An install SHALL run the same pass first and write nothing when it refuses. A reference to an object of the set that is not written yet SHALL be validated with a stand-in of the right shape, and a lookup SHALL also match an object declared earlier in the set.

#### Scenario: A value the schema refuses fails the check
- **GIVEN** an object whose `status` is not in its schema's enum
- **WHEN** `occ openregister:example-set:install demo --check` runs
- **THEN** it SHALL exit 1 naming the object key and the schema's message, and SHALL write nothing

#### Scenario: A clean set passes the check
- **GIVEN** a set whose objects all satisfy their schemas
- **WHEN** the check runs
- **THEN** it SHALL exit 0 and report the number of objects checked per register and schema

### Requirement: A second install writes nothing (REQ-EXS-005)

The loader SHALL keep an install record per set in OpenRegister's app config, listing the accounts it created or adopted, the groups it created, the memberships it added and the id of every object it wrote under `<app>/<key>`. A second install SHALL write nothing that the record holds and still finds on the instance, and SHALL report each as already there. An object the record holds but the instance lost SHALL be written again.

#### Scenario: A repeated install is a no-op
- **GIVEN** a complete install of set `demo`
- **WHEN** the install runs again
- **THEN** it SHALL create no account, group, membership or object, report every one as already there, and exit 0

### Requirement: An install proves what arrived (REQ-EXS-006)

After writing, the loader SHALL read every object it created in this run back from the instance and compare it with what it sent, and SHALL check that every recorded account, group and membership exists. The command SHALL exit 0 when everything declared is on the instance, 1 when nothing was written (refused set, check failure, foreign account) and 2 when something is missing or was not kept, naming each.

#### Scenario: A key the schema drops is named
- **GIVEN** an object whose declared key the schema does not keep
- **WHEN** the install reads it back
- **THEN** the output SHALL name the object and the key, and the command SHALL exit 2

### Requirement: Remove deletes exactly what the install made (REQ-EXS-007)

`openregister:example-set:remove <set>` SHALL delete, in reverse order of writing, every object the record holds, then the memberships it added, then the groups it created, then the accounts it created. It SHALL NOT touch an adopted account, a pre-existing group, or any object it did not write. What it cannot delete SHALL stay in the record and be named, with exit 2.

#### Scenario: Remove leaves foreign data alone
- **GIVEN** an object in the same schema that the set did not write
- **WHEN** the set is removed
- **THEN** that object SHALL still exist and the set's own objects SHALL be gone

### Requirement: An administrator can list the sets (REQ-EXS-008)

`openregister:example-set:list` SHALL list every set for which an enabled app ships a fragment, with the apps that ship one and whether the set is installed.

#### Scenario: The list names the fragments
- **GIVEN** openregister and portaliq both ship `zuiddrecht.json`
- **WHEN** the list runs
- **THEN** it SHALL show `zuiddrecht` with `openregister, portaliq` and its install state
