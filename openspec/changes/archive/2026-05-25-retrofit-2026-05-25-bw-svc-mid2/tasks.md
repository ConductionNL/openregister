# Tasks

## Capability: text-extraction — per-source handler layer (reverse-spec)

> Consolidated from the former `text-extraction-sources` name; the handler REQs
> below archive into the shared `text-extraction` capability alongside the
> orchestrator REQs from `retrofit-2026-05-25-bw2-svc-flat-2`.

- [x] task-1: text-extraction#REQ-001 — Common extraction contract (`TextExtractionHandlerInterface::extractText`, `FileHandler::getSourceMetadata`, `ObjectHandler::getSourceMetadata`) (retroactive annotation)
- [x] task-2: text-extraction#REQ-002 — Normalised extraction + SHA-256 checksum (`FileHandler::extractText`, `ObjectHandler::extractText`) (retroactive annotation)
- [x] task-3: text-extraction#REQ-003 — Freshness-gated re-extraction (`FileHandler::needsExtraction`, `FileHandler::getSourceTimestamp`, `ObjectHandler::needsExtraction`, `ObjectHandler::getSourceTimestamp`) (retroactive annotation)

## Cross-capability annotations to existing requirements

- [x] task-4: mcp-discovery — MCP protocol/session/capabilities/audit (`McpProtocolService::initialize`, `::createSession`, `::validateSession`, `::destroySession`, `::ping`) (retroactive annotation)
- [x] task-5: mcp-discovery — MCP resource definitions (`McpResourcesService::listResources`, `::listTemplates`, `::readResource`) (retroactive annotation)
- [x] task-6: mcp-discovery — MCP tool definitions/scoping/audit (`McpToolsService::listTools`, `::callTool`, `::invokeTool`, `::addProvider`) (retroactive annotation)
- [x] task-7: oas-validation — ETag revalidation / performance (`OasETagComputer::computeETag`, `::hash`, `::matches`) (retroactive annotation)
- [x] task-8: oas-validation — request validation against OAS schema (`OasRequestValidator::validate`) (retroactive annotation)
- [x] task-9: oas-validation — validation error reporting / x-validation-summary (`OasValidationReport::addError`, `::addWarning`, `::addAutoCorrection`, `::passed`, `::toSummary`) (retroactive annotation)
- [x] task-10: oas-validation — RFC 7807 problem details (API-46) (`ProblemDetailsBuilder::build`, `::validationFailed`, `::notFound`, `::conflict`) (retroactive annotation)
- [x] task-11: object-lifecycle#REQ-010 — two-tier schema cache + invalidation (`SchemaCacheHandler::cacheSchema`, `::cacheSchemaConfiguration`, `::cacheSchemaProperties`, `::cleanExpiredEntries`, `::clearAllCaches`, `::clearSchemaCache`, `::getCacheStatistics`, `::invalidateForSchemaChange`) (retroactive annotation)
- [x] task-12: faceting-configuration — multi-layered facet caching (`FacetCacheHandler::cacheFacetableFields`, `::cleanExpiredEntries`, `::clearAllCaches`, `::getCacheStatistics`) (retroactive annotation)
- [x] task-13: schema property validation (`PropertyValidatorHandler::validateProperties`, sibling of annotated `validateProperty`) (retroactive annotation)
- [x] task-14: avg-verwerkingsregister — automated PII detection (`EntityRecognitionHandler::extractFromChunk`, `::processSourceChunks`) (retroactive annotation)

## Excluded (boilerplate)

- [x] task-15: exclude EML value-object serialisers (`EmlAttachment::jsonSerialize`, `EmlBody::jsonSerialize`, `EmlStructure::jsonSerialize`) — pure VO field-to-array projection, field shape already specified by text-extraction-eml
