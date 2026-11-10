# Design: migration-pack-editor-ui

## D-1: the migration-pack store, not the Twig mapping and not register objects

OpenRegister has three places a column mapping could live:

| Store | Fits the packs | Cost |
| --- | --- | --- |
| `openregister_migration_packs` (`/api/migration-packs`) | Yes: the packs are written in this format, `MappingEngine::mapRow` runs them unchanged, `PackDefinitionValidator` checks every transform they use | A screen and four API fixes (this change) |
| Twig `Mapping` entity (`/api/mappings`) | No: lookup, required, date and join become templates | An engine rewrite in every app that imports with packs; errors lose their row and column |
| Register objects (as Integriq stores its mappings) | Partly: the generic object form cannot edit nested field mappings | A third store next to the one that exists, and a second validator in a save hook |

The screen therefore edits migration packs. Integriq's mapping editor is the
pattern for its layout (a general card, a rule table, a test panel, a dialog
per rule); no Integriq code is imported and Integriq is not changed.

## D-2: an ordered list of rows, and a round trip that loses nothing

A pack's `fieldMappings` is an ordered list, and the order matters:

- one column may feed several targets (stackiq's `Applicatiesoort` feeds
  `applicationType` and `cloudDienstverleningsmodel`);
- several columns may feed one target, and the engine writes the rows in
  order, so for a source row where both have a value the later row wins
  (`Roepnaam` over `Nickname`).

Integriq's column-mapping draft keys rows by source column and would merge the
first case away. The editor's draft (`src/views/migrationPack/packDraft.js`)
is built around the ordered list instead, with move up and move down, and a
hint under every target that more than one row writes.

The draft keeps the stored document next to the fields it edits. The payload
is rebuilt in the stored key order: keys the editor does not model (a
top-level `x-stackiq`, a transform option a later version adds, an
`idStrategy` extra) are copied through, and a key the stored document did not
have is only written when the editor gave it a value. Two consequences are
deliberate:

- A lookup's `default` is three-way, because the engine checks
  `array_key_exists('default')`: no key reports an error for an unmapped
  value, `null` leaves the field empty, any other value is stored. A text box
  cannot tell `null` from an empty string, so the dialog offers the three as
  a choice.
- A value that was not a string in the stored document (a lookup result
  `["SaaS"]`, a number, `true`) is edited as JSON and saved with its type.

PHP stores an empty object as `[]`; an unedited empty `defaults` or
`skipRows` is written back in the stored shape.

The round trip is tested on the five TOPdesk packs stackiq ships and on
OpenRegister's own template pack: loaded and saved without an edit, each
comes back equal and in the same key order.

stackiq ships five packs (module, manufacturer, municipality, usage and
business owner) but stores four of them here: its `SeedCmdbImportPacks`
repair step seeds the four sheet packs as built-in packs, and keeps the
municipality pack, which maps the import's own options rather than a sheet
column, in its shipped file only. A rig with stackiq therefore lists four
stackiq packs. The fixtures and the round-trip tests cover all five, because
an administrator can still import the municipality pack by hand.

## D-3: the definition travels in an envelope; the route id wins

Nextcloud's `Request::decodeContent()` merges the JSON body after the URL
parameters, so a body `id` replaces the route `{id}` and the dispatcher casts
the slug to an int. Create and update therefore accept
`{"definition": {...}}` and read the definition from it. When the body has no
`definition` object, the raw body is the definition as before, minus keys
starting with `_` and, on update, minus the route `id`. The editor always
sends the envelope.

## D-4: the slug of a built-in pack is locked on the server

An app finds the pack it seeded by slug. Locking the slug field in the editor
is not enough: the Advanced (JSON) view, a `PUT` from a script and an import
can all change `id`. The server refuses an update of a built-in pack whose
definition changes the slug with 422 `builtin-slug-locked`. The editor also
locks the field and refuses the change in the JSON view before it is sent.

## D-5: preview is a read on the real engine

`POST /api/migration-packs/preview` takes an unsaved definition and up to 20
sample rows, validates the definition with `PackDefinitionValidator` (422
`invalid-pack` with the errors in `details.errors`) and returns
`MappingEngine::mapRow` per row. Nothing is stored. A body without a
`definition` object or with `rows` that are not a list of objects answers 400
`invalid-request`, more than 20 rows 400 `too-many-rows`, a definition and
rows over 1 MiB of JSON together 400 `invalid-request` (the row count alone
does not bound the work, since a row can have any number of columns), and an unexpected
failure 500 `migration-pack-error`, logged with the exception. The editor's test panel
calls it 400 ms after the last change and drops an answer to an older
request, so what the panel shows is what an import of that row does. It is
admin-only because only an administrator edits.

## D-6: who sees what, and where the boundary is

The access boundary is the server: every write route checks
`IGroupManager::isAdmin()` and now also requires the CSRF token
(`NoCSRFRequired` removed from create, update, destroy and import, and never
added to preview). `@nextcloud/axios` sends the token, so the screens need
nothing extra. Reads stay open to any signed-in user, as before, because the
import flow lets a register manager pick a pack.

