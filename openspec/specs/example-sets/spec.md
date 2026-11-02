# example-sets Specification

## Purpose
An example set is a named demo world, such as Zuiddrecht, that every enabled app adds its part to. One command checks the whole set, installs it, refreshes it in place and removes exactly what it wrote. It covers the people and groups in the cast, objects, tasks, files, flows, views, preferences and settings, so a demo or test instance starts from the same known data every time.

## Requirements

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

Declared values SHALL support `{{cast:<key>.<field>}}` (a dot path into a cast person, organisation or group), `{{date:<n>}}` and `{{datetime:<n> HH:MM}}` (n days from the install day in the set's time zone), `{{object:<key>.<field>}}` (a field of an object declared earlier in the SAME fragment) and `{{lookup:<name>}}` (the id of the one row a lookup by business key finds, in any register; a lookup that more than one row answers, which two organisations holding the same key do, SHALL be refused naming the count, never resolved to whichever row comes first). A value that is exactly one placeholder SHALL take the type of what it stands for; a placeholder inside a longer string SHALL be interpolated as text. A placeholder that cannot be resolved SHALL be an error naming the file, the object key and the placeholder; it SHALL NEVER become an empty string.

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

`--check` SHALL resolve every placeholder and lookup, verify each target register and schema exists on the instance, check every cast `bsn` against the eleven test, and validate every object with OpenRegister's own object validator against its target schema, whether or not that schema has hard validation on. It SHALL write none of the set's accounts, groups, objects or changes; the only things it may write are the import of a register the set requires (REQ-EXS-009), because those schemas are what it checks against, and the OpenRegister organisations the cast declares and the instance lacks (REQ-EXS-016), because `{{organisation:...}}` placeholders resolve to them; both are recorded, and remove takes them away again. The command SHALL say so in its `--check` option text and in its closing output. It SHALL exit non-zero when anything is refused and name each refusal. An install SHALL run the same pass first and write none of the set's accounts, groups, objects or changes when it refuses. A reference to an object of the set that is not written yet SHALL be validated with a stand-in of the right shape, and a lookup SHALL also match an object declared earlier in the set. An account id the set creates (a cast member's `uid` or a service account's `uid`) SHALL pass a property of format `user` in the check although the account does not exist yet, because an install writes every account before any object; any other id that names no existing account SHALL still be refused, in an object and in a change. The validator SHALL accept those ids for the set's own validation only.

#### Scenario: A value the schema refuses fails the check
- **GIVEN** an object whose `status` is not in its schema's enum
- **WHEN** `occ openregister:example-set:install demo --check` runs
- **THEN** it SHALL exit 1 naming the object key and the schema's message, and SHALL write nothing

#### Scenario: A clean set passes the check
- **GIVEN** a set whose objects all satisfy their schemas
- **WHEN** the check runs
- **THEN** it SHALL exit 0 and report the number of objects checked per register and schema
- **AND** that number SHALL equal the number the install reports as declared for the same register and schema; changes SHALL be counted and reported apart

#### Scenario: A change does not raise the object count
- **GIVEN** a fragment with ten `dossiq case` objects and two changes on `dossiq case`
- **WHEN** the set is checked and then installed
- **THEN** the check SHALL report `dossiq case: 10 checked` and `dossiq case: 2 change(s) checked`, and the install `dossiq case: 10 declared`
- @e2e exclude {console output; covered by ExampleSetLiveCheckTest}

#### Scenario: An account the set creates passes a user format before it exists
- **GIVEN** a ticket whose `assignee` (format `user`) is `{{cast:daan.uid}}`, and no account `daan.verbeek` on the instance
- **WHEN** the check runs
- **THEN** it SHALL NOT refuse the ticket, and SHALL write no account
- **AND WHEN** the set is installed
- **THEN** the account SHALL be written before the ticket

#### Scenario: An account id the set does not declare is still refused
- **GIVEN** a ticket whose `assignee` is `nobody.here`, which is neither on the instance nor a uid of the set
- **WHEN** the check runs
- **THEN** it SHALL refuse the ticket with the schema's format message

### Requirement: A second install writes nothing (REQ-EXS-005)

The loader SHALL keep an install record per set in OpenRegister's app config, listing the accounts it created or adopted, the groups it created, the memberships it added and the id of every object it wrote under `<app>/<key>`. A second install SHALL write nothing that the record holds and still finds on the instance, and SHALL report each as already there. An object the record holds but the instance lost SHALL be written again; only a read that says the object does not exist counts as lost, and an object whose read FAILED (a database or magic-table error, a schema that cannot be resolved) SHALL NOT be written again: the install writes nothing for it, keeps the recorded id, reports it as not written, and the next install tries the read again.

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

`openregister:example-set:remove <set>` SHALL delete, in reverse order of writing, every object the record holds, then the memberships it added, then the groups it created, then the accounts it created. It SHALL NOT touch an adopted account, a pre-existing group, or any object it did not write. What it cannot delete SHALL stay in the record and be named, with exit 2; an object whose read before the delete failed (anything but "it does not exist") cannot be told gone, so it stays and is named with the reason.

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

### Requirement: A set imports the OpenRegister registers it requires (REQ-EXS-009)

The cast file MAY name OpenRegister registers in `requires`. An entry SHALL be either a register slug (for example `"publication"`), imported from OpenRegister's real register descriptor, or `{"register": "<slug>", "mock": true}`, imported from OpenRegister's mock (sample) descriptor that declares the slug, the way `occ openregister:descriptors:list --app=openregister --import=<slug> --mock` does. An example set is sample data, so it MAY require a register OpenRegister ships only as a mock (zuiddrecht requires `brp` and `kvk` that way). An entry that names no register slug SHALL refuse the set when it is gathered. Before the check, the loader SHALL import every required register that is not on the instance from the descriptor its entry names, and SHALL record the import job that import reports it ran under (with the configuration id it ran under), never a job found by comparing the jobs the instance holds before and after, which would claim an import another process recorded meanwhile. A required register that is already on the instance SHALL be used as it is and SHALL NOT be recorded. A second run SHALL import nothing. Remove SHALL soft-delete exactly the objects of the recorded import jobs, after the set's own objects, and SHALL NOT touch an import it did not make. The register and its schemas stay on the instance, as they may hold other people's data; so that a later install does not find a register without its data, the record keeps the slug of a register whose import was removed (without jobs, and not counted as an install), and the next install imports that register's data again although the register exists. A required register that cannot be imported SHALL refuse the set.

#### Scenario: A missing register is imported once and removed again
- **GIVEN** a set that requires `brp` and an instance without it
- **WHEN** the set is installed twice and then removed
- **THEN** `brp` SHALL be imported by the first run only, reported as `imported` then `kept`, and remove SHALL delete the objects of that import

#### Scenario: A register whose data was removed is imported again
- **GIVEN** a set that requires `brp`, installed on an instance without it and then removed
- **WHEN** the set is installed again
- **THEN** `brp` SHALL be imported again (its data was removed although the register stayed), reported as `imported`, and remove SHALL delete the objects of that second import

#### Scenario: A register that was there stays there
- **GIVEN** a set that requires `brp` and an instance that already has it
- **WHEN** the set is installed and removed
- **THEN** nothing SHALL be imported and remove SHALL leave every import of `brp` alone

#### Scenario: A register shipped only as a mock is imported from the mock
- **GIVEN** a set whose `requires` holds `{"register": "brp", "mock": true}` and a fresh instance without `brp`
- **WHEN** `occ openregister:example-set:install <set> --check` runs
- **THEN** `brp` SHALL be imported from OpenRegister's mock `brp` descriptor under the `openregister.mock` configuration id, reported as `imported`, and recorded so remove deletes its objects

#### Scenario: A plain slug never falls back to a mock
- **GIVEN** a set whose `requires` holds `"brp"` and OpenRegister ships `brp` only as a mock
- **WHEN** the set is checked
- **THEN** it SHALL be refused naming `brp`, and SHALL NOT import the mock

#### Scenario: A register that cannot be imported refuses the set
- **GIVEN** a set that requires a register OpenRegister ships no descriptor for
- **WHEN** the set is installed
- **THEN** the command SHALL exit 1 naming the register, and SHALL write none of the set's accounts, groups or objects

### Requirement: A set never adds anyone to Nextcloud's administrators (REQ-EXS-010)

A cast group or fragment group whose gid is `admin` (in any letter case) SHALL refuse the set before anything is written. An administrator role in an app is given through that app's own admin group in its fragment.

#### Scenario: An admin group refuses the set
- **GIVEN** a fragment that puts a cast person in group `admin`
- **WHEN** the set is checked or installed
- **THEN** it SHALL be refused naming the group, and no account or group SHALL be written

### Requirement: A set may change an object it did not create and undo exactly that (REQ-EXS-011)

A fragment MAY declare `changes`: each with a `key`, a `register`, a `schema`, a `where` that finds exactly one existing object by business key, and `set` (dot path to value) and/or `append` (dot path to one value added to the list there). The check SHALL refuse a change whose `where` finds no object or more than one (naming the count; two organisations may hold the same business key) or whose result its schema refuses. Install SHALL write only what differs, SHALL record the previous value of every path it set, the values it appended, and any map it created on the way to a path. A change already fully present SHALL NOT be recorded. Remove SHALL put back exactly the recorded values and leave every other part of the object as it is then. A path whose value is no longer the one the change wrote (somebody edited it after the install) SHALL be left as it is and named in the output as left in place, as for app config and user preferences.

#### Scenario: A sign-in mode added to a portal is taken off again
- **GIVEN** a portal row with sign-in modes `["password"]` that the set did not create
- **WHEN** a set appends `nextcloud` to `authentication.modes` and sets a card in `authentication.modeLabels`, somebody renames the portal, and the set is removed
- **THEN** the portal SHALL have modes `["password"]`, no `modeLabels`, and its new name

### Requirement: A field a schema keeps as JSON text is sent as text (REQ-EXS-012)

An object MAY name fields in `jsonFields`. Those fields SHALL be sent to the register as JSON text and validated in that form, and the read-back proof SHALL compare the decoded text with the declared structure. A change on such an object (REQ-EXS-018) SHALL carry the object's `jsonFields`, and a change found by `where` MAY name its own `jsonFields`: the change SHALL read those fields as their structure, apply `set` and `append` to it, and validate and write them as JSON text again, the same encoding the object was created with. Its proof and its remove SHALL decode the same fields.

#### Scenario: A history list is stored as text and proved as a list
- **GIVEN** an object with `jsonFields: ["history"]` and a list in `history`
- **WHEN** the set is installed
- **THEN** the register SHALL receive the list as JSON text and the proof SHALL report nothing lost

#### Scenario: A change on a case with a JSON text history is checked as it is written
- **GIVEN** a case with `jsonFields: ["statusHistory"]`, a result for it, and a change with `object: <the case>` that sets `result`
- **WHEN** the check runs and the set is installed
- **THEN** the check SHALL NOT refuse the change for `statusHistory`, and the stored case SHALL hold its `statusHistory` as JSON text and point at the result

#### Scenario: An append inside JSON text is proved and taken out as a list
- **GIVEN** an existing row whose `history` is the text `[{"at":"a"}]`, and a change with `jsonFields: ["history"]` that appends `{"at":"b"}` to `history`
- **WHEN** the set is installed and then removed
- **THEN** the row SHALL hold `[{"at":"a"},{"at":"b"}]` as text, the proof SHALL report nothing lost, and after remove it SHALL hold `[{"at":"a"}]` again

### Requirement: An object of a set may carry files from its fragment (REQ-EXS-013)

An object MAY list `files`, each with a `path` relative to the `lib/Settings/example-sets/` folder of the app that ships the fragment, and optionally `title`, `description` and `share`. A path that is absolute, holds a `..` segment, a backslash or a NUL, or resolves outside that folder SHALL refuse the set. The check SHALL refuse a file that is not a readable file there. After the object is written the loader SHALL add each file with OpenRegister's FileService, share it when `share` is true, and record it under the object. Remove SHALL delete the recorded files before the object. The proof SHALL name a recorded file the object no longer has. `title` and `description` are kept in the fragment for the reader; FileService stores the file under its file name.

#### Scenario: A shared document arrives with its publication and leaves with it
- **GIVEN** a publication with `files: [{"path": "zuiddrecht/pub-1/01-besluit.pdf", "share": true}]`
- **WHEN** the set is installed and then removed
- **THEN** the publication SHALL have the shared file `01-besluit.pdf`, and after remove neither the file nor the publication SHALL exist

#### Scenario: A path out of the folder is refused
- **GIVEN** a file path `../openregister/secret.pdf`
- **WHEN** the set is gathered
- **THEN** it SHALL be refused naming the fragment file

### Requirement: A fragment may declare service accounts (REQ-EXS-014)

A fragment MAY declare `serviceAccounts`, each with a `key`, a `uid`, a `displayName` and `groups`. The loader SHALL create each as an enabled Nextcloud account with a long random password that is never shown, logged or recorded, and SHALL add it to its groups, creating a group that is not there. A service account SHALL follow the account rules of REQ-EXS-003: an existing account the set did not create refuses the set unless adopted. A uid that is also a cast member's SHALL refuse the set. `{{account:<key>.uid}}` SHALL fill its uid anywhere in the set.

#### Scenario: A background account is made and joins its group
- **GIVEN** a service account `background` with uid `dossiq_background` in group `dossiq-background-service`
- **WHEN** the set is installed
- **THEN** the account SHALL exist, be in that group, and no password SHALL appear in the output or the record

### Requirement: A fragment may set its own app's config and undo exactly that (REQ-EXS-015)

A fragment MAY declare `appConfig`, each entry a `key` and a `value` (placeholders allowed) or `generate: true`. An entry SHALL only set config of the app that ships the fragment; naming another app SHALL refuse the set. A key the app keeps as sensitive SHALL be refused unless the set generates the value; a generated value SHALL be stored as sensitive and SHALL never be printed or recorded; the record holds only a one-way fingerprint of it, so that remove can tell the generated value from one somebody put there. Install SHALL write only a value that differs and SHALL record the old value. Remove SHALL put the old value back, or delete the key when there was none, but SHALL leave a key whose value changed after the install (a generated key whose value no longer has the recorded fingerprint included), naming it; a generated key that existed with an empty value gets that value back. Output SHALL name keys, never values.

#### Scenario: The background account is configured and the old value comes back
- **GIVEN** dossiq's `woo_publication_catalog_slug` holds `publication`
- **WHEN** the set writes `zuiddrecht` there and is then removed
- **THEN** the key SHALL hold `publication` again

#### Scenario: A sensitive key is not written from a file
- **GIVEN** a key the app keeps as sensitive
- **WHEN** a fragment gives it a value
- **THEN** the check SHALL refuse it, and nothing SHALL be written

### Requirement: A set finds OpenRegister organisations and creates the ones its cast needs (REQ-EXS-016)

`{{organisation:<slug or name>}}` SHALL fill the uuid of an OpenRegister organisation (the tenant entity, not an object), a slug winning over a name. The cast MAY declare `openregisterOrganisations` (key, name, slug, description). Before the check the loader SHALL create each one whose slug is not on the instance and record it; remove SHALL delete only the organisations the set created, last. Only a lookup that says the organisation does not exist SHALL read as absent (an install creates it) or as already gone (remove forgets it); any other error SHALL refuse the install (nothing is created) or leave the organisation in the record to be tried again. Remove SHALL NOT delete an organisation that has a member other than the accounts the set made or adopted (a leftover membership of an account that no longer exists does not count): it SHALL stay, stay in the record, and be named as left in place.

#### Scenario: The municipality becomes an organisation once
- **GIVEN** the cast declares organisation `zuiddrecht` and the instance has none with that slug
- **WHEN** the set is installed twice and removed
- **THEN** it SHALL be created once, fill `{{organisation:zuiddrecht}}`, and be gone after remove

#### Scenario: An organisation somebody joined stays
- **GIVEN** an organisation the set created, to which an administrator added a real account
- **WHEN** the set is removed
- **THEN** the organisation SHALL stay and stay in the record, and the output SHALL name it as left in place

### Requirement: The instance's address fills a placeholder (REQ-EXS-017)

`{{instance:baseUrl}}` SHALL fill Nextcloud's `overwrite.cli.url` without a trailing slash. When the instance sets no address the placeholder SHALL be refused, never filled with nothing.

#### Scenario: A publication link points at this instance
- **GIVEN** `overwrite.cli.url` is `https://cloud.example.test/`
- **WHEN** a value is `{{instance:baseUrl}}/apps/x`
- **THEN** it SHALL be written as `https://cloud.example.test/apps/x`

### Requirement: A change may set a link on an object written earlier in the run (REQ-EXS-018)

A change MAY name `object: <key>` of an object declared earlier in the same fragment instead of a `where`, so a later step can set a link once its target exists, such as a case's `result`. It SHALL be recorded like any change (REQ-EXS-011), and remove SHALL stay exact. A key that names no earlier object SHALL refuse the set.

#### Scenario: A case gets its result
- **GIVEN** a case and then a result for it in one fragment, and a change on the case setting `result` to `{{object:result-1.id}}`
- **WHEN** the set is installed
- **THEN** the case SHALL point at the result, and remove SHALL leave neither

### Requirement: A date may be pinned to a weekday (REQ-EXS-019)

`{{date:<n>@<mon..sun>}}` and `{{datetime:<n>@<mon..sun> HH:MM}}` SHALL fill the first given weekday on or after the day n days from the install day, so a council meeting stays on a Thursday.

#### Scenario: Next week's council meeting
- **GIVEN** an install on Tuesday 2026-10-13
- **WHEN** a value is `{{date:+7@thu}}`
- **THEN** it SHALL be `2026-10-22`

### Requirement: Every cast member has a stable contactsUid (REQ-EXS-020)

Every cast person and organisation that declares no `contactsUid` SHALL get `example-set-<set>-<key>`, the same on every install, so an app that keeps a Contacts uid can quote `{{cast:<key>.contactsUid}}`. The loader SHALL NOT create Contacts cards.

#### Scenario: Henk's uid
- **WHEN** set `zuiddrecht` is gathered
- **THEN** `{{cast:henk.contactsUid}}` SHALL be `example-set-zuiddrecht-henk`

### Requirement: A register or schema is named by slug and written as its id (REQ-EXS-021)

`{{register:<slug>}}` SHALL fill the numeric database id of the register with that slug, and `{{schema:<register-slug>/<schema-slug>}}` the numeric id of the schema with that slug inside that register, never one from another register. As a whole value either SHALL keep its integer type; inside a longer text it SHALL be written as digits. A slug the instance does not have SHALL refuse the set, naming the placeholder. The ids SHALL be looked up after the registers the set requires are imported (REQ-EXS-009), so a check finds them too. A fragment SHALL use these placeholders wherever the register stores an id, so the read-back proof compares an id with an id.

#### Scenario: A catalog lists its register and schema by id
- **GIVEN** register `publication` with id 31 holding schema `publication` with id 1113, and a catalog with `registers: ["{{register:publication}}"]` and `schemas: ["{{schema:publication/publication}}"]`
- **WHEN** the set is installed
- **THEN** the catalog SHALL be written with `registers: [31]` and `schemas: [1113]`, and the proof SHALL report nothing lost

#### Scenario: An unknown register refuses the set
- **WHEN** a value is `{{register:nope}}` and the instance has no register `nope`
- **THEN** the check SHALL refuse the object, naming `{{register:nope}}`, and SHALL write nothing

#### Scenario: A register the set imports has its id in the check
- **GIVEN** a set that requires register `brp`, which is not on the instance, and a value `{{register:brp}}`
- **WHEN** the check runs
- **THEN** it SHALL import `brp` and fill its id, and refuse nothing

### Requirement: Remove deletes what the set wrote even in an archival schema and says why anything stays (REQ-EXS-022)

Removing an example set is an administrative act on data the set itself wrote, not a user-driven delete. The remover SHALL delete an object listed in the set's install record through OpenRegister's system delete path, the one the retention sweep uses, so a schema that declares `x-openregister-archival` does not refuse it. It SHALL use that path only for objects in the install record. As on the retention sweep, an object under an active legal hold SHALL NOT be deleted. A file the set attached SHALL be found by its id in OpenRegister's own account and deleted as that file, so remove works from `occ` without a session. Every object and file that stays SHALL be named in the output with the reason OpenRegister gave, and SHALL stay in the record so a later remove tries again. An object whose file stays SHALL stay too.

#### Scenario: An archival case the set wrote is removed
- **GIVEN** a set that wrote a dossiq `case`, whose schema declares `x-openregister-archival`
- **WHEN** the set is removed
- **THEN** the case SHALL be deleted, and a user's delete of a case SHALL still be refused with `SCHEMA_ARCHIVAL_IMMUTABLE`

#### Scenario: A held object stays and says so
- **GIVEN** an object of the set under an active legal hold
- **WHEN** the set is removed
- **THEN** the object SHALL stay, the output SHALL name it with `it is under an active legal hold`, and it SHALL stay in the record

#### Scenario: Attached files are removed from occ
- **GIVEN** a publication of the set with an attached file
- **WHEN** `occ openregister:example-set:remove` runs
- **THEN** the file and then the publication SHALL be deleted

#### Scenario: A file that stays is named with its reason
- **GIVEN** an attached file that OpenRegister refuses to delete
- **WHEN** the set is removed
- **THEN** the output SHALL name the file, its object and the reason, the object SHALL stay because of it, and both SHALL stay in the record for the next remove

### Requirement: A kept object follows the objects it references (REQ-EXS-023)

When an install keeps an object an earlier run wrote, it SHALL resolve again every top-level field whose declared value holds an `{{object:}}` or `{{lookup:}}` placeholder and SHALL write only a field whose stored value differs AND is still the value the install wrote there (the record keeps what each object was created with in its reference fields, and a refresh keeps its newest value), so a reinstall after a partial remove does not leave a kept object pointing at an object that was deleted and written again. A field that holds any other value, or that the record holds no written value for, is somebody's edit: it SHALL be left as it is and named in the output (`Left as it is`), and it SHALL NOT make the install fail. The write SHALL be recorded as the change `<app>/<key>#references` with the value it replaced (the first replaced value is kept over later refreshes), the install SHALL prove it like any change (REQ-EXS-011), and remove SHALL stay exact. A kept object whose references already hold their values SHALL not be written.

#### Scenario: A kept publication follows its theme
- **GIVEN** a set whose publication references a theme by `{{object:theme-1.id}}` and another by a lookup, and a remove that deleted the theme but could not delete the publications
- **WHEN** the set is installed again
- **THEN** the theme SHALL be written with a new id, the publications SHALL be kept and point at the new theme, and the refresh SHALL be recorded with the old id

#### Scenario: A user's edit of a reference is left
- **GIVEN** a kept publication whose theme reference a user pointed at an unrelated id after the install
- **WHEN** the set is installed again
- **THEN** the reference SHALL keep the user's value, no `#references` change SHALL be recorded, and the output SHALL name the field as left as it is

#### Scenario: A refreshed set installs again without writes
- **GIVEN** a set whose references were refreshed
- **WHEN** it is installed again and then removed
- **THEN** the second install SHALL write nothing, and remove SHALL leave none of the set's objects and no record

### Requirement: A fragment may set its own app's preferences for the set's own accounts (REQ-EXS-024)

A fragment MAY declare `userPreferences`, each entry a `user` (`{{cast:<key>.uid}}` or `{{account:<key>.uid}}`), a `key` and a scalar `value` (placeholders allowed), written with `IConfig::setUserValue`. An entry SHALL only set preferences of the app that ships the fragment; naming another app SHALL refuse the set. The account SHALL be one the set's cast declares, created or adopted; such an account MAY be in Nextcloud's administrators group. An account outside the cast SHALL be refused, with its own reason when it is an administrator, and a key whose name holds a secret (password, secret, token, credential, private, api key, session) or that Nextcloud keeps as sensitive SHALL be refused; each refusal is made by the check, before anything is written. Install SHALL write only a value that differs and SHALL record the old value. Remove SHALL put the old value back, or delete the key when there was none, but SHALL leave a value that changed after the install, naming it, and SHALL do so before it deletes the accounts it created.

#### Scenario: The demo staff skip the tour
- **GIVEN** dossiq's fragment gives `{{cast:esra.uid}}` the preference `pref_walkthrough_completed_version` = `1.2.0`
- **WHEN** the set is installed twice and then removed
- **THEN** Esra SHALL hold `1.2.0` after the first install, the second install SHALL write nothing, and after remove the key SHALL be gone

#### Scenario: An adopted account gets its own value back
- **GIVEN** an adopted account that held `0.9.0`
- **WHEN** the set writes `1.2.0` and is then removed
- **THEN** the account SHALL hold `0.9.0` again, unless somebody changed the value after the install, which SHALL stay and be named

#### Scenario: An administrator the cast declares gets the preference
- **GIVEN** a preference for a cast account that was adopted and is one of Nextcloud's administrators
- **WHEN** the set is installed
- **THEN** the preference SHALL be written, and the administrators group SHALL be unchanged

#### Scenario: An administrator, a foreign account and a secret are refused
- **GIVEN** a preference for an account the set does not declare, one for an administrator outside the cast, and one for a key named `api_token`
- **WHEN** the set is checked
- **THEN** each SHALL be refused with its reason, and nothing SHALL be written

### Requirement: A fragment may put tasks on its objects

A fragment MAY declare `tasks`, each entry a `key` unique in the fragment and `data` with the task's fields: `title`,
`description`, `objectUuid` (usually `{{object:<key>.id}}`), `state` (any state the task engine maps), `assignee`,
`candidateGroups`, `priority`, `dueAt`, `kind`, and for a finished task `completedAt` and `completedBy`, placeholders
allowed. The check SHALL refuse, before anything is written, a field outside that list, a task without a title or an
object, an `assignee` or `completedBy` that is not an account the set creates or adopts, a state the engine does not
map, and a date that is not one. Install SHALL write each task once through the task service's trusted path, keyed
`example-set:<app>/<key>` with the fragment's app as its app id, SHALL record its uuid, and SHALL report a recorded task
that is no longer there as missing.

#### Scenario: A case gets its open and its finished tasks
- **GIVEN** dossiq's fragment declares an open task assigned to `{{cast:thomas.uid}}` on `{{object:woo-lindelaan.id}}`
  and a completed one with `completedAt`
- **WHEN** the set is installed twice
- **THEN** both tasks SHALL be in the engine on that case once, the completed one with its completion moment, and the
  second install SHALL write nothing

#### Scenario: A task for a stranger is refused
- **GIVEN** a task assigned to an account the set does not declare
- **WHEN** the set is checked
- **THEN** the check SHALL refuse it, naming the account, and nothing SHALL be written

### Requirement: Remove deletes the tasks a set wrote

Remove SHALL delete the tasks the record holds, newest first and before the objects they are on, and SHALL count them.
A task already gone SHALL count as removed.

#### Scenario: Remove leaves no task behind
- **GIVEN** an installed set with two tasks
- **WHEN** the set is removed
- **THEN** both tasks SHALL be deleted from the engine and the record SHALL hold none

### Requirement: A fragment may declare saved views for the set's own accounts

A fragment MAY declare `views`, each entry a `key` unique in the fragment and `data` with `name`, `description`,
`owner`, `isPublic`, `query`, `presentation` and `alert`, placeholders allowed. The check SHALL refuse, before anything
is written, a field outside that list, a view without a name, an owner that is not an account the set creates or adopts,
and a `query`, `presentation` or `alert` that is not an object. Install SHALL write each view once through the view
service, which validates its presentation and alert, SHALL record its uuid, and SHALL report a recorded view that is no
longer there as missing. An OpenRegister that cannot write views SHALL refuse a fragment that declares them.

#### Scenario: A saved search with a count alert
- **GIVEN** dossiq's fragment declares a public view owned by `{{cast:thomas.uid}}` with query `search: "Lindelaan"` and
  `alert: {operator: "gte", threshold: 12}`
- **WHEN** the set is installed twice
- **THEN** the view SHALL exist once with that owner, query and alert, and the second install SHALL write nothing

### Requirement: Remove deletes the views a set wrote

Remove SHALL delete the views the record holds, with the tasks and before the objects, newest first, and SHALL count
them. A view already gone SHALL count as removed.

#### Scenario: Remove leaves no view behind
- **GIVEN** an installed set with a saved view
- **WHEN** the set is removed
- **THEN** the view SHALL be gone and the record SHALL hold none

### Requirement: The cast carries the jeugdteam regisseur

The Zuiddrecht cast SHALL carry Fatma Kaya (`fatma.kaya`, regisseur jeugdteam, groups medewerkers and Sociaal domein),
the handler of dossiq's jeugdwet example case.

#### Scenario: the shipped set
- **GIVEN** the shipped Zuiddrecht set
- **THEN** its cast holds Fatma Kaya in Sociaal domein

### Requirement: The cast carries the handler the dossiq boards draw

The Zuiddrecht cast SHALL carry Pieter Jansen (`pieter`, behandelaar, groups medewerkers and Vergunningen), the handler
dossiq's boards draw as the reader, so a fragment can give him a saved view of his own.

#### Scenario: the shipped set
- **GIVEN** the shipped Zuiddrecht set
- **THEN** its cast holds 18 people, Pieter Jansen among them in Vergunningen with the account id `pieter`

### Requirement: A fragment may give a resident a portal task

A fragment's task MAY declare `performerType` with the value `external`, with an `assignee` of the form `party:<reference>`, placeholders allowed. Such a task MAY declare `uploadRules` with the keys `required`, `maxFiles`, `maxSizeBytes` and `acceptedTypes`.

The check SHALL refuse:
- an external task whose assignee is not a `party:` reference;
- a `completedBy` on an external task;
- `uploadRules` on a task that is not external;
- an `uploadRules` key outside that list.

An external assignee SHALL NOT be checked against the set's accounts. Install SHALL write `uploadRules` as the task's `metadata.upload` and SHALL write the task as the fragment's app.

#### Scenario: Sanne is asked for a copy of her ID
- **GIVEN** portaliq's fragment declares an external task on Sanne's parking permit case, with assignee `party:{{cast:sanne.uid}}` and `uploadRules: {required: true, maxFiles: 2}`
- **WHEN** the set is installed
- **THEN** the task SHALL be in the engine with performer type `external`, assignee `party:sanne.devries` and `metadata.upload.maxFiles` 2, so the portal lists it as Sanne's open task

#### Scenario: A misaddressed external task is refused
- **GIVEN** an external task assigned to `sanne.devries` without the `party:` prefix
- **WHEN** the set is checked
- **THEN** the check SHALL refuse it and nothing SHALL be written

### Requirement: A fragment may give a task its checklist

A fragment's task MAY declare `checklist`, a list of items with a non-empty `id` and `label`, an optional `description` and an optional `checked`. Install SHALL write the checklist to the engine as declared, so a ticked item is ticked on the task page.

The check SHALL refuse:
- a checklist that is not a list;
- an item without an id or a label;
- an item id used twice in one checklist;
- a `checked` that is not true or false.

#### Scenario: A task with two of four items done
- **GIVEN** dossiq's fragment declares a task with a checklist of four items, the first two `checked: true`
- **WHEN** the set is installed
- **THEN** the task SHALL be in the engine with those four items, and its page SHALL show two of four done

#### Scenario: A malformed checklist is refused
- **GIVEN** a task whose checklist item has no label
- **WHEN** the set is checked
- **THEN** the check SHALL refuse it and nothing SHALL be written

### Requirement: A fragment may let the set's own accounts follow its objects

A fragment MAY declare `watchers`, each with a `key` and the fields `user`, `objectUuid`, and optionally `register`, `schema` and `notify`, placeholders allowed. Install SHALL subscribe the user to the object once and record the pair. A later install SHALL leave a recorded follow as it is and SHALL report one that is gone as missing.

The check SHALL refuse:
- a `user` that is not an account the set creates or adopts;
- a row without `objectUuid`;
- a `notify` that is not true or false;
- any other field.

#### Scenario: Anouk follows the Woo case
- **GIVEN** a fragment declares a watcher with user `{{cast:anouk.uid}}` on its Woo case
- **WHEN** the set is installed
- **THEN** `anouk.bakker` SHALL follow the case, and the case page SHALL show her among its followers

### Requirement: Remove takes away the follows a set wrote

Remove SHALL unsubscribe each follow the record holds, and only those.

#### Scenario: Removing the set
- **GIVEN** the set was installed with one follow
- **WHEN** the set is removed
- **THEN** the follow SHALL be gone and the report SHALL count one removed watcher

### Requirement: The Zuiddrecht cast has Lars de Koning of Mobiliteit en verkeer

The Zuiddrecht cast SHALL hold the group `mobiliteit-en-verkeer`, displayed as "Mobiliteit en verkeer". It SHALL also hold the employee Lars de Koning, account `lars.dekoning`, as a member of that group.

#### Scenario: A fragment names Lars
- **GIVEN** a fragment assigns a task to `{{cast:lars.uid}}`
- **WHEN** the set is installed
- **THEN** the task SHALL belong to the account `lars.dekoning`

### Requirement: The Zuiddrecht cast holds the contacts, companies and second tenant the dossiq boards name

The Zuiddrecht cast SHALL hold:
- the residents Pieter Bos and Henk Visser, Henk at Beukenlaan 3;
- the companies Visser Bouw B.V. and Aannemersbedrijf Van Wijk B.V.;
- the OpenRegister organisation Gemeente Waterland;
- the employee Marieke Visser.

Each resident SHALL have a BRP entry and each company a KvK and an establishment entry. BSNs SHALL pass the eleven test and KvK numbers SHALL be example numbers.

#### Scenario: A new case finds a possible duplicate
- **GIVEN** the set is installed
- **WHEN** a handler starts a case for "Visser" at Beukenlaan 3
- **THEN** the contacts SHALL offer Henk Visser and Visser Bouw B.V.

### Requirement: The Zuiddrecht cast has the Woo en informatie team

The Zuiddrecht cast SHALL hold the group `woo-en-informatie`, displayed as "Woo en informatie", with Pieter Jansen, Thomas Kuiper and Anouk Bakker as members. Anouk Bakker SHALL be its team leader.

#### Scenario: Sharing a saved view with the team
- **GIVEN** the set is installed
- **WHEN** Pieter shares a saved view
- **THEN** the group "Woo en informatie" SHALL be offered, with three members

### Requirement: A fragment may put parties on its objects

A fragment MAY declare `parties`. Each row has a `key` and the fields `objectUuid`, `register`, `schema`, `partyUuid` and `role`, and optionally `validFrom`, `validUntil`, `note` and `primary`. Placeholders are allowed.

The check SHALL refuse:
- any other field;
- a row without an object, a party or a role;
- a `validFrom` or `validUntil` that is not a date.

Install SHALL link the party to the object in that role through the party model, with the fragment's app as the linker, and SHALL record the link. A later install SHALL report a recorded link that is gone as missing. Remove SHALL unlink the parties the record holds, and only those.

#### Scenario: A representative with a mandate until the end of the year
- **GIVEN** a fragment declares the party J. van Wijk on the Woo case in the role `gemachtigde`, valid until 31 December
- **WHEN** the set is installed
- **THEN** the case's parties SHALL list J. van Wijk under `gemachtigde` with that end date

#### Scenario: Removing the set
- **GIVEN** the set was installed with one party link
- **WHEN** the set is removed
- **THEN** the link SHALL be gone and the report SHALL count one removed party

### Requirement: The Zuiddrecht cast holds the parties of the Lindelaan Woo case

The Zuiddrecht cast SHALL hold:
- the person J. van Wijk, contact of Aannemersbedrijf Van Wijk B.V., with a BRP entry;
- the organisation Bewonersvereniging Lindelaan, with KvK and establishment entries.

#### Scenario: A fragment quotes the representative
- **GIVEN** a fragment refers to `{{cast:jvanwijk.bsn}}`
- **WHEN** the set is checked
- **THEN** the placeholder SHALL resolve

### Requirement: A later install attaches the files an object gained

When an install keeps an object the set wrote earlier and the record holds no files for it, the install SHALL attach the files the object's declaration names and SHALL record them. A later install SHALL attach nothing more.

#### Scenario: Files added to a case after it was installed
- **GIVEN** a case written by an earlier install, with no files recorded
- **AND** its declaration now names seven files
- **WHEN** the set is installed again
- **THEN** the seven files SHALL be attached to that case and recorded, and the case SHALL not be written again

### Requirement: The Zuiddrecht cast holds Buurtvereniging Oost and its chair

The Zuiddrecht cast SHALL hold the organisation Buurtvereniging Oost with KvK and establishment entries, and its contact Marja Verbeek with a BRP entry.

#### Scenario: A fragment quotes the association
- **GIVEN** a fragment refers to `{{cast:buurtoost.kvk}}`
- **WHEN** the set is checked
- **THEN** the placeholder SHALL resolve

### Requirement: A fragment may leave notes of the set's own accounts on its objects

A fragment MAY declare `notes` rows `{ user, objectUuid, message, createdAt? }`. The installer SHALL write each as a
Nextcloud comment of object type `openregister` on the object, by the user, with the message, created at `createdAt` (or
now), once, and record it. The check SHALL refuse a row whose user is not an account the set creates or adopts, that names
no object or message, carries another field, or whose `createdAt` is not a date and time.

#### Scenario: A note of this afternoon

- **GIVEN** a note by Esra on case-1 with `createdAt: {{datetime:0 15:40}}`
- **THEN** case-1 carries Esra's note created at 15:40 on the install day, and a second install keeps it

### Requirement: A fragment may say which objects the set's own accounts opened and when

A fragment MAY declare `readStates` rows `{ user, objectUuid, register?, schema?, seenAt? }`. The installer SHALL record
that the user opened the object at `seenAt` (or now), once, and record the pair. The check SHALL refuse a row whose user is
not an account the set creates or adopts, that names no object, carries another field, or whose `seenAt` is not a date
and time.

#### Scenario: A case opened this morning

- **GIVEN** a read state of Esra on case-1 with `seenAt: {{datetime:0 09:12}}`
- **THEN** case-1 reads as opened by Esra at 09:12 on the install day

### Requirement: Remove takes away the notes and read states a set wrote

Remove SHALL delete every note the record holds and put every recorded object back to unread for its user. A note or
read state that disappeared SHALL be reported missing by the next install.

#### Scenario: Removing the set

- **GIVEN** the set installed with one note and one read state
- **WHEN** the set is removed
- **THEN** the note is gone and the case is unread for Esra again

### Requirement: A cast organisation may declare its quota and its members

An `openregisterOrganisations` entry MAY declare a `quota` (`storage`, `requests`, `bandwidth`, whole numbers) and `members` (cast people's keys). An organisation the install creates SHALL carry that quota and those members' accounts. The catalogue SHALL refuse an unknown quota key, a value that is not a whole number and a member that is not a cast person with an account.

#### Scenario: A second tenant Pieter can read
- **GIVEN** the cast declares Gemeente Waterland with members `marieke` and `pieter` and a storage quota of 100 GB
- **WHEN** the set is installed on an instance without it
- **THEN** Waterland SHALL be created with that quota, and Pieter SHALL find it among his organisations

### Requirement: A fragment may give a task its engine key and organisation

A fragment's task MAY declare `taskKey` and `organisation`. Install SHALL write the task with `taskKey` as its engine key when declared, and with the set's own key otherwise. It SHALL write `organisation` as the task's organisation.

#### Scenario: A tenant's onboarding steps
- **GIVEN** a fragment declares seven tasks of kind `dossiq.onboarding` on tenant Waterland, keyed `contract` … `go_live`, five of them completed
- **WHEN** the set is installed
- **THEN** dossiq's onboarding progress for Waterland SHALL read 5 of 7

### Requirement: A fragment may declare flows

An example set fragment MAY declare flows of the app that ships it. Each flow MUST be checked before anything is
written, stored switched off, published unless the fragment says not to, written once, proved on reinstall and removed
with the set.

#### Scenario: A case type flow in the demo set
- GIVEN a fragment of app dossiq with a flow "Omgevingsvergunning regulier" of seven steps with canvas positions
- WHEN the set is installed
- THEN the flow is stored for dossiq, switched off, published as version 1, with the steps where the fragment put them
- AND installing again keeps it

#### Scenario: A faulty flow
- GIVEN a flow with a line to a step it does not have
- THEN the check refuses it and nothing is written

#### Scenario: Removing the set
- WHEN the set is removed
- THEN the flow is deleted with its versions

### Requirement: A preference may quote a view of its fragment

A fragment's user preference value MAY contain `{{view:<key>.uuid}}`, where `<key>` is a saved view the same fragment
declares. The installer SHALL write the fragment's views before its user preferences and fill the placeholder with the
written view's uuid. The check SHALL accept the placeholder for a declared view before it is written, and SHALL refuse
the set when the key names no view of the fragment.

#### Scenario: A view in the menu

- **GIVEN** a fragment declares the view `pieter-termijn-deze-week` and Pieter's preference `pref_cn_saved_view_menu`
  with `[{"id":"{{view:pieter-termijn-deze-week.uuid}}","page":"Cases","name":"Termijn deze week"}]`
- **WHEN** the set is installed
- **THEN** the preference holds the view's uuid, and the view shows in Pieter's menu

#### Scenario: An unknown view

- **GIVEN** a preference quotes `{{view:nope.uuid}}` and the fragment declares no view `nope`
- **THEN** the check refuses the set with "cannot resolve"

### Requirement: The Zuiddrecht cast has Kees de Wit, a contact person without a BSN

The Zuiddrecht cast SHALL hold Kees de Wit, of the organisation `buurtoost`, with an e-mail address and an address,
and with no BSN and no account.

#### Scenario: A fragment names Kees as a contact person
- **GIVEN** a fragment writes a person row from `{{cast:keesdewit.displayName}}` with no BSN
- **WHEN** the set is installed
- **THEN** the row SHALL be written, and no account SHALL be created for him

### Requirement: A note or read state may quote a task of its fragment

A fragment's watcher, party, note, read state or flow row MAY contain `{{task:<key>.uuid}}`, where `<key>` is a task the
same fragment declares. The installer SHALL write the fragment's tasks before those parts and fill the placeholder with
the written task's uuid. The check SHALL accept the placeholder for a declared task before it is written, and SHALL
refuse the set when the key names no task of the fragment.

#### Scenario: A note on a task

- **GIVEN** a fragment declares the task `hoorzitting-inplannen` and Pieter's note with `objectUuid`
  `{{task:hoorzitting-inplannen.uuid}}`
- **WHEN** the set is installed
- **THEN** the note is written on that task

#### Scenario: An unknown task

- **GIVEN** a note quotes `{{task:nope.uuid}}` and the fragment declares no task `nope`
- **THEN** the check refuses the set with "cannot resolve"

### Requirement: A change may quote a flow of its fragment

A fragment's change, or a row of an engine part, MAY contain `{{flow:<key>.uuid}}`, where `<key>` is a flow the same
fragment declares. The installer SHALL write the fragment's flows after its objects and before its changes, and fill the
placeholder with the written flow's uuid. The check SHALL accept the placeholder for a declared flow before it is
written, and SHALL refuse the set when the key names no flow of the fragment.

#### Scenario: The flows a case type allows

- **GIVEN** a fragment declares the flow `zienswijze-derde` and a change on the Woo case type that sets
  `startableFlows` to `["{{flow:zienswijze-derde.uuid}}"]`
- **WHEN** the set is installed
- **THEN** the case type lists the written flow's uuid

#### Scenario: An unknown flow

- **GIVEN** a change quotes `{{flow:nope.uuid}}` and the fragment declares no flow `nope`
- **THEN** the check refuses the set with "cannot resolve"

### Requirement: A cast person may carry a function, a role, an absence and a status

A cast person's `function` SHALL be written as the profile headline of the account the set created for them, and an
optional `roles` as its profile role. An optional `absence` (`fromDay` and `untilDay` counted from the install day)
SHALL be written as the account's out of office, and an optional `status` as its user status with its message. The
install SHALL write these at every run on accounts the set created only, and SHALL name a part it could not write
without refusing the set.

#### Scenario: A colleague away until next week

- **GIVEN** a cast person with `absence` `{"fromDay": -2, "untilDay": 7}`
- **WHEN** the set is installed
- **THEN** their account is out of office from two days before the install day until seven days after it

#### Scenario: An adopted account

- **GIVEN** a cast person whose account the administrator adopted
- **WHEN** the set is installed
- **THEN** their profile, absence and status are not written

### Requirement: A refresh rewrites every object the set declares

`example-set:install --refresh` SHALL rewrite every object the set's record holds to its declared values resolved
today (dates against the install day, cast, lookups, objects). Declared fields SHALL replace the stored values; fields
the declaration does not name SHALL stay. Objects the set did not write SHALL NOT be changed, and nothing SHALL be
deleted. The report SHALL count the refreshed objects per type, and the proof SHALL check them. The saved views, tasks, notes and read states the set
declares SHALL be written again from today's declaration, after the earlier one is taken away. Without `--refresh`
the install SHALL keep a held object or row as before.

#### Scenario: A date counted from the install day

- **GIVEN** a held case with `start` `{{date:-2}}`, installed on 13 October
- **WHEN** the set is installed with `--refresh` on 18 October
- **THEN** its `start` is 16 October, and a field the declaration does not name keeps its value

#### Scenario: Not the set's object

- **GIVEN** a case type the set looks up but did not write
- **WHEN** the set is installed with `--refresh`
- **THEN** the case type is unchanged

### Requirement: A group may name its sub-admins from the cast

A cast group or a fragment group MAY declare `subAdmins`, a list of cast keys of people with an account. Install SHALL make each named account a Nextcloud sub-admin of that group when it is not one yet, SHALL NOT make it a member or an administrator, and SHALL record what it made. The proof SHALL name a recorded sub-admin right that is gone. Remove SHALL take away only the sub-admin rights the record holds, before it removes memberships and accounts. A key that is no cast person with an account SHALL refuse the set before anything is written.

#### Scenario: A sub-admin is made, kept and taken away
- **GIVEN** a fragment group with member Esra and sub-admin Sanne
- **WHEN** the set is installed twice and then removed
- **THEN** Sanne SHALL be sub-admin after the first install (reported created), still after the second (reported kept), and not after remove; Sanne SHALL never be a member

#### Scenario: An unknown sub-admin refuses the set
- **GIVEN** a group naming a sub-admin key that is not a cast person with an account
- **WHEN** the set is checked
- **THEN** the set SHALL be refused with that key named, and nothing SHALL be written

### Requirement: OpenRegister's fragment may name the destruction-list register and schema

OpenRegister's own fragment of a set MAY declare an `archival` part: rows with a key and `data` holding `destructionListRegister` and/or `destructionListSchema`. Install SHALL merge only those keys into OpenRegister's `archival` setting, SHALL keep every other key of it, and SHALL record what each key held before. The proof SHALL name a written key that no longer holds the set's value. Remove SHALL put back what each key held (or drop a key that was not there, and the whole setting when the set made it from nothing), but SHALL leave a key whose value somebody changed since. Any other key, or a value that does not resolve, SHALL refuse the set before anything is written. A fragment of another app declaring the part SHALL refuse the set.

#### Scenario: The keys are merged and put back
- **GIVEN** an `archival` setting with `notificationLeadDays` 14 and `destructionListRegister` 7
- **WHEN** the set naming the destruction-list register and schema is installed and later removed
- **THEN** after install the setting SHALL hold `notificationLeadDays` 14 and the register's and schema's ids; after remove it SHALL hold `notificationLeadDays` 14 and `destructionListRegister` 7 again

#### Scenario: An unknown key refuses the set
- **GIVEN** an `archival` part with `destructionBatchSize`
- **WHEN** the set is checked
- **THEN** the set SHALL be refused with that key named, and nothing SHALL be written

### Requirement: A refresh sends only what the schema lets a write change

On `--refresh` a kept object SHALL be rewritten without sending a new value for a field its schema keeps as first written (`readOnly`, `immutable`): such a field SHALL carry its stored value. A lens field SHALL NOT be sent. A row of an append-only schema SHALL be kept as written and SHALL NOT be reported as not refreshed.

#### Scenario: A readOnly field and a lens
- **GIVEN** a kept object whose schema keeps `since` readOnly and reads `objectTitle` through a lens
- **WHEN** the set is refreshed on a later day
- **THEN** the object SHALL be refreshed, `since` SHALL keep its stored value and no lens value SHALL be stored

### Requirement: A refresh writes declared state as the system

The example-set store SHALL write in the system scope, so a read in the save path is never refused as an anonymous caller. An update by the store SHALL be marked as a write of declared state while it runs; the lifecycle guard SHALL let such a write set the lifecycle value the declaration names without a matching transition, and only inside the system scope.

#### Scenario: A declared status that no transition reaches
- **GIVEN** a kept advice request whose stored status is `expired` and whose declaration says `requested`
- **WHEN** the set is refreshed
- **THEN** the status SHALL be written as `requested`

### Requirement: A refresh keeps the record of what it wrote

After a refresh rewrites a kept object, the record of its references and of its `#references` change SHALL hold the values the rewrite wrote, so the proof does not read them as lost. The proof SHALL compare a value declared as JSON text with the value the store answers decoded.

#### Scenario: A history quoting a date
- **GIVEN** a kept object with a `#references` change from an earlier run and a history field quoting a date
- **WHEN** the set is refreshed on a later day
- **THEN** the install SHALL report ok and the change's recorded value SHALL be the refreshed history

### Requirement: A declared object may name its owner from the cast

A declared object MAY carry `owner`, which SHALL resolve to the account id of a person the set's cast declares. Install SHALL create the object owned by that account. On `--refresh` an object the record holds that another account owns SHALL be handed to the declared owner through OpenRegister's audited ownership change, as the system; an object the owner already holds SHALL NOT be handed over again. An `owner` that resolves to anything else SHALL refuse the set before anything is written.

#### Scenario: Created as the named account
- **GIVEN** a decision declaring `owner: {{cast:esra.uid}}`
- **WHEN** the set is installed
- **THEN** the decision SHALL be owned by `esra.demir`

#### Scenario: Handed over on refresh
- **GIVEN** that decision, now owned by `__system__`
- **WHEN** the set is refreshed twice
- **THEN** it SHALL be owned by `esra.demir` after the first refresh, with one hand-over in all

#### Scenario: An owner outside the cast
- **GIVEN** a declared owner `admin`, or a placeholder that resolves to no cast account
- **WHEN** the set is checked
- **THEN** the set SHALL be refused naming the owner, and nothing SHALL be written

### Requirement: The system scope may assign a record

`ObjectOwnershipService` SHALL let a trusted system operation (inside `SystemOperationContext`) assign or reassign a record without a session user. Outside that scope only an administrator SHALL.

#### Scenario: Assigned inside the system scope, refused outside it
- **GIVEN** no session user and a record owned by `__system__`
- **WHEN** a trusted system operation assigns it to `carol` inside `SystemOperationContext`
- **THEN** the record SHALL be owned by `carol`
- **AND** the same assignment outside the system scope SHALL be refused

### Requirement: A change sends a text property as text

When a change, a references refresh or a remove writes an object back, a top-level property its schema types as text only (`string`, optionally nullable) whose value the store read back as a structure SHALL be validated and written as JSON text, whether or not the change names it in `jsonFields`.

#### Scenario: A case with a JSON-text geometry
- **GIVEN** a case whose `geometry` is declared as JSON text and read back as a structure
- **WHEN** a change sets another field of it, the set is checked again on the kept case, and the set is removed
- **THEN** the check SHALL pass, the change SHALL be created, and remove SHALL report no failure

### Requirement: A declared object may keep fields verbatim

A declared object MAY carry `verbatimFields`, a list of top-level field names of its `data`. Install and check SHALL write those fields exactly as declared, without filling placeholders in them. Every other field SHALL be filled as before, and a placeholder in it that cannot be filled SHALL refuse the set.

#### Scenario: A mail template with its own placeholders
- **GIVEN** an object with `subject` "Uw verzoek {{zaakNummer}} is ontvangen" and `verbatimFields: ["subject", "body"]`
- **WHEN** the set is checked and installed
- **THEN** the check SHALL pass and the subject SHALL be stored with `{{zaakNummer}}` as written

#### Scenario: A typo elsewhere still refuses
- **GIVEN** the same object with `owner` "{{cast:esraa.uid}}" outside its verbatim fields
- **WHEN** the set is checked
- **THEN** the set SHALL be refused naming that placeholder

### Requirement: A refresh does not send fields the declaration leaves unset

On `--refresh` a field of the stored object that reads back as null and that the declaration does not name SHALL NOT be sent, so the refresh writes what the check validated.

#### Scenario: A string field the declaration does not name
- **GIVEN** a kept object whose schema refuses null for `intakeChannel`, which the declaration does not name and which reads back as null
- **WHEN** the set is refreshed
- **THEN** the object SHALL be refreshed and the install SHALL report ok

### Requirement: A declared group without a name gets the declared one

When a group the set declares already exists and its display name is empty or equal to its id, install SHALL set the declared display name and SHALL record the name it had. A group with a name of its own SHALL be left as it is. Remove SHALL put the recorded name back while the group still carries the set's name, and SHALL NOT delete a group the set did not create.

#### Scenario: An app-made group
- **GIVEN** an existing group `behandelaars` shown as "behandelaars", declared with display name "Behandelaars"
- **WHEN** the set is installed and later removed
- **THEN** the group SHALL read "Behandelaars" after install and "behandelaars" after remove, and SHALL still exist

### Requirement: A fragment may give an account an export it made

A fragment MAY declare `exportRuns`. Each row SHALL name an account the set creates or adopts, a plain file name, and its content as text or as `contentBase64` (at most 64 kB); it MAY name the format, row count, register and schema names, the moment it was made and its download count. Install SHALL write the file into the account's `Exports` folder and record an export run naming it (source `example-set`, actor the account), produced at the declared moment and expiring after the usual retention from then. The proof SHALL name a run or file that is gone. Remove SHALL delete the file and the run. Any other field, an account outside the set, a file name with a folder or a leading dot, or missing or oversized content SHALL refuse the set before anything is written.

#### Scenario: Pieter's export of last quarter's Woo requests
- **GIVEN** an export row for `pieter`, `woo-2026-q3.csv`, with two CSV lines and `producedAt` two days back
- **WHEN** the set is installed and later removed
- **THEN** `Exports/woo-2026-q3.csv` SHALL hold the two lines and a run SHALL name it, produced two days back; after remove neither SHALL exist

### Requirement: A set may be refreshed every day

An administrator MAY mark a set for a daily refresh with `--daily` on the install command, and SHALL be able to stop it with `--no-daily`. A background job SHALL, once a day, refresh every marked set that is installed, against that day, exactly as `--refresh` does. A marked set that is not installed SHALL be skipped and SHALL NOT be installed by the job. No set SHALL be marked by default.

#### Scenario: Today follows the view day
- **GIVEN** an installed set with an object dated `{{date:0}}`, marked for the daily refresh
- **WHEN** the job runs the next day
- **THEN** the object SHALL carry the next day's date

### Requirement: A fragment flow may carry finished runs

A `flows` entry MAY declare `runs`, each a finished run (`status` one of completed, stopped, dead_letter, failed) on a `subject` object uuid, with optional `subjectRegister`, `subjectSchema`, `trigger`, `triggeredBy`, `error`, `created` and `updated`. The set SHALL store each run for the flow in the flow's organisation, and SHALL delete the runs with the flow on remove. A run that is not finished, has no subject or carries another field SHALL be refused at check time.

#### Scenario: a case's run history
- **GIVEN** a flow entry with a completed and a failed run on a case
- **WHEN** the set is installed
- **THEN** the case's finished runs read both, and removing the set removes them

#### Scenario: a waiting run
- **WHEN** a run declares status `suspended`
- **THEN** the check refuses it and nothing is written

### Requirement: An app config entry may name its type

A fragment's app config entry MAY carry `type`: `string` (the default) or `int`. An `int` entry SHALL be written as an integer, SHALL be compared and restored as one, and its record entry SHALL keep the type. An `int` entry whose value is no integer or placeholder, or any other type, SHALL refuse the set.

#### Scenario: A sequence counter
- **GIVEN** an app's integer key `counter` holding 7, and an entry `{key: counter, value: "402", type: int}`
- **WHEN** the set is installed and later removed
- **THEN** the key SHALL hold the integer 402 after install and 7 after remove

### Requirement: A fragment may put Deck cards on its objects

A fragment MAY declare `deckCards`. Each row SHALL name `object`, `board`, `list`, `title` and `owner`, and MAY give `description`, `due`, `done`, `assignees`, `labels`, `shareWith` and `color`. The owner and assignees SHALL be accounts the set creates or adopts. On install the set SHALL:
- find the board (by owner and title), the list and the labels by title, or write them;
- write the card;
- link it to the object.

A second install SHALL keep the card. Remove SHALL delete the card, its link and the labels the set wrote, and the list and board the set wrote when nothing else is on them. Without Deck, the row SHALL be reported as not written.

#### Scenario: a card on a case
- **GIVEN** a row on case 710d44a4 for board "Woo en informatie", list "Deze week", assignee Lars de Koning, label "Bezig"
- **WHEN** the set is installed
- **THEN** the case's Kaarten card lists "Lakken offerte lantaarnpalen", and removing the set removes the card and the board it wrote

#### Scenario: an existing board
- **WHEN** the board and list already exist
- **THEN** the card goes on them, and remove leaves them

### Requirement: A fragment may put appointments on its objects

A fragment MAY declare `appointments`. Each row SHALL name `object`, `owner`, `summary` and `start`, and MAY give `calendar`, `end`, `location`, `description` and `attendees` (`{name?, email, status?}`). The owner SHALL be an account the set creates or adopts. On install the set SHALL write the event, with its invitees and their participation, to the owner's calendar, and link it to the object. A second install SHALL keep it, and remove SHALL delete the event and its link.

#### Scenario: an explanation at the desk
- **GIVEN** a row on case 710d44a4: "Toelichting stukken aan de verzoeker", 14 okt 10:00-10:30, Stadskantoor balie 3, Sanne de Vries invited
- **WHEN** the set is installed
- **THEN** the case's Afspraken card lists it, and Sanne de Vries is invited

#### Scenario: an appointment on a task
- **GIVEN** a row whose `object` is `{{task:hoorzitting-inplannen.uuid}}`, a task the fragment declares
- **WHEN** the set is installed
- **THEN** the event is linked to the task, with the register and schema of the record the task is on, and the task page's Afspraken card lists it

#### Scenario: a refresh on a later day
- **GIVEN** an appointment three days after the install day
- **WHEN** the set is refreshed five days later
- **THEN** the event is written again three days after the new day, and the old event and its link are gone

### Requirement: A fragment may put a case plan on its objects

A fragment MAY declare `casePlans`. Each row SHALL name `object` and a `definition` with at least one item, and MAY give `history`. On install the set SHALL bring the items in through the case layer's convergent import as the fragment's app, each item in the state it declares. A second install SHALL create nothing, and remove SHALL delete the items the set created.

#### Scenario: a Woo case's plan
- **GIVEN** a row on case 710d44a4 with a reached milestone, an active task, a discretionary task and a waiting one
- **WHEN** the set is installed
- **THEN** the case's Zaakplan card shows the four items in those states

#### Scenario: a refresh after the plan moved on
- **GIVEN** a seeded plan whose waiting item a cascade activated since
- **WHEN** the set is refreshed
- **THEN** the items the set created are taken away and written again in their declared states

### Requirement: A held change or preference follows its declaration

A change the record holds SHALL be proved and removed only on the fields its declaration still sets. A field its declaration gained SHALL be written on the next install and recorded, so remove can put it back. On `--refresh`, a held change SHALL write a declared field again in two cases: the field holds the value the change replaced, or it holds the value the change wrote while the declaration resolves to a new one. It SHALL leave any other value. On `--refresh`, a held user preference SHALL be written again when its declaration resolves to a value other than the one the set last wrote. The value it first replaced SHALL stay recorded.

#### Scenario: a field a job owns now
- **GIVEN** a held change that set `status`, and a declaration that no longer sets it
- **WHEN** a job changes `status` and the set is refreshed
- **THEN** the install reports nothing lost, and the job's value stays

#### Scenario: an import undid a change
- **GIVEN** a held change from name "Fase 3" to "Oplevering"
- **WHEN** an import puts "Fase 3" back and the set is refreshed
- **THEN** the name is "Oplevering" again, and the change reports refreshed

#### Scenario: a view written again
- **GIVEN** a preference that quotes a view of its fragment
- **WHEN** a refresh writes the view again under a new uuid
- **THEN** the preference quotes the new uuid

#### Scenario: the declaration gains a field
- **GIVEN** a held change that sets `decisionTypes` and `rules`
- **WHEN** its declaration also sets `rightsMatrix` and the set is installed again
- **THEN** `rightsMatrix` is written and recorded with the value it replaced

#### Scenario: the declaration of a written field changed
- **GIVEN** a change wrote a list of rules to an object, and the store gives it back with the keys of each rule in another order
- **WHEN** the declaration now sets other rules and an install runs with `--refresh`
- **THEN** the field is written with the declared rules, the record holds them as its new value, and the change is reported as refreshed

### Requirement: A fragment may write Collectives pages

A fragment MAY declare `collectivesPages`. Each row SHALL name `collective`, `title`, `content` and `owner`, and MAY give `shareWith`, a list of Nextcloud groups. The owner SHALL be an account the set creates or adopts. On install the set SHALL:
- find the collective by name, or create it with the groups in its team;
- find the page by title in it, or create it with the content.

A second install SHALL keep the page. Remove SHALL delete the page the set created, and the collective the set created, with its team. Without Collectives the row SHALL be reported as not written.

#### Scenario: a work instruction
- **GIVEN** a row for collective "Woo en informatie", page "Hoe we Woo-verzoeken behandelen", shared with the Woo team's group
- **WHEN** the set is installed
- **THEN** the page exists with its content, and the case type's `knowledgeBasePage` "Woo en informatie/Hoe we Woo-verzoeken behandelen" opens it

#### Scenario: an existing collective
- **WHEN** the collective already exists
- **THEN** the page goes in it, and remove leaves the collective and its other pages

### Requirement: A seeded view keeps its uuid

A view an example set writes SHALL have a uuid derived from the fragment's app and the view's key. A refresh SHALL update a view the record holds in place, keeping its uuid. A held view that no longer exists SHALL be written again under its derived uuid.

#### Scenario: a refresh
- **GIVEN** an installed view "Termijn deze week"
- **WHEN** the set is refreshed with a changed name
- **THEN** the view has the same uuid and the new name, and a menu entry that names it still opens it

#### Scenario: remove and install
- **WHEN** the set is removed and installed again
- **THEN** the view has the uuid it had before

### Requirement: A fragment may put Talk conversations on its objects

A fragment MAY declare `talkConversations`. Each row SHALL name `object`, `name` and `owner`, and MAY give `participants` and `call` (`start`, `end`). The owner and participants SHALL be accounts the set creates or adopts. On install the set SHALL:
- find the owner's group conversation by name, or create it with the participants;
- write a held call into a new conversation's history, as a `call_started` and a `call_ended` message with the participants and the duration;
- link the conversation to the object.

A second install SHALL keep the conversation and SHALL NOT write the call again. Remove SHALL delete the link, and the conversation when the set created it. Without Talk the row SHALL be reported as not written.

#### Scenario: a call with the requester
- **GIVEN** a row on case 710d44a4: "Gesprek met Sanne de Vries over de stukken", Pieter and Sanne, held 10:30 to 10:42
- **WHEN** the set is installed
- **THEN** the conversation exists with both participants, its history shows a 12-minute call, and the case lists the conversation

### Requirement: A fragment may give its objects a history by named people

A fragment MAY declare `auditEntries`. Each row SHALL name `object`, `action`, `user` and `at`, and MAY give `changed`. The user SHALL be an account the set creates or adopts. On install the set SHALL write one audit trail entry per row, by that user, at that moment, sealed into the hash chain, with the cause `import` and the run `example-set:<app>/<key>`. A second install SHALL find the entry by its run and write nothing. Remove SHALL leave the entries.

#### Scenario: a person looked at a document
- **GIVEN** a row on case e0b306d2: `file.downloaded` by Fatma Kaya on 6 okt, `changed.data.fileName` "medisch verslag"
- **WHEN** the set is installed
- **THEN** the case's history shows "Inzage medisch verslag", by Fatma Kaya, on 6 okt

### Requirement: An adopted account gains the profile fields it lacks

For an account the set adopted, the install SHALL write the cast person's function and role only where the account's headline or role is empty. It SHALL NOT overwrite a value the account holds, and SHALL NOT write absence or status.

#### Scenario: Pieter's function
- **GIVEN** the adopted account pieter, with role "Woo-coördinator" and no headline
- **WHEN** the set is installed with Pieter's function "Woo-coördinator"
- **THEN** his headline is "Woo-coördinator", and his role is unchanged

### Requirement: A refresh writes a party link again where it is

On `--refresh` the set SHALL write every party link the record holds again, in place, without removing it first. The link's cached name and e-mail address SHALL be read from the party again.

#### Scenario: an e-mail address the party gained
- **GIVEN** Marja Verbeek's link as chair, written before her person record had an e-mail address
- **WHEN** the set is refreshed
- **THEN** the link carries marja.verbeek@zuiddrecht.example

### Requirement: A fragment may give its objects timeline entries by named people

A fragment MAY declare `timelineEntries`. Each row SHALL name `object`, `kind`, `message`, `author` and `at`, and MAY give `fields` and `visibility`. The author SHALL be an account the set creates or adopts, and the kind SHALL be declared. On install the set SHALL write one entry per row, by that author, at that moment, with fields its kind accepts, and marked with the source `example-set:<app>/<key>`. A second install SHALL find it by that source and write nothing. Remove SHALL delete it.

#### Scenario: the case history
- **GIVEN** a row on case 710d44a4: kind status, "Zaak opgepakt", by Pieter Jansen, 4 okt 09:10
- **WHEN** the set is installed
- **THEN** the case's history lists it by Pieter on 4 okt, under its kind's chip

### Requirement: An install adopts the task or service account it declared when it already exists

An install SHALL point the record at an engine task that already holds a declared task's key instead of writing a second task with that key, and SHALL adopt a declared service account that already exists when the install creates it.

#### Scenario: the recorded task is gone but its key is held
- **GIVEN** an installed set whose recorded task uuid no longer exists, while an engine task holds the same task key
- **WHEN** the set is installed with `--refresh`
- **THEN** no second task is written, the record points at the task that holds the key, and the task is not reported missing

#### Scenario: the service account appeared before it was created
- **GIVEN** a declared service account the install does not see, which exists when it is created
- **WHEN** the set is installed
- **THEN** the account is reported adopted, nothing is dropped, and remove leaves the account

### Requirement: One install or removal of a set runs at a time

An install or a removal of an example set SHALL hold an exclusive lock on that set for its whole run, and SHALL release it when the run ends, whether or not the run succeeded. A run that finds the set held SHALL write nothing and SHALL say that another run holds the set.

#### Scenario: an install while another run holds the set
- **GIVEN** another run holds the set
- **WHEN** the set is installed
- **THEN** the install is refused with the reason that another install or removal is running, and no account, object or record entry is written

#### Scenario: an install lets go of the set
- **GIVEN** no other run holds the set
- **WHEN** the set is installed, and then installed again
- **THEN** the first run holds no lock after it ends, and the second run proceeds and writes nothing twice

#### Scenario: a removal while another run holds the set
- **GIVEN** an installed set that another run holds
- **WHEN** the set is removed
- **THEN** nothing is removed, the record stays as it was, and the removal names the other run as the reason

#### Scenario: other sets are not held up
- **GIVEN** another run holds a different set
- **WHEN** this set is installed
- **THEN** the install proceeds

### Requirement: A refresh updates a held flow to its declaration

A refresh SHALL update a flow the record holds where it is, keeping its uuid, to the fields the set declares. It SHALL publish the flow again only when a declared field changed, SHALL leave the flow switched on or off as it is, and SHALL keep its runs. A held flow that no longer exists SHALL be written again.

#### Scenario: a changed declaration
- **GIVEN** an installed example flow
- **WHEN** the set is refreshed with the flow's steps in new places
- **THEN** the flow has the same uuid, the new places and a new published version

#### Scenario: the held flow is already published
- **GIVEN** an installed example flow whose current version is published, by nobody in particular
- **WHEN** the set is refreshed with a changed declaration
- **THEN** the changed flow is published as the next version, and the refresh does not refuse it as "Only a draft version can be published"

#### Scenario: an unchanged declaration
- **WHEN** the set is refreshed and the flow's declaration did not change
- **THEN** no new version is published

### Requirement: A fragment may link mail to its objects

A fragment MAY declare `emailLinks`. Each row SHALL name `object`, `subject` and either `messageId` or both `mailAccountId` and `mailMessageId`. It MAY give `sender`, `mailDate` and `linkedBy`; `linkedBy` SHALL be an account the set creates or adopts. On install the set SHALL write one email link per row, on the object's register and schema, marked with the source `example-set:<app>/<key>`. A second install SHALL write nothing new. Remove SHALL delete the link.

#### Scenario: the subsidy mail on Buurtvereniging Oost
- **GIVEN** a row on Buurtvereniging Oost: "Subsidieformulier en de lijst met documenten gestuurd.", from Fatma Yildiz's address, 6 okt 16:05, linked by Fatma
- **WHEN** the set is installed
- **THEN** the organisation's linked emails list it, and pipelinq's activity shows it as "e-mail, gekoppeld"

### Requirement: A refresh writes only an object whose values differ

On `--refresh` a kept object SHALL be written only when the update the refresh would send, after the schema's write rules, differs from the stored row in at least one field. An object whose every sent field already holds the value it would be sent SHALL NOT be updated, so no audit-trail row and no background job is made for it. The comparison SHALL be made against the stored row, so a value changed on the instance since an earlier refresh SHALL be written back. The report SHALL count such an object as `unchanged` for its type; an object that is written SHALL be counted as `refreshed` as before.

#### Scenario: A second refresh on the same day
- **GIVEN** a set refreshed on 14 October
- **WHEN** the set is refreshed again on 14 October and nothing on the instance changed
- **THEN** no kept object SHALL be updated, and every kept object SHALL be counted as `unchanged`

#### Scenario: A declared date moves on a later day
- **GIVEN** a kept object with `reported` declared as `{{date:-1}}`, installed on 13 October
- **WHEN** the set is refreshed on 16 October
- **THEN** the object SHALL be written with `reported` 15 October and counted as `refreshed`

#### Scenario: A value changed on the instance
- **GIVEN** a kept object whose `title` an editor changed after the last refresh
- **WHEN** the set is refreshed on the same day
- **THEN** the object SHALL be written with its declared `title`

### Requirement: A fragment may give a task its requester and expiry

A fragment's task MAY declare `requester` and `expiresAt`. The check SHALL refuse a `requester` that is not an account the set creates or adopts, an `expiresAt` that is not a date or does not resolve, and an `expiresAt` before the task's `dueAt`. Install SHALL write both to the task.

#### Scenario: A hearing task asked for by a colleague
- **GIVEN** a fragment declares task "Hoorzitting inplannen" with `requester` `{{cast:anouk.uid}}` and `expiresAt` `{{datetime:9 17:00}}`
- **WHEN** the set is installed
- **THEN** the task SHALL name Anouk as its requester and expire nine days after the install day at 17:00

#### Scenario: A requester the set does not know
- **GIVEN** a fragment's task declares `requester` `{{cast:nobody.uid}}` or an account the set does not create or adopt
- **WHEN** the set is checked
- **THEN** the check SHALL refuse the task and write nothing

### Requirement: The record lets go of a held entry the set no longer declares

When a fragment is installed, the record SHALL let go of every change and every engine row (task, view, watcher, party, note, read state, flow and the other parts) of that fragment's app that the fragment no longer declares. The proof and remove SHALL leave what such an entry wrote alone, and the install report SHALL name each entry under "Left as it is". An entry of an app whose fragment is not in the plan SHALL stay in the record.

#### Scenario: A change moved into the object's declaration
- **GIVEN** a held change that set a ticket's `slaDeadline`, and a fragment that no longer declares that change
- **WHEN** the ticket's deadline now holds another value and the set is refreshed
- **THEN** the install reports nothing lost, names the change as left, and remove does not put the old deadline back

#### Scenario: A read state replaced by another one
- **GIVEN** a held read state that the fragment no longer declares, and that is gone from the instance
- **WHEN** the set is installed
- **THEN** the install does not report it missing, and names it as left

#### Scenario: An app outside the plan
- **GIVEN** a held change of an app whose fragment is not in the plan
- **WHEN** the set is installed
- **THEN** the record keeps the change and the report names nothing as left

### Requirement: A field that reads back null is not set

A held change SHALL count a field that reads back as null as not set, the same as a field the object did not have. On `--refresh`, a held change whose field was not set before it wrote it, and reads back as null, SHALL write the field again.

#### Scenario: A field the schema gained after the change wrote it
- **GIVEN** a held change that wrote `contactRef` where the contract had none
- **WHEN** `contactRef` reads back as null and the set is refreshed
- **THEN** `contactRef` is written again, and the change reports refreshed

### Requirement: An organisation the set made gains the quota it declares later

When the set finds an organisation by its slug, and the record holds that organisation as one the set created, the loader SHALL write each declared quota field (storage, requests, bandwidth, users, objects, objectsSchema) the organisation holds none of. It SHALL NOT change a quota field the organisation holds. An organisation the set did not create SHALL NOT be written. A quota write that fails SHALL be reported as a refusal for that organisation.

#### Scenario: A tenant made before its quota was declared
- **GIVEN** the record holds Waterland as created by the set, and Waterland has no object or user quota
- **WHEN** the set declares `quota { users: 50, objects: 5000, objectsSchema: case }` and is installed again
- **THEN** Waterland holds a user quota of 50, an object quota of 5000 and the object quota schema `case`

#### Scenario: A quota somebody set stays
- **GIVEN** the record holds Waterland as created by the set, and Waterland has a user quota of 80
- **WHEN** the set declares a user quota of 50
- **THEN** Waterland keeps a user quota of 80

#### Scenario: An organisation the set did not make
- **GIVEN** an organisation with the declared slug that the record does not hold as created
- **WHEN** the set is installed
- **THEN** its quota is not written

### Requirement: An example set's writes are caused as an import of that set

Every object write an example set install or refresh makes SHALL be recorded with the write cause `import` and the run `example-set:<set>`. An audit row the set declares itself SHALL keep the cause and run it declares. When the run ends, the cause SHALL be what it was before.

#### Scenario: The daily refresh moves a case type's relative dates
- GIVEN the Zuiddrecht set declares a case type with `validFrom` `{{date:-12}}`
- WHEN the daily refresh rewrites it the next day
- THEN the audit row of that update has cause `import` and run `example-set:zuiddrecht`
- AND the seeded row "Version 4 published" by Lars de Koning keeps its own run
