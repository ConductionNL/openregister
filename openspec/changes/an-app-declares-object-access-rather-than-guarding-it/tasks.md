# Tasks: an app declares object access rather than guarding it

## 1. The contract, written down

- [ ] 1.1 Document the declaration form in the schema `authorization`
      reference: the principal vocabulary, the `match` clause, `$userId`,
      `$user.groups`, `$organisation`, `$now` and `$contains`, with the
      assignee and assignees example.
- [ ] 1.2 State that an app-side guard needs a comment naming the expression
      it tried and why it failed.

## 2. The undecidable posture

- [ ] 2.1 Read `authorization.onUndecidable` at schema save, validate it
      against `closed` and `open`, default `closed`.
- [ ] 2.2 Apply it in `PermissionHandler` at every branch that today returns
      early on an unresolvable object or a caught throwable.
- [ ] 2.3 Apply it in `MagicRbacHandler` so the list path takes the same
      posture as the find path.
- [ ] 2.4 Log the unanswerable question with the missing input, at warning,
      under both postures.

## 3. Grant issuer

- [ ] 3.1 Record the issuer on a per-object grant.
- [ ] 3.2 Resolve the issuer's own access from their grant in
      `ObjectGrantResolver`, on both layers.
- [ ] 3.3 Revoke the derived access when the grant is revoked.

## 4. The orphan validator

- [ ] 4.1 Call `TokenGrantValidator::refusalFor()` on the grant issue path and
      return its reason to the caller.

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
