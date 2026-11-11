---
kind: code
depends_on: []
---

# Proposal: migration-pack-editor-ui

## Summary

Give migration packs a screen. An administrator lists the packs, opens one,
and changes its column mappings: the source column, the target field, whether
the row is required, and the transform (trim, date, lookup table, yes/no
table, join, fixed value). The editor tests the pack against a sample row
before it is saved, and saving gives back exactly what was stored for every
part the editor does not show.

The API gains what such a screen needs and lacked: an envelope body for
create and update, so a pack's own `id` slug can no longer replace the route's
row id; a server-side lock on the slug of a built-in pack; a preview endpoint
that maps sample rows without storing anything; CSRF on every write; one error
shape for every refusal; and a log line naming who created, changed, imported
or deleted a pack.

## Why

Packs are stored in OpenRegister (`openregister_migration_packs`, served by
`/api/migration-packs`) and run by its mapping engine, but there is no way to
see or change one except by exporting the JSON, editing it by hand and
importing it again. Apps now ship packs that a municipality is expected to
adjust. stackiq's TOPdesk CMDB import is the first (WOO-588): Gemeente
Rotterdam's administrator must be able to view and edit the column mappings
the import uses, without a developer and without hand-written JSON.

The decision of 2026-10-12 puts all mapping in OpenRegister. Of the three
places a mapping could live there, the migration-pack store is the one the
packs are already written for: the import calls `MappingEngine::mapRow` with
them unchanged, and `PackDefinitionValidator` already checks every transform
they use. A Twig mapping would need an engine rewrite and loses the row and
column in every error; packs stored as register objects would duplicate a
store OpenRegister already has. See design.md D-1.

Building the screen surfaced four defects the API had without one:

- A `PUT /api/migration-packs/{id}` whose body carries the pack's own `id`
  answers 404. Nextcloud merges the JSON body over the route parameters, so
  the slug replaces the row id and the dispatcher casts it to 0.
- The slug of a built-in pack can be changed through update and import. An
  app that finds its pack by slug then silently falls back to its shipped
  file, and its next update seeds a second copy.
- The write routes skip the CSRF check (`NoCSRFRequired`).
- Nothing records who changed a pack, while a pack decides what an import
  writes.

## What changes

- A **Migration packs** page and a pack **editor** page in OpenRegister, with
  a menu entry next to Connections that only administrators see.
- The editor keeps the field mappings as an ordered list, keeps keys it does
  not model, and treats a lookup's "other values" in the three ways the
  engine does: an error, an empty field, or a stored value.
- `POST` and `PUT /api/migration-packs[/{id}]` accept `{"definition": {...}}`;
  on update the route id always wins. The raw body keeps working.
- An update that changes the slug of a built-in pack answers 422
  `builtin-slug-locked`.
- `POST /api/migration-packs/preview` (admin): an unsaved definition and up to
  20 sample rows in, the mapped data and errors per row out.
- Create, update, delete, import and preview require an administrator and a
  CSRF token. Create, update, import and delete each write an info log line
  with the acting user, the pack slug and the pack row id; the line of an
  import also says whether it created a pack or replaced a stored one.
- Every refusal the controller gives has one shape (ADR-050):
  `{"message": <localised text>, "error": <kebab-case code>, "details"?: {...}}`,
  with codes `invalid-pack` (422, findings in `details.errors`),
  `builtin-slug-locked` (422), `pack-slug-exists` (422), `pack-changed`
  (409), `pack-not-found` (404), `invalid-request` and
  `too-many-rows` (400), `not-authenticated` (401), `admin-required` (403) and
  `migration-pack-error` (500, logged). The message never carries an
  exception's text; the screens map the code to their own translated text.
- English and Dutch for every new string; the screens' strings in every
  locale the frontend catalogue requires.

## Impact

- **stackiq** reads its TOPdesk packs from this store first and falls back to
  its shipped files (its own change, `cmdb-import-mapping-view`). It depends
  on `MigrationPackService::findByPackSlug()`, `MigrationPack::getDefinitionArray()`,
  `PackDefinitionValidator` and `MappingEngine::mapRow()`; design.md D-9
  records that as a contract.
