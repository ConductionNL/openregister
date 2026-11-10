# Design: records-draft-versions

Read at openregister development 0ca409ee04.

## D-1: a draft is a delta beside the live object, not a second object

The spec requires delta storage ("Drafts MUST store only the delta") and the reserved
key `main` for the published version. A new table `openregister_object_drafts` holds
`uuid`, `object_uuid`, `register`, `schema`, `key`, `name`, `created_by`, `created`,
`updated`, `base_version` (the object's `version` when the draft was made) and
`delta` (json). The live object in its magic table is untouched until promotion.

A second object in the same table was rejected: every list, facet, count and
relation query would need a filter to hide it, and forgetting one leaks a draft.

## D-2: reading a draft

`ObjectService::find()` (called from `ObjectsController::show()`,
`lib/Controller/ObjectsController.php:2913`) accepts `version`. `main` or absent reads
the live object. Another key loads the draft, checks the caller may see it (creator
or write access, per the spec's RBAC requirement), merges the delta onto the live
data, renders through the same `RenderObject` path, and adds `_version: {key, name,
base}` to `@self`. Relations in the delta hold uuids like any property, so rendering
resolves them the same way.

## D-3: promotion and conflicts

`DraftService::promote()` compares, per field in the delta, the value at
`base_version` (from the audit trail) with the live value. A field changed in both
is a conflict: 409 with the field, the draft value and the live value. With no
conflict, the delta is saved through the normal save path (`SaveObject`), so
validation, RBAC, hooks, lifecycle guards and the audit trail all run once. The
draft row is deleted in the same transaction. `?force=true` is allowed for
administrators, as the spec says, and the audit entry names the overwritten fields.

## D-4: search and lists exclude drafts by construction

Drafts live in their own table, so every existing query excludes them. The opt-in
the spec requires ("Search MUST be configurable to include or exclude draft
versions") is a `_drafts=true` parameter that adds matching draft keys to a result's
`@self.drafts` for callers who may see them, without changing the result set.

## D-5: preview through an access link

`lib/Db/AccessLink.php` already models a secret anchor, a subject (`subjectType`,
`subjectId`), capabilities limited to read, comment and upload (:140), an expiry
(`expiresAt`) and revocation. A preview link is an access link with subject type
`object-draft`, capability `read` only, and a required expiry (default 24 hours). The
public page and JSON route of access links (`AccessLinkPageController`) serve the
merged draft for such a link.

A schema's `previewUrl` template, for example
`https://www.voorbeeld.nl/preview/{uuid}?versie={version}&token={token}`, is filled
with the link's anchor as `{token}`. The Drafts tab opens it in a new tab. The site
calls the access link JSON route with the token and renders what it gets.

## D-6: the Drafts tab

`src/views/object/ObjectDetails.vue` has a tab container (from :144). A Drafts tab
lists drafts with key, name, creator and changed fields; editing a draft reuses the
object form with the draft loaded; compare shows the delta against the live values;
the preview button appears when the schema declares `previewUrl`.

## Declarative-vs-imperative decision

`previewUrl` is declared on the schema. The draft store and promotion are platform
code, because they are a storage concern every schema shares, not a per-schema rule.

## Risks

- A draft that outlives a schema change: promotion runs full validation, so a delta
  that no longer fits the schema is refused with the validation errors.
- A leaked preview link: it is read-only, expires, can be revoked, and shows one
  draft of one object.
- Retention: the spec's version retention rules apply; a discarded draft is deleted
  and its creation and discard stay on the audit trail.
