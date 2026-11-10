---
kind: refactor
depends_on: []
---

## Why

Three services flagged Priority 1 (Kritiek) for size/complexity in
`QUALITY_OVERVIEW.md` have **grown** since, not been split — the recommendation
was never acted on:

- `lib/Service/ObjectService.php` — **3856 lines** now (its own docblock still
  claims "~2,500"), 54 public + 24 private methods, 30+ injected deps. It is a
  facade, but `saveObject()` (`:1127-1307`) still does 10+ sequential concerns
  inline (folder access, cascading, defaults, date normalization, validation,
  save, contact-cache invalidation, render).
- `lib/Service/TextExtractionService.php` — **2365 lines** (was 1830 when
  flagged), mixing 11 responsibilities: orchestration, per-format extraction
  (PDF/Word/Spreadsheet/EML), chunking, language heuristics, persistence, batch
  orchestration, sanitisation. `FileService` already demonstrates the target
  shape — it is split into 16 single-responsibility handlers under
  `lib/Service/File/`.
- `lib/Service/SchemaService.php` — **1790 lines**, mixing CRUD/versioning with a
  full property-type inference engine (`analyzeObjectProperties`,
  `detectStringFormat`, `recommendPropertyType`, enum detection, …) — effectively
  a standalone profiler bolted onto the CRUD service.

These carry stacked `@SuppressWarnings` acknowledging the smell. Size alone is
not a bug, but each already hosts real defects found in this review (the PATCH
race, the unbounded exploration scan, the inline `saveObject` pipeline), and the
coupling makes them hard to change safely.

## What Changes

- Extract `TextExtractionService`'s per-format extractors into
  `PdfExtractionHandler`, `WordExtractionHandler`, `SpreadsheetExtractionHandler`,
  `EmlExtractionHandler`, plus a `ChunkingHandler`, mirroring `lib/Service/File/`.
- Extract `SchemaService`'s inference engine into a `SchemaProfiler` /
  `PropertyTypeInferenceService`, leaving `SchemaService` for CRUD/versioning.
- Extract `ObjectService::saveObject()`'s inline steps into a cohesive
  save-pipeline collaborator so the facade coordinates rather than inlines.

## Impact

- Affected: `lib/Service/TextExtractionService.php`, `lib/Service/SchemaService.php`,
  `lib/Service/ObjectService.php`, new handler/service classes, DI registration
  in `lib/AppInfo/Application.php`.
- No external API or behaviour change — pure structural refactor; existing tests
  must stay green and cover the moved logic.
- Risk: high blast radius across consuming apps (opencatalogi, softwarecatalog);
  do it incrementally, one extraction per PR, behaviour-preserving, with tests
  pinned before each move. Sequence AFTER the correctness fixes that touch these
  files (PATCH race, exploration scan) to avoid churn conflicts.
