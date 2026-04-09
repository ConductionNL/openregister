## Context

OpenRegister already has retention metadata infrastructure (`ObjectEntity.retention`, `ObjectRetentionHandler`, `Schema.archive`) and a comprehensive draft spec (`archivering-vernietiging`) defining MDTO-compliant archival metadata, selectielijsten, destruction workflows, legal holds, and e-Depot export. The deletion pipeline (`DeleteObject`) supports soft delete with audit trails, and the `AuditTrailMapper` provides hash-chained immutable logging.

What is missing is the active workflow layer: no background jobs scan for objects past their `archiefactiedatum`, no destruction list management exists, no legal hold enforcement prevents premature destruction, and no destruction certificates are generated. This design bridges the existing metadata infrastructure with an operational destruction workflow.

## Goals / Non-Goals

**Goals:**
- Implement automated destruction scheduling via Nextcloud `TimedJob` that generates destruction lists
- Provide a multi-step approval workflow for destruction lists (single and two-step)
- Enforce legal holds that block destruction regardless of archival dates
- Generate immutable destruction certificates (verklaring van vernietiging)
- Calculate `archiefactiedatum` from configurable derivation methods (afleidingswijzen)
- Handle cascade destruction respecting referential integrity rules and legal holds on children

**Non-Goals:**
- e-Depot SIP package generation and transfer (deferred to a separate change -- endpoints are stubbed)
- MDTO XML export format (already partially covered, full implementation deferred)
- Selectielijst import UI (API-only in this change)
- Frontend/Vue components for destruction list management (API-only)

## Decisions

### Decision 1: Destruction lists stored as register objects

**Choice**: Store destruction lists as `ObjectEntity` instances in a dedicated `archival` register/schema, not as a separate database table.

**Rationale**: OpenRegister's architecture stores all structured data as register objects. This gives destruction lists the same audit trail, versioning, search, and API access as any other object. It avoids schema migrations and follows the existing pattern where system data (e.g., selectielijsten) lives in registers.

**Alternative considered**: A dedicated `destruction_lists` database table would give stronger typing but would break the register-centric architecture and require new mappers, controllers, and migration steps.

### Decision 2: New `ArchivalController` for all archival API endpoints

**Choice**: Create a dedicated `ArchivalController` (not extend `ObjectsController`) with routes under `/api/archival/`.

**Rationale**: The archival workflow is a distinct domain concern with its own authorization (archivist role), its own state machine (destruction list lifecycle), and its own business rules. Mixing these into `ObjectsController` would violate single-responsibility and make the already large controller harder to maintain.

**Endpoints**:
- `GET /api/archival/destruction-lists` -- list destruction lists with status filter
- `GET /api/archival/destruction-lists/{id}` -- get destruction list detail
- `POST /api/archival/destruction-lists/{id}/approve` -- approve (full or partial)
- `POST /api/archival/destruction-lists/{id}/reject` -- reject with reason
- `POST /api/archival/legal-holds` -- place legal hold on object(s)
- `DELETE /api/archival/legal-holds/{id}` -- release legal hold
- `GET /api/archival/legal-holds` -- list active legal holds
- `GET /api/archival/certificates` -- list destruction certificates

### Decision 3: Service layer split into three focused services

**Choice**: `DestructionService`, `LegalHoldService`, `ArchiefactiedatumCalculator` as separate service classes under `lib/Service/Archival/`.

**Rationale**: Each has distinct responsibilities and dependencies. `DestructionService` orchestrates the workflow, `LegalHoldService` manages holds and checks, `ArchiefactiedatumCalculator` handles date derivation. This follows the existing handler pattern in `lib/Service/Object/`.

### Decision 4: Background jobs use Nextcloud `TimedJob` + `QueuedJob`

**Choice**: `DestructionCheckJob` extends `OCP\BackgroundJob\TimedJob` (runs daily by default). `DestructionExecutionJob` extends `OCP\BackgroundJob\QueuedJob` (one-shot, triggered after approval).

**Rationale**: Nextcloud's job infrastructure handles scheduling, retries, and error reporting. `TimedJob` for periodic scanning follows the pattern of existing jobs (`CacheWarmupJob`, `SolrNightlyWarmupJob`). `QueuedJob` for execution avoids timeouts on large destruction batches -- the same pattern used by `WebhookDeliveryJob`.

### Decision 5: Legal holds stored in object `retention` field

**Choice**: Legal hold data is stored in the existing `ObjectEntity.retention` JSON field as `retention.legalHold`.

**Rationale**: No schema migration required. The `retention` field already carries archival metadata. Adding `legalHold` as a nested structure keeps all archival state in one place. The `LegalHoldService` checks `retention.legalHold.active` before any destruction proceeds.

### Decision 6: Two-step approval via schema configuration

**Choice**: Schema's `archive` property gains `requireDualApproval: true` flag. The `DestructionService` checks this during approval and requires a second distinct approver.

**Rationale**: Configuration-driven rather than code-driven. The schema already has an `archive` property for retention settings. Adding dual-approval there keeps archival policy centralized per object type.

## Risks / Trade-offs

- **[Performance] Large destruction lists** -- A destruction list with thousands of objects could cause memory issues during approval processing. Mitigation: `DestructionExecutionJob` processes in configurable batches (default 100), using `QueuedJob` chaining for continuation.

- **[Data integrity] Cascade destruction with legal holds** -- If a parent is approved for destruction but a child has a legal hold, the entire parent destruction must halt. Mitigation: Pre-flight validation in `DestructionService::validateDestructionList()` checks all cascade targets for legal holds before execution begins.

- **[Concurrency] Legal hold placed between approval and execution** -- A legal hold could be placed after approval but before the `DestructionExecutionJob` runs. Mitigation: The execution job re-checks legal holds immediately before each object deletion.

- **[Complexity] Archiefactiedatum recalculation** -- When source properties change, the archival date must be recalculated. This requires hooking into `SaveObject`. Mitigation: A lightweight `ArchivalMetadataHook` in the save pipeline that only activates for schemas with archival configuration.

## Migration Plan

1. Deploy new service classes, controller, and background jobs -- no database migration needed
2. Register `DestructionCheckJob` in `Application::register()` with `IJobList::add()`
3. Register new routes in `appinfo/routes.php` under `/api/archival/`
4. Existing `retention` field data is forwards-compatible -- no data migration needed
5. Rollback: Remove the registered background job and routes; no data changes to undo

## Open Questions

- Should the destruction check frequency be configurable via admin settings or fixed at daily?
- Should destruction certificates be exportable as PDF, or is JSON/register object sufficient for v1?
- Should the archivist role be a Nextcloud group or an OpenRegister-specific role?
