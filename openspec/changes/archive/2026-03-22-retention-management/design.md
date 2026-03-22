## Context

OpenRegister currently has rudimentary retention infrastructure:
- `ObjectEntity.retention` — a JSON field storing soft-delete metadata (deletedAt, deletedBy, retentionPeriod, purgeDate)
- `Schema.archive` — a JSON field for archive configuration (currently unused beyond storage)
- `ObjectRetentionHandler` — manages app-level retention settings (log retention periods, audit trail toggles)

These fields exist but lack the archival lifecycle management required by the Archiefwet 1995: selectielijsten, automated destruction scheduling, approval workflows, legal holds, and e-Depot transfer. The existing `archivering-vernietiging` spec defines the full vision; this change implements the core backend capabilities.

Constraints:
- Must use Nextcloud OCP interfaces (INotification, BackgroundJob, IAppConfig)
- Must integrate with existing ObjectService CRUD flow and audit trail system
- Must not break existing retention/soft-delete behavior
- PostgreSQL with JSON column querying for retention metadata filtering

## Goals / Non-Goals

**Goals:**
- Extend ObjectEntity.retention with MDTO-compliant archival metadata
- Implement selectielijsten as register objects (self-referential: managed within OpenRegister)
- Build automated destruction lifecycle: scheduling, destruction lists, multi-step approval, execution
- Add legal hold support preventing destruction of held objects
- Provide pre-destruction notifications via Nextcloud INotification
- Generate destruction certificates as immutable register objects
- Add retention settings API endpoints for archival configuration

**Non-Goals:**
- e-Depot SIP export (deferred to follow-up change — requires OpenConnector integration and MDTO XML serialization)
- Frontend UI for destruction list management (API-first; UI comes separately)
- WOO publication interaction rules (requires WOO publication system to exist first)
- MDTO XML export format (complex serialization, separate change)

## Decisions

### 1. Selectielijsten as register objects (not config tables)

**Decision**: Store selectielijst entries as regular OpenRegister objects in a designated register/schema, not as separate database tables.

**Rationale**: This reuses the existing ObjectService CRUD, search, audit trail, and API infrastructure. Selectielijsten are essentially structured data — exactly what OpenRegister manages. This also allows version management via object versioning and import via existing data import mechanisms.

**Alternative considered**: Dedicated `selectielijst_entries` table — rejected because it duplicates CRUD, search, and audit infrastructure that already exists.

### 2. Destruction lists as register objects

**Decision**: Destruction lists are register objects containing references (UUIDs) to the objects they cover, with status tracking (in_review, approved, rejected, executed).

**Rationale**: Same reasoning as selectielijsten — reuse existing infrastructure. Destruction lists become searchable, auditable, and API-accessible without new endpoints. The destruction certificate is also a register object, ensuring permanent retention.

### 3. Background job pattern: TimedJob for scanning, QueuedJob for execution

**Decision**: `DestructionCheckJob` extends `OCP\BackgroundJob\TimedJob` for periodic scanning. `DestructionExecutionJob` extends `OCP\BackgroundJob\QueuedJob` for processing approved destruction lists.

**Rationale**: TimedJob runs on a configurable schedule (daily default). QueuedJob prevents timeout issues when destroying many objects. This separates identification (automated) from execution (triggered after approval).

### 4. Legal hold stored in retention JSON field

**Decision**: Legal hold data stored as a nested object within `ObjectEntity.retention.legalHold` rather than a separate column.

**Rationale**: The retention JSON field already exists and is queried via PostgreSQL JSON operators. Adding a nested object avoids a database migration for a new column while keeping all archival metadata co-located. The `legalHold.history[]` array preserves the full hold/release lifecycle.

### 5. RetentionService as orchestrator

**Decision**: New `RetentionService` class orchestrates archival operations (metadata calculation, destruction scheduling, legal hold management). It delegates to existing `ObjectService` for persistence and audit trail creation.

**Rationale**: Follows the existing service layer pattern (RegisterService, SchemaService, ObjectService). Keeps archival business logic separated from generic object CRUD.

### 6. Archiefactiedatum calculation via afleidingswijze configuration

**Decision**: The afleidingswijze (derivation method) is configured per schema in `Schema.archive.afleidingswijze` with options: `afgehandeld` (from closure date), `eigenschap` (from a named property), `termijn` (closure + process term + retention), `ingangsdatum_besluit` (from decision start date), `vervaldatum` (from expiry date).

**Rationale**: Aligns with ZGW API standard afleidingswijzen used by OpenZaak. Configuration at schema level means all objects of a type use the same derivation method, with per-object override possible via direct retention field updates.

## Risks / Trade-offs

**[Risk] Large destruction lists may timeout** — Mitigation: QueuedJob processes objects in batches (configurable batch size, default 50). Each batch is a separate job execution.

**[Risk] Concurrent legal hold and destruction approval race condition** — Mitigation: DestructionExecutionJob re-checks legal hold status at execution time, not just at approval time. Objects with holds placed after approval are automatically excluded.

**[Risk] Selectielijst version transitions affect existing objects** — Mitigation: Objects store the selectielijst version reference at creation time. New versions only apply to new objects. A reporting endpoint shows objects grouped by selectielijst version.

**[Risk] JSON field querying performance for retention metadata** — Mitigation: PostgreSQL GIN index on retention JSON field for efficient filtering. Destruction check job uses indexed queries.

**[Trade-off] No dedicated destruction list UI** — This change is API-first. The admin manages destruction lists via API or through n8n workflows. A dedicated UI is a follow-up concern.

## Migration Plan

1. Database migration extends retention JSON structure (non-breaking — adds optional fields to existing JSON)
2. Schema.archive extended with new configuration keys (non-breaking — new optional keys)
3. Register background jobs in `info.xml`
4. Deploy RetentionService, DestructionCheckJob, DestructionExecutionJob
5. Admin configures selectielijst register/schema and initial entries
6. Rollback: Remove background jobs from info.xml, services are unused without configuration

## Open Questions

- Should the destruction approval role be a Nextcloud group or a custom RBAC role from the authorization system? (Decision: Use Nextcloud group `archivaris` initially, integrate with RBAC when that system matures)
- What is the minimum batch size for destruction execution to balance throughput vs. memory? (Decision: Default 50, configurable via settings)
