## 1. Webhook read surface

- [ ] 1.1 Add `$d = $this->requireAdmin()` / `isCurrentUserAdmin()` guard to `WebhooksController::index()` (`:211`), `show()` (`:321`), `logs()` (`:1070`), `logStats()` (`:1135`), `allLogs()` (`:1191`), matching the write-endpoint pattern in the same file.
- [ ] 1.2 If non-admin read is intended for some, scope `WebhookMapper`/`WebhookLog` queries to the caller's organisation instead of a blanket admin gate — decide per endpoint; default admin-only.
- [ ] 1.3 Confirm any frontend webhook-log view is gated to admins.

## 2. Search-trail analytics

- [ ] 2.1 Add the `requireAdmin()` guard to `SearchTrailController::statistics()` (`:449`), `popularTerms()` (`:478`), `activity()` (`:531`), `registerSchemaStats()` (`:561`), `userAgentStats()` (`:614`).
- [ ] 2.2 Add the `requireAdmin()` guard to the four destructive SearchTrail endpoints — `cleanup()` (`:716`), `destroy()` (`:854`), `destroyMultiple()` (`:896`), `clearAll()` (`:966`) — unconditionally admin-only (no org-scope alternative for a history wipe). Re-verify line numbers against HEAD before editing.

## 3. File download RBAC

- [ ] 3.1 Implement real parent-object resolution in `FilesController::resolveParentObjectForFile()` (replace the null-returning stub, `:1206-1212`).
- [ ] 3.2 Gate `downloadById()` (`:1182-1213`) on `ObjectService::find(..., _rbac: true)` for the resolved parent, matching `show()` (`:384-385`); deny when the caller lacks object read permission.

## 4. GraphiQL explorer

- [ ] 4.1 Restrict `GraphQLController::explorer()` (`:189-203`) to admins, OR self-host the GraphiQL assets and remove the `unsafe-inline`/`unsafe-eval`/CDN CSP allowances.

## 5. Verification

- [ ] 5.1 Test: non-admin gets 403 on all five webhook read endpoints, all five search-trail analytics endpoints, and all four destructive search-trail endpoints (cleanup/destroy/destroyMultiple/clearAll).
- [ ] 5.2 Test: non-admin without object read permission gets 403 from `downloadById()`; a permitted user succeeds.
- [ ] 5.3 Test: `explorer()` is admin-only (or serves no CDN/eval CSP).
- [ ] 5.4 `composer check:strict` passes.

## Acceptance criteria

- No `@NoAdminRequired` read endpoint on webhooks or search-trail analytics
  returns data to a non-admin (unless explicitly org-scoped).
- `downloadById()` enforces object-level read RBAC equivalent to `show()`.
- The GraphiQL explorer no longer grants `unsafe-eval`/`unsafe-inline` to
  non-admins.
