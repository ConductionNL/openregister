# Tasks: scoped-api-tokens

## 1. Data

- [ ] 1.1 `grant` on the personal token store and on `Consumer` with a migration; validator (verbs, match grammar, no `manage`, not wider than the issuer).

## 2. Evaluation

- [ ] 2.1 Grant layer in `PermissionHandler` and the SQL RBAC builder; `@self.tokenSubject` variable.
- [ ] 2.2 `actorVia` on audit entries; `whoami` reports the effective grant.

## 3. Surfaces

- [ ] 3.1 Grant editor with object preview on the personal token page and the Consumer admin page.

## 4. Tests

- [ ] 4.1 `tests/e2e/ci/scoped-token.spec.ts`: issue a scoped token, list and write with it.
- [ ] 4.2 Unit tests for the validator, the intersection, list filtering and `actorVia`; Newman with a scoped token.
