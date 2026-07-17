---
kind: fix
depends_on: []
adr: openspec/architecture/adr-006-publish-is-rbac-scope.md
---

## Why

Several read-side controller endpoints carry `#[NoAdminRequired]` (login-only,
not admin-only) but were intended to be admin-only or object-RBAC-gated — their
sibling write endpoints in the same files are explicitly guarded, so this is a
semantic-auth gap, not a design choice. Any authenticated non-admin user can
read data the author meant to protect.

1. **Webhook config + delivery logs (HIGH).** `WebhooksController::index()`
   (`:211`), `show()` (`:321`), `logs()` (`:1070`), `logStats()` (`:1135`),
   `allLogs()` (`:1191`) have no auth check, while `update()`/`destroy()`/
   `test()`/`retry()` in the same file each do
   `if ($this->isCurrentUserAdmin() === false) return $this->forbiddenResponse();`
   with the comment "Webhook reconfiguration is admin-only (wave-3 C10)." The
   delivery-log rows (`lib/Db/WebhookLog.php:43-56`) store **unmasked**
   `payload`, `requestBody`, `responseBody`, and `url` — full business-object
   contents sent to external endpoints — readable by any logged-in user.

2. **Search-trail analytics (HIGH).** `SearchTrailController::statistics()`
   (`:449`), `popularTerms()` (`:478`), `activity()` (`:531`),
   `registerSchemaStats()` (`:561`), `userAgentStats()` (`:614`) are
   `@NoAdminRequired` with no body guard, while `index()`/`show()` call
   `requireAdmin()`. `SearchTrailService` does no per-user/org scoping, so a
   non-admin can pull instance-wide search history (query text, browsers,
   register/schema breakdowns). **Additionally — and more severe — four
   *destructive* SearchTrail endpoints are also `@NoAdminRequired` with no body
   guard: `cleanup()` (`:716`), `destroy()` (`:854`), `destroyMultiple()`
   (`:896`), and `clearAll()` (`:966`). Any logged-in user can delete
   instance-wide search-trail history, including `clearAll()` which wipes it
   entirely.** These are not read leaks but unauthenticated data destruction and
   MUST be gated in the same change.

3. **File download by id (HIGH).** `FilesController::downloadById()`
   (`:1182-1213`) bypasses the object-level read-RBAC that `show()` applies —
   its `resolveParentObjectForFile()` is a "best-effort stub that returns null"
   (acknowledged in the code comment `:1206-1212`), so the only gate is NC mount
   visibility (`Node::isReadable()`), not OR object RBAC.

4. **GraphiQL CSP relaxation for non-admins (MEDIUM).**
   `GraphQLController::explorer()` (`:189-203`) adds `unsafe-inline`,
   `unsafe-eval`, and a CDN script domain to the CSP, but is `@NoAdminRequired`,
   so every logged-in user gets an eval-enabled page — widening the blast radius
   of any stored-XSS or CDN compromise.

## What Changes

- Add the admin guard (`isCurrentUserAdmin()` → `forbiddenResponse()`) to the
  five webhook read endpoints and five search-trail analytics endpoints —
  matching their already-guarded siblings — OR scope results to the caller's
  organisation if non-admin read access is genuinely intended (decide per
  endpoint; default to admin-only to match author intent).
- Add the same admin guard to the four destructive SearchTrail endpoints
  (`cleanup()`, `destroy()`, `destroyMultiple()`, `clearAll()`) — these are
  admin-only unconditionally (no org-scoping alternative for a destructive
  history wipe).
- Implement real parent-object resolution in `resolveParentObjectForFile()` and
  gate `downloadById()` on `ObjectService::find(_rbac: true)` as `show()` does.
- Restrict `GraphQLController::explorer()` to admins, or self-host the GraphiQL
  assets to drop the CDN allowance and `unsafe-inline`/`unsafe-eval`.

## Impact

- Affected: `lib/Controller/WebhooksController.php`,
  `lib/Controller/SearchTrailController.php`,
  `lib/Controller/FilesController.php`, `lib/Service/File/FileValidationHandler.php`,
  `lib/Controller/GraphQLController.php`.
- Behavioural change: non-admins lose read access to webhook config/logs and
  search analytics; existing admin flows unaffected.
- Risk: a UI that currently renders webhook logs for non-admins would break —
  audit callers and gate the UI entry to admins in the same change.
