# Record Templates

**Status**: Implemented (backend store and API). The "Save as template" and "Start from a template" dialogs are not built yet.

## Overview

A record template is a named set of field values a user keeps to start new records with. A caseworker who
registers the same kind of melding every week saves its recurring values once and starts each new melding from them.

A template is user data, like a [saved view](views.md), not configuration on a schema. It belongs to one register
and schema, has an owner, and can be public or shared with users and groups.

## What a template holds

| Field | Required | Meaning |
|---|---|---|
| `name` | Yes | Shown in the template list; at most 255 characters |
| `description` | No | A longer explanation |
| `register` | Yes | The id of the register the template starts records in |
| `schema` | Yes | The id of the schema the template starts records in |
| `values` | No | An object keyed by property name: the values a new record starts with |
| `isPublic` | No | `true` offers it to everyone who may create in the schema |
| `sharedWith` | No | Shares with users or groups, in the same shape as a saved view's shares |

A template stores values, not a half-saved record. A record started from it is saved like any other, so validation,
defaults, calculations and authorization all apply once, at that save. A template is never a way to write a field
the user may not write: a value for a forbidden field is refused when the record is saved.

## Who sees and changes a template

- **Use needs create rights.** A template is offered only to a user who may create records in its schema, even when
  it is public or shared with their group. To anyone else the list leaves it out and a direct read answers 404.
- **Change needs ownership.** Only the owner or an administrator may update or delete a template. A share is a way
  to use it, not to edit it; anyone else gets 403.
- Each template in an answer carries the caller's access under `@self.access`.

## API

| Verb | Route | Answer |
|---|---|---|
| GET | `/api/record-templates` | templates the caller may use; filter with `?register=` and `?schema=` |
| GET | `/api/record-templates/{id}` | one template; 404 when absent or not usable |
| POST | `/api/record-templates` | 201 with the new template, owned by the caller |
| PUT | `/api/record-templates/{id}` | replace a template |
| PATCH | `/api/record-templates/{id}` | change only the fields sent |
| DELETE | `/api/record-templates/{id}` | 204 |

All routes need a signed-in user (401 otherwise). An invalid template answers 400 with a `findings` list, each with a
`code` and a `message`, for example `name.required`, `name.too-long`, `values.not-an-object` or
`isPublic.not-a-boolean`. A user who may not create in the schema cannot store a template for it (403).

```json
POST /api/record-templates
{
  "name": "Weekly street-light report",
  "register": "5",
  "schema": "18",
  "values": { "category": "openbare-verlichting", "priority": "normal" },
  "sharedWith": [ { "group": "wijkteam-noord", "mode": "read" } ]
}
```

## Planned interface

The interface half of the change (`openspec/changes/records-saved-templates`) adds:

- **Save as template** on a record's menu, with a field picker. Identity, dates and relations start unticked,
  because they belong to one record.
- **Start from a template** in the create dialog. Values whose property no longer exists, or whose type no longer
  matches, are dropped, and the dialog says which.
- A template picker in the shared nextcloud-vue create form, so other apps get the same behaviour.

## Related Features

- [Views](views.md): saved searches, which templates are modelled on
- [Registers & Schemas](registers-and-schemas.md): the schema a template starts records in
