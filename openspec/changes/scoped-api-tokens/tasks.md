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

## Discovery cluster 40

- [ ] C40.1 A required end date at issue, enforced at use, refused without one (D-C40-1).
- [ ] C40.2 A warning to the holder before a token lapses, and a recorded renewal.
- [ ] C40.3 A service account principal owned by a team, holding grants and tokens, with no interactive sign-in (D-C40-2).
- [ ] C40.4 A per-token rate limit, refused over it and named in the refusal.
- [ ] C40.5 An administered outbound allowlist checked at save (D-C40-3).
- [ ] C40.6 Tests: the missing end date refusal, the expired token, the leaver who does not break the integration, the interactive sign-in refusal, the allowlist refusal at save.
- [ ] C40.7 Hand over to the dossiq and integriq lanes with candidate ids C-access-and-privacy-35, -42, -43, -44 and -70, noting that C-access-and-privacy-35 is already answered by `account-self-service`.
- [ ] C40.8 Report the inherited defect in `specs/auth-system/spec.md`: a requirement header outside the `## Requirements` section at line 888, which makes archive refuse every delta against the spec. Debt sweep, not this change.
