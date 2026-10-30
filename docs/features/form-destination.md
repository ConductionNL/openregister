# Forms that submit into their destination

## Overview

A form writes into the object a person works on: a case, a ticket, a lead.
There is no intake queue in between (decision 179, ADR-117). OpenRegister
checks a form against that object's schema while the form is built, and
creates the object in the same request as the submit. The resident's
confirmation names the real record.

## Check a form while you build it

`POST /index.php/apps/openregister/api/forms/validate` (signed in):

```json
{
  "destination": { "register": "dossiq", "schema": "case" },
  "audience": "public",
  "mapping": {
    "fields": [
      { "field": "onderwerp", "property": "title", "type": "text", "maxLength": 200 },
      { "field": "kanaal", "property": "channel", "type": "choice", "options": ["website", "phone"] }
    ],
    "fixed": { "caseType": "0b5c1e9e-4f3a-4b8e-9c7d-2a1f0e6d5c4b" }
  }
}
```

The answer is `{ "accepted": false, "findings": [...] }`. Save the form only
when `accepted` is true. Each finding is `{ field?, property, code, message }`:

| Code | What is wrong |
|------|---------------|
| `required-unmapped` | A required property has no field, no fixed value and no server default |
| `property-unknown` | A field writes into a property the schema does not have |
| `property-read-only` | A field writes into a read-only property |
| `type-mismatch` | The field cannot produce the property's type |
| `format-mismatch` | The field cannot produce the property's format |
| `enum-unconstrained` | A free-text field writes into a list of allowed values |
| `enum-value-unknown` | A choice offers a value the property does not accept |
| `constraint-looser` | The field allows more than the property (length, range, pattern) |
| `fixed-value-invalid` | A fixed value is one the property refuses |
| `destination-not-public` | The schema does not let the form's audience create objects |
| `destination-is-staging` | The schema is marked `staging: true`, so it is no destination |

Flow task forms use the same validator: `TaskFormReader` delegates to it.

## Schema markers

Put these in a property's `x-openregister` block:

| Marker | Effect |
|--------|--------|
| `serverSet: true` | A listener fills this property, so no field has to |
| `confirmation: true` | The submit answer returns this property, read after the create listeners ran |
| `reference: true` | The submit answer returns this property as `reference` |

```json
"termStartsAt": { "type": "string", "format": "date-time",
  "x-openregister": { "serverSet": true, "confirmation": true } }
```

Two schema configuration keys apply: `staging: true` marks a schema that is
no destination, and `additionalProperties: true` lets fields write into
properties the schema does not declare.

## Submit

Apps call `FormSubmitService::submit()` (one destination) or `submitAll()`
(several `writes[]`, all or none) in process. A form stored as an
OpenRegister object (`status: published`, `destination` or `writes`,
`mapping`, `audience`) can be submitted over HTTP:

`POST /index.php/apps/openregister/api/forms/{formId}/submit`, with an
`Idempotency-Key` header when the client may retry.

| Status | Meaning |
|--------|---------|
| 201 | Created: `{ reference, id, receivedAt, confirmation, objects }` |
| 202 | The honeypot `_hp` was filled; nothing is stored |
| 401 | The form is for signed-in people |
| 403 | The subject may not create the destination |
| 404 | No such published form (also counted as a brute-force attempt) |
| 409 | The `Idempotency-Key` is in use by a submit still running, or was used by another person or for another payload |
| 422 | The payload was refused: `{ message, findings }`, nothing was created |
| 503 | The destination could not be reached; the resident tries again later |

A retried `Idempotency-Key` returns the first answer for 24 hours, to the same
person with the same payload only; the key is reserved while its submit runs, so
a parallel retry answers 409 instead of creating a second object. That
parallel-retry protection relies on Nextcloud's file locking (`filelocking.enabled`,
on by default); with file locking switched off the key is not reserved and only the
sequential repeat is answered from the stored answer. When one
of several writes is refused, the earlier writes of that submit are deleted
before the answer, and the deletion is logged.

## Files

`POST /index.php/apps/openregister/api/forms/{formId}/uploads` with `file` and
`property` returns `{ token, expiresAt }`, checked against the property's
size and type rules. Put `{ "uploadToken": "<token>" }` in the submit for
that property. The token is claimed only when the submit succeeds. An hourly
job deletes unclaimed tokens after 24 hours and logs how many.

## Drafts

A draft is the destination object itself, saved with `@self.status: draft`
(decision 180). There is no separate draft store.

- `POST /index.php/apps/openregister/api/forms/{formId}/draft` (signed in)
  saves the answers so far and returns `{ id, status: "draft" }`. Send `_draft`
  with that id to update it.
- A draft may miss required answers. A wrong type, format or enum value is
  still refused, whatever the schema's hard-validation flag.
- A draft is visible to its owner only. Set `draftsVisible: true` in the
  schema configuration to let everyone who may read the schema see drafts.
- Who cannot read a draft cannot change it. A PATCH, or a create that names
  the draft's id, answers 403 for anybody but the owner. An administrator
  may still correct a draft.
- Submit with `_draft` to leave draft. The submit runs full validation, sets
  `@self.status` to `active`, answers with the moment it left draft as
  `receivedAt`, and dispatches `ObjectActivatedEvent`.
- A client may create an object as a draft. It cannot turn a saved object
  into a draft. A draft leaves draft through the submit, or through its
  owner's activate action below. A plain save that sets `@self.status` to
  `active` is refused.
