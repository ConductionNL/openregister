# Register & Schema read accessibility under app-group restriction

## Problem

Deployments want to restrict OpenRegister's **management surface** to a specific Nextcloud user group (e.g. "data engineers") using Nextcloud's built-in *"Limit app to groups"* setting (`occ app:enable openregister --groups <group>`). But OpenRegister is a **data platform**: its register/schema metadata and object data APIs are consumed by *other* Conduction apps (opencatalogi, decidesk, openconnector, …) on behalf of *every* user, not just the management group.

Nextcloud's app-group restriction is **all-or-nothing per user**: it gates route dispatch by `IAppManager::isEnabledForUser()`, which blocks **every non-public route** for any user outside the group — including admins not in the group, and the consuming apps' calls. The only routes that bypass it are those marked `#[PublicPage]`.

Empirically verified on a live instance (OR restricted to a group `rbactest` was not in):

| Endpoint | Attribute | user outside group | anonymous |
|---|---|---|---|
| `GET /api/registers` | `@NoAdminRequired` | **412 (blocked)** | 401 |
| `GET /api/schemas` | `@PublicPage` | 200 | 200 |
| `GET /api/objects/{register}/{schema}` | `@PublicPage` | 200 | 200 |

So today, when OR is group-restricted, **object reads and schema reads keep working** (already `@PublicPage`) but **register reads break** (`@NoAdminRequired` only) — and any consumer app that lists registers for a non-group user gets a 412.

## Context

- **Object & schema reads are already `@PublicPage`.** `ObjectsController::index/show` and `SchemasController::index/show` carry `#[PublicPage]` and rely on `ObjectService` / `PermissionHandler` to enforce `_rbac` + `_multitenancy` against the *resolved* session user. The register read endpoints (`RegistersController::index/show`) were never made public, so they are the outlier.
- **`@PublicPage` bypasses BOTH the login requirement AND the app-group restriction.** That is the mechanism we rely on — but it also exposes the endpoint to fully anonymous callers, which is not always desired for register/schema *definitions* (as opposed to published catalogue data).
- **Write gating is handled separately.** The companion security fix (`fix(security): gate register/schema create/update/delete to admin/manage`, OR PR #1949) keeps create/update/delete on `@NoAdminRequired` and enforces admin/manage authorization. Those write endpoints intentionally stay non-public, so the NC app-group restriction *also* limits management to the group — exactly the desired split. This change covers the **read** half only.
- **Published visibility already exists in the data model.** Registers and schemas carry `published` / `depublished` timestamp fields (see `Register`/`Schema` entities). This change reuses them as the anonymous-access gate rather than introducing a new flag.

## Proposed Solution

Make the **register read** endpoints `@PublicPage` (objects + schemas already are) so the entire read surface survives NC app-group restriction, and add a uniform **"logged-in unless published"** guard to the register/schema read controllers so the public-page exposure does not leak definitions to the open internet:

1. **`RegistersController::index` / `show`** — add `#[PublicPage]` (alongside existing `#[NoAdminRequired]` + `#[NoCSRFRequired]`).
2. **Read-visibility guard** (registers and schemas, `index` + `show`):
   - If a Nextcloud user is resolved on the request → serve normally (subject to existing RBAC / multitenancy scoping).
   - If the caller is **anonymous** → return only resources whose `published` is set and not `depublished` (a published register/schema). `index` filters the result set to published-only; `show` returns `401` for an unpublished resource and the resource for a published one.
3. **Writes unchanged** — create/update/delete stay `@NoAdminRequired` (non-public) and keep the admin/manage gating from PR #1949. Under NC app-group restriction they remain blocked for non-group users, which is the intended "management limited to the group" behaviour.
4. **Document the deployment model** — a short note in the auth/access docs explaining that group-restricting OpenRegister limits *management* to the group while register/schema/object **reads** remain reachable (anonymous only for published resources, logged-in users subject to RBAC).

## Capabilities

| Capability | Type | Action |
|---|---|---|
| `auth-system` | backend | **Modified** — adds requirements for register/schema read endpoint accessibility under NC app-group restriction, the `#[PublicPage]` read surface, and the "logged-in unless published" anonymous-access guard |

## Out of scope

- The write-authorization fix (admin/manage gating on create/update/delete) — shipped in OR PR #1949.
- Any change to object-level RBAC / multitenancy (already enforced by `ObjectService` / `PermissionHandler`).
- A new per-resource visibility flag — this change reuses the existing `published`/`depublished` fields.
