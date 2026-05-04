## Context

Retrofit change. Tasks describe retroactive annotation, not new implementation work.

OpenRegister's core object CRUD pipeline is implemented as a layered set of handlers under `lib/Service/Object/`. The coverage scanner classified all 252 methods under `object-interactions` (the notes/CalDAV/file-attachment spec) — a misclassification driven by the directory name overlap. None of these handlers actually touch comments, CalDAV, or file attachments; they implement an internal pipeline (save → validate → cache → metadata-hydrate → bulk-process) that has no corresponding capability spec.

The ghost change `retrofit-object-lifecycle-2026-04-28` mints `object-lifecycle` as a new capability and retroactively specifies the pipeline as 5 REQs. Cross-capability handlers (permissions, audit, faceting, search, export, relations) are tagged with cross-references to their canonical home specs rather than collapsed into `object-lifecycle`.

**Core files covered (single-capability):**
- `lib/Service/Object/SaveObjects.php`, `SaveObject.php`, `CrudHandler.php`, `DeleteObject.php`, `GetObject.php`, `RenderObject.php`
- `lib/Service/Object/ValidateObject.php`, `ValidationHandler.php`, `BulkValidationHandler.php`
- `lib/Service/Object/CacheHandler.php`, `PerformanceHandler.php`, `RelationshipOptimizationHandler.php`
- `lib/Service/Object/SaveObject/MetadataHydrationHandler.php`, `MetadataHandler.php`, `ComputedFieldHandler.php`
- `lib/Service/Object/SaveObjects/PreparationHandler.php`, `ChunkProcessingHandler.php`
- 12+ supporting handlers (Translation, DataManipulation, Migration, Vectorization, Utility, etc.)

**Cross-capability files (tagged with foreign REQs, not respec'd here):**
- `PermissionHandler.php` → rbac-scopes#REQ-001
- `LinkedEntityEnricher.php`, `RelationHandler.php`, etc. → linked-entity-types#REQ-003
- `AuditHandler.php` → audit-trail-immutable#REQ-002
- `FacetHandler.php` → faceting-configuration#REQ-002
- `SearchQueryHandler.php`, `QueryHandler.php` → zoeken-filteren#REQ-001
- `ExportHandler.php` → data-import-export#REQ-007
- `RevertHandler.php`, `FilePropertyHandler.php` → content-versioning

## Goals / Non-Goals

**Goals:**
- Mint `object-lifecycle` as the canonical capability for the internal save/validate/cache/metadata pipeline
- Spec the 5 distinct pipeline phases as REQ-001 through REQ-005
- Cross-reference 12 handlers to their proper foreign capabilities (avoids duplicate spec coverage)
- Annotate all 252 methods with `@spec` tags

**Non-Goals:**
- No code changes — annotations + spec only
- Does not refactor the handler layering (intentional design)
- Does not respec the cross-capability handlers (they belong to their existing specs)
- Example/demo files (`NewFacetingExample.php`, `ObjectServiceFacetExample.php`) are tagged as documentation aids, not as canonical implementations

## Decisions

**Decision: `--cluster object-lifecycle` (new capability) over forcing into `object-interactions`**

The scanner's clustering was wrong. `object-interactions` is about NC integrations (comments, CalDAV, files); the handlers under `lib/Service/Object/` implement the internal CRUD pipeline. These are unrelated subsystems that share no contract.

**Decision: 5 REQs by pipeline phase, not 25 by handler**

The 252 methods cluster cleanly into 5 observable behaviors: save, validate, cache, bulk-process, metadata-hydrate. Per-handler REQs would inflate the spec without adding testable surface; per-method REQs would be unmaintainable.

**Decision: Cross-reference rather than absorb foreign-cap handlers**

`PermissionHandler` enforces RBAC and belongs in `rbac-scopes`. `AuditHandler` writes audit trail and belongs in `audit-trail-immutable`. Tagging them with their canonical home REQs preserves spec ownership and avoids duplicate coverage.

**Decision: `ReferentialIntegrityService` annotated as `object-lifecycle#REQ-001`**

It enforces FK semantics during the save pipeline rather than as a standalone integrity capability. Tagging it under save-pipeline keeps it close to where it executes.

## Risks / Trade-offs

- **Scanner re-classification lag**: until the next coverage scan, the scanner will continue to report these methods under `object-interactions`. The new `object-lifecycle` spec exists; the scanner needs to re-score. → Re-run `/opsx-coverage-scan openregister` after this change merges.
- **REQ granularity may be too coarse**: 5 REQs covering 252 methods is dense. If future spec writers want to evolve a single phase (e.g., add bulk-import semantics), they may need to split a REQ. → Acceptable; the playbook explicitly biases toward fewer, larger REQs for retrofit.
- **Cross-cap tags create spec ↔ spec coupling**: changes to `rbac-scopes#REQ-001` semantics now affect interpretation of `PermissionHandler.php`. → Standard cross-reference cost; better than duplicate coverage.

## Migration Plan

No migration required — annotations only. The ghost change is archived immediately and creates `openspec/specs/object-lifecycle/spec.md`.

`.git-blame-ignore-revs` was updated with the annotation commit SHA. After merge, re-run `/opsx-coverage-scan openregister` to refresh the scanner's bucket assignment.
