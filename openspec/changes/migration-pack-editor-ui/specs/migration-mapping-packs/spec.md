# migration-mapping-packs

## ADDED Requirements

### Requirement: Administrators MUST be able to list and edit migration packs in the OpenRegister UI

The system SHALL offer a Migration packs page at `/apps/openregister/migration-packs`
listing every pack with its name, slug, source format, version, whether it is
built-in, and when it was last updated, with an action to open, export and
(for an administrator) delete each pack, and an action to import a pack from a
JSON file. A delete SHALL be confirmed first; for a built-in pack the
confirmation SHALL say that the app that installed it installs it again on its
next install or update.

The system SHALL offer an editor at `/apps/openregister/migration-packs/{id}`
with: the general settings (name, description, version, source format, id
strategy, and the slug, read-only for a built-in pack); the field mappings as
an ordered table of source column, target field, required and a summary of the
transform, where rows can be added, removed and moved up or down; a dialog per
row for every transform type the validator accepts (trim; date with source and
stored format; lookup table and yes/no table with their values and a choice of
what happens to a value that is not in the table; join with its columns and
separator; fixed value); the pack defaults and the rows to skip; and an
Advanced (JSON) view of the whole definition. A save SHALL show the server's
refusal readably, one line per validation finding. When the version was not
changed, a save SHALL raise the patch version and SHALL say so before saving.
A save SHALL be refused, with a way to reload, when the pack was saved by
someone else after the editor loaded it.

#### Scenario: An administrator changes a lookup value and saves

- **GIVEN** an administrator and the stored pack `stackiq-topdesk-usage` at version `2.0.0`
- **WHEN** they open it, change the stored value for `In voorraad` in the `Applicatie Status` lookup to `Acquisition`, and save
- **THEN** the editor sends the whole definition with that value and version `2.0.1`
- **AND** the editor shows that the pack was saved as version `2.0.1`

#### Scenario: A row is moved and saved in the new order

- **GIVEN** a pack whose rows 4 and 5 both write `shortDescription`, from `Nickname` and from `Roepnaam`
- **WHEN** the administrator moves row 5 up and saves
- **THEN** the stored `fieldMappings` list `Roepnaam` before `Nickname`
- **AND** both rows carry a hint that rows 4 and 5 write the same field and the lower row with a value wins

#### Scenario: A validation refusal is shown per finding

- **GIVEN** an administrator editing a pack
- **WHEN** they save and the server answers 422 with the findings `"name" is required` and `"version" must be a semver string`
- **THEN** the editor shows the refusal with each finding on its own line
- **AND** the editor keeps the unsaved changes

#### Scenario: A save over someone else's save is refused

- **GIVEN** an administrator who opened a pack, and another administrator who saved it afterwards
- **WHEN** the first administrator saves
- **THEN** nothing is sent to the update route
- **AND** the editor says the pack was changed by someone else and offers to reload it

#### Scenario: Deleting a built-in pack warns that it comes back

- **GIVEN** a built-in pack an app installed
- **WHEN** an administrator chooses Delete
- **THEN** the confirmation names the pack and says that the app installs it again, unedited, on its next install or update

### Requirement: The pack editor MUST save a definition without losing what it does not show

The definition the editor builds from an unedited pack SHALL equal the
stored one, so an unedited pack shows no unsaved changes and an edit changes
only what was edited. The editor SHALL keep every top-level, field-mapping,
transform and id-strategy key it does not model, unchanged and in place. It
SHALL keep the field mappings as an ordered list: two rows with the same
source and two rows with the same target SHALL both be saved, in their order.
A lookup or yes/no table SHALL keep the three ways a value that is not in the
table is treated, as distinct stored forms: no `default` key (the row reports
an error), `default: null` (the field stays empty; a yes/no table stores
false, and the editor says so), and a `default` value.
A date format cleared in the editor SHALL be left out of the transform, so
the mapping engine's default applies, rather than stored as an empty string.
A value that is not a string in the stored definition SHALL be saved with its
JSON type.

#### Scenario: The shipped TOPdesk packs survive an unedited save

- **GIVEN** each of the five TOPdesk packs stackiq ships, stored unchanged
- **WHEN** an administrator opens it in the editor
- **THEN** the editor shows no unsaved changes
- **AND** the definition it builds equals the stored one, key for key and in the same order

