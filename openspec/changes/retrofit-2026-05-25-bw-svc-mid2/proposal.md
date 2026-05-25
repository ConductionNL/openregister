# Retrofit — backend Service text/oas/schema/mcp (svc-mid2, 2026-05-25)

Reverse-spec / annotate triage over 52 uncovered methods (`missing @spec`)
across four `lib/Service/` sub-trees: `TextExtraction/`, `Oas/`, `Schemas/`,
and `Mcp/`. ADR-003 two-tool pass — every method is either annotated to an
existing requirement, mapped to one new requirement, or `@spec exclude`d as
boilerplate. Code already exists; this change retroactively specifies it.

Source batch: `/tmp/or-scan/bw-svc-mid2.json` (52 methods).

## Outcome

- **49 methods spec'd** — 40 annotated to existing requirements in five
  capabilities (`mcp-discovery`, `oas-validation`, `object-lifecycle`,
  `faceting-configuration`, `avg-verwerkingsregister`) plus 9 mapped to **one
  new capability** `text-extraction-sources` (REQ-001..003, 3 new REQs).
- **3 methods excluded** — the `jsonSerialize()` value-object serialisers on the
  three EML value objects (pure field-to-array projection boilerplate).
- **1 new capability**, **3 new REQs** total (well under the ≤5 cap).

## New capability: text-extraction-sources (9 methods, 3 REQs)

The generic per-source text-extraction layer (handlers implementing
`TextExtractionHandlerInterface`, feeding `ChunkMapper`) had no capability
owner. It is distinct from `text-extraction-eml` (EML parsing primitives) and
`text-extraction-office-completeness` (PhpWord/ODT walking), which cover
format-specific extraction, and from `vector-embeddings`, which consumes the
chunks produced afterward. One new capability captures the source-handler
contract:

- **REQ-001** — common extraction contract (`TextExtractionHandlerInterface::extractText`, `FileHandler::getSourceMetadata`, `ObjectHandler::getSourceMetadata`)
- **REQ-002** — normalised extraction + SHA-256 checksum (`FileHandler::extractText`, `ObjectHandler::extractText`)
- **REQ-003** — freshness-gated re-extraction (`FileHandler::needsExtraction`, `FileHandler::getSourceTimestamp`, `ObjectHandler::needsExtraction`, `ObjectHandler::getSourceTimestamp`)

## Annotated to existing requirements (40 methods)

### Mcp/ → `mcp-discovery` (12 methods)
The `mcp-discovery` spec (status `implemented`) names these services and methods
explicitly in its scenarios, so the annotations are faithful retroactive maps:
- `McpProtocolService` — `initialize`, `createSession`, `validateSession`, `destroySession`, `ping` → "MCP Session Management", "MCP Capabilities Negotiation", "MCP Audit Logging"
- `McpResourcesService` — `listResources`, `listTemplates`, `readResource` → "MCP Resource Definitions"
- `McpToolsService` — `listTools`, `callTool`, `invokeTool`, `addProvider` → "MCP Tool Definitions", "Multi-Register Tool Scoping", "MCP Audit Logging"

### Oas/ → `oas-validation` (13 methods)
The active `oas-validation` change spec covers runtime request/response
validation, validation reporting, performance/ETag caching, and RFC 7807
problem details. The four helper classes implement those requirements:
- `OasETagComputer` — `computeETag`, `hash`, `matches` → "Performance Impact of Validation" (ETag revalidation scenario)
- `OasRequestValidator` — `validate` → "Request Validation Against OAS Schema"
- `OasValidationReport` — `addError`, `addWarning`, `addAutoCorrection`, `passed`, `toSummary` → "Validation Error Reporting" (`x-validation-summary`)
- `ProblemDetailsBuilder` — `build`, `validationFailed`, `notFound`, `conflict` → "Error responses include problem details (API-46 / RFC 7807)"

### Schemas/ → `object-lifecycle` + `faceting-configuration` + annotate-openregister (13 methods)
- `SchemaCacheHandler` (8) — `cacheSchema`, `cacheSchemaConfiguration`, `cacheSchemaProperties`, `cleanExpiredEntries`, `clearAllCaches`, `clearSchemaCache`, `getCacheStatistics`, `invalidateForSchemaChange` → `object-lifecycle#REQ-010` ("Schema reads MUST use a two-tier cache with explicit invalidation"; sibling methods of the already-annotated `getSchema`/`invalidate`, both carrying `retrofit-2026-05-24-b-svc-object-facade` task-5)
- `FacetCacheHandler` (4) — `cacheFacetableFields`, `cleanExpiredEntries`, `clearAllCaches`, `getCacheStatistics` → `faceting-configuration` "Multi-layered facet caching" (names `FacetCacheHandler`, the `openregister_schema_facet_cache` table, the cache-statistics shape)
- `PropertyValidatorHandler` (1) — `validateProperties` → `retrofit-2026-05-24-annotate-openregister` task-13 (the public loop sibling of the already-annotated `validateProperty`)

### TextExtraction/ → `avg-verwerkingsregister` (2 methods)
- `EntityRecognitionHandler` — `extractFromChunk`, `processSourceChunks` → `avg-verwerkingsregister` "Automated PII detection MUST flag unregistered personal data processing" (the spec names `EntityRecognitionHandler` as the PII-detection engine and specifies the regex/Presidio/LLM/hybrid method configuration these methods implement)

## Excluded (3 methods)

Pure value-object serialisation boilerplate — `jsonSerialize()` projects the
VO's public readonly properties into an array with no behavioural contract
beyond the field shape already specified by `text-extraction-eml`:
- `EmlAttachment::jsonSerialize`, `EmlBody::jsonSerialize`, `EmlStructure::jsonSerialize`

## Mode
Mixed: `--cluster text-extraction-sources` (1 new cap, REQ-001..003) plus
cross-capability annotation to five existing capabilities. No existing
requirements are modified.

## Notes
- No cohort frontmatter is added to the five existing capabilities — the cohort
  flag tracks REQ provenance, not annotation provenance (per the retrofit
  playbook's documentation-only sub-patterns). Only the new
  `text-extraction-sources` master spec carries `retrofit: true`.
- `object-lifecycle#REQ-010` and the `oas-validation` API-46/request-validation/
  ETag requirements live in active (un-archived) changes; `@spec` is a textual
  reference and remains valid after those changes archive.

See [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
