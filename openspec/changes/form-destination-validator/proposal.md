---
kind: code
depends_on: []
---

# Proposal: form-destination-validator

OpenRegister's half of decision 179 (Ruben, 10 October 2026): "We dont intake to an intake, we intake into a case, or ticket or something else." Cross-app change: `hydra/openspec/changes/form-submits-into-its-destination-object`, architecture in hydra ADR-117. Every other app change in that chain waits on this one.

## Why

A form today is checked against its own field list. The destination schema judges the data later, in a background job, with nobody left to tell. OpenRegister already refuses a bad field at save time for flow task forms (`TaskFormReader::validate`), but nothing does the same for a form that creates an object. And `ObjectsController::create` validates only when a schema has hard validation on, then answers a bare 400 string, while update answers per-property errors.

## What changes

- **`FormDestinationValidator`**: judges a form's field-to-property mapping against a destination schema and returns findings (`required-unmapped`, `property-unknown`, `type-mismatch`, `format-mismatch`, `enum-unconstrained`, `enum-value-unknown`, `constraint-looser`, `fixed-value-invalid`, `destination-not-public`, `destination-is-staging`). Built by extending `TaskFormReader::validate`, not beside it.
- **`FormSubmitService`** and `POST /api/forms/{formId}/submit`: validates the payload with the destination's full validator whatever its hard-validation flag, creates the object or objects in one request, and returns `reference`, `receivedAt` and every property marked `x-openregister.confirmation: true`. A refusal is 422 with findings. An `Idempotency-Key` repeats the first answer.
- **All or nothing** for several writes: validate all, then write; on a later refusal, delete the earlier writes of that submit and audit it.
- **Upload tokens**: `POST /api/forms/{formId}/uploads` holds file bytes only, expires after 24 hours, purge counted.
- **Schema save checks dependent forms**: a schema change that invalidates a published form is refused or unpublishes the form, per the owning app's setting.
- **Create errors match update errors**: `ObjectsController::create` returns the per-property shape on a validation failure.
- **Two schema markers**: `x-openregister.confirmation` (returned to the submitter) and `x-openregister.serverSet` (filled by a listener, so not reported as unmapped).
- **`or-form-and-journey-registry` amended**: `journeyRun` no longer stages written ids; a step commits all or nothing.

## Out of scope

Authoring screens (buildiq), the public host (portaliq), the case fields (dossiq).

## Rollback

The validator ships in report mode for one release (findings returned, save allowed). The submit service is additive; callers switch to it in their own changes.
