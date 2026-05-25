# Zoeken en Filteren — saved-view CRUD lifecycle (ViewService)

> Reverse-spec delta. `ViewService` is the management lifecycle for the saved-view definitions that the canonical `zoeken-filteren` `View-based search composition` requirement *consumes* (`SearchQueryHandler.applyViewsToQuery()`). The bundle nominally targeted `no-code-app-builder`, but that local spec is a redirect stub and `faceting-configuration` covers facet computation, not view CRUD — so this is retargeted to `zoeken-filteren`. One NEW requirement, drafted from observed `ViewService` behaviour only.

## Why

`zoeken-filteren` specifies how saved views are *applied* to a search but not how they are *managed*. `ViewService` ships the access-controlled CRUD lifecycle (find / findAll / create / update / delete) plus the single-default-view-per-user invariant, which no existing requirement covers.

## ADDED Requirements

### Requirement: Saved views MUST be managed through an access-controlled CRUD lifecycle with a single default per user

`ViewService` MUST manage saved `View` definitions (the `{registers, schemas, filters, searchTerms}` query objects consumed by `SearchQueryHandler.applyViewsToQuery()`) with owner/public access control and a single-default-view-per-user invariant.

- `find(id, owner)` MUST load the view by id and grant access only when the caller is the owner OR the view is public; otherwise it MUST throw `DoesNotExistException("View not found or access denied")` (denial is indistinguishable from not-found, so a private view's existence is not leaked).
- `findAll(owner)` MUST return the views the user owns or that are public, delegating to `ViewMapper::findAll(owner:)`.
- `create(name, description, owner, isPublic, isDefault, query)` MUST persist a new `View`; when `isDefault` is `true` it MUST first clear any existing default for that owner so at most one default view exists per user; `favoredBy` MUST be initialised to an empty list.
- `update(id, name, description, owner, isPublic, isDefault, query, favoredBy?)` MUST resolve the view via the access-controlled `find()`, and when it is being newly promoted to default (`isDefault` true and previously false) MUST clear the owner's existing default first; `favoredBy` MUST be updated only when explicitly provided (non-null).
- `delete(id, owner)` MUST resolve the view via the access-controlled `find()` before deleting, so a caller cannot delete a view they cannot access.
- The single-default invariant MUST be enforced by `clearDefaultForUser(owner)`, which unsets `isDefault` on every default view owned by that user.
- All write operations MUST log and re-throw on failure (the persisted state is the mapper's; the service does not swallow errors).

#### Scenario: Access control on find hides other users' private views
- **GIVEN** a private view owned by `alice`
- **WHEN** `find(id, owner: "bob")` is called and the view is not public
- **THEN** the method MUST throw `DoesNotExistException("View not found or access denied")`
- **AND** when `bob` is the owner OR the view is public, the view MUST be returned

#### Scenario: Creating a default view clears the previous default
- **GIVEN** user `alice` already has a default view
- **WHEN** `create(..., isDefault: true, ...)` is called for `alice`
- **THEN** `clearDefaultForUser("alice")` MUST run first so the prior default is unset
- **AND** exactly one of `alice`'s views MUST have `isDefault = true` afterwards
- **AND** the new view's `favoredBy` MUST be an empty list

#### Scenario: Update only clears the default when newly promoting
- **GIVEN** a view that is currently not the default
- **WHEN** `update(..., isDefault: true, ...)` promotes it to default
- **THEN** the owner's existing default MUST be cleared first
- **AND** when the view was already default (`isDefault` unchanged) no extra clear MUST occur
- **AND** `favoredBy` MUST be changed only when a non-null `favoredBy` argument is supplied

#### Scenario: Delete is access-controlled
- **GIVEN** a view the caller does not own and that is not public
- **WHEN** `delete(id, owner)` is called
- **THEN** the access-controlled `find()` MUST throw before any deletion occurs
- **AND** an owned-or-public view MUST be deleted via `ViewMapper::delete()`

## Notes

- Access control is owner-or-public only; there is no group/RBAC layer on views themselves (a public view is readable by every authenticated caller). The query a view encodes is still subject to object-level RBAC when executed via `SearchQueryHandler`.
- `find()` deliberately collapses access-denied into not-found, which avoids leaking the existence of other users' private views.
