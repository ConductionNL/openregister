# Proposal: Reverse-spec backend Service text/oas/schema/mcp methods (svc-mid2)

## Why

A 2026-05-25 coverage scan (`/tmp/or-scan/bw-svc-mid2.json`) flagged 52 uncovered
public methods (`missing @spec`) across four `lib/Service/` sub-trees —
`TextExtraction/`, `Oas/`, `Schemas/`, and `Mcp/`. Most implement behaviors that
already-shipped capabilities describe; a generic per-source text-extraction layer
(handlers implementing `TextExtractionHandlerInterface`, feeding `ChunkMapper`)
had no capability owner. This is a reverse-spec retrofit: it documents observed
behavior of already-shipped code without changing it, applying the ADR-003
two-tool pass — every method is either annotated to an existing requirement,
mapped to one new requirement, or `@spec exclude`d as boilerplate.

## What Changes

- **ADD** the per-source handler requirements to the `text-extraction`
  capability (consolidated from the former `text-extraction-sources` name) —
  three requirements (REQ-001..003) covering the generic per-source extraction
  layer:
  - REQ-001 — common extraction contract (`TextExtractionHandlerInterface::extractText`,
    `FileHandler::getSourceMetadata`, `ObjectHandler::getSourceMetadata`).
  - REQ-002 — normalised extraction + SHA-256 checksum (`FileHandler::extractText`,
    `ObjectHandler::extractText`).
  - REQ-003 — freshness-gated re-extraction (`FileHandler::needsExtraction`,
    `FileHandler::getSourceTimestamp`, `ObjectHandler::needsExtraction`,
    `ObjectHandler::getSourceTimestamp`).
- Annotate 40 methods with `@spec` pointers to existing capabilities (no spec
  change), grouped by capability:
  - `mcp-discovery` (12) — `McpProtocolService` session/capabilities/audit,
    `McpResourcesService` resource definitions, `McpToolsService` tool
    definitions/scoping/audit.
  - `oas-validation` (13) — `OasETagComputer` (ETag revalidation/performance),
    `OasRequestValidator::validate` (request validation), `OasValidationReport`
    (`x-validation-summary` reporting), `ProblemDetailsBuilder` (RFC 7807 /
    API-46 problem details).
  - `object-lifecycle#REQ-010` (8) — `SchemaCacheHandler` two-tier schema cache +
    invalidation (sibling methods of the already-annotated `getSchema`/`invalidate`).
  - `faceting-configuration` (4) — `FacetCacheHandler` multi-layered facet caching
    (`openregister_schema_facet_cache` table, cache-statistics shape).
  - `retrofit-2026-05-24-annotate-openregister#task-13` (1) —
    `PropertyValidatorHandler::validateProperties` (public loop sibling of the
    already-annotated `validateProperty`).
  - `avg-verwerkingsregister` (2) — `EntityRecognitionHandler::extractFromChunk`,
    `::processSourceChunks` (the PII-detection engine the spec names).
- **EXCLUDE** 3 methods as boilerplate (`@spec exclude`): the `jsonSerialize()`
  value-object serialisers on the three EML value objects
  (`EmlAttachment`, `EmlBody`, `EmlStructure`) — pure field-to-array projection
  whose shape is already specified by `text-extraction-eml`.
- No production code behavior changes — annotations and documentation only.

## Counts

- Methods in batch: 52
- Spec'd against a new requirement: 9 (3 new REQs, `text-extraction`)
- Annotated against existing requirements: 40
- Excluded as boilerplate: 3
- New capabilities: 1 (`text-extraction`, consolidated from the former
  `text-extraction-sources` name)
- New requirements: 3 (well under the ≤5 cap)

## Impact

- Affected specs: `text-extraction` (per-source handler requirements, 3
  requirements; consolidated from the former `text-extraction-sources` name).
- Capabilities referenced for annotation (no spec change): `mcp-discovery`,
  `oas-validation`, `object-lifecycle`, `faceting-configuration`,
  `avg-verwerkingsregister`.
- Affected code (annotations only): `lib/Service/TextExtraction/FileHandler.php`,
  `ObjectHandler.php`, `TextExtractionHandlerInterface.php`,
  `EntityRecognitionHandler.php`; `lib/Service/Oas/OasETagComputer.php`,
  `OasRequestValidator.php`, `OasValidationReport.php`, `ProblemDetailsBuilder.php`;
  `lib/Service/Schemas/SchemaCacheHandler.php`, `FacetCacheHandler.php`,
  `PropertyValidatorHandler.php`; `lib/Service/Mcp/McpProtocolService.php`,
  `McpResourcesService.php`, `McpToolsService.php`; and the three EML value
  objects (`@spec exclude`).
- No migrations, no API changes, no behavioral change.

## Notes

- No cohort frontmatter is added to the five existing capabilities — the cohort
  flag tracks REQ provenance, not annotation provenance (per the retrofit
  playbook). Only the new `text-extraction` master spec carries
  `retrofit: true`.
- `object-lifecycle#REQ-010` and the `oas-validation` API-46 / request-validation /
  ETag requirements live in active (un-archived) changes; `@spec` is a textual
  reference and remains valid after those changes archive.
- **Unified (2026-05-25):** the sibling change `retrofit-2026-05-25-bw2-svc-flat-2`
  carries the `text-extraction` capability for the `TextExtractionService`
  orchestration layer (extractFile/extractObject/chunkDocument/queue), which
  delegates to the per-source handlers this delta specifies. The two were adjacent
  layers of the same subsystem, so they have been unified: this delta was renamed
  from `text-extraction-sources` to `text-extraction` and both changes now archive
  into one `text-extraction` capability (handler REQs from here, orchestrator REQs
  from the sibling).

See [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