- **Integriq** is not touched. Its mapping editor is the pattern for this
  screen's layout; no code is shared.
- **API clients** that create or update packs keep working with the raw body.
  A client that posted to the write routes without a CSRF token, outside a
  browser session, must send `OCS-APIRequest: true` or use app passwords with
  basic auth, as for any other CSRF-protected Nextcloud route.
- **opencatalogi, softwarecatalog**: no change; they do not use packs.

## Accessibility (WCAG 2.2 AA)

The two pages, the transform dialog, the delete dialog and the sample-row
panel are new UI. They touch these success criteria; each names the Hydra
gate that catches a regression, or says that none does.

| SC | Where | Caught by |
| --- | --- | --- |
| 1.3.1 Info and Relationships | List and mapping tables with `<th scope="col">`; every section titled by its heading (`aria-labelledby`) | gate-43 (table-headers), gate-33 (axe-core) |
| 1.3.1 / 3.3.2 Labels or Instructions | Every input has a visible label or an `aria-label` naming its row (`Source column of row 3`); the sample-row inputs name their column | gate-40 (form-label-association) |
| 1.4.3 / 1.4.11 Contrast | Nextcloud theme variables only; the Built-in badge uses `--color-text-maxcontrast` | gate-33 (axe-core) |
| 2.1.1 Keyboard | Row move, remove, transform and delete are `NcButton`s; no click handler on a `div` or `span` | gate-32 (semantic-controls) |
| 2.4.3 Focus Order | No positive `tabindex`; the hidden file input has `tabindex="-1"` and the visible Import JSON button opens it | gate-36 (tabindex-positive), gate-37 (aria-hidden-focusable) |
| 3.3.1 Error Identification | A refused save lists each finding on its own line; the JSON view names the parse error and the locked slug | Playwright `migration-pack-pages.spec.ts`; no static gate |
| 3.3.4 Error Prevention | Delete asks for confirmation in an `NcDialog`, never `window.confirm`; a save that would overwrite someone else's is refused | gate-34 (window-confirm), Playwright |
| 4.1.2 Name, Role, Value | Icon-only buttons carry an `aria-label`; the Form / Advanced (JSON) switch sets `aria-pressed` | gate-39 (button-name), gate-37, gate-33 |
| 4.1.3 Status Messages | Save results and preview results sit in an `aria-live="polite"` region | gate-33 (axe-core); the announcement itself is not machine-checked |

gate-33 only runs when a repository sets `enable-axe` on the shared quality
workflow. openregister has not, so for the rows that name it the check today
is review and the Playwright run, not the gate.

The six criteria new in 2.2:

- **2.4.11 Focus Not Obscured (Minimum).** The pages have no sticky header or
  footer of their own; the dialogs are `NcDialog`, which keeps focus inside
  the dialog. No Hydra gate measures it.
- **2.5.7 Dragging Movements.** Rows are reordered with Move up and Move down
  buttons, never by dragging. n/a for a gate.
- **2.5.8 Target Size (Minimum).** The buttons are `NcButton`s at
  Nextcloud's default clickable size, above the 24 px minimum. The native
  Required checkbox in the mapping table is smaller, but it stands alone in
  its cell, so the spacing exception applies. No Hydra gate measures it.
- **3.2.6 Consistent Help.** The pages offer no help mechanism of their own;
  the explanatory notes sit in the same place on both pages. n/a.
- **3.3.7 Redundant Entry.** The sample-row test keeps the values typed for
  each column while the pack is edited; nothing asks for a value twice.
- **3.3.8 Accessible Authentication (Minimum).** No authentication step is
  added; Nextcloud's own sign-in applies. n/a.

## Out of scope

- Per-organisation packs. The store is instance-wide: one mapping per pack
  slug per instance. A shared instance that imports for several
  municipalities gets one mapping for all of them.
- A target-schema field picker. A pack does not say which schema it targets
  (stackiq's targets span three schemas and a Contacts identity), so targets
  stay free text.
- Marking app-owned packs in the `registers#import` pack picker.
- Shipped-version tracking ("is the stored pack still the shipped one?"):
  that belongs to the app that seeds the pack.
