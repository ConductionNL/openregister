## Context

OpenRegister's `RenderObject::renderEntity` is the canonical render pipeline that turns an `ObjectEntity` into the JSON response shape consumers see. It already supports a `_extend` mechanism for opting into expensive computations (linked entities, names, schema/register hydration). Extension parameters are normalized at `lib/Service/Object/RenderObject.php:1015` so that shorthand `_x` forms map to their canonical `@self.x` paths — currently only `_schema` ⇄ `@self.schema` and `_register` ⇄ `@self.register`.

File metadata, however, is not on this opt-in path. At `lib/Service/Object/RenderObject.php:908`, `renderEntity` unconditionally calls `$this->renderFiles($entity)`, which:

1. Calls `FileMapper::getFilesForObject($object)` (one query per object).
2. Calls `SystemTagMapper::getTagIdsForObjects(...)` for that object's files (one query per object).
3. Calls `SystemTagManager::getTagsByIds(...)` to resolve labels.
4. Builds a `formattedFile` array per file (id, path, title, accessUrl, downloadUrl, type, extension, size, hash, published, modified, labels).
5. Calls `$object->setFiles($formattedFiles)`.

`ObjectEntity::getObjectArray()` (`lib/Db/ObjectEntity.php:748`) emits the result as `@self.files`.

Because this is unconditional, every consumer of every show endpoint pays the file-lookup cost on every render — even consumers that never use the file information. Meanwhile, list endpoints (`PublicationsController::index` → `ObjectService::searchObjectsPaginated`) bypass `renderEntity` entirely and emit no `@self.files` field at all, leaving consumers with no way to know whether an object has files without an extra round-trip.

The result: `@self.files` is over-served on show, missing on list, and consumers cannot opt in or out. We want a single, explicit, symmetric contract under the existing `_extend` mechanism.

## Goals / Non-Goals

**Goals:**

- Make `@self.files` opt-in for full metadata via `_extend`. Both `?_extend[]=@self.files` and `?_extend[]=_files` are accepted, mirroring the existing `_schema` / `_register` shorthand.
- Provide a lightweight default of file IDs only (`[123, 456, 789]`) on **both** show and list endpoints — symmetric, predictable, cheap.
- Keep the lightweight-default cost bounded: no per-row N+1 on list endpoints. A single batched `FileMapper::getFileIdsForObjects(string[] $uuids)` query covers an entire result page.
- Preserve backwards-compatible *shape* of the full metadata when extend is requested: identical to today's `renderFiles()` output.
- Document the change clearly: breaking semantics for show consumers; performance warning for list+extend.

**Non-Goals:**

- Modifying the body of `RenderObject::renderFiles()`. Only its call site changes.
- Changing `RenderObject::renderFileProperties()` (schema-property file hydration — different mechanism, different concern).
- Changing `PublicationsController::attachments()` in opencatalogi or any equivalent dedicated attachments endpoint.
- Introducing a path-based lightweight form. Kept as an explicit future option if the ID-only default proves insufficient.
- Touching opencatalogi *code*. Only opencatalogi documentation needs to be updated, tracked as a task in this change.
- Persisting the lightweight ID list. The contract is computed at render time; nothing on disk changes.

## Decisions

### 1. Single normalized form: `@self.files`

Add `'_files' => '@self.files'` to the `normalizeMap` at `RenderObject.php:1015`. After normalization, all downstream code only checks for `@self.files` — the `_files` shorthand is an alias, not a parallel branch. This keeps the rule "one contract, two spellings" honest and prevents drift.

### 2. Gate `renderFiles()` at the call site

At `RenderObject.php:908`, replace the unconditional:

```php
$entity = $this->renderFiles(object: $entity);
```

with a conditional that only fires when normalized `_extend` contains `@self.files`. The function body stays untouched. This keeps the diff minimal and the breaking change surgical: full metadata only when explicitly asked for.

### 3. Lightweight default: file IDs

When extend does not cover `@self.files`, populate `@self.files` with a list of file IDs only — `[123, 456, 789]`. Rationale (chosen during exploration):

- **IDs over paths**: paths leak storage layout and break when a file moves; IDs are stable and opaque.
- **IDs over mini-objects**: simpler, tightest contract, cheapest payload. Path-based or mini-object enrichment is left as an explicit future option.
- **Always present**: even an object with zero files emits `@self.files: []` so consumers can rely on the field.

### 4. Batched file-IDs lookup

New method on `FileMapper` (or the appropriate file-lookup component):

