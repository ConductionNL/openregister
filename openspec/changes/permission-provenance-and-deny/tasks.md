# Tasks: permission-provenance-and-deny

## 1. The catalogue

- [x] 1.1 `GET /api/permissions`: every grantable verb with the app that declared it, the scope levels it may be granted at and a sentence in plain language (D-1).
- [x] 1.2 An app declares its custom verbs; a declared verb with no evaluator is refused and names the app, an evaluator with no declaration is reported in the RBAC settings (D-2).
- [x] 1.3 A role's `actions` array and every authorization block are validated against the catalogue; an unknown verb fails the save with 422 naming the verb.

## 2. Deny

> The deny ships staged, not enforcing. Section 9 carries the rollout, and
> nothing in this section refuses anybody until `openregister.deny_enforcement`
> is set to `enforcing` (D15, 2026-09-14).

- [x] 2.1 An authorization entry may name a verb as denied for a group, a role or an object; the schema of the block accepts it and the validator refuses a grant and a deny on the same principal at the same level, naming both (D-3).
- [x] 2.2 `PermissionHandler`: a deny removes the verb inside its scope and is not overridden by a broader grant, including a grant inherited from an ancestor object (D-3).
- [x] 2.3 `MagicRbacHandler`: the same deny term in the list filter, so a list and an object read agree (D-6).
- [x] 2.4 `manage` on a register cannot be denied to the last principal holding it; the refusal names what would be orphaned (D-4).

## 3. Provenance

- [x] 3.1 `GET /api/scopes` reports, per action, the rule that granted it: register default, schema rule, role, per-object grant or the ancestor it came from. The `actions` list keeps its shape (D-5).
- [x] 3.2 An action a broader rule would have granted and a deny removed is reported with that deny, so the absence has a reason.
- [x] 3.3 The scope audit reports per rule as well as per schema and action, and the denial log names the rule rather than only the decision. `GET /api/permissions/scope-audit` reports per rule and keeps the per-action index beside it; every refusal logs the rule for the verb and why it did not answer.

## 4. Tests

- [x] 4.1 `tests/e2e/ci/permission-provenance-and-deny.spec.ts`: grant read on a register, deny read on one object, read the list and the object, and read the provenance for both answers. Written and mode-aware; NOT run here, because this host has no Playwright. CI runs it.
- [x] 4.2 Unit tests: deny over an inherited grant, deny over a role grant, the grant-and-deny-at-one-level refusal, the unknown verb, the last `manage` holder, and a list filter that matches the per-object answer on a tree of depth 5. The role grant and the grant held outside the block are in `PermissionHandlerDenyOverGrantChainTest`, the depth-5 agreement in `MagicRbacHandlerDepthAndScaleTest`, the two save-time refusals in `AuthorizationDenyValidatorTest` and `SaveTimeRefusalsInEveryModeTest`, the unknown verb in `PermissionCatalogueTest`.
- [x] 4.3 A regression test that an instance declaring no deny and no custom verb resolves exactly as before.
- [x] 4.4 `openspec validate permission-provenance-and-deny --strict`.

## 5. Hand over

- [x] 5.1 Hand the catalogue to the dossiq lane as soon as 1.1 answers, with the register row id: the mandate matrix names its grantable set and the role editor gains its permission half (D-7). Handed over in ConductionNL/dossiq#2792, with row Q13.25, the response shape, the three reads beside it and the staging caveat.

## 6. Discovery wave 1: access inside the query

- [x] 6.1 Grants, inheritance and denies are compiled into the object query as predicates; page, total and facet counts are computed over the permitted set (D-8).
- [x] 6.2 The same predicates are applied in the search index path, so search and list agree.
- [x] 6.3 A performance test on a tree of depth 5 and 100,000 objects, proving the filter is in the query plan. `MagicRbacHandlerDepthAndScaleTest` asserts the predicate is in the emitted WHERE clause and that the SQL is byte-identical for a tree of five rows and one of 100,000, so no row is read to produce it. A unit run has no database, so the term is proved present rather than read back out of `EXPLAIN`.

## 7. Discovery wave 1: what you may do, and who may do it

- [x] 7.1 An object read carries the actions the current user may take on it, from the same resolution (D-9). `@self.actions` on the single-object read, resolved by `PermissionHandler::permittedActionsFor()`.
- [x] 7.2 `GET /api/objects/{register}/{schema}/{id}/permissions`: the principals holding rights on the object, each with the rule behind the grant (D-10). Reading the object is not enough to read the set: the owner, an administrator or a holder of `manage`.
- [x] 7.3 The history of that set is readable: who held which right, when it changed and which rule changed it. `GET .../permissions/history?at=`, read from the object's audit trail rather than from a second table.
- [x] 7.4 Two roles are readable side by side against the catalogue, showing which permissions differ.

## 8. Discovery wave 1: derived, scoped and expiring grants

- [ ] 8.1 A rule maps identity provider claims to roles and scopes at login, in the declared rule shape.
- [ ] 8.2 A grant may carry an end, including one bound to a workflow step's deadline; an expired grant is not resolved and needs no sweep (D-11).
- [ ] 8.3 A change to a rule that derives access re-runs the derivation and reports how many grants changed (D-11).
- [ ] 8.4 `manage` may be scoped to a named area, so delegated administration is not a second administrator.
- [x] 8.5 Hand the catalogue's destroy verb to `delete-window-and-recorded-destruction`, which consumes it under D10. `destroy` is canonical in the catalogue, so a block or a role naming it saves; `DestroyRightService` already resolves it through `PermissionHandler`.

## 9. The rollout: staging first (D15)

- [x] 9.1 `openregister.deny_enforcement` takes `off`, `staging` or `enforcing`. The default is `staging`, and a value nobody declared reads as `staging` rather than as `enforcing` (D-12).
- [x] 9.2 All four enforcement paths read that one switch: the object read, the relation-path check and both list emitters. Below `enforcing` no deny predicate reaches the query, so no total and no facet count moves.
- [x] 9.3 A staged denial is recorded with the rule that carries it, the principal it names, the verb and the caller. Staging still resolves; only the verdict is dropped.
- [x] 9.4 The provenance carries the staged deny beside the grant, so the field that says why a person may act also says what is about to stop them.
- [x] 9.5 `GET /api/permissions/deny-preview` reports what enforcement would refuse, read from the rules as written rather than from what has fired, so a deny nobody has hit yet is still in the report.
- [x] 9.6 Unit tests: the default is staging, a staged deny grants and records, `off` grants and records nothing, the same fixture enforcing refuses, and no staged deny reaches the list SQL.
- [x] 9.7 The save-time refusals are NOT staged: a grant-and-deny collision and an orphaned `manage` are refused in every mode. Regression test.