#### Scenario: An empty default stays null

- **GIVEN** the module pack, whose `Applicatiesoort` lookup to `cloudDienstverleningsmodel` has `"default": null`
- **WHEN** the pack is saved
- **THEN** that transform still has a `default` key whose value is `null`, not an empty string and not absent

#### Scenario: A key the editor does not know is kept

- **GIVEN** a pack with a top-level `x-stackiq` object and a transform carrying an option the editor does not show
- **WHEN** an administrator changes the pack name and saves
- **THEN** `x-stackiq` and the transform option are saved unchanged
- **AND** the transform dialog names the option it does not show and says it is kept

#### Scenario: One column feeding two targets keeps both rows

- **GIVEN** a pack where `Applicatiesoort` maps to `applicationType` and, in a later row, to `cloudDienstverleningsmodel`
- **WHEN** the pack is saved
- **THEN** both rows are saved, in that order

### Requirement: Pack changes MUST be limited to administrators and logged

Creating, updating, deleting, importing and previewing a pack SHALL require a
Nextcloud administrator; the server SHALL answer 403 to any other signed-in
user, whatever the screen offered. The Migration packs menu entry SHALL only
be shown to an administrator. For any other signed-in user the pages SHALL be
read-only: no import, delete or save, and no sample-row test. Every create,
update, import and delete SHALL write an info line to the Nextcloud log with
the acting user id, the pack slug and the pack row id; the line of an import
SHALL also say whether it created a pack or replaced a stored one. A failed
change SHALL write no such line. The lines are info lines, so Nextcloud keeps
them only when its log level is Info or lower; at the default level, Warning,
they are dropped.

#### Scenario: A non-admin does not see the menu entry

- **GIVEN** a signed-in user who is not a Nextcloud administrator
- **WHEN** they open OpenRegister
- **THEN** the navigation has no Migration packs entry

#### Scenario: A non-admin who opens the editor by URL can only read

- **GIVEN** a signed-in non-admin who opens `/apps/openregister/migration-packs/12`
- **WHEN** the page loads
- **THEN** the pack is shown with every field disabled and a note that only administrators can change packs
- **AND** there is no Save, Delete or Import action and no sample-row test

#### Scenario: A non-admin's write is refused by the server

- **GIVEN** a signed-in non-admin
- **WHEN** they call `PUT /api/migration-packs/12` directly
- **THEN** the server answers 403 and the pack is unchanged

#### Scenario: An update is logged with who made it

- **GIVEN** administrator `ronald`
- **WHEN** he updates the pack `stackiq-topdesk-module`
- **THEN** the Nextcloud log has an info line naming `ronald` and `stackiq-topdesk-module`

#### Scenario: An import that replaces a stored pack is logged

- **GIVEN** administrator `ronald` and the stored built-in pack `stackiq-topdesk-module`
- **WHEN** he imports a JSON file whose `id` is `stackiq-topdesk-module`
- **THEN** the Nextcloud log has an info line naming `ronald`, `stackiq-topdesk-module` and that the import replaced the stored pack

### Requirement: Create and update MUST accept a definition envelope and keep the route id

`POST /api/migration-packs` and `PUT /api/migration-packs/{id}` SHALL accept a
body `{"definition": {...}}` and take the pack definition from it. On update
the route `{id}` SHALL always name the row to update; a body `id` is the pack
slug and SHALL never be taken as the row id. A body without a `definition`
object SHALL still be read as the definition itself, without keys starting
with `_` and, on update, without the route `id`.

#### Scenario: An envelope update keeps the route id and the slug

- **GIVEN** pack row 5 with slug `stackiq-topdesk-module`
- **WHEN** an administrator sends `PUT /api/migration-packs/5` with `{"definition": {"id": "stackiq-topdesk-module", ...}}`
- **THEN** row 5 is updated and keeps slug `stackiq-topdesk-module`
- **AND** the answer is HTTP 200, not 404

#### Scenario: A raw body still creates a pack

- **GIVEN** an administrator
- **WHEN** they send `POST /api/migration-packs` with the pack document itself as the body
- **THEN** the pack is created with HTTP 201, as before this change

