---
status: draft
retrofit: true
---

# Retrofit: Reverse-spec controller bundle — object/data (5 sub-clusters)

## Why

A coverage scan of OpenRegister's controller layer identified five HTTP-surface
sub-clusters around object and data operations whose request/response contracts
are not yet captured by any spec. The underlying services are largely
implemented and (for the lifecycle pipeline, import/export, and soft-delete)
already speced at the service layer — but the *controller* contract (route
shape, status codes, parameter handling, error envelopes) was undocumented. This
ghost change reverse-specs the observed controller behavior and annotates the
methods, biasing strongly toward **extending** existing capabilities rather than
minting new ones.

This is a documentation/annotation change only. No runtime behavior is modified.

## What changes

Five sub-clusters, all `--extend`:

### 1. `object-rest-api` → extend **object-lifecycle**

`ObjectsController` is the canonical `/api/objects/{register}/{schema}/...` CRUD
and sub-resource surface. The existing `object-lifecycle` spec documents the
*internal* layered save pipeline (`SaveObject` handlers), not the HTTP contract.
We add REQs that document the controller's observed REST behavior for the route
family that is not otherwise covered: collection listing via the magic-mapper
router (`objects()`), single-object read (`show`), partial update (`patch`),
optimistic locking (`lock`/`unlock`), object merge, and the relation/audit
sub-resources (`contracts`, `uses`, `used`, `logs`). The bulk-validation
trigger (`validate`) and the retired blob-clear endpoint (`clearBlob`) are also
documented.

Already-covered route members are **annotated to their owning cap, not
re-specced**: `index`/`geoSearch` → `zoeken-filteren`; `create`/`update` → the
object-lifecycle save pipeline REQs; `destroy`/`canDelete` →
`deletion-audit-trail`.

### 2. `object-cross-cutting-vector-files-migrate` → CROSS-CUTTING (annotate-only)

Several `ObjectsController` methods physically live in this controller but belong
to other capabilities. **Per the guardrail, these are NOT lumped into one cap —
each method is annotated to the capability that actually owns its behavior, and
NO new REQs are minted for them** (the owning specs already cover the behavior):

- `vectorizeBatch`, `getObjectVectorizationStats`, `getObjectVectorizationCount`
  → **chat-ai** (vector/RAG pipeline; these are the object-side batch vectorize
  controls feeding the same vector store the chat-ai RAG context reads from).
- `downloadFiles` → **files-render-extension** (object file surface; the bulk
  ZIP download is a file-output endpoint for the same attached-files model the
  render extension governs).
- `import`, `export` → **data-import-export** (the controller endpoints the
  import/export spec already names: `ObjectsController::import()` /
  `ObjectsController::export()`).
- `migrate` → **workflow-engine-abstraction** (object migration between
  register/schema with property mapping is the object-mutation operation that
  the workflow/import machinery drives).

The spec delta therefore touches multiple caps but adds spec text only where the
controller contract is genuinely uncovered; the cross-cutting methods get inline
`@spec` annotations pointing at their owning capability via this change's
tasks.md.

### 3. `configuration-api` → extend **data-import-export**

`ConfigurationController` and `ConfigurationsController` are the
configuration-package REST surface. `data-import-export` already owns the
configuration-portability REQ (OpenAPI 3.0.0 register export/import). The
**GitHub/GitLab/URL publishing and discovery** flow (`discover`,
`getGitHubBranches`, `getGitLabBranches`, `importFromGitHub`/`GitLab`/`Url`,
`publishToGitHub`) is a distinct remote-portability surface not yet captured →
one new REQ. The per-configuration `ConfigurationsController::export`/`import`
(file download / upload of a single configuration) is annotated to the existing
portability REQ.

> NOTE: the batch JSON labels several `ConfigurationController` methods as
> "triaged DROP from chat-ai / actions / object-lifecycle / geo-metadata". Per
> architect review those labels are **wrong** — these methods are
> configuration-package GitHub publishing and ARE in-scope for
> `data-import-export`. They are grouped here, not dropped.

### 4. `bulk-data-ops` → extend **data-import-export**

`BulkController` bulk-delete-by-schema/register (`deleteSchema`,
`deleteSchemaObjects`, `deleteRegister`) is a mass-mutation surface adjacent to
the bulk-import already covered by `data-import-export`. The `validateSchema`
and `save`/`delete` members are already annotated/covered. One new REQ documents
the bulk-delete contract. `resolveRegisterSchemaIds` is a private helper
(annotate-only, no REQ).

### 5. `soft-delete-recovery-api` → extend **deletion-audit-trail**

`DeletedController` is the trash/recycle-bin REST surface.
`deletion-audit-trail` REQ-3 (restore) and REQ-11 (list / filter / statistics)
already cover the bulk of this controller, and most methods are **already
annotated** (`index`, `restore`, `restoreMultiple`, `destroy`,
`destroyMultiple` → existing retrofit tasks). The remaining uncovered members —
`statistics` and `topDeleters` (deletion analytics surface) — are annotated to
the existing REQ-11 which names exactly those endpoints. **No new REQs** for
this sub-cluster; it is annotation-only.

## New requirements summary (target ≤ 12)

| Capability | New REQs | Notes |
|---|---|---|
| object-lifecycle | 8 | REST CRUD/lock/merge/relation/audit/validate HTTP surface |
| data-import-export | 2 | config GitHub/GitLab/URL publishing+discovery; bulk delete |
| deletion-audit-trail | 0 | annotation-only (REQ-3/REQ-11 cover it) |
| chat-ai / files-render-extension / workflow-engine-abstraction | 0 | cross-cutting annotation-only |
| **Total** | **10** | within budget |

## Impact

- Specs extended: `object-lifecycle`, `data-import-export`.
- Specs referenced (annotation targets, no delta): `deletion-audit-trail`,
  `chat-ai`, `files-render-extension`, `workflow-engine-abstraction`.
- Controllers annotated: `ObjectsController`, `ConfigurationController`,
  `ConfigurationsController`, `BulkController`, `DeletedController`.
- No code behavior change; docblock `@spec` annotations only.
