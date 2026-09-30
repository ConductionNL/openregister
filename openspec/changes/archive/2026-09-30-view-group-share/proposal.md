---
kind: code
---

# Proposal: view-group-share

## Summary

Let a saved view be shared with a group in read or write mode. A View has
`isPublic`, `isDefault` and `favoredBy` and nothing in between: a view is
private or it is everyone's. nextcloud-vue's `saved-views-shared-by-role`
specifies the control ("a group multiselect and a read or write mode per
selected group") and says the persistence "is proposed in the OpenRegister
repo" without naming a change. No such change exists. This is it.

## Ledger rows

| row | capability | rating | size |
|---|---|---|---|
| 9.4 | Shared saved searches with department or role permissions | no | M (this half S) |

The register marks the row covered by the nextcloud-vue change and its slug
reads "none needed (openregister View gains a group share)". The coverage
check for this programme found no openregister artefact behind that
parenthesis, so the row is uncovered on this side and gets a change.

## Why

The register's note: "nearest `allowSavedViews` (personal only)". The best
competitor, verbatim from the `best` column: "xxllnc Zaken:
`backend/zaken/src/zsnl_domains/case_management/entities/saved_search.py`
(`_round2/compare/M1-functionality.md`)".

The register's `why`: "a view shared with a group carrying its columns is
the View entity plus CnSavedViewsControl".

## What changes

- A View gains `sharedWith`: a list of `{ "group": "<gid>", "mode": "read" | "write" }`.
- `GET /api/views` returns the caller's own views, the views shared with a
  group the caller is in, and public views, each with `@self.access`
  (`owner`, `write`, `read`).
- A `write` share lets a member update the query, presentation and alert of
  the view but not its `sharedWith`, `owner` or deletion; a `read` share
  lets a member apply and favourite it.
- The owner, or an administrator, edits `sharedWith`. Sharing with a group
  the owner is not in is allowed; sharing with a group that does not exist
  is refused.
- A view's `presentation` (columns, sort) travels with the share, so a
  department view lands with its columns.

## Consumers

- dossiq: ship department views for Cases as seeded shared views. Specified
  in dossiq by the dossiq lane (register row 9.4).
- nextcloud-vue: `saved-views-shared-by-role` consumes the response shape.
- Every index page in the fleet.

## ADRs

- ADR-022.
- openregister ADR-010 (permission verbs): `read` and `write` on a view,
  no third verb.
- openregister ADR-002 (organisation tenancy): a share never crosses the
  organisation of the view.

## Impact

- Extends: `saved-search-views` REQ-001 and REQ-002.
- Affected code: `lib/Db/View.php` (`sharedWith`), `ViewMapper` (list by
  membership), the views controller (access, guards), one migration.
- Size: S.
