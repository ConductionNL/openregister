---
kind: code
---

# Proposal: records-saved-templates

## Summary

A caseworker saves a record as a named template, for example "Melding
wateroverlast" with the category, the priority and the standard text already filled
in. The next time they create a record of that type they pick the template and start
from its values. A template can be kept private or shared with a group, like a saved
view.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| openregister | rec-template | Start a new record from a saved template with values already filled in | partial |

The row is in Open Register's own matrix, in its core area (records).
Demand: changelog, https://github.com/nocodb/nocodb/releases/tag/0.301.3. No competitor
is rated yes on this row. The row is partial with a demand row that asks for the
missing half, which makes it a build under the pass's rule for partial rows.

## Why

Two ways exist to start with values, and neither is a template. The search page's
copy action (`handleCopyRow()` in `src/views/search/SearchIndex.vue`, opening
`src/modals/object/CopyObject.vue`) copies an existing record, including values that
belong only to it. Schema property defaults (`lib/Service/Object/SaveObject.php:1540`
onward, `$property['default']`) fill one value for every record, set by an
administrator. The matrix note: "copy a record or rely on per-field defaults; no
named, saved record templates to choose from".

The Templates page (`src/views/templates/TemplatesIndex.vue`) says "Templates are
coming soon" and is about document templates; it calls no route.

## What changes

- A record template: a name, a description, the register and schema it belongs to,
  and a set of values. It has an owner and is private, shared with groups, or public
  to everyone who may create records of that schema, the sharing model saved views use.
- `/api/record-templates` with the usual create, read, update, delete, and a list
  filtered by register and schema that returns only the templates the caller may use.
- "Save as template" on a record's menu stores the chosen fields of that record as a
  template; the user picks which fields to keep.
- The create dialog offers "Start from a template" and fills the form with the
  template's values. The user still saves the record through the normal path.
- A template value that no longer fits the schema is dropped from the form with a
  notice, never saved.

## Consumers

- dossiq and pipelinq intake, where the same kind of case or lead comes in many
  times a day.
- The nextcloud-vue create form every leaf app uses, which gains the template picker.

## ADRs

- hydra ADR-001 and ADR-070: templates are Open Register data with an owner, not
  browser storage.
- hydra ADR-005: a template is only offered to a user who may create records of its
  schema, and its values pass the normal validation when the record is saved.

## Impact

- New capability `record-templates`.
- Affected code: a `RecordTemplate` entity, mapper and migration modelled on
  `lib/Db/View.php` and `lib/Db/ViewMapper.php`, a controller and routes, the object
  menu, the create dialog, nextcloud-vue's create form.
- Backwards compatible: new routes and a new option in the create dialog.
- Size: M.

## Out of scope

- Document templates, which the Templates page placeholder is about.
- Templates that create several linked records at once.
