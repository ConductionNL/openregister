# Design — Open Raadsinformatie Adapter

This document outlines the architectural decisions for OpenRegister's integration with Open Raadsinformatie data sources. It is a **sibling** to, not a replacement for, the Procest-owned canonical ORI specification.

## Integration Pattern

### D1 — ORI adapter implements the external-source integration pattern

**Decision.** The ORI adapter inherits from OpenRegister's existing `ExternalSourceAdapter` base class and implements:

- `authenticate(SourceConfig): AuthToken` — Obtain credentials from OCP vault and exchange for ORI session token
- `discover(): EntityDefinition[]` — Enumerate available ORI entity types (besluiten, stukken, etc.)
- `pull(EntityDefinition, since?: DateTime): EntitySet` — Fetch entities from ORI, paginated by source, with server-supplied cursor or timestamp for incremental sync
- `map(Entity): Array<RegisterObject>` — Translate ORI entity to OpenRegister's object array (one ORI entity may map to multiple OR objects if the register schema is granular)
- `validate(RegisterObject[]): ValidationResult[]` — Validate mapped objects against target register's schema before persist

This pattern reuses existing connection pooling, error-handling, and observer patterns from opencatalogi and softwarecatalog adapters.

### D2 — ORI data entities map to OpenRegister registers via schema-driven translation

**Decision.** Each ORI entity type (e.g., besluiten/decisions) is mapped to a dedicated OpenRegister register:

- **ORI register** — created by the admin (e.g., "Gemeente Arnhem Council Decisions")
- **Schema** — admin selects or creates a schema that matches the ORI entity structure (fields, constraints)
- **Object properties** — ORI fields (title, date, status) become object properties; ORI URLs become `_sourceUrl` metadata
- **Relationships** — ORI entity references (decision → document, document → person) map to OpenRegister's `$references` property (if schema declares it)

The mapping is **schema-first**: the admin defines the target register's schema; the adapter validates every ORI entity against it before sync.

### D3 — Sync uses incremental pull with server-provided cursors

**Decision.** The sync service implements read-through pull semantics:

- **On first run** — request all entities from ORI (may paginate across multiple requests)
- **On subsequent runs** — request only entities created or modified since the last sync cursor
- **Cursor management** — store the server-supplied cursor in the register's metadata (`_syncCursor`) alongside a timestamp
- **Failure handling** — on failed sync, retry with exponential backoff; on permanent failure (auth expired, schema mismatch), mark the source as paused and alert the admin

The adapter does **not** push to ORI; it is read-only. Edits to ORI objects in OpenRegister's UI are tracked separately and not synced back.

### D4 — Admin UI lives under Integrations / External adapters

**Decision.** The ORI adapter admin interface is a sub-page (SUB_PAGE) under the top-level "Integrations" section:

- **Main page** — lists registered ORI data sources with last-sync timestamp and status
- **Add source** — form to enter ORI endpoint, auth method, and sync schedule
- **Source detail** — shows sync history, validation errors, and per-entity sync results
- **Schedule** — configurable via OCP's background job system (next-run time, frequency)

This placement reuses the existing Nextcloud sidebar routing and admin panel structure. No new top-level menu entries are created.

## Seed Data

Example ORI data sources for testing:

```yaml
ORI Source:
  - name: "Gemeente Arnhem — Raadsstukken"
    endpoint: "https://data.arnhem.nl/ori/api"
    entity_type: "stukken"
    register_slug: "arnhem-council-documents"
    sync_interval: 3600  # 1 hour
    auth: "token"
    status: "active"
    last_sync: 2026-05-23T10:15:00Z
    
  - name: "Gemeente Utrecht — Besluiten"
    endpoint: "https://data.utrecht.nl/ori/api"
    entity_type: "besluiten"
    register_slug: "utrecht-council-decisions"
    sync_interval: 7200  # 2 hours
    auth: "oauth2"
    status: "paused"  # auth expired
    last_sync: 2026-05-20T14:30:00Z
```

Example sync result (stored in register metadata):

```json
{
  "_syncSource": "ori:https://data.arnhem.nl",
  "_syncCursor": "2026-05-23T10:14:59Z",
  "_syncStatus": "success",
  "_syncEntitiesCount": 1247,
  "_syncObjectsCreated": 1200,
  "_syncObjectsUpdated": 47,
  "_syncValidationErrors": [],
  "_nextSyncAt": "2026-05-23T11:15:00Z"
}
```

## Deferred Decisions

### Future: Multi-source federation

When a single register needs to aggregate ORI data from multiple municipalities, implement a federation layer above the per-source adapters. This is deferred pending real-world usage patterns.

### Future: ORI-to-OpenRegister bidirectional sync

Currently, OpenRegister is read-only for ORI data. A future change may implement write-back sync (marking a decision as "archived" in OpenRegister pushes an archive event to ORI). This requires ORI API write contracts and cross-project governance; defer to a separate change.

### Future: ORI schema inference

Currently, the admin must select or create a schema before importing ORI data. A future improvement might auto-generate a schema from ORI's entity definitions. Defer pending OpenRegister's schema-inference library maturity.
