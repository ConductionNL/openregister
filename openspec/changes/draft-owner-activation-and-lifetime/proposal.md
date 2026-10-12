---
kind: code
depends_on: [form-destination-validator]
---

# Proposal: draft-owner-activation-and-lifetime

Decision 216 (Ruben, 12 October 2026): "Extend OpenRegister". Two generic capabilities on top of the explicit lifecycle status `draft` (decisions 180, 195 and 201). It relaxes one sentence of `form-destination-validator`: "only a form submit leaves draft".

## Why

A draft is the destination object in status `draft`. Two things were missing for an entry screen that is not a stored form, and pipelinq's quick contact-moment log ran into both (Q-pipelinq-180-2):

1. **Nothing but a stored form's submit could finish a draft.** `DraftStatusPolicy` refuses a plain save that sets `@self.status` to `active`. A screen without a stored form could save its draft and never send it.
2. **Nothing removed an abandoned draft.** `DraftFinder` hands back `updatedAt` "for a caller's own retention", so every app would have to carry its own cleanup job. pipelinq's (decision 145) was never built.

A third gap showed while reading for the rework: a list keeps the caller's own drafts in view (decision 201), so an agent's half-typed entries sit in their own work list with no way to leave them out.

## What changes

- **The owner makes their own draft definitive.** `POST /api/objects/{register}/{schema}/{id}/activate`, served by `ObjectDraftController` and `DraftActivation`. It takes the path the form submit takes: read the owner's draft, judge it against the whole schema, allow one promotion in `DraftStatusPolicy`, save it as `active` under the same id, dispatch `ObjectActivatedEvent`. An explicit action: a PUT or PATCH that sets the status stays refused.
- **Only the owner.** The acting user is the asserted owner (decision 195: a user, or a scope value from in-process code). An administrator is not the owner. RBAC stays on for the save. A refusal is a real HTTP error status: the controller is a plain `Controller`, because OCSMiddleware turns a 403 from an OCSController into a 200.
- **A schema sets a draft lifetime.** Configuration key `draftLifetimeDays`, a whole number of days, 1 or more. It is declared in `Schema::validateConfigurationEntry()`, because an unknown configuration key is dropped on save without a word. It survives an import and an export.
- **One cleanup job.** `DraftExpiryJob`, daily, declared in `appinfo/info.xml`. It removes every draft that nobody changed for longer than its schema's lifetime, through `ObjectService::deleteObject()`. No setting, no removal.
- **`_drafts=none`.** A list lens that leaves out every draft, the caller's own included.
- **One shared excusal set.** The properties the server fills (default, computed, `serverSet`) are excused from `required` by `DraftStatusPolicy::serverFilledExcusals()`, used by the form submit and by the activation, so a draft one accepts the other accepts.

## Decisions taken while building, for Ruben to overrule

- **Expired drafts are removed with the ordinary (soft) delete**, not the permanent delete `DraftDiscard` uses. The brief asked for the normal delete path so audit trail and files follow. The consequence: an expired draft stays recoverable for the schema's deletion window and is purged after it, like any deleted object.
- **A lifetime counts from the last change** (`updated`, else `created`), not from the first save. A draft somebody is still typing in is not abandoned.
- **Somebody who may read the draft and is not its owner gets 403.** Somebody who may not read it gets 404, so the endpoint cannot be used to probe ids.

## Out of scope

The apps that use it. pipelinq's quick log is reworked on top in its own change (`contact-moment-ticket-drafts`), which sets seven days on its schema.
