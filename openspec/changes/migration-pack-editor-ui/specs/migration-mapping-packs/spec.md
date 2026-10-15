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
Advanced (JSON) view of the whole definition. In a yes/no table the editor
SHALL take only yes or no as a value and as the value for other values, and
SHALL keep a stored 1 or 0 as it is; it SHALL refuse text such as `false`,
which the import casts to true. The server's validator does not check these
values, so a pack written through the API is not held to this. A save SHALL show the server's
refusal readably, one line per validation finding. When the version was not
changed, a save SHALL raise the patch version and SHALL say so before saving.
A save SHALL be refused, with a way to reload, when the pack was saved by
someone else after the editor loaded it.

#### Scenario: An administrator changes a lookup value and saves

- **GIVEN** an administrator and a stored copy of the usage pack stackiq ships, at version `1.0.0`
- **WHEN** they open it, change the stored value for `In voorraad` in the `Applicatie Status` lookup to `Acquisition`, and save
- **THEN** the stored pack has `Acquisition` for `In voorraad`, version `1.0.1`, and every other part as it was
- **AND** the editor shows that the pack was saved as version `1.0.1`

#### Scenario: A row is moved and saved in the new order

- **GIVEN** an administrator and a stored copy of the module pack stackiq ships, whose rows 4 and 5 both write `shortDescription`, from `Nickname` and from `Roepnaam`
- **WHEN** they open it
- **THEN** both rows carry a hint that rows 4 and 5 write the same field and the lower row with a value wins
- **WHEN** they move row 5 up and save
- **THEN** the stored `fieldMappings` list `Roepnaam` before `Nickname`

#### Scenario: A validation refusal is shown per finding

- **GIVEN** an administrator editing a pack
- **WHEN** they save and the server answers 422 `invalid-pack` with the findings `"name" is required` and `"version" must be a semver string` in `details.errors`
- **THEN** the editor shows the refusal with each finding on its own line
- **AND** the editor keeps the unsaved changes

#### Scenario: A save over someone else's save is refused

- **GIVEN** an administrator who opened a pack, and another administrator who saved it afterwards
- **WHEN** the first administrator saves
- **THEN** nothing is sent to the update route
- **AND** the editor says the pack was changed by someone else and offers to reload it

#### Scenario: Deleting a built-in pack warns that it comes back

- **GIVEN** an administrator and the built-in pack `zgw-zaken-json` that OpenRegister installs
- **WHEN** they choose Delete for it on the Migration packs page
- **THEN** the confirmation names the pack and says that the app installs it again, unedited, on its next install or update
- **AND** choosing Cancel leaves the pack stored

#### Scenario: An administrator deletes a pack after confirming

- **GIVEN** an administrator and a stored pack that no app installed
- **WHEN** they choose Delete for it on the Migration packs page and confirm
- **THEN** the pack is no longer listed and no longer stored

#### Scenario: An administrator imports a pack from a JSON file

- **GIVEN** an administrator on the Migration packs page
- **WHEN** they choose Import JSON and pick a pack definition file whose `id` is not stored yet
- **THEN** the page says the pack was imported and lists it
- **AND** the stored pack has the definition from the file

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

- **GIVEN** a stored copy of each of the five TOPdesk packs stackiq ships (module, manufacturer, municipality, usage and business owner; stackiq stores the four sheet packs and keeps the municipality pack, which maps import options, in its own files)
- **WHEN** an administrator opens it in the editor
- **THEN** the editor shows no unsaved changes
- **WHEN** the administrator changes only the name and saves
- **THEN** the stored definition equals the one before, key for key and in the same order, apart from the name and the raised version

#### Scenario: An empty default stays null

- **GIVEN** a stored copy of the module pack, whose `Applicatiesoort` lookup to `cloudDienstverleningsmodel` has `"default": null`
- **WHEN** an administrator saves it from the editor
- **THEN** that transform still has a `default` key whose value is `null`, not an empty string and not absent

#### Scenario: A key the editor does not know is kept

- **GIVEN** a pack with a top-level `x-stackiq` object and a transform carrying an option the editor does not show
- **WHEN** an administrator opens the transform, changes the pack name and saves
- **THEN** the transform dialog names the option it does not show and says it is kept
- **AND** `x-stackiq` and the transform option are saved unchanged

#### Scenario: One column feeding two targets keeps both rows

