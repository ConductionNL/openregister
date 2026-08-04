## 1. Audit current callers

- [ ] 1.1 Enumerate every caller of `RegisterMapper`/`SchemaMapper` create/update/delete and of the `find()` read paths. Note which are user-facing (must be RBAC-checked) vs internal/system (may legitimately pass `_rbac: false`). Record findings even if "all callers should be checked."

## 2. Re-enable mutation RBAC

- [ ] 2.1 Uncomment / restore `verifyRbacPermission('create', 'register')` at `RegisterMapper.php:558`, `('update', ...)` at `:647`, `('delete', ...)` at `:740`.
- [ ] 2.2 Same for `SchemaMapper.php:599` (create), `:1509` (update), `:1589` (delete).
- [ ] 2.3 For any internal system caller that must bypass, pass an explicit `_rbac: false` at that call site with a comment — do not disable the check globally.

## 3. Resolve the read-path "solr hotfix"

- [ ] 3.1 Per ADR-007 (Solr removed), re-enable read RBAC at `RegisterMapper.php:246,500` and `SchemaMapper.php:261,540`, or replace the global bypass with explicit per-caller `_rbac: false` where genuinely required.
- [ ] 3.2 Remove the "uncomment when ready" comments once resolved.

## 4. Verification

- [ ] 4.1 Unit/integration test with a multi-role fixture: an org member WITHOUT the register-write role gets 403 on create/update/delete; an owner/admin succeeds.
- [ ] 4.2 Regression: opencatalogi + softwarecatalog register/schema flows (run as admin/owner) still pass.
- [ ] 4.3 `composer check:strict` passes.

## Acceptance criteria

- No `verifyRbacPermission()` call on the register/schema mutation surface is
  commented out; each is either active or replaced by an explicit, documented
  internal-bypass call site.
- A non-privileged org member cannot create, update, or delete registers/schemas.
- No "remove this hotfix for solr" comments remain in the mappers.
