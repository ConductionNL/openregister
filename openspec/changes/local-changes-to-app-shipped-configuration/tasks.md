# Tasks: local-changes-to-app-shipped-configuration

## 1. The baseline

- [ ] 1.1 Store the shipped definition beside the live one on descriptor import, with the app and its version.
- [ ] 1.2 Record the baseline for registers, schemas and their declared configuration blocks.

## 2. The divergence

- [ ] 2.1 A read that reports, per part, whether it is unchanged, changed locally, changed upstream or changed on both sides.
- [ ] 2.2 Each diverged part names the actor and the moment of the local change.
- [ ] 2.3 The divergence is reported beside the effective-configuration explainer.

## 3. The guarded update

- [ ] 3.1 The import applies parts changed upstream only, and preserves parts changed locally only.
- [ ] 3.2 A part changed on both sides is reported as a conflict and is not applied.
- [ ] 3.3 A conflict is applied only on an explicit per-part decision, which is recorded.
- [ ] 3.4 An unattended upgrade completes, leaving conflicts unresolved and reported.

## 4. The way back

- [ ] 4.1 A route that resets a diverged part to the shipped baseline, with an actor and an audit entry.

## 5. Tests

- [ ] 5.1 Unit tests for the four states, the preserved local addition and the conflict that is not applied.
- [ ] 5.2 A test asserting that a repair-step upgrade does not overwrite a locally changed part.
- [ ] 5.3 An e2e over the divergence report after a local edit to a shipped schema.
- [ ] 5.4 Deduplication check (ADR-012) recorded in the PR body, naming the `schema-import` requirement this generalises.
