---
kind: code
---

# Proposal: records-draft-versions

## Summary

A caseworker works on a named draft of a record while the published version stays
what everyone else sees. They can open the draft in the real public website before
it goes live, through a preview link that expires. When the draft is ready, they
promote it and it becomes the published version. The draft and promote behaviour is
already specified in `content-versioning` and marked deferred; this change is the
change that builds it, and adds the preview.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| openregister | rec-draft-publish | Keep a draft of a record apart from the published version and publish it when ready | no |
| openregister | rec-named-version | Work on a named draft version of a record and promote it once it is approved | no |
| openregister | rec-preview-site | Preview a draft record in the real public website before publishing it | no |

All three are in Open Register's own matrix, in its core area (records).

**rec-draft-publish.** No demand row. Competitors rated yes:
- directus (source read at v12.4.1, not driven): "directus:packages/system-data/src/fields/collections.yaml:158
  versioning toggle per collection; directus:api/src/services/versions.ts:319 save a
  delta to a version and :436 promote it to the main item".
- strapi (source read at v5.55.1, not driven): "strapi:packages/core/content-manager/admin/src/hooks/useDocumentActions.ts:360
  publishDocument, :316 discardDocument, :521 unpublishDocument".

**rec-named-version.** No demand row. Competitor rated yes, directus (source read at
v12.4.1, not driven): "directus:api/src/controllers/versions.ts:17 create a named
version (key, name) of an item; directus:api/src/services/versions.ts:436 promote
after review".

**rec-preview-site.** Demand: changelog,
https://github.com/strapi/strapi/releases/tag/v5.46.0. Competitor rated yes, directus
(source read at v12.4.1, not driven): "collection meta preview_url
directus:packages/system-data/src/fields/collections.yaml:141 drives a live preview
of the draft item in a split pane directus:app/src/modules/content/routes/item.vue:440,466".

## Why

`openspec/specs/content-versioning/spec.md` already requires it: "Objects MUST support
a draft/published lifecycle" and "Drafts MUST be promotable to published version",
with delta storage, conflict detection on promote, and the reserved key `main`. Both
requirements carry "Status: deferred: No DraftService or draft version entity found
in codebase". The matrix evidence agrees: `lib/Db/ObjectEntity.php` has no draft,
and nothing in `lib/Service/Object*` keeps a copy apart. The archived change
`2026-03-21-content-versioning` shipped without the capability, so the rows are
still `none` and this change builds it.

openregister ADR-006 makes publication an RBAC scope, not a data field. A draft here
is not a "published: false" flag. It is a working copy kept beside the live object;
the live object is what RBAC exposes, and promotion replaces it.

## What changes

- A draft store: one row per draft with object uuid, key, name, creator, base
  version, and the delta of changed fields.
- The routes the spec names: create, list, read, update and delete drafts under
  `/api/objects/{register}/{schema}/{id}/versions`, read with `?version=<key>`, and
  `POST .../versions/{key}/promote` with the 409 conflict answer.
- Drafts are excluded from lists and search unless the caller asks for them.
- A schema may declare `previewUrl`, a URL template with `{uuid}`, `{version}` and
  `{token}`. A caseworker creates a preview link for a draft: a read-only access link
  scoped to that draft, expiring after a set time. The public site fetches the draft
  through the link's public route.
- The object detail page gains a Drafts tab: create, edit, compare with the
  published version, open the preview, promote, discard.

## Consumers

- opencatalogi and portaliq render the public page; they read a draft through the
  preview link's route and show a preview banner.
- `records-change-held-for-approval` (this pass) stores a pending change as a draft
  and promotes it on approval.

## ADRs

- openregister ADR-006: publication stays an RBAC scope; a draft is a working copy.
- openregister ADR-003: create, promote and discard are audit facts; promote records
  the previous published state.
- hydra ADR-005: drafts are visible only to their creator and to users with write
  access, as the spec already says.
- hydra ADR-108 (public surface placement): the preview route is a public, read-only,
  token-scoped route with an expiry.

## Impact

- Delivers the deferred requirements of `content-versioning` and adds requirements
  for the preview and the drafts tab.
- Affected code: new `ObjectDraft` entity, mapper and migration, `DraftService`,
  a `VersionsController`, `ObjectService::find()` (`version` parameter),
  `lib/Db/AccessLink.php` (a draft subject type), `lib/Controller/AccessLinkPageController.php`
  public read, `src/views/object/ObjectDetails.vue` (new tab).
- Backwards compatible: an object without drafts behaves as today.
- Size: L. The tasks below stay within 20; if a builder finds it too large, split
  preview (section 4) into its own change.

## Out of scope

- Approval before a draft is promoted: `records-change-held-for-approval`.
- Scheduled promotion at a date. A flow on a schedule can call the promote route.
- Drafts of files. File versions are Nextcloud's.
