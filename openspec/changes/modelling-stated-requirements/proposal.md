---
kind: code
---

# Proposal: modelling-stated-requirements

## Summary

Four modelling rows are built but no requirement says them. A functional administrator builds a record type in a form, a save that breaks the type is refused with the field named, an object-typed field is checked against its own JSON Schema, and a field takes its allowed values from a managed vocabulary. The code exists; the spec does not, so nothing cites the rows and nothing stops a later change from breaking them. This change writes the four requirements down against the main specs they belong to.

## Rows this closes

| row | capability | built in |
|---|---|---|
| mod-visual-builder | Build a record type by adding fields in a form | `src/views/schema/SchemaDetails.vue`, `src/modals/schema/EditSchemaProperty.vue` |
| mod-validate | Refuse a record that breaks its type's rules, naming the field | `lib/Service/Object/ValidateObject.php` (`formatValidationError`) |
| mod-json-field-schema | Check a JSON field against a JSON Schema before saving | `lib/Service/Object/ValidateObject.php` (Opis validator over nested schemas) |
| mod-vocabulary | Take a field's allowed values from a managed vocabulary | `lib/Listener/CodedValueValidationListener.php`, `EditSchemaProperty.vue` concept scheme field |

The other modelling and quality rows already have a requirement that says them; this PR records it as `built.requirement` in `openspec/parity/capabilities.json`. It also repoints `mod-field-types`, `rec-inline-edit` and `rec-translate` from the archived `records-form-and-cell-editors` change to `openspec/specs/objects-crud`, where that change was synced.

## What changes

- `objects-crud`: two added requirements (refusal names the field; object-typed fields are validated against their own schema).
- `skos-concept-registers`: one added requirement (a coded property takes its values from a scheme).
- `entity-management-modals`: one added requirement (a schema's fields are built in a form).

## Screens

`openregister/OrSchema`, `openregister/OrEigenschap`, `openregister/OrObjectBewerken`; see `screens.md`.

## Out of scope

- Custom validation messages per field (`mod-custom-messages`, change `modelling-validation-messages`).
- A picker from the scheme in the record form; today the record form shows a text field.