- **GIVEN** a stored copy of the module pack, where `Applicatiesoort` maps to `applicationType` and, in a later row, to `cloudDienstverleningsmodel`
- **WHEN** an administrator saves it from the editor
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

- **GIVEN** a signed-in user who is not a Nextcloud administrator, and an administrator
- **WHEN** each of them opens OpenRegister
- **THEN** the administrator's navigation has a Migration packs entry
- **AND** the non-admin's navigation has none

#### Scenario: A non-admin who opens the list can only read

- **GIVEN** a signed-in non-admin
- **WHEN** they open `/apps/openregister/migration-packs`
- **THEN** the packs are listed with a note that only administrators can change packs
- **AND** there is no Import JSON and no Delete action

#### Scenario: A non-admin who opens the editor by URL can only read

- **GIVEN** a signed-in non-admin who opens the editor of a stored pack by its URL
- **WHEN** the page loads
- **THEN** the pack is shown with every field disabled and a note that only administrators can change packs
- **AND** there is no Save, Delete or Import action and no sample-row test

#### Scenario: A non-admin's write is refused by the server

- **GIVEN** a signed-in non-admin and a stored pack
- **WHEN** they call `PUT /api/migration-packs/{id}` for it directly
- **THEN** the server answers 403 and the pack is unchanged

### Requirement: Create and update MUST accept a definition envelope and keep the route id

`POST /api/migration-packs` and `PUT /api/migration-packs/{id}` SHALL accept a
body `{"definition": {...}}` and take the pack definition from it. On update
the route `{id}` SHALL always name the row to update; a body `id` is the pack
slug and SHALL never be taken as the row id. A body without a `definition`
object SHALL still be read as the definition itself, without keys starting
with `_` and, on update, without the route `id`. A definition the validator
refuses SHALL be answered with HTTP 422 `{"message": "The pack definition is
invalid", "error": "invalid-pack", "details": {"errors": [...]}}`, one entry
per finding with rows numbered from 1. An update of a row that does not exist
SHALL be answered with HTTP 404 and `error` `pack-not-found`. The update
envelope MAY carry `expectedUpdatedAt`, the `updatedAt` of the pack as the
caller read it; when the stored pack was saved at another time, the update
SHALL be answered with HTTP 409 and `error` `pack-changed`, and the pack SHALL
stay unchanged. An `expectedUpdatedAt` that is not a date-time in the form the
API answers `updatedAt` SHALL be answered with HTTP 400 `invalid-request`.
Without it, an update replaces the stored pack. The editor SHALL send it on
every save.

#### Scenario: An envelope update keeps the route id and the slug

- **GIVEN** an administrator and a stored pack with row id `{id}` and slug `e2e-pack`
- **WHEN** they send `PUT /api/migration-packs/{id}` with `{"definition": {"id": "e2e-pack", ...}}`
- **THEN** the answer is HTTP 200, not 404
- **AND** row `{id}` is updated and keeps slug `e2e-pack`

#### Scenario: A raw body still creates a pack

- **GIVEN** an administrator
- **WHEN** they send `POST /api/migration-packs` with the pack document itself as the body
- **THEN** the pack is created with HTTP 201, as before this change

#### Scenario: A definition the validator refuses is answered with its findings

- **GIVEN** an administrator
- **WHEN** they send `POST /api/migration-packs` with `{"definition": {...}}` whose definition has no `name`
- **THEN** the server answers 422 with `error` `invalid-pack` and a finding about `name` in `details.errors`
- **AND** no pack is stored

#### Scenario: An update of a pack that does not exist is answered 404

- **GIVEN** an administrator
- **WHEN** they send `PUT /api/migration-packs/{id}` for a row id that is not stored
- **THEN** the server answers 404 with `error` `pack-not-found`

#### Scenario: An update based on an older read is refused

- **GIVEN** an administrator and a stored pack
- **WHEN** they send `PUT /api/migration-packs/{id}` with `{"definition": {...}, "expectedUpdatedAt": ...}` naming an update time the pack no longer has
- **THEN** the server answers 409 with `error` `pack-changed`
- **AND** the pack is unchanged
- **AND** the same update naming the pack's current `updatedAt` is answered 200

### Requirement: The slug of a built-in pack MUST NOT change

