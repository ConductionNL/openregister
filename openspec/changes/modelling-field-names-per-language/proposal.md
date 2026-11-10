---
kind: code
depends_on: []
---

# Proposal: modelling-field-names-per-language

## Summary

An administrator who adds a field to a schema gives it a name in each language
the organisation uses. A colleague who works in English sees "Contract end
date", a colleague who works in Dutch sees "Einddatum contract". The names are
part of the schema, travel with it on export and import, and every app that
renders forms and tables from the schema can show them.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| pipelinq | `plat-translated-field-names` | Show your own field names in each colleague's language | partial |

Row `plat-translated-field-names` sits in pipelinq's matrix with
`built.owner` ConductionNL/openregister. The pipelinq lane's note: "A field an
administrator adds is an OpenRegister schema property ... rendered by
nextcloud-vue; a label per language needs the property model to carry one,
which pipelinq cannot add. Shipped fields are translated already."

Demand row: featureRequest,
https://www.pdpartnerassociation.com/top-6-feature-request/ ("you can not
translate your custom fields"). Three competitors rate it `yes`:

- HubSpot: https://knowledge.hubspot.com/object-settings/translate-custom-crm-content "you can create translations for your custom CRM data, so labels and names appear in the appropriate language"
- EspoCRM: "Administration > Label Manager (application/Espo/Resources/metadata/app/adminPanel.json:162) edits field and option labels per language, including custom fields"
- Odoo: "odoo/addons/base/models/ir_model.py:533 field_description = fields.Char(string='Field Label', ... translate=True)"

## What changes

- A property may carry a `titles` modifier: a map of BCP 47 language tags to
  a name, beside the existing `title`.
- The schema validator accepts it, refuses a key that is not a language tag or
  a value that is not a non-empty string, and publishes the modifier in the
  property vocabulary.
- A schema read with `?_lang=<tag>` or an `Accept-Language` header answers
  each property's `title` in that language when `titles` has it, and keeps
  `title` otherwise. Without either, the schema is returned as stored.
- The schema editor shows one name field per configured register language.

## Out of scope

- Translating enum option labels. The same shape fits them later.
- nextcloud-vue reading `titles` in its own forms and tables. That is the
  library's half, named for its lane; today it shows `tr(prop.title || key)`.

## Impact

- `lib/Service/Schemas/PropertyValidatorHandler.php` (`MODIFIERS` at `:471`).
- The schema read path in `SchemasController` for the language projection.
- The schema edit modal's property form.
- `openspec/specs/schema-property-exploration/spec.md`.
