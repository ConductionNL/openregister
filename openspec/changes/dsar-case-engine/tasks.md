# Tasks — dsar-case-engine (kind: code, depends_on: dsar-case-subsystem)

Imperative successor to the declarative head. Consumes the head's `dataSubjectRequest` register
(case entity, lifecycle graph incl. the `finaliseDenial` `requires` binding, denial-ground enum,
evidence/redaction sub-collection shapes, retention windows). No new OR schema is added.

## 1. Evidence collection

<<<<<<< HEAD
- [ ] 1.1 Add an `EvidenceSourceProvider` interface (identify via stable `sourceId`; harvest evidence items for a case) and a registry that leaf apps register providers into (ADR-019); OR core enumerates only registered providers.
- [ ] 1.2 Add an async harvest service that collects from registered providers, dedups items by `contentHash`, writes each item (`sourceId`/`contentHash`/`status`) onto the case's declared `evidence` sub-collection via `ObjectService` (RBAC + multitenancy), and audits each attach through `AuditTrailMapper` pinned to the DSAR processing activity.

## 2. Export bundle

- [ ] 2.1 Add an export-bundle service that assembles via `DataSubjectRequestService::assembleAccessExport` (no re-implementation, ADR-011); select + vendor a PAdES-LTV signing library (in scope, isolated behind the service as a swappable dependency), sign the bundle with PAdES-LTV, and attach a SHA-256 content hash; audit the generation.
- [ ] 2.2 Add the one-time secure download: mint a single-use, time-boxed token; burn it on first successful download; refuse replay; keep the download authenticated + case-scoped (never `@PublicPage`).
- [ ] 2.3 Add regulator-dossier assembly from the case's evidence, redaction records (with grounds), and audit-trail history, read through the RBAC-scoped case object.

## 3. Redaction write path

- [ ] 3.1 Add a redaction-write service that applies a field-level redaction, records a `redactions` entry (`field`/`before`/`after`/`ground`) on the case via `ObjectService`, audits it, and stays distinct from `DataSubjectRequestService::erase(mode=pseudonymise)` (MUST NOT invoke the erase path).

## 4. Denial finalise guard

- [ ] 4.1 Add the `finaliseDenial` lifecycle guard class (the FQCN the head's transition references via `requires`, ADR-031 §3): refuse finalise when `regulatorReference` is empty, permit it when present, never gate `draftDenial`, and fail closed.

## 5. Retention sweep

- [ ] 5.1 Add a retention-sweep `TimedJob` (mirroring `AvgRetentionJob`/`OCP\BackgroundJob\TimedJob` with `IAppConfig` enabled + dry-run toggles) that hard-deletes cases past `retainUntil`, scrubs evidence PII via `DataSubjectRequestService::erase(mode=pseudonymise)`, and audits each destructive action.
- [ ] 5.2 Make the sweep legal-hold aware: consult `RetentionService::hasActiveLegalHold` / `validateNotImmutable` and skip any held case intact; register the job in `appinfo/info.xml` background-jobs.

## 6. Controllers, routes, access control

- [ ] 6.1 Add case-management controller method(s): create case, run lifecycle transition (incl. `draftDenial`/`finaliseDenial` through the guard), attach/trigger evidence, apply redaction, generate bundle, download bundle — delegating to the services above; all reads/writes through `ObjectService`.
- [ ] 6.2 Add the case-level access-control check layered on object RBAC (ADR-023): handler-scopes-own + configured officer-role override, never widening beyond object RBAC, failing closed when the officer role cannot be resolved.
- [ ] 6.3 Register every controller method in `appinfo/routes.php` under the `/api/gdpr/cases/...` shape with the correct auth annotation (`@NoAdminRequired`, `@NoCSRFRequired` only on the download; never `@PublicPage`); verify route↔method reachability — no orphan methods, no orphan routes (ADR-016, ADR-029).

## 7. Tests

- [ ] 7.1 Add PHPUnit tests (CI way: php:8.3-cli + OCP stubs, no live NC) against `ObjectService` / provider / `RetentionService` / `DataSubjectRequestService` doubles for: evidence dedup + per-item status, bundle assemble/sign + one-time-token single-use, redaction distinct-from-erase, the denial guard (blocked/allowed/draft-not-gated/fail-closed), and the legal-hold-aware sweep (dry-run + skip-held).
- [ ] 7.2 Add controller tests asserting auth annotations, anonymous rejection, RBAC + case-level access control (handler-scopes-own, officer override, fail-closed), and route↔method reachability.

## 8. Verification

- [ ] 8.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) and the Hydra mechanical gates (spdx-headers, route-auth, no-admin-idor, unsafe-auth-resolver, route-reachability, redundant-controller); fix any pre-existing issues touched.
- [ ] 8.2 Run `openspec validate --change dsar-case-engine --strict`; resolve any errors.
=======
- [x] 1.1 Add an `EvidenceSourceProvider` interface (identify via stable `sourceId`; harvest evidence items for a case) and a registry that leaf apps register providers into (ADR-019); OR core enumerates only registered providers.
- [x] 1.2 Add an async harvest service that collects from registered providers, dedups items by `contentHash`, writes each item (`sourceId`/`contentHash`/`status`) onto the case's declared `evidence` sub-collection via `ObjectService` (RBAC + multitenancy), and audits each attach through `AuditTrailMapper` pinned to the DSAR processing activity.

