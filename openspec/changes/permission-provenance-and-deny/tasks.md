# Tasks: permission-provenance-and-deny

## 1. The catalogue

- [ ] 1.1 `GET /api/permissions`: every grantable verb with the app that declared it, the scope levels it may be granted at and a sentence in plain language (D-1).
- [ ] 1.2 An app declares its custom verbs; a declared verb with no evaluator is refused and names the app, an evaluator with no declaration is reported in the RBAC settings (D-2).
- [ ] 1.3 A role's `actions` array and every authorization block are validated against the catalogue; an unknown verb fails the save with 422 naming the verb.

## 2. Deny

- [ ] 2.1 An authorization entry may name a verb as denied for a group, a role or an object; the schema of the block accepts it and the validator refuses a grant and a deny on the same principal at the same level, naming both (D-3).
- [ ] 2.2 `PermissionHandler`: a deny removes the verb inside its scope and is not overridden by a broader grant, including a grant inherited from an ancestor object (D-3).
- [ ] 2.3 `MagicRbacHandler`: the same deny term in the list filter, so a list and an object read agree (D-6).
- [ ] 2.4 `manage` on a register cannot be denied to the last principal holding it; the refusal names what would be orphaned (D-4).

## 3. Provenance

- [ ] 3.1 `GET /api/scopes` reports, per action, the rule that granted it: register default, schema rule, role, per-object grant or the ancestor it came from. The `actions` list keeps its shape (D-5).
- [ ] 3.2 An action a broader rule would have granted and a deny removed is reported with that deny, so the absence has a reason.
- [ ] 3.3 The scope audit reports per rule as well as per schema and action, and the denial log names the rule rather than only the decision.

## 4. Tests

- [ ] 4.1 `tests/e2e/ci/permission-provenance-and-deny.spec.ts`: grant read on a register, deny read on one object, read the list and the object, and read the provenance for both answers.
- [ ] 4.2 Unit tests: deny over an inherited grant, deny over a role grant, the grant-and-deny-at-one-level refusal, the unknown verb, the last `manage` holder, and a list filter that matches the per-object answer on a tree of depth 5.
- [ ] 4.3 A regression test that an instance declaring no deny and no custom verb resolves exactly as before.
- [ ] 4.4 `openspec validate permission-provenance-and-deny --strict`.

## 5. Hand over

- [ ] 5.1 Hand the catalogue to the dossiq lane as soon as 1.1 answers, with the register row id: the mandate matrix names its grantable set and the role editor gains its permission half (D-7).
