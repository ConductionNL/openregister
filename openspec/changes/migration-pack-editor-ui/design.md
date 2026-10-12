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
`MappingEngine::mapRow` per row. Nothing is stored. The editor's test panel
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
audit trail. Update and delete write an info line through the app logger
naming the acting user id, the pack id and the pack slug. That answers "who
changed the TOPdesk mapping" from the Nextcloud log without a new table.

## D-8: last write wins, with a check before saving

The store has no version column or ETag. Before saving, the editor reads the
pack again and refuses when `updatedAt` moved since it was opened, offering a
reload. This narrows the window to the two requests of one save; it is not a
lock, and a script writing through the API is not stopped by it.

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
patch version goes up (`2.0.0` becomes `2.0.1`) and the footer says so before
the save. A version the administrator typed is kept. The JSON view saves the
definition exactly as written.

## Risks

- **An edit that the importing app does not expect.** OpenRegister cannot
  know an app's own rules (stackiq converts dates only for the columns its
  profile names, for example). The editor says that apps can add rules of
  their own; enforcing them is the app's job (stackiq validates the stored
  pack before an import and fails closed).
- **Deleting a built-in pack.** The app that seeded it seeds it again, unedited,
  on its next install or update. The delete dialog says so.