The system SHALL refuse an update of a built-in pack whose definition has a
different `id` than the stored slug with HTTP 422 and the body
`{"message": "...", "error": "builtin-slug-locked"}`, and the pack SHALL stay
unchanged. The editor SHALL show the slug of a built-in pack read-only and
SHALL refuse a changed `id` in the Advanced (JSON) view before sending it.
The lock SHALL outlive a delete: when a built-in pack is deleted, a pack that
is later created, imported or renamed under its slug SHALL be built-in, so its
slug stays locked. Because that pack is then stored under the slug, the app
that installed the pack does not install the shipped one again.

#### Scenario: Renaming a built-in pack through the API is refused

- **GIVEN** an administrator and the built-in pack `zgw-zaken-json`
- **WHEN** they send an update whose definition has `"id": "my-zgw"`
- **THEN** the server answers 422 with `error` `builtin-slug-locked`
- **AND** the pack keeps the slug `zgw-zaken-json`

#### Scenario: Renaming a built-in pack in the JSON view is stopped in the editor

- **GIVEN** an administrator in the Advanced (JSON) view of the built-in pack `zgw-zaken-json`
- **WHEN** they change `id` and try to go back to the form or save
- **THEN** the editor says the slug of a built-in pack cannot change and names the stored slug
- **AND** nothing is sent

#### Scenario: A deleted built-in pack imported again keeps its lock

- **GIVEN** the built-in pack `zgw-zaken-json`, deleted by an administrator
- **WHEN** an administrator imports a JSON file whose `id` is `zgw-zaken-json`
- **THEN** the stored pack is built-in
- **AND** an update whose definition has `"id": "my-zgw"` is refused with `error` `builtin-slug-locked`

### Requirement: A pack MUST be testable against sample rows without saving it

`POST /api/migration-packs/preview` SHALL, for administrators, take
`{"definition": {...}, "rows": [{"<column>": "<value>"}, ...]}` with at most 20
rows, map every row with the mapping engine an import uses, and answer HTTP 200
`{"rows": [{"rowNumber": 1, "data": {...}, "errors": [...]}]}`. It SHALL store
nothing. A body without a `definition` object, or whose `rows` are not a list
of objects, SHALL be answered with HTTP 400 and `error` `invalid-request`;
more than 20 rows with HTTP 400 and `error` `too-many-rows`; a definition and
rows that together take more than 1 MiB (1,048,576 bytes) as JSON with HTTP
400 and `error` `invalid-request`, because one row can carry any number of
columns. An invalid
definition SHALL be answered with HTTP 422
`{"message": "...", "error": "invalid-pack", "details": {"errors": [...]}}`.
An unexpected failure SHALL be answered with HTTP 500 and `error`
`migration-pack-error`, and logged with the exception.
The editor SHALL offer a sample-row test that asks a value for every source
column the pack reads, runs on the definition as edited (saved or not), and
shows the fields the import would write and the row's errors.

#### Scenario: A sample row shows what the import would write

- **GIVEN** an administrator editing a stored copy of the module pack, who changed the target of row 1 to `title` without saving
- **WHEN** they enter ` Zaaksysteem ` for `Applicatie Naam`, `A-1` for `APPID` and `Cloud` for `Applicatiesoort`
- **THEN** the test shows `title` as `"Zaaksysteem"` and `cloudDienstverleningsmodel` as `null`

#### Scenario: A sample row with a missing required column shows the error

- **GIVEN** an administrator editing a stored copy of the module pack, whose `APPID` mapping is required
- **WHEN** the sample row has a value for `Applicatie Naam` and leaves `APPID` empty
- **THEN** the test lists a problem for source `APPID`, target `externalNumber`

#### Scenario: An invalid definition is refused with its findings

- **GIVEN** an administrator
- **WHEN** they send a preview whose definition has no `name`
- **THEN** the server answers 422 with `error` `invalid-pack` and the finding in `details.errors`

#### Scenario: Too many sample rows are refused

- **GIVEN** an administrator and a valid definition
- **WHEN** they send a preview with 21 rows
- **THEN** the server answers 400 with `error` `too-many-rows` and maps nothing

#### Scenario: A malformed preview request is refused

- **GIVEN** an administrator
- **WHEN** they send a preview without a `definition` object, or with `rows` that are not a list of objects
- **THEN** the server answers 400 with `error` `invalid-request` and maps nothing

#### Scenario: An oversized preview request is refused

