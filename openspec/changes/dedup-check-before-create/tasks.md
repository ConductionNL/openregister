# Tasks: dedup-check-before-create

## 1. Check

- [ ] 1.1 Candidate-versus-stored scoring in the dedup service, bounded by the blocking keys and the cap.
- [ ] 1.2 Route `POST .../dedup-check` with the schema's read RBAC.

## 2. Policy

- [ ] 2.1 `onCreate` and `overrideGroups` in the annotation validator.
- [ ] 2.2 `block` and `_dedupOverride` in `SaveObject`; `dedup.overridden` audit entry.

## 3. Tests

- [ ] 3.1 Unit tests for scoring parity with the service, the 409, the override and the audit entry; Newman for the route.
