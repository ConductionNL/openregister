---
kind: code
depends_on: []
---

# Proposal: records-validate-without-saving

## Summary

A maker's release test in buildiq asks OpenRegister "would this record be
accepted, and if not, on which fields?" without saving anything. OpenRegister
answers with the same verdict a real save would give: schema validation and
the save rules on dependent values, coded values and conditionally required
fields, per field.

## Halves this closes

This is the OpenRegister half of buildiq's merged change
`lifecycle-release-test-gate` (buildiq `development` 974af86), rows
`lc-automated-tests` (2 competitors yes: Mendix, Power Apps) and
`operate-debug-log-and-monitoring`. It has no row in OpenRegister's matrix;
the owner moves pass of 28 Sep 2026 handed it here. Buildiq writes:
"openregister owes a validate-only call on its published contract. Record
tests need 'would this record be accepted by this schema, and if not, on which
fields' without saving. OpenRegister has it internally
(`ValidateObject::validateObject()`, `lib/Service/Object/ValidateObject.php:1694`),
but `OCA\OpenRegister\Contract\ObjectServiceInterface`
(`lib/Contract/ObjectServiceInterface.php`), the contract buildiq consumes,
offers no validate method, and `objects#validate` (`appinfo/routes.php:329`)
re-validates stored objects rather than a sample. No open openregister change
covers it."

## What changes

- `ObjectServiceInterface::validateObject(array $object, register, schema,
  ?string $id = null): ValidationVerdict` on the published contract. With an
  `id`, the sample is checked as an update of that object; without, as a
  create.
- `POST /api/objects/{register}/{schema}/validate` with the sample as the body
  answers `{ valid, errors: [{ property, message, rule }] }`, always 200 for a
  well-formed request, never writing.
- The verdict includes the save rules that run as listeners today, through one
  shared check, so validate and save cannot disagree.

## Out of scope

- Running lifecycle actions, flows or webhooks for a sample. Only the checks
  that decide acceptance run.
- The existing stored-object revalidation at `/api/objects/validate`. It stays.

## Impact

- `lib/Contract/ObjectServiceInterface.php` and its implementation.
- `lib/Service/Object/ValidateObject.php` (`validateObject()` at `:1694`).
- `lib/Listener/DependentValueListener.php`,
  `lib/Listener/CodedValueValidationListener.php` (their checks become callable
  without an event).
- `lib/Controller/ObjectsController.php`, route in `appinfo/routes.php`.
