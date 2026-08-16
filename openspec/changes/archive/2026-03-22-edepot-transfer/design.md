## Context

OpenRegister stores structured government data as objects with a `retention` JSON field and schema-level `archive` configuration. Objects with `archiefnominatie` set to `bewaren` must eventually be transferred to an external e-Depot (digital archive) per the Archiefwet 1995. The existing codebase has no e-Depot integration — no MDTO XML generation, no SIP packaging, and no transfer workflow.

Key existing infrastructure:
- `ObjectEntity.retention` (JSON field): stores archival metadata including `archiefnominatie`, `archiefstatus`, `archiefactiedatum`
- `Schema.archive` (JSON field): schema-level archival configuration (default nominatie, bewaartermijn)
- `ObjectService`: central CRUD service for objects
- `AuditTrail` entity and mapper: immutable audit logging
- `QueuedJob` / `TimedJob` patterns: used for background processing (e.g., vectorization, text extraction)
- `FileService` / file handlers: manage Nextcloud Files associations per object
- OpenConnector integration: external source synchronization

## Goals / Non-Goals

**Goals:**
- Generate MDTO-compliant XML metadata for objects eligible for permanent preservation
- Assemble SIP (Submission Information Package) packages following OAIS (ISO 14721) with METS structural metadata, PREMIS preservation metadata, and SHA-256 fixity checksums
- Provide a transfer list workflow (create, review, approve, execute) analogous to the destruction list pattern
- Support multiple transport protocols: SFTP, REST API, and OpenConnector source
- Track transfer status per object (`overgebracht`, `eDepotReferentie`, `transferErrors`)
- Enforce read-only status on transferred objects
- Log all transfer actions in the audit trail

**Non-Goals:**
- Implementing AIP (Archival Information Package) or DIP (Dissemination Information Package) — only SIP is in scope
- Building a full e-Depot system within OpenRegister — this is an export/transfer feature only
- Handling ingest validation feedback loops (async e-Depot validation callbacks) — only synchronous acceptance/rejection
- PDF/A rendition generation — assumes Docudesk or external tooling provides these; we package what exists

## Decisions

### 1. SIP Package Structure: Flat ZIP with Per-Object Directories

Each SIP is a ZIP archive containing one directory per object, plus package-level metadata files.

```
sip-{transferId}.zip
├── mets.xml                     # Package-level structural map
├── premis.xml                   # Preservation metadata (fixity, events)
├── sip-manifest.json            # Machine-readable manifest with checksums
├── objects/
│   ├── {uuid-1}/
│   │   ├── mdto.xml             # MDTO metadata for this object
│   │   ├── content/
│   │   │   ├── original.pdf     # Original file
│   │   │   └── rendition.pdf    # PDF/A rendition (if available)
│   │   └── metadata.json        # Object data snapshot
│   ├── {uuid-2}/
│   │   └── ...
```

**Rationale**: Per-object directories allow partial failure handling — if an e-Depot rejects individual objects, we can identify exactly which ones failed. The flat ZIP approach avoids nested archives which complicate checksumming.

**Alternative considered**: Single XML with inline Base64 content — rejected because it does not scale for large files and is incompatible with most e-Depot ingest pipelines.

### 2. MDTO XML Generation via PHP DOM

Use PHP's `DOMDocument` to generate MDTO XML rather than a template engine.

**Rationale**: `ext-dom` is always available in Nextcloud environments (hard dependency of Nextcloud core). DOM provides full control over namespace handling, which MDTO requires (`mdto:` namespace prefix). Template engines (Twig, Blade) would add a dependency and make namespace handling fragile.

### 3. Transfer List as Register Objects

Transfer lists are stored as regular OpenRegister objects in a designated "archival management" schema, following the same pattern as destruction lists.

**Rationale**: This reuses existing CRUD, search, audit trail, and API infrastructure. No new database tables needed. The transfer list schema is defined as a standard JSON Schema with properties for status, object references, approval metadata, etc.

### 4. Transport Layer: Strategy Pattern with Three Implementations

Create a `TransportInterface` with implementations: `SftpTransport`, `RestApiTransport`, `OpenConnectorTransport`.

```php
interface TransportInterface {
    public function send(string $sipFilePath, array $config): TransportResult;
    public function testConnection(array $config): bool;
    public function getName(): string;
}
```

**Rationale**: E-Depot systems vary widely — Nationaal Archief uses SFTP, regional archives may offer REST APIs, and OpenConnector provides a generic integration layer. The strategy pattern allows adding new transports without modifying the transfer service.

**Alternative considered**: Only OpenConnector integration — rejected because it adds a hard dependency on OpenConnector for what should be a standalone archival feature.

### 5. Read-Only Enforcement via ObjectService Hook

After successful transfer, objects get `archiefstatus` = `overgebracht`. The existing `ObjectService` save pipeline checks this status and rejects updates with HTTP 409.

**Rationale**: Centralizing the check in `ObjectService` catches all update paths (API, internal service calls, imports). This follows the existing pattern where `ObjectService` already validates object state before persistence.

### 6. Background Job: TransferCheckJob (TimedJob)

A daily `TimedJob` scans for objects where `archiefnominatie` = `bewaren` AND `archiefactiedatum` <= today AND `archiefstatus` = `nog_te_archiveren`, then generates a transfer list for archivist review.

**Rationale**: Mirrors the `DestructionCheckJob` pattern from the archivering-vernietiging spec. Uses `TimedJob` for predictable scheduling with configurable intervals.

## Risks / Trade-offs

- **[Large SIP packages]** Objects with many/large file attachments could produce ZIP files exceeding available tmp space. Mitigation: Stream ZIP creation using `ZipStream-php` or PHP's `ZipArchive` with temp file rotation; add a configurable max-package-size setting that splits into multiple SIPs.

- **[MDTO schema versioning]** The MDTO standard may release new versions. Mitigation: Version the XML generator class; store MDTO version in SIP manifest; make namespace URI configurable.

- **[Transport failures]** Network issues during SFTP/REST transfer could leave partial state. Mitigation: Mark transfer as `in_progress` before sending, update to `completed`/`failed` after; implement per-object status tracking so partial failures are recoverable.

- **[ext-ssh2 for SFTP]** PHP's `ssh2` extension is not always available. Mitigation: Use `phpseclib` (pure PHP SSH/SFTP) as the SFTP implementation — no native extension needed. This is already a common pattern in Nextcloud apps.

- **[Read-only enforcement gaps]** Direct database writes bypass ObjectService. Mitigation: Document that all object mutations MUST go through ObjectService; add database-level check as a future hardening step.

## Migration Plan

1. No database migration needed — `retention` is a JSON field, new sub-keys are additive
2. New `archive` sub-keys on Schema entity are optional with sensible defaults
3. Register the `TransferCheckJob` in Application.php boot; it no-ops if no e-Depot is configured
4. Settings endpoint is admin-only; feature is dormant until an e-Depot endpoint is configured
5. Rollback: disable the app version; no data is removed; `archiefstatus` values remain but are inert without the transfer service

## Open Questions

- Should we support async ingest validation (e-Depot sends callback after processing)? Deferred to a follow-up change.
- Should transferred objects be deletable from OpenRegister after confirmed transfer? Current design says no (read-only), but some organisations may want cleanup.
