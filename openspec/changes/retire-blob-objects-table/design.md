## Context

OpenRegister currently has two parallel storage paths for objects:

1. **Blob table** (`oc_openregister_objects`) — a single table storing all objects as JSON in a `LONGTEXT` column, accessed via `ObjectEntityMapper`
2. **Magic tables** (`oc_openregister_table_{slug}`) — per-schema tables with typed columns, accessed via `MagicMapper`

The `UnifiedObjectMapper` routes operations to one or the other based on register/schema context and magic-mapping configuration. This dual-path architecture was necessary during the transition period but now adds complexity, duplication, and subtle bugs when data exists in both locations. All active registers already use magic tables; the blob table holds only legacy/orphaned data.

Key classes involved:
- `ObjectEntityMapper` + `ObjectEntity\BulkOperationsHandler` — blob CRUD
- `MagicMapper` + `MagicMapper/*Handler` — magic table CRUD
- `UnifiedObjectMapper` — routing layer
- `OptimizedBulkOperations` — shared bulk SQL builder (used by both paths)
- `ObjectService`, `SaveObject`, `SaveObjects` — service layer with blob fallback branches

## Goals / Non-Goals

**Goals:**
- Migrate all remaining blob-table objects to their correct magic tables
- Remove all blob-table code paths (mapper, routing, fallback logic)
- Drop the `oc_openregister_objects` table from the database
- Simplify the storage architecture to a single path (magic tables only)

**Non-Goals:**
- Changing the external REST API contract (endpoints stay identical)
- Modifying the magic table architecture itself
- Changing how `ObjectEntity` works as a value object (it remains the DTO)
- Migrating search indexes (Solr/Elasticsearch) — they are independent

## Decisions

### 1. Background job with batched migration (not a blocking migration)

Use a Nextcloud `TimedJob` (cron-based) rather than a blocking `IRepairStep` migration.

**Rationale:** A repair step runs during `occ upgrade` and blocks the process. With potentially millions of objects, this could take hours. A background job runs in batches without blocking the admin. It can also be monitored and restarted.

**Alternative considered:** `IRepairStep` — rejected because it blocks upgrade and has no progress visibility.

### 2. Batch size of 100, grouped by register+schema

Each job run: query up to 100 objects from `oc_openregister_objects`, group them by `(register, schema)` pair, then call `MagicMapper::ultraFastBulkSave()` per group.

**Rationale:** Grouping by register+schema is required because each magic table is per-schema. The mass upsert (`ultraFastUnifiedBulkSave`) handles up to 10,000 objects per batch, so 100 is conservative and safe. It keeps memory low and cron execution fast.

**Alternative considered:** Larger batches (1000+) — rejected because cron jobs have execution time limits in Nextcloud, and smaller batches give better progress granularity.

### 3. Two-phase removal: migrate first, drop later

**Phase 1 — Migration job:** Registered as a `TimedJob` that runs every 5 minutes. Each run processes up to 100 blob objects. After successfully upserting a batch into the magic table, delete the migrated rows from the blob table. Track progress in `appconfig`.

**Phase 2 — Table drop:** A separate database migration (`Version*Date*.php`) checks `appconfig` for a "migration complete" flag. If the flag is not set OR the blob table still has rows, the migration logs a warning and skips the drop. This ensures the table is only dropped when empty.

**Rationale:** Separating migration from removal prevents data loss. The drop migration is idempotent and safe to re-run.

### 4. Objects without a valid register+schema are logged and skipped

Some blob objects may have null/invalid register or schema references (orphaned data). The migration job logs these to the Nextcloud logger at WARNING level and skips them. An admin can review and manually delete orphans.

**Alternative considered:** Auto-delete orphans — rejected because it's destructive without admin consent.

### 5. Remove UnifiedObjectMapper, promote MagicMapper

After migration, `UnifiedObjectMapper` becomes a pass-through to `MagicMapper`. Remove it entirely and inject `MagicMapper` (or a simplified wrapper) directly into services.

`ObjectEntityMapper` is deleted. `ObjectEntity` remains as the DTO — it's used throughout the codebase as a value object and is not tied to the blob table.

### 6. OptimizedBulkOperations stays

`OptimizedBulkOperations` builds SQL for `INSERT ... ON DUPLICATE KEY UPDATE` and is used by magic table bulk operations too. It is NOT blob-specific — only `ObjectEntity\BulkOperationsHandler` (which delegates to it for the blob table) is removed.

## Risks / Trade-offs

- **Data loss if drop runs before migration completes** → The drop migration checks for zero rows and a completion flag; it skips if either condition fails
- **Orphaned objects with no register/schema** → Logged and skipped; admin review required; documented in release notes
- **Dependent apps bypassing ObjectService** → Audit opencatalogi and softwarecatalog for direct mapper usage; they should only use `ObjectService`
- **Cron not running frequently enough** → Migration progress is visible in admin settings; admins can trigger `occ background-job:execute` manually
- **Magic table doesn't exist yet for some schemas** → `MagicMapper::createTableForRegisterSchema()` auto-creates tables on first use; the migration job triggers this
