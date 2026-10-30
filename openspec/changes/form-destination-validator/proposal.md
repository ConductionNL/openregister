---
kind: code
depends_on: []
---

# Proposal: form-destination-validator

OpenRegister's half of decision 179 (Ruben, 10 October 2026): "We dont intake to an intake, we intake into a case, or ticket or something else." Cross-app change: `hydra/openspec/changes/form-submits-into-its-destination-object`, architecture in hydra ADR-117. Ruben's answers: decisions 180 (drafts) and 181. Every other app change in that chain waits on this one.

## Why

A form today is checked against its own field list. The destination schema judges the data later, in a background job, with nobody left to tell. OpenRegister already refuses a bad field at save time for flow task forms (`TaskFormReader::validate`), but nothing does the same for a form that creates an object. And `ObjectsController::create` validates only when a schema has hard validation on, then answers a bare 400 string, while update answers per-property errors.

## What changes

- **`FormDestinationValidator`**: judges a form's field-to-property mapping against a destination schema and returns findings (`required-unmapped`, `property-unknown`, `type-mismatch`, `format-mismatch`, `enum-unconstrained`, `enum-value-unknown`, `constraint-looser`, `fixed-value-invalid`, `destination-not-public`, `destination-is-staging`). Built by extending `TaskFormReader::validate`, not beside it.
- **`FormSubmitService`** and `POST /api/forms/{formId}/submit`: validates the payload with the destination's full validator whatever its hard-validation flag, creates the object or objects in one request, and returns `reference`, `receivedAt` and every property marked `x-openregister.confirmation: true`. A refusal is 422 with findings. An `Idempotency-Key` repeats the first answer.
- **All or nothing** for several writes: validate all, then write; on a later refusal, delete the earlier writes of that submit and audit it.
- **Upload tokens**: `POST /api/forms/{formId}/uploads` holds file bytes only, expires after 24 hours, purge counted.
- **Schema save checks dependent forms**: a schema change that invalidates a published form is refused or unpublishes the form, per the owning app's setting.
- **Create errors match update errors**: `ObjectsController::create` returns the per-property shape on a validation failure.
- **Three schema markers**: `x-openregister.confirmation` (returned to the submitter), `x-openregister.serverSet` (filled by a listener, so not reported as unmapped) and `x-openregister.reference` (the property returned as `reference`). Two schema configuration keys survive a save: `staging` and `additionalProperties`.
- **Explicit lifecycle status `draft`** (decision 180): object metadata gains a stored `@self.status` with `draft` and `active`. A draft may miss required properties and is never type-invalid. Moving to `active` runs full validation and is the moment of receipt. Objects without the field keep today's date-deduced status.
- **`or-form-and-journey-registry` amended**: `journeyRun` is replaced by draft destination objects; a step commits all or nothing.
- **Refuse from the first release** (decision 181): no report-only mode.

## Out of scope

Authoring screens (buildiq), the public host (portaliq), the case fields (dossiq).

## Rollback

The submit service, the routes and the `draft` status are additive; callers switch to them in their own changes. The validator refuses from the first release (decision 181, Q9), so rolling back the check means reverting this change. The create error body changes from a bare string to the update shape (same status).
