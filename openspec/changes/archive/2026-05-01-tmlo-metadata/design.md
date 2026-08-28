## Context

OpenRegister is the foundation data registration platform. Dutch municipalities must comply with TMLO (Toepassingsprofiel Metadatastandaard Lokale Overheden) for archival metadata on government records. Currently, ObjectEntity has a `retention` JSON field but no structured TMLO/MDTO-compliant archival metadata.

TMLO is the local government profile of MDTO (Metadatastandaard voor Duurzaam Toegankelijke Overheidsinformatie). By implementing TMLO at the OpenRegister level, all consuming apps (Procest, Pipelinq, Docudesk, ZaakAfhandelApp, OpenCatalogi) inherit archival compliance automatically.

### Current State
- ObjectEntity has `retention` (JSON) field -- stores basic retention info but not TMLO-structured
- Register has `configuration` (JSON) -- can be extended with tmloEnabled flag
- Schema has `configuration` (JSON) -- can be extended with tmloDefaults
- No MDTO XML export capability exists
- No archival status transition validation exists

## Goals / Non-Goals

**Goals:**
- Add TMLO metadata as a first-class JSON column on ObjectEntity
- Enable/disable TMLO per register via configuration
- Auto-populate TMLO defaults from schema configuration
- Validate archival status transitions
- Provide MDTO-compliant XML export
- Provide query API for filtering by archival metadata

**Non-Goals:**
- Actual destruction execution (separate change: archival-destruction-workflow)
- e-Depot transfer protocol (separate change: edepot-transfer)
- Retention period calculation engine (separate change: retention-management)
- Migrating existing `retention` field data to `tmlo` (future concern)

## Decisions

### D1: Separate `tmlo` JSON column vs extending `retention`

**Decision:** Add a new `tmlo` JSON column on ObjectEntity rather than extending the existing `retention` field.

**Rationale:** The `retention` field has existing consumers and a different semantic meaning (soft-delete retention). TMLO metadata is a distinct archival compliance concern. Keeping them separate avoids breaking existing behavior and makes the TMLO data model explicit.

**Alternatives considered:** Extending `retention` with TMLO sub-keys -- rejected because it couples two distinct concerns and risks breaking existing retention logic.

### D2: Configuration-based toggle vs separate entity

**Decision:** Use `Register.configuration.tmloEnabled` boolean and `Schema.configuration.tmloDefaults` object rather than creating new TMLO-specific entities.

**Rationale:** Leverages existing configuration JSON fields. No new tables needed. Configuration is already serialized/deserialized. Minimal migration overhead.

**Alternatives considered:** Separate TmloConfig entity -- rejected as over-engineered for a boolean toggle and a small defaults object.

### D3: TmloService as single service class

**Decision:** Create a single `TmloService` class handling all TMLO logic (population, validation, export, query).

**Rationale:** TMLO logic is cohesive and relatively bounded. A single service keeps the logic discoverable. If complexity grows, it can be split later.

### D4: MDTO XML generation via PHP DOMDocument

**Decision:** Use PHP's built-in DOMDocument for XML generation rather than a template engine or third-party library.

**Rationale:** DOMDocument is available in all PHP installations, produces well-formed XML, and handles namespaces properly. No additional dependencies.

### D5: Query via JSON column extraction

**Decision:** Filter TMLO fields using database JSON extraction functions (JSON_EXTRACT for MySQL/SQLite, ->> for PostgreSQL) in the mapper.

**Rationale:** Avoids denormalizing TMLO fields into separate columns. JSON extraction is supported by all target databases.

## Risks / Trade-offs

- **[Risk] JSON query performance** -- Filtering on JSON sub-fields is slower than indexed columns. Mitigation: TMLO queries are typically administrative (batch operations), not high-frequency. Can add generated columns with indexes later if needed.
- **[Risk] Database compatibility** -- JSON extraction syntax differs between PostgreSQL, MySQL, and SQLite. Mitigation: Use Nextcloud's IQueryBuilder with platform-specific JSON functions wrapped in a helper method.
- **[Risk] MDTO schema evolution** -- MDTO standard may evolve. Mitigation: Version the TMLO field structure. The `tmlo` JSON column is flexible enough to accommodate additional fields.

## Migration Plan

1. Database migration adds `tmlo` column (nullable JSON, default NULL)
2. No data migration needed -- existing objects get NULL tmlo
3. Registers opt-in by setting configuration.tmloEnabled = true
4. Rollback: drop the `tmlo` column via reverse migration

## Seed Data

When TMLO is enabled on a register, seed objects should include sample TMLO metadata to demonstrate the feature. The seed data should cover:
- Objects with different archiefstatus values (actief, semi_statisch)
- Objects with and without archiefactiedatum
- Objects with both archiefnominatie values (blijvend_bewaren, vernietigen)

## Open Questions

- Should TMLO metadata be included in SOLR indexing for search? (Deferred -- can be added later)
- Should audit trail entries be created for archiefstatus transitions? (Deferred -- covered by existing audit trail)
