## Why

Today, `@self.files` on rendered objects behaves asymmetrically and unpredictably depending on which API path the consumer takes:

- **Show endpoints** that route through `RenderObject::renderEntity` always emit fully-formatted file metadata (id, path, accessUrl, downloadUrl, type, size, hash, labels, …) — regardless of whether the consumer needs it.
- **List endpoints** that bypass `renderEntity` (e.g. `searchObjectsPaginated`) emit no `@self.files` field at all.

This means the same logical resource has two different shapes across endpoints, file lookups silently inflate every show response (one FileMapper query + one tag query per render), and consumers cannot opt out on show or opt in on list. The cost is paid by every consumer of every show endpoint, all the time, even when files are irrelevant.

We want a single, explicit, symmetric contract: `@self.files` is always present, cheap by default, and rich on opt-in — same rule on every endpoint, same syntax as the existing `_schema` / `_register` extension shorthands.

## What Changes

- **BREAKING:** `@self.files` on rendered objects no longer contains full file metadata by default. The default is now a lightweight list of file IDs (`[123, 456, 789]`). Consumers that depend on the full metadata MUST opt in via `_extend`.
- **New opt-in via `_extend`:** Both `?_extend[]=@self.files` and `?_extend[]=_files` produce the full formatted file metadata (same shape `RenderObject::renderFiles()` produces today). The two syntaxes are equivalent and normalized via the existing `normalizeMap` in `RenderObject::renderEntity`.
- **List endpoints now emit `@self.files` too** — as the lightweight ID list by default, populated via a single batched `FileMapper::getFileIdsForObjects(string[] $uuids)` query (no N+1).
- **Performance contract for list + extend:** When `_extend[]=@self.files` is requested on a list endpoint, the per-row `renderFiles()` cost applies (one FileMapper query + one tag query per object). This is permitted but officially **discouraged** and must be loudly documented as causing degraded performance on list endpoints.
- **No code changes outside openregister.** Consumers (opencatalogi `PublicationsController::show` and `::index`, and any other render-path consumer) inherit the new behavior automatically. Documentation in opencatalogi must be updated to reflect the new contract — handled as a docs-only follow-up task.

### Out of Scope

- `RenderObject::renderFileProperties` — schema-property file hydration (replaces file IDs with file objects inside object data when a schema property is typed `file`). Different mechanism, untouched.
- `PublicationsController::attachments()` (opencatalogi line 689) — separate explicit attachments endpoint. Untouched.
- The body of `RenderObject::renderFiles()` — only its **call site** at `RenderObject.php:908` becomes conditional; the function itself is not modified.
- Path-based lightweight default. Kept as a documented future option if the ID-only default proves insufficient for clients.

## Capabilities

### New Capabilities
- `files-render-extension`: The contract for when and how `@self.files` appears on rendered objects. Defines the lightweight default (file IDs), the opt-in extend syntax (`@self.files` and `_files`), the symmetry between show and list endpoints, and the documented performance trade-off for list-with-extend.

### Modified Capabilities
None. No existing capability covers the render-time files contract.

## Impact

- **Code (openregister):**
  - `lib/Service/Object/RenderObject.php` — gate `renderFiles()` call on extend; extend `normalizeMap` with `_files` ⇄ `@self.files`.
  - `lib/Db/FileMapper.php` (or equivalent) — new batched `getFileIdsForObjects(string[] $uuids)` method.
  - Search/render pipeline (`ObjectService::searchObjectsPaginated` and entity render path) — populate lightweight `@self.files` by default via the batched lookup; route to `renderFiles()` only when extend covers `@self.files`.
- **API contract:** Breaking change for show endpoints across every consumer of openregister's render output. Mitigation is a one-line query parameter on the consumer side.
- **Dependent apps:** opencatalogi inherits the new behavior with **no code changes**, but its public documentation for `/publications/{catalogSlug}/{id}` and `/publications/{catalogSlug}` must be updated. Tracked as a task in this change. Other render-output consumers (softwarecatalog, etc.) will inherit the new behavior; their docs may need a similar pass but are not blocked by this change.
- **Performance:** Default behavior is **strictly cheaper** than today on show endpoints (no file metadata work) and adds at most one batched query on list endpoints (lightweight IDs). With extend, list endpoints inherit the existing per-row file lookup cost — accepted, documented as discouraged.
- **Tests:** Unit / integration coverage for the four cases — show with/without extend, list with/without extend — plus the `_files` ⇄ `@self.files` normalization equivalence.
- **Migration:** No data migration. Pure render-time contract change. Consumers upgrade by adding `?_extend[]=@self.files` where they need full metadata.
