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
| 422 | The payload was refused: `{ message, findings }`, nothing was created |
| 503 | The destination could not be reached; the resident tries again later |

A retried `Idempotency-Key` returns the first answer for 24 hours. When one
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
- Submit with `_draft` to leave draft. The submit runs full validation, sets
  `@self.status` to `active`, answers with the moment it left draft as
  `receivedAt`, and dispatches `ObjectActivatedEvent`.
- A client may create an object as a draft. It cannot turn a saved object
  into a draft, and only the submit can leave draft.
- An object without a stored status keeps the status it has today.

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
