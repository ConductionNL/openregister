# Revive OpenRegister's dead write capabilities

## Why

Hydra gate-52 (`orphaned-write-capability`) flagged eleven write-capable service
methods in OpenRegister with zero production callers (Codeberg openregister#393).
Four were triaged as highest blast radius — OpenRegister is the data abstraction,
so every Conduction app inherits them:

1. `ArchivalService::generateDestructionList()` — Archiefwet destruction lists
2. `Archival\DestructionService::generateCertificate()` — *verklaring van vernietiging*
3. `MetricsService::recordMetric()` — production-observability metrics
4. `Credential\CredentialAppTokenService::issueToken()` — credential-broker token binding

Verifying each against HEAD (rather than trusting the triage) changed the picture
substantially. Two are superseded duplicates whose capability **does** run in
production through a different class; one is genuinely dead with a clear intended
trigger; one is not dead at all but a consumer-facing seam. Crucially, the
verification surfaced a **live, previously-unknown compliance defect**: the
`/api/archival/destruction-lists/{id}/approve` route silently destroys nothing
and produces no certificate.

See `design.md` for the per-method verdict table and caller evidence.

## What Changes

- **DELETE** `ArchivalService` (whole class, zero references in `lib/`) — superseded
  by `RetentionService` + the registered `DestructionCheckJob` cron, which is what
  actually produces destruction lists in production.
- **DELETE** `Archival\DestructionService::generateCertificate()` and its only
  would-be caller `executeDestruction()` — superseded by `DestructionExecutionJob`
  + `RetentionService::generateDestructionCertificate()`, which is what actually
  produces the certificate.
- **FIX** the live `/api/archival/.../approve` route, which never reaches that
  working certificate path because of three defects (wrong job-argument key,
  non-canonical approvals key, and the approved list never being persisted).
- **WIRE** `MetricsService::recordMetric()` to object create/update/delete via a
  new `ObjectMetricsListener` on the already-dispatched `ObjectCreatedEvent` /
  `ObjectUpdatedEvent` / `ObjectDeletedEvent`, satisfying the canonical
  production-observability requirement "CRUD Operation Counters … MUST persist …
  using the `openregister_metrics` database table".
- **MARK-SEAM** `CredentialAppTokenService::issueToken()` with
  `@orphaned-write-capability exclude …` — OpenRegister is the token *verifier* by
  design (ADR-004 Rule 2); the *consuming app* is the issuer.

## Impact

- Affected specs: `archival-destruction-workflow`, `production-observability`
- Affected code: `lib/Service/ArchivalService.php` (deleted),
  `lib/Service/Archival/DestructionService.php`, `lib/Controller/ArchivalController.php`,
  `lib/EventListener/ObjectMetricsListener.php` (new), `lib/Service/MetricsService.php`,
  `lib/AppInfo/Application.php`, `lib/Service/Credential/CredentialAppTokenService.php`
- **Behaviour change:** approving a destruction list via `/api/archival/…` now
  actually executes destruction (it previously no-opped). This is the specified,
  archivist-gated behaviour and matches the already-working `/api/retention/…` route.
