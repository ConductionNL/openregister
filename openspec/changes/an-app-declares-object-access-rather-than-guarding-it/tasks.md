# Tasks: an app declares object access rather than guarding it

## 1. The contract, written down

- [x] 1.1 Document the declaration form in the schema `authorization`
      reference: the principal vocabulary, the `match` clause, `$userId`,
      `$user.groups`, `$organisation`, `$now` and `$contains`, with the
      assignee and assignees example.
- [x] 1.2 State that an app-side guard needs a comment naming the expression
      it tried and why it failed.
      Both in `docs/Features/access-control.md`, "An app declares object access;
      it does not guard it".

## 2. The undecidable posture

- [x] 2.1 Read `authorization.onUndecidable` at schema save, validate it
      against `closed` and `open`, default `closed`.
- [x] 2.2 Apply it in `PermissionHandler` at every branch that today returns
      early on an unresolvable object or a caught throwable.
- [x] 2.3 Apply it in `MagicRbacHandler` so the list path takes the same
      posture as the find path.
- [x] 2.4 Log the unanswerable question with the missing input, at warning,
      under both postures.

Build note (2): `UndecidablePosture` reads the key from the schema's OWN block,
because the resolved block is what failed. It is a control key in
`PermissionCatalogueInterface::CONTROL_KEYS`, and a block holding only
`onUndecidable` still cascades to its register (otherwise the posture would
switch the cascade off and never apply). The undecidable branches are the
unresolvable-authorization ones: `PermissionHandler::evaluatePermission()`, and
on the list side the filter, the row predicate conditions, the row verdict and
`callerQualifiesForAction()`. The tenancy helpers
(`admitsOrganisationlessRowsThroughCascade()`, `updateCrossesOrganisations()`)
keep refusing, because they decide whether to lift the organisation filter and
not whether to grant access. On 2.4: `open` logs at warning with the schema,
action, caller, object and the missing input. `closed` keeps the existing
fail-closed line unchanged, at error, which is above warning and names the
schema, action, caller and the missing input. That keeps the recorded golden
logs stable.

## 3. Grant issuer

- [x] 3.1 Record the issuer on a per-object grant.
- [x] 3.2 Resolve the issuer's own access from their grant in
      `ObjectGrantResolver`, on both layers.
- [x] 3.3 Revoke the derived access when the grant is revoked.

Build note (3): the issuer is the grant's own `sharedBy`, which
`ObjectSharingService::shareOnFolder()` already sets to the calling user, so
nothing new is stored. `ObjectGrantResolver::resolve()` reads a second pass,
`IManager::getSharesBy()` with reshares off, over the same principal share
types and through the same stored-folder check and widest-wins composition as
a received grant. The map it fills is the one both layers read (`isGranted()`
on the find path, `grantedObjectUuidsFor()` on the list path), so they cannot
disagree. A revoked share is no longer returned, so the issuer's access goes
with it; nothing is inferred from any other row. Link and email shares are
minted by the app's account (`ShareLinkService`), so they carry no human
issuer and are outside this pass. Test: `tests/Unit/Service/Rbac/ObjectGrantIssuerTest.php`.

## 4. The orphan validator

- [x] 4.1 Call `TokenGrantValidator::refusalFor()` on the grant issue path and
      return its reason to the caller.
      Already on main: `ConsumersController::grantRefusal()` calls
      `TokenGrantValidator::refusalFor()` on consumer create and update and
      answers its reason; the validator is no longer an orphan.

## 5. Tests

- [ ] 5.1 Unit tests: the four contract scenarios, both postures, issuer
      derive and revoke, and the validator refusals on the issue path. Doubles
      use `onlyMethods`.
- [ ] 5.2 `tests/e2e/api-direct/object-access-contract.spec.ts`, probing with
      an ordinary authenticated user who should be refused.
- [ ] 5.3 A test asserting `PermissionHandler` and `MagicRbacHandler` answer
      the same set for one declaration, which is the layer-agreement guard.

## 6. Consuming apps

- [ ] 6.1 dossiq: write the case `authorization` block in the installer,
      delete `Service/Sharing/CaseAccessPolicy` and `Service/CaseAccessGuard`,
      and drop the call sites to an ordinary read.
- [ ] 6.2 Name zaakafhandelapp, decidiq and keepiq as adopters in the
      connection registry follow-up, one PR each.
