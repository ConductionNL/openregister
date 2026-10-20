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

A fragment MAY declare `userPreferences`, each entry a `user` (`{{cast:<key>.uid}}` or `{{account:<key>.uid}}`), a `key` and a scalar `value` (placeholders allowed), written with `IConfig::setUserValue`. An entry SHALL only set preferences of the app that ships the fragment; naming another app SHALL refuse the set. The account SHALL be one the set creates or adopts, SHALL NOT be in Nextcloud's administrators group, and a key whose name holds a secret (password, secret, token, credential, private, api key, session) or that Nextcloud keeps as sensitive SHALL be refused; each refusal is made by the check, before anything is written. Install SHALL write only a value that differs and SHALL record the old value. Remove SHALL put the old value back, or delete the key when there was none, but SHALL leave a value that changed after the install, naming it, and SHALL do so before it deletes the accounts it created.

#### Scenario: The demo staff skip the tour
- **GIVEN** dossiq's fragment gives `{{cast:esra.uid}}` the preference `pref_walkthrough_completed_version` = `1.2.0`
- **WHEN** the set is installed twice and then removed
- **THEN** Esra SHALL hold `1.2.0` after the first install, the second install SHALL write nothing, and after remove the key SHALL be gone

#### Scenario: An adopted account gets its own value back
- **GIVEN** an adopted account that held `0.9.0`
- **WHEN** the set writes `1.2.0` and is then removed
- **THEN** the account SHALL hold `0.9.0` again, unless somebody changed the value after the install, which SHALL stay and be named

#### Scenario: An administrator, a foreign account and a secret are refused
- **GIVEN** a preference for an account the set does not declare, one for an adopted administrator, and one for a key named `api_token`
- **WHEN** the set is checked
- **THEN** each SHALL be refused with its reason, and nothing SHALL be written