## 2. Export bundle

- [~] 2.1 Add an export-bundle service that assembles via `DataSubjectRequestService::assembleAccessExport` (no re-implementation, ADR-011); select + vendor a PAdES-LTV signing library (in scope, isolated behind the service as a swappable dependency), sign the bundle with PAdES-LTV, and attach a SHA-256 content hash; audit the generation. **PARTIAL (Phase-1a):** export-bundle service, PDF render, SHA-256 content hash and generation-audit are implemented; signing is isolated behind a swappable `PadesSigner` interface (constructor-injected) with a default `UnsignedPadesSigner` that attaches the hash and a `signed:false / pending PAdES-LTV library` state. **PAdES-LTV DEFERRED (spike outcome 2026-07-04):** the tc-lib-pdf spike was **No-Go** — 8.65.4 is LGPL-3/EUPL-clean with the API but mid-development (discards the RFC-3161 timestamp → B-T stubbed; legacy subfilter; no ESS signing-cert attr; DSS/VRI empty without B-T). **Accepted interim = SHA-256 hash-only** via `UnsignedPadesSigner` (already shipped). Real PAdES-LTV is a deferred follow-up; **leading candidate `pyHanko`** (MIT sidecar, real B-LTA) or a tc-lib-pdf re-spike once timestamp-embedding lands. No `composer require` made. The `PadesSigner` interface keeps the swap a config/adapter change (see `TODO(ADR-047 Phase-1b)` in `ExportBundleService` + the DI binding in `Application::register`).
- [x] 2.2 Add the one-time secure download: mint a single-use, time-boxed token; burn it on first successful download; refuse replay; keep the download authenticated + case-scoped (never `@PublicPage`).
- [x] 2.3 Add regulator-dossier assembly from the case's evidence, redaction records (with grounds), and audit-trail history, read through the RBAC-scoped case object.

## 3. Redaction write path

- [x] 3.1 Add a redaction-write service that applies a field-level redaction, records a `redactions` entry (`field`/`before`/`after`/`ground`) on the case via `ObjectService`, audits it, and stays distinct from `DataSubjectRequestService::erase(mode=pseudonymise)` (MUST NOT invoke the erase path).

## 4. Denial finalise guard