- **GIVEN** an administrator and a valid definition
- **WHEN** they send a preview with 20 rows that take more than 1 MiB as JSON together
- **THEN** the server answers 400 with `error` `invalid-request` and maps nothing

#### Scenario: A non-admin cannot run a preview

- **GIVEN** a signed-in non-admin and a valid definition
- **WHEN** they send a preview
- **THEN** the server answers 403 and maps nothing

### Requirement: Migration pack writes MUST require a CSRF token

The system SHALL CSRF-protect the create, update, delete, import and preview
routes of `/api/migration-packs`: a request from a browser
session without a valid request token SHALL be refused by Nextcloud before the
controller runs. The read routes (list, show, export) SHALL stay as they are.
The editor SHALL send every write through a client that adds the request
token.

#### Scenario: A cross-site write is refused

- **GIVEN** an administrator signed in to Nextcloud in a browser, and a stored pack
- **WHEN** the browser sends `DELETE /apps/openregister/api/migration-packs/{id}` with the session cookie but without the request token
- **THEN** Nextcloud refuses the request and the pack is not deleted

#### Scenario: The editor's own writes pass

- **GIVEN** an administrator signed in to Nextcloud in a browser, in the editor
- **WHEN** they save a pack
- **THEN** the request carries the request token and is accepted

## MODIFIED Requirements

### Requirement: Migration packs MUST be manageable via admin-gated REST CRUD with JSON file import/export
The system SHALL expose `/api/migration-packs` REST routes: `index`/`show`/`export` available to any authenticated user (packs are shared instance assets the import flow must browse), `create`/`update`/`destroy`/`import`/`preview` restricted to Nextcloud administrators (HTTP 403 otherwise; HTTP 401 for anonymous callers on all routes). The write routes (`create`, `update`, `destroy`, `import`, `preview`) SHALL require Nextcloud's CSRF request token; a client outside a browser session sends `OCS-APIRequest: true` or authenticates with an app password. `create` and `update` SHALL take the definition from a `{"definition": {...}}` envelope or, without one, from the raw body; on `update` the route `{id}` SHALL name the row. `export` SHALL download the pack's definition as a standalone JSON file named `<packSlug>.json`; `import` SHALL accept an uploaded pack JSON file and upsert by the document's own `id` (create when absent, update the existing row when present), so packs round-trip between instances. A pack that `create`, `import` or a rename stores under the slug of a deleted built-in pack SHALL be built-in, and the slug of a built-in pack SHALL NOT change through `update` or `import`. Every error answer the controller gives SHALL be `{"message": <localised text>, "error": <kebab-case code>, "details"?: {...}}`, where the message never carries an exception's text: 422 `invalid-pack` with the validator's findings in `details.errors`, 422 `builtin-slug-locked`, 422 `pack-slug-exists` for a create or rename to a slug another pack has, 409 `pack-changed` for an update whose `expectedUpdatedAt` the pack no longer has, 404 `pack-not-found`, 400 `invalid-request` or `too-many-rows`, 401 `not-authenticated`, 403 `admin-required`, and 500 `migration-pack-error` for an unexpected failure, which is logged with the exception. A refusal Nextcloud gives before the controller runs (not signed in, not an administrator, no request token) carries Nextcloud's own body.

#### Scenario: Non-admin cannot mutate packs
- **GIVEN** an authenticated non-admin user
- **WHEN** they call `POST /api/migration-packs`, `PUT /api/migration-packs/{id}`, `DELETE /api/migration-packs/{id}`, `POST /api/migration-packs/import` or `POST /api/migration-packs/preview`
- **THEN** each call returns HTTP 403 and no pack is created, changed or deleted

#### Scenario: Any authenticated user can browse and export packs
- **GIVEN** an authenticated non-admin user
- **WHEN** they call `GET /api/migration-packs` and `GET /api/migration-packs/{id}/export`
- **THEN** the list returns HTTP 200 and the export downloads the pack definition as JSON

#### Scenario: Pack JSON export/import round-trips between instances
- **GIVEN** a pack exported as `<packSlug>.json`
- **WHEN** an admin uploads that file, with a changed description, to `POST /api/migration-packs/import`
- **THEN** the existing row with that slug is updated instead of erroring or creating a second pack

#### Scenario: Anonymous callers are rejected everywhere
- **GIVEN** a caller without a session, app password or basic auth
- **WHEN** they call any `/api/migration-packs` route
- **THEN** the system returns HTTP 401