### Requirement: The slug of a built-in pack MUST NOT change

An update of a built-in pack whose definition has a different `id` than the
stored slug SHALL be refused with HTTP 422 and the body
`{"message": "...", "error": "builtin-slug-locked"}`, and the pack SHALL stay
unchanged. The editor SHALL show the slug of a built-in pack read-only and
SHALL refuse a changed `id` in the Advanced (JSON) view before sending it.
The lock SHALL outlive a delete: when a built-in pack is deleted, a pack that
is later created, imported or renamed under its slug SHALL be built-in, so its
slug stays locked. Because that pack is then stored under the slug, the app
that installed the pack does not install the shipped one again.

#### Scenario: Renaming a built-in pack through the API is refused

- **GIVEN** the built-in pack `stackiq-topdesk-module`
- **WHEN** an administrator sends an update whose definition has `"id": "my-module"`
- **THEN** the server answers 422 with `error` `builtin-slug-locked`
- **AND** the pack keeps the slug `stackiq-topdesk-module`

#### Scenario: Renaming a built-in pack in the JSON view is stopped in the editor

- **GIVEN** an administrator in the Advanced (JSON) view of a built-in pack
- **WHEN** they change `id` and try to go back to the form or save
- **THEN** the editor says the slug of a built-in pack cannot change and names the stored slug
- **AND** nothing is sent

#### Scenario: A deleted built-in pack imported again keeps its lock

- **GIVEN** the built-in pack `stackiq-topdesk-module`, deleted by an administrator
- **WHEN** an administrator imports a JSON file whose `id` is `stackiq-topdesk-module`
- **THEN** the stored pack is built-in
- **AND** an update whose definition has `"id": "my-module"` is refused with `error` `builtin-slug-locked`

### Requirement: A pack MUST be testable against sample rows without saving it

`POST /api/migration-packs/preview`, for administrators, SHALL take
`{"definition": {...}, "rows": [{"<column>": "<value>"}, ...]}` with at most 20
rows, map every row with the mapping engine an import uses, and answer HTTP 200
`{"rows": [{"rowNumber": 1, "data": {...}, "errors": [...]}]}`. It SHALL store
nothing. An invalid definition SHALL be answered with HTTP 422
`{"message": "...", "error": "invalid-pack", "details": {"errors": [...]}}`.
The editor SHALL offer a sample-row test that asks a value for every source
column the pack reads, runs on the definition as edited (saved or not), and
shows the fields the import would write and the row's errors.

#### Scenario: A sample row shows what the import would write

- **GIVEN** an administrator editing the module pack, unsaved
- **WHEN** they enter ` Zaaksysteem ` for `Applicatie Naam` and `Cloud` for `Applicatiesoort`
- **THEN** the test shows `name` as `"Zaaksysteem"` and `cloudDienstverleningsmodel` as `null`

#### Scenario: A sample row with a missing required column shows the error

- **GIVEN** the module pack, whose `APPID` mapping is required
- **WHEN** the sample row leaves `APPID` empty
- **THEN** the test lists an error for source `APPID`, target `externalNumber`

#### Scenario: An invalid definition is refused with its findings

- **WHEN** an administrator sends a preview whose definition has no `name`
- **THEN** the server answers 422 with `error` `invalid-pack` and the finding in `details.errors`

#### Scenario: Too many sample rows are refused

- **WHEN** an administrator sends a preview with 21 rows
- **THEN** the server answers with a client error and maps nothing

### Requirement: Migration pack writes MUST require a CSRF token

The create, update, delete, import and preview routes of
`/api/migration-packs` SHALL be CSRF-protected: a request from a browser
session without a valid request token SHALL be refused by Nextcloud before the
controller runs. The read routes (list, show, export) SHALL stay as they are.
The editor SHALL send every write through a client that adds the request
token.

#### Scenario: A cross-site write is refused

- **GIVEN** an administrator signed in to Nextcloud in a browser
- **WHEN** another site makes the browser send `DELETE /apps/openregister/api/migration-packs/12` without the request token
- **THEN** Nextcloud refuses the request and the pack is not deleted

#### Scenario: The editor's own writes pass

- **GIVEN** an administrator in the editor
- **WHEN** they save, delete or import a pack
- **THEN** the request carries the request token and is accepted
