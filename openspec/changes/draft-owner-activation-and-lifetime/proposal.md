---
kind: code
depends_on: [form-destination-validator]
---

# Proposal: draft-owner-activation-and-lifetime

Decision 216 (Ruben, 12 October 2026): "Extend OpenRegister", with his rulings 226 and 228 of the same day. Two generic capabilities on top of the explicit lifecycle status `draft` (decisions 180, 195 and 201). It relaxes one sentence of `form-destination-validator`: "only a form submit leaves draft".

## Why

A draft is the destination object in status `draft`. Two things were missing for an entry screen that is not a stored form, and pipelinq's quick contact-moment log ran into both (Q-pipelinq-180-2):

1. **Nothing but a stored form's submit could finish a draft.** `DraftStatusPolicy` refuses a plain save that sets `@self.status` to `active`. A screen without a stored form could save its draft and never send it.
2. **Nothing removed an abandoned draft.** `DraftFinder` hands back `updatedAt` "for a caller's own retention", so every app would have to carry its own cleanup job. pipelinq's (decision 145) was never built.

A third gap showed while reading for the rework: a list keeps the caller's own drafts in view (decision 201), so an agent's half-typed entries sit in their own work list with no way to leave them out.

## What changes

- **The owner makes their own draft definitive.** `POST /api/objects/{register}/{schema}/{id}/activate`, served by `ObjectDraftController` and `DraftActivation`. It takes the path the form submit takes: read the owner's draft, judge it against the whole schema, allow one promotion in `DraftStatusPolicy`, save it as `active` under the same id, dispatch `ObjectActivatedEvent`. An explicit action: a PUT or PATCH that sets the status stays refused.
- **Only the owner.** The acting user is the asserted owner (decision 195: a user, or a scope value from in-process code). An administrator is not the owner. RBAC stays on for the save. A refusal is a real HTTP error status: the controller is a plain `Controller`, because OCSMiddleware turns a 403 from an OCSController into a 200.
- **A draft lives 30 days, unless its schema says otherwise (decision 228).** Configuration key `draftLifetimeDays`: a whole number of days, 1 or more, sets another lifetime, and `false` keeps the schema's drafts. No key means 30 days. It is declared in `Schema::validateConfigurationEntry()`, because an unknown configuration key is dropped on save without a word. It survives an import and an export. A value that cannot be read is stored as `false` and reported, so a typing error keeps drafts.
- **One cleanup job.** `DraftExpiryJob`, daily, declared in `appinfo/info.xml`. It removes every draft that nobody changed for longer than its schema's lifetime, through `ObjectService::deleteObject()`. One run removes at most 1,000 drafts and logs a count per schema.
- **A look before the job runs.** `occ openregister:drafts:expired` lists how many drafts the next run would remove per schema, and removes nothing. The first run after the upgrade removes drafts older than 30 days that were kept until then; the changelog says so.
- **A draft can be deleted on every schema.** A stored draft passes the archival delete gate, and a draft's delete is permanent. Without it neither the owner nor the cleanup could remove a draft on a schema with an archival block.
- **A removed draft leaves one audit-trail entry (decision 226).** Action `draft.removed`: who or which job, when, which register, schema and object, and that it was a draft. Never what it held. Written through `AuditTrailMapper::createAuditTrailEntry()`.
- **Lists without your own drafts.** A schema sets `draftsListed: false` and its lists, counts and facets leave out every draft by default, the caller's own included. `_drafts=none` asks the same of one request. Asking for drafts (`_drafts=true` or `any`) still shows them.
- **One shared excusal set.** The properties the server fills (default, computed, `serverSet`) are excused from `required` by `DraftStatusPolicy::serverFilledExcusals()`, used by the form submit and by the activation, so a draft one accepts the other accepts.

- **A draft notifies nobody.** A schema's `created` notification rules fire when the object leaves draft, once, and not on the draft's first save. Without it every autosaved draft announced a half-typed object, and the finished one was never announced.

## Known gap, closed in its own change

OpenRegister registers about sixteen listeners on `ObjectCreatedEvent` (flow triggers, webhooks, activity, push, metrics, search). None looks at the status, and none listens to `ObjectActivatedEvent`. So a flow or webhook on "created" still fires on a draft's first save. Only the notification rules are fixed in this change, because pipelinq's ticket declares one. Decision 227 gives the rest one rule, in the change `draft-creation-side-effects`.

## Decisions taken while building, and Ruben's rulings on them

- **A draft passes the archival delete gate, and its delete is permanent.** Ruled in decision 226: yes, and the removal writes one audit-trail entry without the draft's content. Found while building pipelinq's side: `ticket` carries `x-openregister-archival`, and OpenRegister refuses every user delete on such a schema. So an owner could not discard a draft ticket and the cleanup could not remove one; the job would have run green and removed nothing. A draft was never received, so its archival term has not started. `ObjectService::deleteObject()` now lets an object stored in status `draft` through that gate, and nothing else. It removes a draft for good, with its files, because a soft-deleted draft on an archival schema would be a tombstone the purge refuses for ever. This is the same end state `DraftDiscard` already asked for ("deleted permanently: it holds unsent answers and nothing refers to it"). The entries the draft's own saves wrote before the removal stay as they are.
- **A lifetime counts from the last change** (`updated`, else `created`), not from the first save. A draft somebody is still typing in is not abandoned.
- **Somebody who may read the draft and is not its owner gets 403.** Somebody who may not read it gets 404, so the endpoint cannot be used to probe ids. Stays as built.
- **No setting meant "kept" in the first version of this change.** Ruled in decision 228: no setting means 30 days, and `false` is the explicit way to keep drafts.

## Out of scope

The apps that use it. pipelinq's quick log is reworked on top in its change `contact-moments-keep-draft`, which sets seven days on its `ticket` schema.
