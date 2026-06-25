---
status: done
---

# saas-multi-tenant Specification

## Purpose
Manages per-tenant HMAC signing keys for multi-tenant deployments, guaranteeing each tenant has exactly one active key that is stored encrypted via Nextcloud's crypto service and bootstrapped or rotated through a single write path. The service is DI-registered for server-side use only — used by audit-trail and evidence signers — and never exposes plaintext key material over REST, in logs, or in audit output.

@e2e exclude Backend per-tenant HMAC key lifecycle (single-active-row lookup, bootstrap/rotation inserts, encryption-failure abort, DI constructability, no-plaintext-key-over-REST invariant). Data-layer service with no user-facing OpenRegister UI surface — exercised via PHPUnit (mapper/service units). Covered by PHPUnit.

## Requirements
### Requirement: Per-tenant active HMAC key MUST be a single most-recent row

The system SHALL expose, for each tenant, exactly one active key at a
time. Lookup SHALL key on (`tenant_id`, `status = 'active'`) and SHALL
return the most-recent row when more than one is matched (defensive
against partial-rotation states). The active-key lookup MUST be the
single read path used by both the get-current and rotate flows.

#### Scenario: Active key exists for tenant

- **GIVEN** the `openregister_tenant_keys` table contains exactly one
  row for `tenant_id = "org-A"` with `status = "active"`
- **WHEN** `TenantKeyService::getCurrentTenantKey("org-A")` runs
- **THEN** the service SHALL `SELECT id, encrypted_key FROM
  openregister_tenant_keys WHERE tenant_id = "org-A" AND status =
  "active" ORDER BY id DESC LIMIT 1`
- **AND** the service SHALL pass the resulting `encrypted_key` through
  `ICrypto::decrypt()` and return the plaintext

#### Scenario: No active key exists for tenant

- **GIVEN** `openregister_tenant_keys` has zero rows for `tenant_id =
  "org-B"`
- **WHEN** `TenantKeyService::getCurrentTenantKey("org-B")` runs
- **THEN** the active-row lookup SHALL return `null` and the service
  SHALL bootstrap a fresh key (see Requirement 2)

#### Scenario: Multiple active rows exist (defensive)

- **GIVEN** `openregister_tenant_keys` has more than one row for
  `tenant_id = "org-C"` with `status = "active"` (e.g. a partial
  rotation left both rows active)
- **WHEN** the active-row lookup runs
- **THEN** the row with the highest `id` SHALL be returned
- **AND** lower-`id` rows SHALL be ignored for this read (cleanup is
  out of scope for this REQ)

### Requirement: New active keys MUST be stored encrypted with status='active'

The system SHALL insert new key rows through a single write path that
encrypts the plaintext key material via `OCP\Security\ICrypto::encrypt`
before persistence, sets `status = 'active'`, and stamps the row with
an ISO-8601 timestamp. The plaintext key material MUST NOT be persisted
to the row, the audit log, or any HTTP response.

#### Scenario: Bootstrap insert on first access

- **GIVEN** no active key exists for `tenant_id = "org-D"`
- **WHEN** `TenantKeyService::getCurrentTenantKey("org-D")` is called
- **THEN** the service SHALL generate 32 bytes of CSPRNG output via
  `random_bytes(32)`, hex-encode it to a 64-character string, and
  pass that string to `ICrypto::encrypt`
- **AND** the service SHALL `INSERT INTO openregister_tenant_keys
  (tenant_id, encrypted_key, status, created_at) VALUES ("org-D",
  <ciphertext>, "active", <iso8601>)`
- **AND** the service SHALL return the plaintext (caller-side use)
  while the row stores only the ciphertext

#### Scenario: Rotation insert after retiring previous active row

- **GIVEN** an active key row already exists for `tenant_id = "org-E"`
- **WHEN** `TenantKeyService::rotateTenantKey("org-E")` is called
- **THEN** the existing row SHALL be flipped to `status = 'retired'`
- **AND** a new row SHALL be inserted via the same encrypted-insert
  path with `status = 'active'` and a fresh `created_at` timestamp
- **AND** the rotation SHALL return metadata only (`tenant_id`,
  `rotated_at`, `retired_key_id`) — never the plaintext key material

#### Scenario: Encryption failure aborts the write

- **GIVEN** `ICrypto::encrypt` throws a `RuntimeException` (e.g.
  instance secret is missing)
- **WHEN** an insert is attempted
- **THEN** no row SHALL be written to `openregister_tenant_keys`
- **AND** the exception SHALL propagate to the caller

### Requirement: TenantKeyService MUST be DI-registered as a server-side internal API

The `TenantKeyService` SHALL be registered in the Nextcloud DI
container so it can be injected into audit-trail signers and future
per-tenant evidence-signing call sites. The service MUST NOT be wired
into any HTTP controller, OCS controller, or CLI command that exposes
key material outside the server boundary.

#### Scenario: Service is constructable via the DI container

- **WHEN** the Nextcloud DI container resolves
  `OCA\OpenRegister\Service\TenantKeyService`
- **THEN** it SHALL receive `IDBConnection`, `ICrypto`, and
  `LoggerInterface` constructor arguments
- **AND** the resolved instance SHALL be the same singleton across
  the request (standard Nextcloud DI behaviour)

#### Scenario: No REST endpoint surfaces the plaintext key

- **WHEN** the OpenRegister app's routes are loaded from
  `appinfo/routes.php`
- **THEN** no route SHALL map to a controller method that returns
  `TenantKeyService::getCurrentTenantKey()` output in its HTTP
  response body, headers, or logs
- **AND** the rotation endpoint (if exposed) SHALL return only the
  metadata array (`tenant_id`, `rotated_at`, `retired_key_id`)

