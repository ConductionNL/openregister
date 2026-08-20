# OpenRegister RBAC / IDOR Integration Tests

Multi-user authorization suite (PHASE-4) for the OpenRegister RBAC contract.

- Collection: `rbac.postman_collection.json` (33 requests, 35 assertions)
- Runner: `run-newman.sh`

## Run

```bash
# Foreground, serialised against other UI/API audits:
flock /tmp/uiaudit-openregister.lock bash run-newman.sh
```

The suite needs a non-admin user `e2euser` with a password satisfying
Nextcloud's default `password_policy` (minLength 10, e.g. `E2epass-1234`) —
the collection's own `Setup (admin)` folder self-provisions it via the OCS
user-provisioning API (`S0. Provision e2euser`, create-if-absent: tolerates
both "created" and "already exists"), so the collection is runnable standalone
(this is what CI does — it runs each `tests/integration/*.postman_collection.json`
directly with only admin creds, no separate `occ` setup step).

The runner additionally:
1. Idempotently creates the same non-admin user `e2euser` / `E2epass-1234` via
   `occ` before running (create-if-absent; the user is a reusable fixture and
   is **never deleted**) — a local-run convenience, redundant with but safe
   alongside the collection's own self-provisioning.
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
   objects (`3a`); the single-object read-by-id path, including its
   cross-schema UUID fallback, denies a cross-organisation read with a 404
   (`3b`, fixed — see "Findings" below). The Setup folder creates a second organisation (`S6b`)
   and switches admin's active org into it (`S6c`) just for the creation of
   object Z (`S7`), then switches back (`S7b`) — this makes object Z
   genuinely cross-org relative to `e2euser`, which stays in the default
   organisation throughout. Without this fixture, a fresh single-organisation
   Nextcloud instance (e.g. a from-scratch CI install) has only one
   organisation shared by every user, so `3a` would fail for environmental
   reasons (object Z would be same-org, not cross-org) and `3b` would falsely
   appear secure (a same-org read is *correctly* allowed on a default-open
   schema, for the wrong reason).
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

### FIXED (openregister#2137, security) — cross-org IDOR via the unfiltered cross-table fallback

Test `3b`. Commit `71c6b7e47` closed the **direct** scoped single-object read:
`MagicMapper::findInRegisterSchemaTable` now delegates to `MagicSearchHandler::
applyAccessControlToQuery`, so a request whose scoped lookup would return a
cross-org row correctly gets **no row** instead. The test was updated to assert
`403`/`404` — but this was apparently never live-verified against a genuinely
**second** organisation (this suite's own Setup folder only ever created ONE),
so the assertion went green without the scenario it claims to cover ever
actually executing cross-org.

Live-verifying it on NC32 with two real organisations (`S6a`-`S7b` above,
added PHASE-4b) showed the IDOR was still live, via a **different** code path:
`ObjectService::find()` catches the `DoesNotExistException` the (correctly
org-filtered) scoped lookup now raises and, because the identifier is a UUID,
retries via `MagicMapper::findAcrossAllMagicTables()` — a pre-existing
cross-schema fallback (commit `ab0da0133`, added to resolve relation UUIDs
when the URL's register/schema is stale). That fallback accepted
`_rbac`/`_multitenancy` parameters but **never used them** — its identifier
lookup (`locateIdentifierInMagicTables`/`fetchMagicRowById`) applied zero
org/RBAC filtering. It could not tell "the URL's register/schema was stale"
(its intended trigger) apart from "the scoped read was correctly denied by
RBAC/multitenancy" (this case) — both surfaced as the same
`DoesNotExistException` — so it unconditionally fell through and returned the
row anyway.

**Net effect (before the fix):** any authenticated non-admin user could read
any object by UUID across any organisation on this endpoint, regardless of the
scoped-path fix.

**Fix:** `MagicMapper::findAcrossAllMagicTables()` now re-runs the same
`MagicSearchHandler::applyAccessControlToQuery()` filters used by the scoped
path against the one candidate row it matched (scoped to that row's own
table). A row that fails those filters — cross-org, or RBAC-denied — is
treated exactly like "not found in this table" (the scan keeps looking at
remaining candidates, and a fully exhausted scan raises the same
`DoesNotExistException` as a genuine miss, so there is no existence leak and
no new distinguishable status code). Callers that explicitly pass
`_rbac=false`/`_multitenancy=false` (admin/system context) are unaffected. `3b`
now asserts the secure `404` and is live-verified against two genuinely
distinct organisations (see PHASE-4b Setup above).

### FIXED — `_owner` not persisted on magic-mapper create

Test `1g`. Objects created via the magic-mapper write path used to persist an
**empty** `_owner` column (the entity was stamped by `applyOwnerAttribution()`
but the value was not written to the magic table). Consequences were:
- the creating user **could not update their own object** — the update path's
  lock guard rejected it with a misleading `403 "does not have permission to
  unlock"`;
- ownership-based RBAC and owner-targeted notification fan-out were neutered
  for all magic-stored rows (protected only by org filtering, never by
  ownership).

Fixed alongside the cross-org IDOR in commit `71c6b7e47` —
`MagicMapper::prepareObjectDataForTable` no longer strips the server-stamped
owner. `1g` now asserts the secure/correct behaviour (`200`/`201`).