- [x] 4.1 Add the `finaliseDenial` lifecycle guard class (the FQCN the head's transition references via `requires`, ADR-031 §3): refuse finalise when `regulatorReference` is empty, permit it when present, never gate `draftDenial`, and fail closed.

## 5. Retention sweep

- [x] 5.1 Add a retention-sweep `TimedJob` (mirroring `AvgRetentionJob`/`OCP\BackgroundJob\TimedJob` with `IAppConfig` enabled + dry-run toggles) that hard-deletes cases past `retainUntil`, scrubs evidence PII via `DataSubjectRequestService::erase(mode=pseudonymise)`, and audits each destructive action.
- [x] 5.2 Make the sweep legal-hold aware: consult `RetentionService::hasActiveLegalHold` / `validateNotImmutable` and skip any held case intact; register the job in `appinfo/info.xml` background-jobs.

## 6. Controllers, routes, access control

- [x] 6.1 Add case-management controller method(s): create case, run lifecycle transition (incl. `draftDenial`/`finaliseDenial` through the guard), attach/trigger evidence, apply redaction, generate bundle, download bundle — delegating to the services above; all reads/writes through `ObjectService`.
- [x] 6.2 Add the case-level access-control check layered on object RBAC (ADR-023): handler-scopes-own + configured officer-role override, never widening beyond object RBAC, failing closed when the officer role cannot be resolved.
- [x] 6.3 Register every controller method in `appinfo/routes.php` under the `/api/gdpr/cases/...` shape with the correct auth annotation (`@NoAdminRequired`, `@NoCSRFRequired` only on the download; never `@PublicPage`); verify route↔method reachability — no orphan methods, no orphan routes (ADR-016, ADR-029).

## 7. Tests

- [x] 7.1 Add PHPUnit tests (CI way: php:8.3-cli + OCP stubs, no live NC) against `ObjectService` / provider / `RetentionService` / `DataSubjectRequestService` doubles for: evidence dedup + per-item status, bundle assemble/sign + one-time-token single-use, redaction distinct-from-erase, the denial guard (blocked/allowed/draft-not-gated/fail-closed), and the legal-hold-aware sweep (dry-run + skip-held).
- [x] 7.2 Add controller tests asserting auth annotations, anonymous rejection, RBAC + case-level access control (handler-scopes-own, officer override, fail-closed), and route↔method reachability.

## 8. Verification

- [x] 8.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) and the Hydra mechanical gates (spdx-headers, route-auth, no-admin-idor, unsafe-auth-resolver, route-reachability, redundant-controller); fix any pre-existing issues touched.
- [x] 8.2 Run `openspec validate --change dsar-case-engine --strict`; resolve any errors.
>>>>>>> origin/development

## Acceptance Criteria

- Evidence collection harvests only from registered providers, dedups by `contentHash` (re-runs are idempotent), and records `sourceId`/`contentHash`/`status` per item with an audit entry.
- The export bundle is assembled via the existing access-export primitive, PAdES-LTV signed + SHA-256 hashed, downloadable exactly once via a burned one-time token, and a regulator dossier reflects evidence + redactions + history.
- A field-level redaction records before/after + ground to the audit trail and never triggers erase-time pseudonymise.
- `finaliseDenial` is refused without a `regulatorReference`, allowed with one, `draftDenial` is never gated, and the guard fails closed.
- The retention sweep hard-deletes only expired, non-legal-held cases, scrubs their evidence PII via `erase(mode=pseudonymise)`, supports dry-run, and audits every destructive action.
- Every case-management endpoint is `@NoAdminRequired` (never `@PublicPage`), registered + reachable in `appinfo/routes.php`, rejects anonymous callers, and enforces handler-scopes-own + officer-override case-level access control that fails closed.
- No new OR schema is added; the change consumes the `dsar-case-subsystem` register.

## Quality Checklist

- Reused `DataSubjectRequestService::assembleAccessExport` / `erase`, `DataSubjectDeadline`, `RetentionService` legal-hold, `AuditTrailMapper` + processing-activity pinning, and the `AvgRetentionJob`/`TimedJob` pattern rather than duplicating them (ADR-011, ADR-022).
- Every imperative item is a genuine ADR-031 exception (external integration / document generation / scheduled bulk work / lifecycle guard) — no declarative construct (lifecycle/calculation/aggregation/notification) reimplemented in code (gate-18/gate-31).
- Auth posture explicit on every route (ADR-005/016/029): `@NoAdminRequired`, `@NoCSRFRequired` only where justified, no `@PublicPage`; no-admin-IDOR and unsafe-auth-resolver gates clean; case-level access control fails closed (CWE-863).
- SPDX + `@license`/`@copyright` docblock headers on every new PHP file (EUPL-1.2).
- Contract coverage: the case-management endpoints exercised via Newman (API/contract), not counted as UI-green; behavioural spec scenarios carry `@e2e` and are referenced by Playwright e2e (or a reason-bearing `@e2e exclude`).
- Any fixture ids/tokens use safe placeholders only (nil UUID `00000000-0000-0000-0000-000000000000`, `YOUR_TOKEN_HERE`) — no realistic-looking secrets/UUIDs (gitleaks).
