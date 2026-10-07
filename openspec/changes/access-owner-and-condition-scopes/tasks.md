# Tasks: access-owner-and-condition-scopes

## 1. Enforcement

- [ ] 1.1 `AuthorizationBlock::normalise()` with the rules of design D-1 and D-2. Verify: `tests/Unit/Service/Authorization/AuthorizationBlockTest.php` for `["@creator"]`, `["@creator", "redactie"]`, a condition with a literal, a condition with `@user.uid`, and a condition on an undeclared field.
- [ ] 1.2 `MagicRbacHandler` and `PermissionHandler` read the normalised block. Verify: `MagicRbacHandlerTest` lists only the caller's rows for `["@creator"]`; `PermissionHandlerTest` refuses a read of another user's row and allows the owner's.

## 2. Capability

- [ ] 2.1 `AuthorizationCapability` registered in `Application::register()`. Verify: unit test on the capability array, and `GET /ocs/v2.php/cloud/capabilities` in Newman shows the three kinds.

## 3. Proof and docs

- [ ] 3.1 Add `tests/e2e/ci/access-own-records.spec.ts`: two users create records in a schema with `read: ["@creator"]` and each lists only their own.
- [ ] 3.2 Document `@creator` and `conditions` in `docs/` beside the authorization block.

Acceptance:
- The capability is never advertised by a build that does not enforce both kinds.