In the frontend, a manifest `permission: "admin"` does nothing in
OpenRegister: it passes no permissions list to `CnAppRoot` or `CnAppNav`, and
with an empty list every entry renders. The menu entry is therefore gated
with `visibleIf: {"user.isAdmin": true}`, which `CnAppNav` resolves against a
`runtime.user.isAdmin` that `MainMenu.vue` fills from `getCurrentUser()` (and
hides when the runtime block is absent). The pages themselves stay reachable
by URL for any signed-in user and render read-only for a non-admin: no
import, delete or save, the transform dialog without Apply, and no test panel.
The `permission` key is kept on the page and menu declarations as
documentation.

## D-7: a log line per change, not an audit trail

A pack is a row in its own table, not a register object, so it has no object
audit trail. Create, update, import and delete each write an info line
through the app logger naming the acting user id, the pack row id and the
pack slug; the import line also says whether it created a pack or replaced a
stored one. A refused or failed change writes no such line. That answers "who
changed the TOPdesk mapping" from the Nextcloud log without a new table, as
long as the log level is Info: at Nextcloud's default, Warning, the lines are
dropped.

## D-8: a save names the version it was based on

The store has no version column or ETag; `updatedAt` serves as the version.
The update envelope may carry `expectedUpdatedAt`, the `updatedAt` the caller
read, and the server refuses the update with 409 `pack-changed` when the
stored pack has another one. The editor always sends it and shows the refusal
with a reload. Because `updatedAt` has a resolution of one second, the editor
also reads the pack again just before the update and compares the definition,
which tells apart two saves within the same second.

What is left: the server reads, compares and writes in three steps, not in
one conditional write, so two updates that reach it at the same moment can
both pass the comparison; and a save that lands within the same second as
the version the editor loaded, after that last read, is not told apart. Both
need two administrators saving the same pack within a second. A script that
leaves out `expectedUpdatedAt` replaces the pack, as before this change.

## D-9: what other apps may rely on

stackiq reads its packs from this store. The following are therefore a
contract for apps, changed only with notice in this spec:

- `MigrationPackService::findByPackSlug(string $packSlug)`, throwing
  `DoesNotExistException` when absent;
- `MigrationPack::getDefinitionArray()`, the stored definition as an array;
- `PackDefinitionValidator::validate(array)` and `assertValid(array)`;
- `MappingEngine::mapRow(array $pack, array $sourceRow, int $rowNumber)`.

## D-10: two custom pages

A migration pack is a foundation entity served by its own controller, like
sources and registers, so the built-in `index` and `detail` page types (which
load register objects) cannot render it, and no named entity source exists
for it. Both pages are `type: "custom"` and carry a `_note` saying so. This
grows the custom-page count by two (hydra gate-69's ratchet), knowingly.

## D-11: version on save

When the administrator saves from the form without changing the version, the
patch version goes up (`1.0.0` becomes `1.0.1`) and the footer says so before
the save. A version the administrator typed is kept. The JSON view saves the
definition exactly as written.

## D-12: one error shape, codes the screens translate

Every refusal the controller gives follows ADR-050:
`{"message": <IL10N text>, "error": <kebab-case code>, "details"?: {...}}`.
The message is a fixed sentence, never `$e->getMessage()`, so no exception
text, path or SQL reaches a client. The codes are `invalid-pack` (422, the
validator's findings in `details.errors`, rows numbered from 1),
`builtin-slug-locked` (422), `pack-slug-exists` (422, a create or rename to
a slug another pack has), `pack-changed` (409, D-8), `pack-not-found` (404),
`invalid-request` and
`too-many-rows` (400), `not-authenticated` (401), `admin-required` (403) and
`migration-pack-error` (500, logged with the exception). The screens map the
code to their own translated text and list `details.errors`; they never parse
an English message. A taken slug is a 422 and not a 409, because the main
spec's scenario "Duplicate pack slug is rejected" fixes that status for API
clients; it has its own code, so a screen can say it in the user's language
instead of showing the validator's English sentence. A refusal Nextcloud gives before the controller runs (no
session, not an administrator, no request token) carries Nextcloud's body,
so a client reads the status there.

## Verification

- `tests/e2e/ci/migration-pack-pages.spec.ts` names every scenario of the
  delta spec with an `@e2e` anchor and drives it through the browser or the
  API of a running instance. CI runs it only on promotion pull requests and
  `workflow_dispatch`, so on a pull request to `development` it proves
  nothing until a run against a rig is linked (task 5.1).
- The log lines (D-7) have no browser test, because a browser cannot read the
  Nextcloud log. `MigrationPacksControllerTest` proves them: a line per
  create, update, import (with its outcome) and delete, and none on failure.
- The route-id fix (D-3) against Nextcloud's own `Request` is proven by
  `MigrationPacksControllerTest::testUpdateReadsTheRouteIdFromARealNextcloudRequest`.
  It skips when PHPUnit runs without an installed Nextcloud, as a local run
  from a worktree does. It runs in the in-container step of
  `api-test-coverage.yml`
  (`OPENREGISTER_TEST_NC_ROOT=/var/www/html`), which does not list skipped
  tests; `--filter RealNextcloudRequest --testdox` in that container shows it
  ran.

## Risks

- **An edit that the importing app does not expect.** OpenRegister cannot
  know an app's own rules (stackiq converts dates only for the columns its
  profile names, for example). The editor says that apps can add rules of
  their own; enforcing them is the app's job (stackiq validates the stored
  pack before an import and fails closed).
- **Deleting a built-in pack.** The app that seeded it seeds it again, unedited,
  on its next install or update. The delete dialog says so.
