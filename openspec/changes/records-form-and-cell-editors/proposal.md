---
kind: code
depends_on: []
---

# Proposal: records-form-and-cell-editors

## Summary

A record editor gets the editor that fits each field: a choice list for an enum, a file picker for a file field, a language tab per translatable field, and a cell in the records list that can be edited in place. The data model already declares all of this; only the screens are missing.

## The rows this closes

Source matrix: openregister `openspec/parity/capabilities.json` (comparedOn 2026-09-25). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### mod-field-types, choose from rich field types such as email, URL, date, choice list or file, each with its own editor

Own rating `partial`, built.state `building`, owner `ConductionNL/openregister`, area `modelling`, source `competitor-derived`.

Matrix evidence, verbatim:

> Types/formats (email, uri, date, file, oneOf, Nc*) chosen in EditSchemaProperty.vue:1185-1250; record form src/modals/object/ViewObject.vue:2997 getPropertyInputComponent gives own editors only to boolean and date/time, email/url as input types (:2979); enum choice lists and file fields render a plain text field

Matrix note, verbatim:

> No enum select editor in the record form.

Competitor cells rated `yes`, verbatim:

- directus: source read at v12.4.1, not driven: directus:packages/constants/src/fields.ts:18 TYPES list (string, text, dateTime, uuid, hash, csv, geometry, json and more); directus:app/src/interfaces has 44 interface folders (input, datetime, select-dropdown, file-image, map, input-rich-text-html, tags) each a dedicated editor
- strapi: source read at v5.55.1, not driven: strapi:packages/core/content-type-builder/server/src/services/constants.ts:11-33 media, string, text, richtext, blocks, json, enumeration, password, email, integer, biginteger, float, decimal, date, time, datetime, timestamp, boolean; strapi:packages/core/content-type-builder/server/src/controllers/validation/content-type.ts:63 plus uid, component, dynamiczone, customField (URL type absent in core, custom fields via plugins e.g. strapi:packages/plugins/color-picker)
- nocodb: source read at 2026.09.0, not driven: nocodb:packages/nocodb-sdk/src/lib/UITypes.ts:13-60 enum with Email, URL, Date, SingleSelect, MultiSelect, Attachment, PhoneNumber, Currency, Rating, GeoData and more; nocodb:packages/nc-gui/components/cell/ one editor per type (Email, Url, Date, SingleSelect, attachment, GeoData.vue)
- pocketbase: source read at v0.40.4, not driven: pocketbase:core/field_email.go:20, core/field_url.go:20, core/field_date.go:17, core/field_select.go:31, core/field_file.go:26, core/field_editor.go:17, core/field_geo_point.go:17, core/field_json.go:23, core/field_relation.go:31; each has its own editor under pocketbase:ui/src/fields/<type>/input.js (e.g. ui/src/fields/geoPoint/input.js:7)

### rec-translate, hold a field's value in several languages and show readers their own language

Own rating `partial`, built.state `building`, owner `ConductionNL/openregister`, area `records`, source `competitor-derived`.

Matrix evidence, verbatim:

> Negotiation executes: lib/Middleware/LanguageMiddleware.php:101 registered lib/AppInfo/Application.php:657, projection lib/Service/Object/RenderObject.php:658 resolveTranslationsForRows called lib/Controller/ObjectsController.php:1090; register languages editor src/sidebars/register/RegisterSideBar.vue:220. src/components/i18n/TranslationFieldEditor.vue is imported nowhere.

Matrix note, verbatim:

> Readers get their language, but editors can only enter language variants as raw JSON or via the translations API.

Competitor cells rated `yes`, verbatim:

- directus: source read at v12.4.1, not driven: directus:app/src/interfaces/translations/index.ts:7 translations interface over a languages collection; directus:app/src/interfaces/translations/translations.vue:83 AI translate is gated by the ai_translations_enabled entitlement (false in Core) but manual translation is not
- strapi: source read at v5.55.1, not driven: strapi:packages/plugins/i18n/server/src/services/content-types.ts:16 pluginOptions.i18n.localized per type and per attribute (:35); locale picker in strapi:packages/plugins/i18n/admin/src/components/CMHeaderActions.tsx

### rec-inline-edit, edit a value directly in a table cell, the way you would in a spreadsheet

Own rating `partial`, built.state `building`, owner `ConductionNL/openregister`, area `records`, source `competitor-derived`.

Matrix evidence, verbatim:

> Only inside the record modal: src/modals/object/ViewObject.vue:2688 handleRowClick edits a property row in the Properties tab. The list table src/views/search/SearchIndex.vue:593 opens the modal on row click (:384), no cell editing. Searched src for inlineEdit/cellEdit/contenteditable.

Matrix note, verbatim:

> Cell editing exists in the record modal's property table, not in the records list.

Competitor cells rated `yes`, verbatim:

- nocodb: source read at 2026.09.0, not driven: nocodb:packages/nc-gui/components/smartsheet/grid/ canvas grid cell editing through nocodb:packages/nc-gui/components/cell/ editors; nocodb:packages/nocodb/src/controllers/data-table.controller.ts:96 PATCH /api/v2/tables/:modelId/records

## Why

The schema editor lets an administrator declare an enum, a file field or a translatable field, and the API honours them, but the record form renders most of them as a plain text box and the records list cannot be edited at all. Every field a record editor fills in by typing the exact enum value is a validation error waiting to happen. The three rows share one screen pair, the record form and the records table, so they are one change.

## What is built today

- Field types and formats are chosen per property in `src/modals/schema/EditSchemaProperty.vue` (email, uri, date, file, oneOf).
- `src/modals/object/ViewObject.vue` `getPropertyInputComponent()` gives an own editor to boolean and date or time, and input types to email and url.
- Language negotiation runs (`lib/Middleware/LanguageMiddleware.php`, `RenderObject::resolveTranslationsForRows`), and `src/components/i18n/TranslationFieldEditor.vue` exists with a spec, but nothing imports it.
- The records list `src/views/search/SearchIndex.vue` opens the record modal on a row click; cells are read-only.

## What changes

1. The record form renders an enum property (or a `oneOf` of constants) as a select with the declared values, a file property as a file picker, and a property whose register declares languages as the existing `TranslationFieldEditor`.
2. The records list lets a user with update rights edit a scalar cell in place (text, number, boolean, date, enum); the save goes through the same PATCH the modal uses, and a refusal shows the server message in the cell.

## Out of scope

- Relation and array cells in the list (they keep opening the modal).
- New field types in the schema editor.