- An object without a stored status keeps the status it has today.

### Make your own draft definitive

An entry screen that is not a stored form finishes its draft on the object
API (decision 216):

`POST /index.php/apps/openregister/api/objects/{register}/{schema}/{id}/activate`

- Only the draft's owner can do it. An administrator is not the owner.
- Send `{ "object": { ... } }` to lay the last values over the stored draft
  first. Without a body the draft is judged as stored.
- The draft must pass the whole schema, whatever its hard-validation flag.
  Properties with a default, a computation or the `serverSet` marker are
  filled by the server and not asked of you.
- The answer is the object under the same id, with `@self.status: active`.
  `ObjectActivatedEvent` is dispatched, as on a submit.

| Status | Reason | When |
|---|---|---|
| 200 | | The draft is now active |
| 401 | `not-signed-in` | Nobody is signed in |
| 403 | `not-the-owner` | The draft is somebody else's, or you may not write this schema |
| 404 | `not-found` | No such object, or you may not read it |
| 409 | `not-a-draft` | The object is not in status `draft` |
| 422 | `invalid` | The draft is not complete; `findings` lists `{ property, code, message }` per property |

Every refusal answers `{ message, reason, findings }`. From PHP, call
`DraftActivation::activate()` with a `DraftOwner`; a portal passes its own
scope value there (decision 195).

### Let abandoned drafts expire

A draft that nobody changed for 30 days is removed. A daily background job
(`DraftExpiryJob`) does it, for every schema of every app. Your app needs no
cleanup job of its own.

Set `draftLifetimeDays` in a schema's configuration to change that:

```json
{ "configuration": { "draftLifetimeDays": 7 } }
```

| Value | What happens to the schema's drafts |
|---|---|
| not set | Removed after 30 days without a change |
| a whole number, 1 or more | Removed after that many days without a change |
| `false` | Kept. The job never looks at this schema |

Anything else (zero, a negative number, a fraction, text) is refused. The
schema is saved with `false`, so a typing error keeps drafts, and the key is
reported in the log as dropped. The setting survives an import and an export.

The lifetime counts from the draft's last change, not from its first save.
One run removes at most 1,000 drafts. The rest waits for the next day. The
job logs one line per schema that lost drafts, and one line with the totals.

#### Look before the job runs

```bash
occ openregister:drafts:expired
occ openregister:drafts:expired --json
```

The command lists how many drafts the next run would remove per schema. It
removes nothing. Run it before the first job run after an upgrade: the
30 days apply to the drafts you already have.

### Deleting a draft

Delete a draft like any object: `DELETE .../api/objects/{register}/{schema}/{id}`.
Two things differ from a record. The draft is removed for good, with its
files, instead of soft-deleted. And a schema with an `x-openregister-archival`
block, which refuses every other delete, lets a draft go: a draft was never
received, so it is not a record yet. An object that is not a draft is refused
there as before.

Every removed draft leaves one entry on the audit trail, with the action
`draft.removed`. It shows where other audit entries show.

| The entry says | From |
|---|---|
| Who removed it | The signed-in user, or `job:DraftExpiryJob` for the cleanup |
| When | The moment of the removal |
| Where | The register and the schema |
| Which object | The draft's id |
| That it was a draft | `status: draft` and `permanent: true` |
| Whose draft it was | The draft's owner |

The entry never holds what the draft contained: no property value and no
property name.

### What a listener sees of a draft

Nothing, until it is real. Listen to `ObjectCreatedEvent` and you get every
object once: when it is created outright, or when a draft leaves draft. You
never get a half-typed object, and you need no status check.

| Moment | Event |
|---|---|
| A draft is saved for the first time | `DraftCreatedEvent` |
| A draft is saved again | `DraftUpdatedEvent` |
| A draft is discarded or expires | `DraftDeletedEvent` |
| A draft leaves draft | `ObjectCreatedEvent`, then `ObjectActivatedEvent` |
| Any other object is created, changed or deleted | `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent`, as before |

The draft events are for OpenRegister's own bookkeeping: caches, the
translation sidecar and cleanup. Do not listen to them from an app.
`ObjectActivatedEvent` carries the moment of receipt. Do your creation work
on `ObjectCreatedEvent` only, or it runs twice.

A generated identifier follows the same rule. A draft takes no number. The
save that leaves draft issues it, so a half-typed case leaves no gap in the
sequence.

### Keep your own drafts out of a work list

A list leaves out other people's drafts and keeps your own (decision 201).
When your drafts are offered back on their own entry screen, keep them out
of every list of the schema:

```json
{ "configuration": { "draftsListed": false } }
```

Lists, counts and facets then hold no draft by default. `_drafts=none` asks
the same of one request. `_drafts=true` shows drafts alone and `_drafts=any`
shows drafts beside the rest, whatever the schema says.

Receipt listeners: treat `ObjectCreatedEvent` for an object that is not a
draft, and `ObjectActivatedEvent`, as the moment of receipt. Skip an object
whose `isDraft()` is true.

## When a schema changes

Saving a schema's properties or required list asks the owning apps for their
published forms into it (`FormDestinationDependentsEvent`). A form the change
breaks is unpublished and its author notified, unless its app sets
`formDestinationBreak` to `refuse`: then the schema save is refused with 409.
The save answer lists every affected form under `affectedForms`.

## Next

Wire your app's form save to `POST /api/forms/validate`, and its public submit
to `FormSubmitService`.