```php
public function getFileIdsForObjects(array $uuids): array
{
    // Returns ['<uuid>' => [123, 456, ...], ...]
    // Single query. Empty arrays for UUIDs with no files.
}
```

This is the **lightweight default's** data source. It is called once per render call (for show: one UUID; for list: all UUIDs in the page). It is **not** called when extend covers `@self.files` — that path runs the existing per-object `renderFiles()` instead, accepting the per-row cost in exchange for full metadata.

### 5. Render pipeline integration

Two integration points:

- **Per-entity render** (`RenderObject::renderEntity`): when extend does not cover `@self.files`, call `getFileIdsForObjects([$entity->getUuid()])` and `setFiles()` with the resulting ID array before serialization. Cheap (single query for one UUID).
- **List render** (`ObjectService::searchObjectsPaginated` and the SOLR/IndexService path): collect all result UUIDs once, call `getFileIdsForObjects($uuids)` once, attach the ID lists to each result row's `@self.files`. Done before returning the paginated response. When extend covers `@self.files`, this path still runs, but each row also goes through `renderEntity`/`renderFiles` for full metadata — accepting the per-row cost.

The integration is in openregister core so every consumer (opencatalogi, softwarecatalog, future) inherits it. No consumer-side code changes are required.

### 6. Performance contract

- **Default (no extend), show:** -1 file query, -1 tag query per request vs today. **Strictly cheaper.**
- **Default, list:** +1 batched file query per request. **One additional query, regardless of page size.** Acceptable.
- **Extend, show:** Same as today — one file query + one tag query for the rendered object.
- **Extend, list:** Per-row file/tag queries (N+1 in the per-object cost). **Officially discouraged.** Documented as causing degraded performance. Surfaces as logged warnings? — see Risks below.

### 7. Documentation, including opencatalogi follow-up

The contract change is breaking for any consumer that assumed full file metadata by default on show. Documentation must:

- Call out the breaking change in openregister's CHANGELOG.
- Document the opt-in syntax (both spellings) in OpenRegister API docs.
- Add a perf warning to the docs: "Using `_extend[]=@self.files` (or `_files`) on list endpoints is heavily discouraged. It causes one file lookup per row in the page and degrades performance significantly. Use it only when full file metadata is genuinely required."
- A dedicated task in this change tracks the **opencatalogi** documentation update (no opencatalogi code changes required, just docs).

## Risks / Trade-offs

### Breaking change for show consumers

Any consumer that today reads `result['@self']['files'][i]['downloadUrl']` will receive an integer instead of an object after this change. **Mitigation:** clear changelog entry, prominent docs note, single-line migration (`?_extend[]=@self.files`). Worth flagging in any client SDK release notes.

### List+extend perf foot-gun

A consumer that adds `_extend[]=@self.files` to a list endpoint with a large page size will trigger N+1 file lookups (one `FileMapper::getFilesForObject` and one `SystemTagMapper::getTagIdsForObjects` per row). On a 100-row page that is 200 extra queries. **Mitigation 1:** loud documentation. **Mitigation 2 (optional, can defer):** consider a future enhancement to batch `renderFiles` over multiple objects (`FileMapper::getFilesForObjects` + tag preload) — out of scope here, but worth a "future improvement" note in the spec. **Mitigation 3 (optional):** log a warning when list-with-extend is used so we can observe usage.

### `_files` shorthand collision risk

If a future change introduces another extension named `_files` with different semantics, the normalization would collide. **Mitigation:** the normalize map is the single source of truth; any new shorthand goes through the same review.

### Default ID-only may not be enough for some clients

Some consumers may always need at least the download URL or filename. They can opt in via `_extend`, but that brings the perf cost. **Mitigation:** documented decision; path-based or mini-object lightweight form is held in reserve as a non-breaking future enhancement.

### Empty `@self.files` semantics

We always emit `@self.files`, even as `[]`. This is a small payload bump on objects with zero files. **Mitigation:** acceptable for predictability; consumers no longer need to handle "field present vs missing" as a separate case.

## Migration Plan

No data migration. Pure render-time contract change. Rollout:

1. Land openregister change. Behavior is immediately the new contract for every render call.
2. Consumers that need full file metadata on show update their requests to include `_extend[]=@self.files`.
3. opencatalogi public documentation is updated in the same change cycle (docs-only task in this change).
4. Other openregister consumers (softwarecatalog, etc.) update their docs as needed in their own follow-ups; no code changes required from them.

## Seed Data

Not applicable. This change does not introduce or modify any OpenRegister schemas. Per ADR-016, the Seed Data section is required only when schemas are introduced or modified. The render-time contract change touches the API response shape, not stored data.
