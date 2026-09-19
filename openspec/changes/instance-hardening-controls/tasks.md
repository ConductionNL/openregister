# Tasks: instance-hardening-controls

## 0. The hardening report and the floors (shipped)

- [x] 0.1 `HardeningPolicy`: the administered controls, the declared floors and the shipped baselines, read from app configuration (REQ-IHC-006).
- [x] 0.2 `PlatformSecurityReader`: Nextcloud's password policy, session policy and brute-force state, read never written.
- [x] 0.3 `HardeningReportService`: every control with its value, its source, its floor and whether the two agree; an unreadable control fails.
- [x] 0.4 `HardeningFloorGuard`: a change below the floor is refused, and a floor below the baseline is refused.
- [x] 0.5 `HardeningSettingsService`: the write path, with an audit row on the change and on the refusal.
- [x] 0.6 `GET /api/hardening/report`, `GET|PUT /api/hardening/floors`, `PUT /api/hardening/controls`, administrator-only.
- [x] 0.7 `SecurityService` reads the inbound ceiling through the policy, so the published number is the enforced one.
- [x] 0.8 `PublicApiCorsMiddleware` reflects an origin only when the allowlist is empty or holds it.
- [x] 0.9 `ThrottledSurfaces`: the six throttler actions named once, referenced by the six controllers.
- [x] 0.10 Unit tests for the policy, the guard, the report, the settings writer, the controller and the middleware.

## 1. The accepted statement

- [ ] 1.1 A statement with a version, published by an administrator (D-1).
- [ ] 1.2 Acceptance required before the application renders, recorded with user, version and time (D-1).
- [ ] 1.3 A new version asks every user again.

## 2. Elevation

- [ ] 2.1 A fresh authentication before the administration surface renders (D-2).
- [ ] 2.2 An administered expiry, refusing administration writes after it lapses (D-2).
- [ ] 2.3 Elevation written to the audit trail.

## 3. Scoped second factor and address binding

- [ ] 3.1 A register or schema declares that reading it requires a verified second factor (D-3).
- [ ] 3.2 A principal without one is refused, naming the requirement.
- [ ] 3.3 An address allowlist on the administration surface and on the API, refusing blankly.

## 4. Content safety

- [ ] 4.1 A notification to an unverified address carries a pointer and no case content (D-4).
- [ ] 4.2 Remote references in an inbound message are held until the reader allows them, per message, recorded.
- [ ] 4.3 A published surface declares whether it may be indexed, and the robots answer follows.

## 5. Privilege guards

- [ ] 5.1 Removing the last administrator of a register, schema or organisation is refused.
- [ ] 5.2 A grant over the administered share is held for a second administrator, both attempt and outcome recorded (D-5).
- [ ] 5.3 An expression resolves only allowlisted environment variables; anything else is absent and logged (D-7).
- [ ] 5.4 A reversible bar with a reason, keeping what the principal already wrote (D-6).
- [ ] 5.5 A credential value resolvable from an external secret manager through the broker, with custody unchanged.

## 6. Tests

- [ ] 6.1 `tests/e2e/ci/instance-hardening.spec.ts`: the statement on first use, a new version asking again, elevation before administration, the last-administrator refusal.
- [ ] 6.2 Unit tests: the elevated session expiry, the second-factor scope refusal, the blank address refusal, the unverified-recipient body, the held grant, the absent environment variable, the bar keeping history.
- [ ] 6.3 A regression test that an instance declaring none of this behaves as before.
- [ ] 6.4 `openspec validate instance-hardening-controls --strict`.

## 7. Hand over

- [ ] 7.1 Hand the statement and the second-factor scope to the dossiq lane, with the eighteen candidate ids.
- [ ] 7.2 Tell the cluster 66 lane that C-configuration-72 is answered by REQ-IHC-002 and needs no second elevated session.
