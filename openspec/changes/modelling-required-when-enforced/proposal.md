---
kind: code
depends_on: []
---

# Proposal: modelling-required-when-enforced

## Summary

An administrator marks a field required only in some cases, for example "the
complaint category is required when the request type is Klacht". Forms already
show and check it. OpenRegister now refuses a save that breaks the rule, on
every write path, so a client that skips the form cannot skip the rule.

## Halves this closes

This is the OpenRegister half of nextcloud-vue's merged change
`form-conditions-from-schema` (nextcloud-vue `development` e487bc8), which
covers pipelinq row `plat-field-conditions` and also serves stackiq
`landscape-dependent-field-options`, shillinq `platform-required-fields` and
portaliq's intake forms. It has no row in OpenRegister's matrix; the owner
moves pass of 28 Sep 2026 handed it here. Nextcloud-vue writes:
"Required-when must also be enforced on save, or a client that skips the form
skips the rule. That is an OpenRegister listener in the shape of
`DependentValueListener`. Listed for the openregister lane. Until it exists,
the administrator can express the same rule as an `x-openregister-validations`
entry, which OpenRegister enforces today."

The annotation shape is fixed by that change (its design D2):
`"x-openregister-required-when": { "field": "requestType", "op": "eq", "value": "Klacht" }`,
with the operators of nextcloud-vue's `evaluateVisibleWhenLocal`: `eq`, `neq`,
`gt`, `gte`, `lt`, `lte`, `empty` and `notEmpty`.

## What changes

- A property may carry `x-openregister-required-when` with that shape, or a
  list of such conditions that must all hold.
- The schema validator accepts it, refuses an unknown operator or a `field`
  the schema does not declare, and publishes it in the property vocabulary.
- A listener on the create and update events refuses a save where a condition
  holds and the property is empty, with 422 naming the property and the
  condition.
- `x-openregister-visible-when` stays data for the forms; OpenRegister does not
  strip a hidden field, because visibility is not access.

## Out of scope

- Required fields per lifecycle state. That is the open change
  `field-rules-by-state`.
- Conditions that call an endpoint. The form ignores those in a schema, and so
  does the server.

## Impact

- New `lib/Listener/RequiredWhenListener.php`, registered beside
  `DependentValueListener` in `lib/AppInfo/Application.php:3372-3373`.
- New `lib/Service/Schemas/RequiredWhenDeclaration.php` for validation and
  evaluation.
- `lib/Service/Schemas/PropertyValidatorHandler.php` (`MODIFIERS`).
