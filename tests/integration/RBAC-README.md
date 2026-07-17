# OpenRegister RBAC / IDOR Integration Tests

Multi-user authorization suite (PHASE-4) for the OpenRegister RBAC contract.

- Collection: `rbac.postman_collection.json` (32 requests, 34 assertions)
- Runner: `run-newman.sh`

## Run

```bash
# Foreground, serialised against other UI/API audits:
flock /tmp/uiaudit-openregister.lock bash run-newman.sh
```

The runner:
1. Idempotently creates a non-admin user `e2euser` / `e2epass` via `occ`
   (create-if-absent; the user is a reusable fixture and is **never deleted**).
2. Runs the collection with `--ignore-redirects` and the standard headers
   (`Accept: application/json`, `OCS-APIRequest: true`).
3. Prefers host `newman` against `http://localhost:8080`; falls back to
   in-container `newman` against `http://localhost`.

Test register / schemas / objects are created in a Setup folder and removed in
a Teardown folder, so the suite is self-cleaning.

> Every request sets `protocolProfileBehavior.disableCookies = true`. Without
> it, the Nextcloud session cookie set by an admin request carries over in
> Newman's cookie jar and silently authenticates later `e2euser` / anonymous
> requests **as admin**, defeating the entire multi-user assertion.

## The OpenRegister RBAC model (as implemented)

Authorization is enforced by OpenRegister centrally
(`lib/Service/Object/PermissionHandler.php` + the magic-mapper SQL path in
`lib/Db/MagicMapper/MagicRbacHandler.php`). Leaf apps own no object-level authz.

- **Schema-level authorization.** Each schema carries an `authorization` JSON
  block keyed per CRUD action (`read` / `create` / `update` / `delete`), each
  mapping to a list of group ids (or conditional rules).
- **Default-OPEN.** A schema with **no** `authorization` block grants every
  authenticated user every action. Anonymous **reads** are also default-open;
  only `create` / `update` / `delete` fail closed for anonymous callers
  (openregister#1955).
- **Ownership only ADDS rights.** The object owner is always allowed on their
  own object — but ownership does **not** restrict other users. IDOR protection
  comes from a schema that **scopes reads** to a non-public group, and/or from
  organisation (multitenancy) filtering — not from ownership alone.
- **`public` group = publish.** Granting the `public` group `read` on a schema
  makes its objects anonymously discoverable ("published"). A schema that scopes
  reads to a non-public group hides its objects from anonymous callers — this is
  the mechanism behind the opencatalogi "draft hidden from anon" behaviour.
- **Organisations (multitenancy).** Each user has an active organisation. The
  **list** path filters objects by organisation. Admin requests run with RBAC
  **and** multitenancy disabled.
- **App settings are admin-gated** by the Nextcloud SecurityMiddleware default
  (controllers without `@NoAdminRequired`), so non-admins get 403 and anonymous
  callers get 401.

## What the suite asserts

1. **Ownership / IDOR** — a non-admin GET/PUT/DELETE on another user's object
   (scoped schema) returns 403/404, never 200; the non-admin can create + read
   their own object.
2. **Public-group publish** — a published object (public `read` grant) is
   readable and listable anonymously; a non-published (scoped) object is hidden
   from the anonymous context.
3. **Cross-org isolation** — the list path filters out another organisation's
   objects.
4. **App admin-gated settings** — `PUT /api/settings` → 403 for a non-admin,
   401 for anonymous, 200 GET for admin.

## Findings captured by this suite

### FIXED — RBAC-denied single-object read returned HTTP 500

`PermissionHandler::checkPermission()` threw a generic `\Exception` on denial,
which escaped `ObjectsController::show()` (only `DoesNotExistException` was
caught) and surfaced as a **500**. Fixed by throwing the domain
`NotAuthorizedException` (extends `\Exception`, so existing `catch (Exception)`
sites stay compatible) and catching it in `show()` to return **404** (chosen
over 403 so an unauthorized caller cannot distinguish "forbidden" from "absent",
avoiding existence leakage). Verified live: scoped read now 404, admin/public
reads unaffected.

### QUARANTINED (security) — cross-org IDOR on the single-object read path

Test `3b`. On a **default-open** schema the single-object GET
(`show` → `ObjectService::find` → `PermissionHandler`) does **not** compare the
object's organisation against the caller's active organisation. The **list**
path does (test `3a` passes). A non-admin in org B can therefore read an admin
object in org A **by id**, even though it is filtered out of their list. The
test pins the current insecure behaviour (200) so the regression is tracked.
**FIX-ME (openregister):** enforce multitenancy in the single-object read path,
then change `3b` to expect `403/404`.

### QUARANTINED (correctness/security) — `_owner` not persisted on magic-mapper create

Test `1g`. Objects created via the magic-mapper write path persist an **empty**
`_owner` column (the entity is stamped by `applyOwnerAttribution()` but the
value is not written to the magic table). Consequences:
- the creating user **cannot update their own object** — the update path's lock
  guard rejects it with a misleading `403 "does not have permission to unlock"`;
- ownership-based RBAC and owner-targeted notification fan-out are neutered for
  all magic-stored rows (they are protected only by org filtering, never by
  ownership).

The test pins the current behaviour (403). **FIX-ME (openregister):** persist
`_owner` on the magic-mapper create path, then change `1g` to expect `200/201`.
