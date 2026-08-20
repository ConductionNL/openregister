## 1. TextExtractionService split (do first — cleanest boundaries)

- [ ] 1.1 Pin characterization tests around current extraction output for a PDF, DOCX, XLSX, EML sample.
- [ ] 1.2 Extract `PdfExtractionHandler`, `WordExtractionHandler`, `SpreadsheetExtractionHandler`, `EmlExtractionHandler` from `lib/Service/TextExtractionService.php`; register in DI; delegate from the service.
- [ ] 1.3 Extract `ChunkingHandler`; move the chunking algorithms. Keep `TextExtractionService` as orchestration only.

## 2. SchemaService inference engine split

- [ ] 2.1 Pin tests around `exploreSchemaProperties()` output for a sample schema.
- [ ] 2.2 Extract the inference cluster (`analyzeObjectProperties`, `analyzePropertyValue`, `detectStringFormat`, `recommendPropertyType`, enum detection, comparators) into `SchemaProfiler`/`PropertyTypeInferenceService`.
- [ ] 2.3 Leave `SchemaService` for CRUD/versioning; delegate exploration to the new service.

## 3. ObjectService save-pipeline extraction

- [ ] 3.1 Pin tests around `saveObject()` behaviour (defaults, cascading, validation, contact-cache, render).
- [ ] 3.2 Extract the inline steps of `saveObject()` (`:1127-1307`) into a save-pipeline collaborator; the facade coordinates. Update the docblock's stale "~2,500 lines" note.

## 4. Verification

- [ ] 4.1 All existing unit + integration tests green after each extraction (one PR per extraction).
- [ ] 4.2 opencatalogi + softwarecatalog regression pass (no behaviour change).
- [ ] 4.3 `composer check:strict` passes; remove now-unnecessary `@SuppressWarnings` where the split brings a class under threshold.

## Acceptance criteria

- Each per-format extractor, the chunker, and the schema profiler is its own
  class; the parent services orchestrate rather than implement.
- No external behaviour change; tests green throughout.
- Stale size claims in docblocks corrected.

## Notes

- Sequence AFTER `fix-object-patch-lost-update`, `bound-unbounded-query-memory`
  (they touch these files) to avoid conflicts.
- No `@spec`/behaviour delta — this is a structural refactor, so no `specs/`
  delta is included; behaviour specs for these services remain unchanged.
