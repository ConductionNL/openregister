# OpenRegister Code Review & Improvement Plan

> **Status:** Draft 1 — generated 2026-06-11 from a full multi-agent code review of `lib/` (~371k LOC PHP, 953 files) and `src/` (~115k LOC Vue/JS).
> **Audience:** developers and AI coding agents picking up individual TODOs. Every item is self-contained: it names the exact file, the change to make, and how to verify it.
> **How findings were produced:** eight parallel review agents (security ×2, bugs ×3, performance, refactor, frontend, ops) each read the real code and cited `file:line`. Every CRITICAL and HIGH item below was then **adversarially re-verified** by a second agent against the source. Items marked _verified_ were confirmed by that second pass; items marked _unverified_ came from a single agent and should be re-checked before large changes.

---

## How to use this document

1. Work **top-down by priority**: P0 (security/data-loss) → P1 (correctness bugs) → P2 (performance) → P3 (refactors) → P4 (frontend/ops hygiene).
2. Each task has a stable ID (e.g. `SEC-CTRL-1`). Reference it in commit messages and PR titles.
3. **Project rules that override your defaults** (from `.claude/CLAUDE.md` and team memory):
   - Use the **Edit/Write tools only** for code changes — never `sed`/`awk`/scripts (high risk of breaking files).
   - PHP must pass `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan). Run `bash scripts/run-hydra-gates.sh` (or the `/hydra-gates` skill) before pushing.
   - **Fix pre-existing quality issues** you touch — don't leave them.
   - **Bump `appinfo/info.xml` `<version>`** on any change that affects the JS bundle or app behaviour (NC serves bundles `immutable` for ~6 months).
   - i18n keys are **English source strings** — never Dutch.
   - Don't add `Co-Authored-By` trailers to commits.
   - The local OCP/PHPStan stubs are stale — validate auth/PHPStan reasoning by `php -l` + logic, defer deep PHPStan to CI.
   - OR's real `ObjectService` API is `find / findAll / saveObject / createObject / updateObject / deleteObject` — do not invent methods.
4. **Add a characterization test BEFORE refactoring a giant class** (see `REF-15`). The giants already have test files — extend them.
5. Verify against the running dev container: `http://localhost:8080` (admin:admin), app at `/apps/openregister/`. Reset brute-force: `docker exec nextcloud php occ security:bruteforce:reset 127.0.1`.

---

## Executive summary

OpenRegister is structurally healthy for its age: the worst god-classes already have extraction sub-directories and ~585 unit tests, all `appinfo/routes.php` targets resolve, and the dominant RBAC pattern (derive `$rbac` from `isCurrentUserAdmin()`, enforce in `ObjectService`/`PermissionHandler`) is sound. The problems are at the **edges where that discipline lapses**, and they cluster into five themes:

1. **Request-controlled security switches.** A *public* listing endpoint and the bulk/import paths let the caller pass `?rbac=false&_multi=false` to turn off RBAC **and** multitenancy. (`SEC-CTRL-1`, `SEC-CTRL-6`)
2. **The bulk write path is a second-class citizen.** `SaveObjects` accepts `_validation`/`_events`/`_rbac` flags and **ignores all three** — no per-object authorization, no schema validation, no events, no audit trail, and it lets clients **forge `@self.owner`/`@self.organisation`** (cross-tenant injection). Single-object save does all of this correctly. (`BUG-OBJ-1`, `BUG-OBJ-15`)
3. **Server fetches user-controlled URLs without SSRF protection** in three places; only one ad-hoc guard exists and it isn't on the user-reachable paths. Two Twig environments compile user-authored templates **without a sandbox** (SSTI). (`SEC-SVC-1/2/3/4`)
4. **Silent failures hide data problems:** webhooks record 4xx/5xx as success, GDPR erasure reports success while skipping failed objects, a file-property update drops previously-stored fields, and object/file deletes never clean up Nextcloud files. (`BUG-SVC-1`, `BUG-SVC-2`, `BUG-OBJ-8`, `BUG-OBJ-2`)
5. **Dead registrations:** the entire event-driven Actions feature, all non-create webhooks, and several background jobs are never wired up, so they silently never run. (`OPS-1/2/3/4`)

Performance work is dominated by N+1 query patterns in the render path and per-row redundant work in the magic mapper.

### Priority counts

| Priority | Theme | Count |
|---|---|---|
| **P0** | Security + data-loss (critical/high, verified) | 14 |
| **P1** | Correctness bugs (medium) | ~18 |
| **P2** | Performance | 14 |
| **P3** | Refactor / architecture | 15 |
| **P4** | Frontend + ops hygiene | ~20 |

---

# P0 — Security & data-loss (do first)

> All P0 items were adversarially verified against source unless noted.

## SEC-CTRL-1 — Public listing endpoint lets anyone disable RBAC + multitenancy _(critical, verified)_
- **File:** `lib/Controller/ObjectsController.php` — `index()` (method ~`:970`, annotations `@NoAdminRequired @NoCSRFRequired @PublicPage` at `:956-960`); flags read at `:1021` and `:1023-1024`; forwarded at `:1052-1053`, `:1092-1093`, `:1270-1274`. Enforcement bypass: `lib/Service/Object/PermissionHandler.php:216-219` (`if ($_rbac === false) { return true; }`).
- **Problem:** `index()` is reachable anonymously and derives `rbac`/`multi` from **request params** instead of admin status. Any caller can request `?rbac=false&_multi=false` to list every object across all organisations and ACLs. `geoSearch()` re-invokes `index()` and inherits this.
- **Fix:**
  1. In `index()`, delete the two lines that read `rbac`/`multi` from `$params` (~`:1021-1024`).
  2. Replace with the admin-derived posture already used by `show()` (see `:1754-1757`):
     ```php
     $isAdmin = $this->isCurrentUserAdmin();
     $rbac    = ($isAdmin === false);
     $multi   = ($isAdmin === false);
     ```
  3. Search the file for `'rbac'`, `'_multi'`, `'multi'` and ensure no surviving path takes them from `$params`/`getParam` (also check `objects()`, `crossTableSearch()`, `geoSearch()`).
- **Verify:** As a non-admin/anonymous user, `curl 'http://localhost:8080/index.php/apps/openregister/api/objects/<reg>/<schema>?rbac=false&_multi=false'` must return only authorized objects. Add a Newman test asserting the `rbac=false` result count equals the default for a non-admin.

## SEC-CTRL-6 — Bulk import still forwards request-controlled `rbac`/`multi` _(medium→high, verified pattern)_
- **File:** `lib/Controller/RegistersController.php:1299-1300` (Excel branch) + the CSV branch just below; forwarded to `importService->importFromExcel(_rbac:..., _multitenancy:...)`.
- **Problem:** Even though *who* may import is gated by `checkRegisterManagePermission()`, the dangerous flags are still read from the request, so a manager can pass `?multi=false` to write objects **across organisation boundaries**.
- **Fix:** Stop reading `rbac`/`multi` from the request here. Derive `$rbac = !$this->isCurrentUserAdmin();` and **hardcode `_multitenancy: true`** so imports stay in the caller's active organisation. Apply to both Excel and CSV branches.
- **Verify:** Import with `?multi=false` as a non-super-admin manager; confirm objects land only in the caller's active organisation.

## SEC-CTRL-3 — IDOR: any authenticated user can read/export any configuration _(high, verified)_
- **File:** `lib/Controller/ConfigurationController.php` — `show()` `:216`, `preview()` `:613`, `export()` `:718` (all `@NoAdminRequired`, no guard). Contrast `create()` `:333`, `update()` `:406`, `destroy()` `:511`, `import()` `:654` which all start with `if ($this->isCurrentUserAdmin() === false) { return 403; }`.
- **Problem:** `export()` with `?includeObjects=true` serialises a configuration's objects — bulk exfiltration by incrementing the numeric `id`.
- **Fix:** Add the same admin guard to the top of `show()`, `preview()`, `export()`:
  ```php
  if ($this->isCurrentUserAdmin() === false) {
      return new JSONResponse(data: ['error' => 'Admin privileges required'], statusCode: 403);
  }
  ```
  (If non-admins are meant to read configs they own, use an owner check instead of leaving it open.)
- **Verify:** As a non-admin, `curl '.../api/configurations/1/export?includeObjects=true'` → 403. Add an integration test.

## SEC-CTRL-2 — Public `names` endpoint leaks object/organisation names _(high, partially verified)_
- **File:** `lib/Controller/NamesController.php` — `index()` `:114-117` (`#[NoAdminRequired]`/`#[PublicPage]`, no auth check, calls `getAllObjectNames()` at `:173`). Backing: `lib/Service/Object/CacheHandler.php` `warmupNameCache()` — `findAllWithUserCount()` `:1460` (all orgs) + `getObjectMapper()->findAll()` `:1472` (no RBAC).
- **Verification nuance:** `index()` + `warmupNameCache` confirmed unauthenticated and RBAC-blind. **But** `create()` requires an `ids[]` body (resolves via `getMultipleObjectNames`, not the full dump), and `MagicMapper::findAll()` returns `[]` when no register/schema is supplied (`MagicMapper.php:7032-7038`), so the *full* unbounded object-name dump may not materialise via that exact path — the **organisation** name leak and the per-`ids` resolution leak are the concrete risks.
- **Fix:**
  1. Require authentication: inject `IUserSession`, return 401 when `getUser() === null`, and drop `#[PublicPage]` from `index()` and `create()`.
  2. Make name resolution RBAC/tenant-aware: filter `getMultipleObjectNames()`/`getAllObjectNames()` by the caller's read permissions + active organisation. Never expose an unbounded global name map over HTTP.
  3. Apply the same to organisation names in `warmupNameCache()`.
- **Verify:** `curl .../api/names` logged-out → 401. As a low-priv user, `POST /api/names {"ids":["<uuid-you-cant-read>"]}` must omit that UUID.

## BUG-OBJ-1 — Bulk save bypasses validation, RBAC, events, and audit _(critical, verified)_
- **File:** `lib/Service/Object/SaveObjects.php` — `processObjectsChunk()` `:963-1017` declares `bool $_rbac, $_multitenancy, $_validation, $_events` (`:966-969`) but the body only calls `transformChunk → persistChunk → buildChunkResults` and **references none of them**. Caller: `lib/Controller/BulkController.php:345-398` — the comment at `:349-351` falsely claims per-object RBAC runs; mixed-schema (`schema=0`) skips even the schema-level gate at `:354-370`.
- **Problem:** A user POSTing bulk with `schema=0` can mass-insert into schemas/registers they cannot write individually, write data that fails `hardValidation`, fire no lifecycle events, and leave no audit trail. This is a wholesale regression of every guarantee single-object `saveObject` enforces.
- **Fix (in `processObjectsChunk`, before `persistChunk`):**
  1. When `$_rbac === true`, inject `PermissionHandler` and check `create`/`update` permission per object; move failures into `$result['invalid']`.
  2. When `$_validation === true`, run `ValidateObject::validateObject()` per object against the resolved schema; reject invalid ones.
  3. When `$_events === true`, dispatch `ObjectCreatedEvent`/`ObjectUpdatedEvent` after persist.
  4. Always write the audit trail (unless an explicit `silent` flag is set).
  5. In `BulkController`, add an explicit per-object RBAC gate for the mixed-schema path and delete the false comment.
- **Verify:** As a non-admin without manage rights, `POST` bulk with `schema=0` + objects in a restricted schema → all rejected. Bulk into a `hardValidation=true` schema with an invalid object → it lands in `invalid`, not `saved`. Assert an audit row exists per created object.

## BUG-OBJ-15 — Bulk save lets clients forge `@self.owner` / `@self.organisation` _(high, verified)_
- **File:** `lib/Service/Object/SaveObjects.php` — `hydrateObjectMetadataFields()` `:1734-1752` only fills owner/organisation **when absent** (`:1737`, `:1746`), keeping client-supplied values. Contrast single-save `lib/Service/Object/SaveObject.php` `setSelfMetadata()` `:3633-3664`: owner is never accepted from the client; organisation only when `isAdmin || hasAccessToOrganisation()`.
- **Problem:** Through the bulk endpoint a user can plant objects owned by someone else and inside another tenant — the exact cross-tenant injection the single-save "wave-7/wave-11" fixes prevent.
- **Fix:** In `hydrateObjectMetadataFields`, mirror the single-save policy: always stamp `owner` to the session user (ignore client input); accept `organisation` only when `isAdmin` or `hasAccessToOrganisation($value)`, else fall back to `getOrganisationForNewEntity()`. Add a regression test pinning parity with single-save.
- **Verify:** As a non-admin in org A, bulk-save an object with `@self.owner="victim"` + `@self.organisation="<orgB>"` → persisted owner is the caller, organisation is org A.

## BUG-OBJ-8 — File-property update overwrites the object with raw partial input (data loss) _(high, verified)_
- **File:** `lib/Service/Object/SaveObject.php` — `updateObject()` `:4887`; merged data built by `prepareObjectForUpdate()` `:4928` and persisted at `:4968`; then on the file-property path `:5030` does `$updatedEntity->setObject($data)` using the **raw incoming `$data`** (only `@self`/`id` removed at `:4912`) and re-persists at `:5050`.
- **Problem:** A PATCH-style update that includes any file property discards the merged/prepared body (computed fields, defaults, cascaded sub-objects, null-fills, **and previously-stored fields not present in the request**). Without a file property the merge is kept; with one it's lost — data loss that depends on payload shape.
- **Fix:** Build the post-file body from the merged data: start from `$preparedObject->getObject()` (or `$updatedEntity->getObject()`), overlay only the file-id replacements computed by the file handler, then `setObject(...)`. Never use raw `$data` as the full body.
- **Verify:** Create object `{a, b, fileX}`; PATCH with only `{fileX:newUpload}`; re-fetch and assert `a` and `b` survive.

## BUG-OBJ-2 — Object delete never cleans up Nextcloud files/folders _(high, verified by reviewer; re-confirm before fix)_
- **File:** `lib/Service/Object/DeleteObject.php` — `delete()` `:175-372`. Permanent delete (`:210-246`) calls only `deleteObjectEntity(hardDelete:true)`; soft delete (`:248-330`) sets the `deleted` metadata. No `FileService`/folder cleanup anywhere (the class header claims it does).
- **Problem:** Every object with an attached folder/file leaks its NC folder forever after permanent delete — storage bloat and **undestroyed file contents** despite the archival/`vernietigd` destruction workflow (a compliance issue).
- **Fix:** Inject `FileService`. In the permanent-delete branch, resolve `$objectEntity->getFolder()` and delete it via `FileService` (guard null/legacy). Decide with product whether soft delete should trash (recoverable) the folder too.
- **Verify:** Create an object with a file, permanently delete it, assert `$rootFolder->getById(...)` is empty. Add a regression test.

## SEC-SVC-1 / SEC-SVC-2 — SSRF: server fetches user-supplied URLs with no IP/scheme allowlist _(high, verified)_
- **Files:**
  - `lib/Service/UploadService.php` — `getJSONfromURL()` `:247-285` (`$this->client->request('GET', $url)` at `:251`, no validation). Entry: `SchemasController::upload()` `:816-818` is `@NoAdminRequired`; for an existing schema only *manage* permission is required.
  - `lib/Service/Object/SaveObject/FilePropertyHandler.php` — `fetchFileFromUrl()` `:916-938` (`file_get_contents` with `follow_location:true, max_redirects:5`, no IP check). Triggered whenever an object is written with a file-typed property whose value is an `http(s)://` URL.
  - Contrast: `ConfigurationController::fetchConfigFromUrl()` `:1206-1242` **does** have an https-only + `gethostbyname` private-IP guard — but the two paths above don't use it, and there is **no shared helper**.
- **Problem:** Any authenticated user can make the server fetch `http://169.254.169.254/...` (cloud metadata), `http://localhost:*`, or RFC-1918 hosts and read the result back (full read SSRF). `follow_location:true` defeats naive allowlists via redirect.
- **Fix:**
  1. Create one shared `assertSafeFetchUrl(string $url): void` (put it in `lib/Service/SecurityService.php`) that: rejects non-`http(s)` schemes; resolves **all** A **and** AAAA records (`dns_get_record`/`gethostbynamel`) and rejects if **any** is loopback/RFC-1918/link-local/ULA/IPv6-loopback (`filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE) === false`); optionally enforces a config-gated allowlist `openregister/import_url_allowlist` (mirror opencatalogi's `local_federation_hosts` pattern).
  2. Call it at the top of `getJSONfromURL`, `fetchFileFromUrl`, **and** refactor `fetchConfigFromUrl` to use it (`SEC-SVC-6`).
  3. Disable redirects (`allow_redirects:false` / `max_redirects:0`); if redirects are required, re-validate each `Location`. Prefer the injected `IClientService` Guzzle client over raw `file_get_contents`.
- **Verify:** POST a schema/object with `url` = `http://127.0.0.1/`, `http://169.254.169.254/`, `http://10.0.0.1/`, and a public URL that 302-redirects to `10.0.0.1` → all rejected; a public allowlisted URL → 200.

## SEC-SVC-6 — `fetchConfigFromUrl` SSRF guard is DNS-rebinding/IPv6-bypassable _(medium, verified guard exists)_
- **File:** `lib/Controller/ConfigurationController.php:1206-1242`.
- **Problem:** TOCTOU — `gethostbyname` resolves once for validation, Guzzle re-resolves at fetch (DNS rebinding); `gethostbyname` returns only the first A record (no AAAA, no multi-record); redirects unconstrained.
- **Fix:** Replace with the shared `assertSafeFetchUrl()` from `SEC-SVC-1`; pass `allow_redirects:false`; to fully close rebinding, pin the connection to the validated IP (Guzzle `CURLOPT_RESOLVE` / `force_ip_resolve`).
- **Verify:** A DNS name alternating public↔`127.0.0.1` and an `[::1]` URL are both rejected.

## SEC-SVC-3 / SEC-SVC-4 — SSTI: user-authored Twig templates compiled without a sandbox _(high/medium, verified)_
- **Files:**
  - `lib/Service/MappingService.php` `:110-111` (`new Environment(new ArrayLoader([]))`, no sandbox) + `:553` (`createTemplate($templateString)` on user mapping strings).
  - `lib/Service/AuthenticationService.php` `:98` + `:337` (`createTemplate($configuration['payload'])->render($configuration)`, no sandbox, full config — incl. secrets — in context).
  - **Reference implementation to copy:** `lib/Service/Object/SaveObject/ComputedFieldHandler.php:124-162` already builds a `Twig\Sandbox\SecurityPolicy` + `new SandboxExtension($policy, sandboxed: true)`.
- **Problem:** A malicious mapping/payload template can reach Twig internals (`_self`, attribute/method access) for info disclosure and, depending on exposed objects/callables, code execution. `AuthenticationService` additionally leaks adjacent secrets via the full `$configuration` context.
- **Fix:**
  1. In `MappingService`, build a `SecurityPolicy` allowlisting only needed tags (`if`, `for`, `set`, `apply`), the app's filters (`b64enc`/`b64dec`/`json_decode`/`zgw_*`), functions (`executeMapping`, `generateUuid`), no methods/properties; `addExtension(new SandboxExtension($policy, true))`. Copy the `ComputedFieldHandler` pattern verbatim and adjust the allowlist.
  2. In `AuthenticationService`, add the same sandbox **and** pass a filtered context (only the intended claim fields, not the whole `$configuration` with secrets).
- **Verify:** Templates with `{{ _self }}` / `{{ object.getClass() }}` / a non-allowlisted filter throw `SecurityError`; legitimate `{{ value|b64enc }}` still renders.

## BUG-DB-2 — SQL injection in the `@self` terms-facet UNION path _(high, verified)_
- **File:** `lib/Db/MagicMapper/MagicFacetHandler.php` — `getTermsFacetUnion()` `:568-621` concatenates `$field` raw into `CAST({$field} AS CHAR)`/`{$field}::text` (`:589-591`), `WHERE {$field} IS NOT NULL` (`:600`), `GROUP BY {$castField}` (`:619`). The `@self` caller (`:515` `$columnName = self::METADATA_PREFIX.$field`, `:518-524`) builds the column straight from request facet keys with **no** `sanitizeColumnName`/`columnExists` — unlike the object-field branch (`:454`/`:460`) and `getDateHistogramFacetUnion` (`:828`).
- **Problem:** A multi-table facet request with a crafted `_facets[@self][...]` key injects arbitrary SQL.
- **Fix:** In `getTermsFacetUnion`, before building SQL, run `$field` through `sanitizeColumnName` and validate against the metadata-column allowlist (`array_keys($this->getMetadataColumns())`); skip the facet if it fails. Mirror the `columnExists` guard the other two paths already use.
- **Verify:** Issue a multi-register search with `_facets[@self][_created) UNION SELECT ...]`; confirm the facet is skipped (empty buckets), not executed.

## BUG-DB-1 — Schema default values interpolated into DDL without escaping _(high, verified pattern)_
- **File:** `lib/Db/MagicMapper.php` — `formatDefaultValueForSQL()` `:4346-4348` (`return "'".$default."'";`) and the same pattern in `createTable` `:2654-2655`.
- **Problem:** An admin-authored property `default` containing a single quote (`O'Brien`) breaks the `ALTER TABLE ... DEFAULT '...'` DDL and aborts table sync; a crafted default is stored-DDL injection. Value writes use `connection->quote()`; the DDL path does not.
- **Fix:** Escape via the platform: `$this->db->getDatabasePlatform()->quoteStringLiteral($default)` (or `$this->db->quote($default)`); apply in both spots. Never hand-build `"'".$x."'"`.
- **Verify:** Create a property with default `a'b`, enable magic mapping, confirm the table syncs and the column default is literally `a'b`.

## SEC-CTRL-5 — File read path re-owns files instead of denying _(medium, single-agent; re-verify)_
- **File:** `lib/Controller/FilesController.php` — `downloadById()` `:1038`, `show()` `:268`; `lib/Service/File/FileValidationHandler.php` — `checkOwnership()` `:278-312` calls `ownFile()` (reassigns ownership) on owner mismatch instead of denying.
- **Problem:** Authenticated file access has no object-level RBAC (only NC mount visibility), and a GET **mutates file ownership** — a state-changing side effect on a read path; any mount-visibility drift becomes a cross-user read.
- **Fix:** In `checkOwnership()`, throw `NotPermittedException` on owner mismatch when the file isn't shared/published to the caller — never call `ownFile()` on a read. In `downloadById()`/`show()`, resolve the parent object and run a `PermissionHandler` read check for authenticated callers too. Move any legitimate ownership repair to an explicit admin job.
- **Verify:** As user B, request a file in user A's object that B can't read → 403/404, and the file's owner is unchanged afterward.

---

# P1 — Correctness bugs

## Object pipeline

- **BUG-OBJ-3 — Magic-mapped objects can never set `@self.published`** _(high, verified — known federation gap)._ `lib/Service/Object/SaveObject.php` `setSelfMetadata()` `:3621-3715` and `SaveObjects.php` never read/set `published`/`depublished` (grep confirms no `setPublished`). **Fix:** in `setSelfMetadata`, after the organisation block, accept `published`/`depublished` from `$selfData` (with a `publish` permission check) and call `setPublished()`/`setDepublished()`; mirror in the bulk path's metadata hydration; on UPDATE only overwrite when the key is explicitly present. **Verify:** save with `@self.published`, re-fetch anonymously via the publication endpoint, assert visible; update without the key, assert state preserved.
- **BUG-OBJ-4 — Null-schema save throws raw `TypeError` 500** _(medium)._ `lib/Service/ObjectService.php:1215` calls `applyAlwaysDefaults(schema: $this->currentSchema, ...)` (non-nullable param) and `:1443` `$this->currentSchema->getHardValidation()` with no null guard, while `checkSavePermissions` returns early on null schema (`:1352`). **Fix:** throw a structured 400 "Schema could not be resolved" when `currentSchema === null` early in the save flow; add a null guard to `validateObjectIfRequired`. **Verify:** `POST /api/objects` with no resolvable schema → 400, not 500.
- **BUG-OBJ-5 — Bulk-delete cache invalidation passes null register/schema** _(medium)._ `lib/Service/ObjectService.php:3185-3201` calls `invalidateForObjectChange(... registerId:null, schemaId:null)`; `CacheHandler::clearSchemaRelatedCaches` only clears the distributed query cache when `schemaId !== null` (`CacheHandler.php:767`). **Fix:** collect distinct `(register, schema)` pairs from deleted objects in the loop, invalidate per pair. **Verify:** cache a collection query, bulk-delete one object, re-run query → deleted object gone immediately.
- **BUG-OBJ-6 — `CacheHandler::getObject()` caches across tenants with a bare-id key** _(medium)._ `CacheHandler.php:289-312` — `$key=(string)$identifier`, `find()` with no `_rbac`/`_multitenancy`/tenant discriminator. **Fix:** route reads through `ObjectService::find` (which post-fetch `checkPermission`s), or include the active organisation in the key and re-apply tenant filtering; audit all callers. **Verify:** tenant B can't retrieve tenant A's object via any path touching `getObject()`.
- **BUG-OBJ-7 — Object-name cache keyed by UUID only, never invalidated on rename** _(low)._ `CacheHandler.php:1071-1290`. **Fix:** add name-cache invalidation to `invalidateForObjectChange`; include organisation in the key if names are tenant-sensitive.
- **BUG-OBJ-9 — Contact-match cache invalidated with pre-save input array** _(low)._ `ObjectService.php:1251-1264` passes `$object` (no final UUID/defaults) not `$savedObject`; whole block swallows `\Throwable`. **Fix:** pass `$savedObject`; narrow the catch + log.
- **BUG-OBJ-10 — Bulk cascade count uses shared mutable `getLastCascadeCount()`** _(low)._ `ObjectService.php:3146-3158`. **Fix:** return the cascade count from `deleteObject` instead of via instance state, or reset it at the start of every delete.
- **BUG-OBJ-11 — `DeleteObject::delete()` reads `$object['id']` without existence check** _(low)._ `DeleteObject.php:190-192`. **Fix:** `$identifier = $object['id'] ?? $object['@self']['id'] ?? $object['uuid'] ?? null;` and throw if still null.
- **BUG-OBJ-13 — `find()` mutates shared `currentRegister`/`currentSchema` as a read side-effect** _(low, but caused openregister#1520-class bugs)._ `ObjectService.php:604-699` (esp. `:656-665`). **Fix:** resolve the render context into locals and pass explicitly; or snapshot-and-restore in a `finally`.
- **BUG-OBJ-14 — Empty/silent `catch` blocks hide cache-invalidation failures** _(low)._ `ObjectService.php:1260-1264, 3030-3038, 3193-3201`; `DeleteObject.php:319-329` (empty body). **Fix:** log a warning with object/register/schema context in each.

## Db layer

- **BUG-DB-3 — Hardcoded `oc_` prefix breaks every raw-SQL path on a custom `dbtableprefix`** _(high, verified)._ `lib/Db/MagicMapper.php` (~21 sites incl. `:1324, :1715, :2820, :4388, :4669`), `MagicBulkHandler.php:491`, `MagicFacetHandler.php:583,821` — all `'oc_'.$tableName`, while `createTable` `:2621` correctly uses `config->getSystemValue('dbtableprefix','oc_')`. **Fix:** add one helper `getFullTableName(string $tableName): string` reading `dbtableprefix` once; replace every `'oc_'.$tableName` and `$prefix='oc_'`. Also fix the two `str_replace('oc_', '', ...)` calls (`:5075, :5139`) → `substr($fullTableName, strlen($prefix))`. **Verify:** install with `dbtableprefix=nc_`, confirm cross-register search/facets/bulk import work.
- **BUG-DB-4 — Non-deterministic pagination: LIMIT/OFFSET without a default ORDER BY** _(medium-high)._ `MagicSearchHandler.php:248-253`. **Fix:** when `$order` is empty, add a stable default (`addOrderBy('t._id','ASC')` or `t._created, t._id`) before `setMaxResults`; add a tiebreaker to the UNION path too. **Verify:** page through 50 rows at `_limit=10` with no `_order`; union of pages = full set, no dupes across runs.
- **BUG-DB-5 — Bulk UPSERT has no transaction → partial writes on failure** _(medium-high)._ `MagicBulkHandler.php:374-383`. **Fix:** wrap the chunk loop in `beginTransaction()/commit()/rollBack()`. **Verify:** import a batch where one row violates a constraint mid-stream → no rows persist.
- **BUG-DB-6 — Bulk UPSERT silently drops objects lacking `_uuid`** _(medium)._ `MagicBulkHandler.php:451-466`. **Fix:** generate a UUID for uuid-less rows, or collect them into an error bucket; don't drop silently.
- **BUG-DB-9 — Inverse-relation lookup uses invalid MySQL `CAST(table AS CHAR)`, failure swallowed** _(medium)._ `MagicMapper.php:6588-6624` — always throws on MariaDB/MySQL, caught at *debug* and returns `[]`, so inverse relations silently resolve to nothing. **Fix:** replace with a valid per-column `CONCAT_WS(...) LIKE ?` / `JSON_SEARCH`; raise the catch to warning. **Verify:** on MariaDB, A references B by UUID, inverse lookup for B returns A.
- **BUG-DB-7 — Identifier quoting doesn't escape embedded quote chars** _(medium, defense-in-depth)._ `MagicMapper.php:3956-3963` + raw backtick concat at `:2630-2690`. **Fix:** double the quote char (`str_replace('"','""',$name)` / `` str_replace('`','``',$name) ``); route `createTable`'s raw `$column['name']` through `quoteIdentifier`.
- **BUG-DB-8 — `sanitizeColumnName` is non-injective → property collisions / lossy round-trip** _(medium)._ `MagicMapper.php:3388-3410`. **Fix:** detect collisions when building the column map and append a deterministic disambiguator (or reject the schema); store the original property name in a sidecar map rather than relying on a lossy inverse.
- **BUG-DB-10 — Schema `find()` cache key omits `$published`; numeric-slug vs id ambiguity** _(medium)._ `SchemaMapper.php:249, 269-281, 331`. **Fix:** include `published` in the read+write cache keys (`:249`/`:328`); prefer exact-id match when `is_numeric($id)`; guard `getUuid() !== null` before `strtolower`. (Same UUID-null pattern in `RegisterMapper`.)
- **BUG-DB-12 — Naive semver bump drops pre-release suffix** _(low)._ `RegisterMapper.php:691-694` — `1.0.0-beta` → `1.0.1`. **Fix:** parse the numeric patch with a regex preserving any `-suffix`; pad missing segments to three.
- **BUG-DB-11 — Leftover `logger->error` debug block in the hot write path** _(low)._ `MagicMapper.php:3222-3235` (hardcoded `element`/`gemmaType`). **Fix:** delete (or gate behind a debug flag at `debug` level).
- **BUG-DB-13 — Request-scoped schema-version cache can mask intra-request schema edits** _(low)._ `MagicMapper.php:3512-3534`. **Fix:** include the schema `version`/`updated` in the cache key, or invalidate on schema mutation.

## Other services

- **BUG-SVC-1 — Webhooks record HTTP 4xx/5xx as successful deliveries** _(high, verified)._ `lib/Service/WebhookService.php:189` (`http_errors:false`) + `deliverWebhook()` `:689-713` sets `setSuccess(true)` + `updateStatistics(success:true)` unconditionally; only network errors hit the catch. **Fix:** after `sendRequest`, branch on `$response['status_code']` 2xx; on non-2xx take the failure path (`setSuccess(false)`, log, `updateStatistics(false)`, schedule retry, return false). **Verify:** point a webhook at a 500 endpoint → log row `success=false` + retry queued.
- **BUG-SVC-2 — DSAR right-to-erasure reports success while skipping failed objects** _(high, GDPR)._ `lib/Service/DsarService.php:294-339` — no `failed` bucket; a failed soft-delete is logged and dropped. **Fix:** add `'failed' => []`, append `{object, error}` in the catch, have the caller treat non-empty `failed` as partial failure (207/error). **Verify:** force one update to throw → summary's `failed` populated, not reported complete.
- **BUG-SVC-3 — Solr search applies neither RBAC nor multitenancy** _(high, verified)._ `lib/Service/Index/Backends/Solr/SolrQueryExecutor.php:161-227` accepts `$_rbac`/`$_multitenancy` but only ever adds `-deleted:true`; no org/owner `fq`. Contrast `AggregationRunner.php:1169-1171` (`_organisation = ?`, fail-closed). **Fix:** when `$_multitenancy`, add `_organisation:<uuid>` (fail closed when no active org); when `$_rbac`, add the owner/group predicate mirroring `MagicRbacHandler`; ensure the indexer writes `_organisation`/ACL fields. **Verify:** index docs for two orgs, search as org-A user, org-B docs absent.
- **BUG-SVC-4 — Postgres date-bucket labels shift by the server UTC offset** _(medium)._ `AggregationRunner.php:1385-1404` (`coerceBucketKey`) — `strtotime` parses offset-less Postgres text in the server TZ, then `gmdate` re-expresses as UTC. **Fix:** when `$raw` has no TZ designator, parse as UTC explicitly (`DateTime::createFromFormat('Y-m-d H:i:s', $raw, new DateTimeZone('UTC'))`); only `strtotime` for offset-bearing shapes. **Verify:** on a CET server, daily aggregation buckets = local date as UTC midnight, not shifted.
- **BUG-SVC-5 — Import drops timezone offset instead of normalizing** _(medium)._ `ImportService.php:1193-1229` — `format('Y-m-d H:i:s')` discards the offset. **Fix:** `setTimezone(new DateTimeZone('UTC'))` before formatting; document the assumed zone for offset-less inputs. **Verify:** `...T00:00:00+05:00` and the equivalent `Z` instant persist to the same value.
- **BUG-SVC-6 — `getSearchBackendConfig` fatals on corrupt JSON (`TypeError` not caught)** _(medium)._ `SettingsService.php:417-442` — `json_decode` can return null into an `: array` return; `catch (\Exception)` misses `TypeError`. **Fix:** capture the decode, `if (is_array($decoded) === false) return <default>;`. **Verify:** set `search_backend` to `"not-json"` → getter returns default.
- **BUG-SVC-7 — Native aggregation builds malformed SQL for value metrics with null field** _(low)._ `AggregationRunner.php:1121-1123, 1228-1249`. **Fix:** short-circuit to fallback when `metric ∈ {sum,avg,min,max}` and `field` is null/empty.
- **BUG-SVC-8 — Notification numeric `eq`/`ne` compares as strings (`1.0 != 1`)** _(low)._ `AnnotationNotificationDispatcher.php:1180-1200`. **Fix:** when both sides numeric, compare as floats; keep string fallback for non-numeric.
- **BUG-SVC-9 — PHP-fallback aggregation equality uses strict `!==`, dropping rows on type mismatch** _(low)._ `AggregationRunner.php:980-1010` — magic-table values are strings, criteria may be int/bool → Postgres path and fallback diverge. **Fix:** compare with `(string)` cast (guarding non-scalars).
- **BUG-SVC-10 — Solr `q` not Lucene-escaped** _(low)._ `SolrQueryExecutor.php:108, 205-211`. **Fix:** add `escapeSolrQuery()` backslash-escaping the Lucene special set, apply to the user term.
- **BUG-SVC-11 — External-aggregation backend failures swallowed with no log** _(low)._ `AggregationRunner.php:321-324`. **Fix:** `logger->warning(...)` in the catch before falling through.

## Security (lower-severity hardening)

- **SEC-SVC-5 — GitHub token prefix + length logged** _(medium)._ `Configuration/GitHubHandler.php:148-156` logs `token_prefix`/`token_length`. **Fix:** log only `has_token => true`; sweep `GitHubHandler`/`GitLabHandler` for any `substr($token...)`/`strlen($token)` in log context.
- **SEC-SVC-7 — Path traversal in `importFromFilePath` (no `..` rejection)** _(medium)._ `Configuration/ImportHandler.php:2865-2890` — `'/var/www/html/'.$filePath` fallback allows `../../etc/passwd`. **Fix:** after `realpath`, assert containment under an allowed base (`str_starts_with($fullPath, $base.'/')`); reject `..`/leading `/`; remove or contain the unbounded fallback.
- **SEC-SVC-9 — `unserialize()` on stored vector blob** _(low, object-injection)._ `Vectorization/Handlers/VectorSearchHandler.php:134`. **Fix:** store/read embeddings as JSON, or `unserialize($x, ['allowed_classes' => false])`.
- **SEC-SVC-10 — `unserialize()` of session-cached config without `allowed_classes`** _(low)._ `Configuration/CacheHandler.php:133`. **Fix:** `unserialize($x, ['allowed_classes' => [\OCA\OpenRegister\Db\Configuration::class]])` or switch to JSON.
- **SEC-SVC-11 — Forwarded-IP headers trusted for client-IP attribution** _(low)._ `SecurityService.php:687-716` parses `X-Forwarded-For`/`X-Real-IP`/`CF-Connecting-IP` unconditionally → lockout bypass. **Fix:** prefer `IRequest::getRemoteAddress()` (honors NC `trusted_proxies`) or gate the header loop behind a trusted-proxy check.
- **SEC-SVC-8 — Path-injection into raw.githubusercontent.com URL** _(low)._ `Configuration/GitHubHandler.php:528-547`. **Fix:** `rawurlencode()` each path segment; reject `/`/`..`/control chars; `allow_redirects:max=0`.
- **SEC-SVC-12 — Downloaded URL file stored without content-type allowlist** _(low, with SEC-SVC-2)._ `FilePropertyHandler.php:759-800`. **Fix:** validate resolved MIME/extension against the property's accepted types before `addFile`; serve with `Content-Disposition: attachment` + restrictive CSP.
- **SEC-CTRL-7 — Pervasive exception-message disclosure on 500s** _(medium)._ ~160 sites return `$e->getMessage()` (e.g. `ObjectsController` ~45, `SchemasController` ~55, `ConfigurationController` ~34, `FilesController` ~29). **Fix:** add a helper that logs the exception and returns a generic `['error' => 'Internal server error']` for 500s; keep specific messages only for 4xx validation. (Pairs well with `REF-5`.)
- **SEC-CTRL-8 — `@NoCSRFRequired` on authenticated state-changing endpoints** _(low)._ e.g. `LinkedEntityController.php:81-191`, many `ConfigurationController`/`ObjectsController` writes. **Fix:** remove `NoCSRFRequired` from SPA-called writes (axios sends the token); keep it only on truly public/bearer routes. Audit each one.
- **SEC-CTRL-9 — Unsanitized filename in `Content-Disposition`** _(low)._ `FilesController.php:324`, `FileService.php:1648`. **Fix:** RFC 6266 encode (`filename*=UTF-8''<rawurlencode>` + ASCII fallback), strip control chars/quotes.
- **SEC-CTRL-4 — Linked-entity write only checks read RBAC** _(medium)._ `LinkedEntityController.php:82-192` + `LinkedEntityService.php:102-118` — `MagicMapper::find()` (read check) then `update()` (no write check), so read-only users can mutate link columns. **Fix:** add a write-permission `PermissionHandler` check before `update()` in each `LinkedEntityService` write method; return 403 in the controller.
- **SEC-CTRL-10 — Dead `ObjectsController::import()` retains the param-RBAC bypass** _(low)._ `:3150-3173` (route disabled at `routes.php:303`). **Fix:** delete the dead method (and the retired `clearBlob()` no-op at `:3834`).

---

# P2 — Performance

> Top 3 wins: **PERF-1** (N+1 file/tag loading in list render), **PERF-4** (per-row regex map rebuild), **PERF-2** (information_schema + all-tables UNION on every extend preload).

- **PERF-1 — File-property hydration is N+1 (2+ queries per file per row)** _(critical)._ `lib/Service/Object/RenderObject.php:1262` (`renderFileProperties` per entity) → `getFileObject` `:966/982` → `fileMapper->getFile` `:1143` (1 query) + `getFileTags` `:756-773` (2 queries). A 20-row page × 3 files ≈ 120 queries. **Fix:** in `renderEntities` (before the per-entity loop ~`:3234`) collect all file IDs across the page, batch-load via a new `FileMapper::getFilesByIds(array)` (single `WHERE fileid IN (...)`) + a batch `getTagIdsForObjects($allFileIds, 'files')`; cache in request-scoped `$this->fileObjectCache`/`$this->fileTagsCache`; have `getFileObject`/`getFileTags` read from it. **Verify:** query log on a 20-row 3-file page: ~120 → ~3.
- **PERF-4 — `convertRowToObjectEntity` rebuilds the column map + runs `sanitizeColumnName` per row** _(high)._ `MagicSearchHandler.php:1581-1654` (`:1628-1632`, `:1898`). 100 rows × 50 props ≈ 25k regex ops per page. **Fix:** compute `$propertyTypes`/`$columnToPropertyMap` once in `executeSearchQuery` and pass into `convertRowToObjectEntity`, or memoize per `$schema->getId()`. **Verify:** `sanitizeColumnName` call count drops from 5000 → 50 for a 100×50 page.
- **PERF-2 — `findMultipleAcrossAllMagicTables` scans `information_schema` + UNION-queries every magic table on every extend preload** _(high)._ `MagicMapper.php:4828-4915`, via `CacheHandler::preloadObjects` `:577`. **Fix:** cache the magic-table list in a static keyed by DB name (invalidate on table create), eliminating the `information_schema` round-trip; where the caller knows target schema(s), narrow the UNION to those tables. **Verify:** branch count N → distinct target schemas; `information_schema` queries 1+ → 0 after warm cache.
- **PERF-3 — `ScheduledNotificationJob` loads whole schema tables into PHP and filters in-memory** _(high)._ `BackgroundJob/ScheduledNotificationJob.php:253, 267-274` → `MagicMapper::findBySchema` (no limit). **Fix:** push the trigger `filter` into SQL (`searchObjectsInRegisterSchemaTable` with `_filter`), add `_limit`/cursor paging, persist a per-schema watermark for delta scans. **Verify:** 50k-object schema with 10 matches → SQL returns ~10 rows; measure peak memory.
- **PERF-13 — `findBySchema` is an unbounded full-table read by design** _(medium)._ `MagicMapper.php:7094-7129`. **Fix:** add optional `$limit`/`$offset`/`$filter` pushed to SQL; audit callers (`ScheduledNotificationJob`, reports) to pass bounds.
- **PERF-5 — Magic tables have no `_deleted` index though every query filters `_deleted IS NULL`** _(medium)._ `MagicMapper.php:2822-2858` (index list) vs filter at `MagicSearchHandler.php:498, 909`. **Fix:** add a partial index in `createTableForRegisterSchema` (Postgres `CREATE INDEX ... ON {table}(_uuid) WHERE _deleted IS NULL`) + a one-shot migration for existing tables; consider partial composites on hot filter columns. **Verify:** `EXPLAIN ANALYZE` on `WHERE _owner=? AND _deleted IS NULL` switches to the partial index.
- **PERF-6 — Cross-table search fetches all rows, counts in PHP, no global limit/offset** _(medium)._ `ObjectsController.php:821-845` + `MagicMapper.php:1604-1627` — returns up to `limit × tableCount` rows; `total = count($fetched)`. **Fix:** `array_slice` to the real page window after merge/sort; compute `total` via per-table `COUNT(*)` sum. **Verify:** `schemas=1,2,3&_limit=20` against 1000-row tables → 20 rows, `total=3000`.
- **PERF-11 — Base64 file embedding reads whole file into memory per file per row** _(medium)._ `RenderObject.php:998-1043` (`getFileAsBase64`, `$file->getContent()` `:1014`) inside `renderFileProperties`. **Fix:** refuse `format: base64` on list/collection renders (return the file reference); on single-object GET, cap by size and stream/encode lazily. **Verify:** 10-row page × 5 MB base64 prop → peak memory bounded after the list-path guard.
- **PERF-10 — Separate `COUNT(*)` on every list request even when total isn't needed** _(low)._ `ObjectsController.php:1115-1124`. **Fix:** support `_count=false`/`_noTotal` to skip the count (return `total:null`); or fetch `limit+1` rows to infer `hasMore`.
- **PERF-7 — `INFO`-level logging of full extend params on every list render** _(low)._ `RenderObject.php:3162-3202`; also `MagicMapper.php:1610, 1644, 1049`. **Fix:** downgrade the `[BATCH_PRELOAD]` traces to `logger->debug`.
- **PERF-8 — Per-entity re-preload duplicates page-level batch preload (CPU)** _(low)._ `RenderObject.php:2164-2182` vs `:3204-3211`. **Fix:** set a "rendering a page" flag so `extendObject` skips the per-entity `preloadObjects` and relies on the warm cache.
- **PERF-9 — `cacheObject` eviction does O(n) `array_slice` of the whole cache per insert** _(low)._ `CacheHandler.php:613-629`. **Fix:** `unset()` the oldest keys in a loop (or use a ring buffer) instead of reallocating via `array_slice`.
- **PERF-12 — `rowToObjectEntity` resolves+decodes schema per row in fallback hydration** _(low)._ `MagicMapper.php:6717-6764`. **Fix:** memoize `_schema → columnToPropertyMap` per schema id (same fix family as PERF-4).
- **PERF-14 — Per-pair `find()` re-resolution in `crossTableSearch` build loop** _(low)._ `ObjectsController.php:756-792`. **Fix:** resolve each register once (outer loop) + pre-resolve schemas into a map.

---

# P3 — Refactor / architecture

> **Sequencing: do `REF-15` (characterization tests) before touching the giants.** Then Phase-1 mechanical wins, then the giants. Run `composer check:strict` + `/hydra-gates` between phases.

### Phase 0 — safety net
- **REF-15 — Characterization tests for the riskiest SQL (do FIRST)** _(high, prerequisite)._ The giants have test files, but the MagicMapper **DDL/column-mapping** methods (`REF-1` targets) and the **union-search** cluster (`REF-2`) are pinned only by the integration test. **Action:** add `tests/Unit/Db/MagicMapper/SchemaColumnMappingTest.php` (pin `mapSchemaPropertyToColumn`/`map*Property`), `TableEvolutionTest.php` (pin `updateTableStructure`/`addMissingColumns`/`findJsonbColumnsNeedingRetype`), `UnionSearchTest.php` (pin `buildUnionSelectPart`/`collectAllPropertyColumns`). They must pass against current code first.

### Phase 1 — high-value, low-risk, independent
- **REF-4 — Extract `AbstractLinkController` for 17 near-identical link controllers** _(high)._ `lib/Controller/*LinksController.php` (17 files, ~5895 lines) share byte-identical `validateObject()` + `mapException()`. **Action:** create `lib/Controller/AbstractLinkController.php extends Controller` with the shared helpers + protected `$objectService`; convert one controller, delete its copies, run its test, roll across the other 16 one at a time.
- **REF-9 — Converge controllers on attribute-based auth** _(medium)._ 88 files use docblock `@NoAdminRequired`, 15 use `#[NoAdminRequired]` (no file mixes both). **Action:** mechanical per-method swap to `#[...]` attributes in batches of ~10. **Preserve posture exactly** — controllers without `@NoAdminRequired` are admin-only by NC default; don't add/remove gates. Run `route-auth`/`semantic-auth` gates after each batch.

### Phase 2 — the giants (gated by Phase 0)
- **REF-1 — Finish MagicMapper decomposition: extract the DDL/schema-evolution engine** _(high)._ `lib/Db/MagicMapper.php` (8662 lines). ~20 DDL/column-map methods span `:1784-4435` and are still inline despite 7 existing handlers. **Action:** (1) `MagicSchemaColumnMapper.php` ← the pure mapping functions (`mapSchemaPropertyToColumn`, `map*Property`, `mapColumnTypeToSQL`, `getMetadataColumns`, `buildTableColumnsFromSchema`, `sanitizeColumnName`, `quoteIdentifier`, `formatDefaultValueForSQL`); (2) `MagicTableDdlHandler.php` ← connection-bound DDL (`createTable`, `*Indexes`, `updateTableStructure`, `addMissingColumns`, `deRequire/reRequireColumns`, `dropDuplicateCamelCaseColumns`, `makeObsoleteColumnsNullable`, `findJsonbColumnsNeedingRetype`, `migrateJsonbToJson*`); (3) move the create/update/sync orchestration. Add delegating wrappers; run unit + integration tests after each step.
- **REF-2 — Extract the MagicMapper cross-table UNION-search cluster** _(high)._ `MagicMapper.php:1047-1696` (~650 lines) → move into `MagicSearchHandler` (keep a public delegator). Also move `searchObjectsPaginatedMultiSchema` `:8271`, `getGlobalSearchResult` `:8466`, `searchObjectsGloballyBySearch` `:8544`.
- **REF-3 — Finish SaveObject decomposition** _(high)._ `lib/Service/Object/SaveObject.php` (5277 lines). **Action:** `DefaultValueHandler.php` ← defaults/slug (`:1220-1608`); `ReferenceValidationHandler.php` ← reference + circular-ref (`:3831-4597`, move the existing dedicated tests to target it); consolidate the 4 cascade methods (`:1655-2212`) into the existing `RelationCascadeHandler`.
- **REF-7 — Split ImportHandler** _(medium)._ `lib/Service/Configuration/ImportHandler.php` (3958 lines, 11 setter-injected collaborators). **Action:** `SeedDataImporter.php` ← `importSeedData`/`processRelatedItems`/`ensureDependenciesForSeedData`/`handleNextcloudAppDependencies`; `WorkflowDeploymentImporter.php` ← `processWorkflowDeployment`/`processWorkflowHookWiring`; ImportHandler keeps config-definition import.

### Phase 3 — facade / boot cleanup
- **REF-8 — Move Application.php registration groups into registrar classes** _(medium)._ `lib/AppInfo/Application.php` (2228 lines). **Action:** `Registration/EventListenerRegistrar.php` ← the 76 `registerEventListener` calls (`:1715-1869`); `Registration/IntegrationProviderRegistrar.php` ← `registerBuiltinIntegrationProviders`/`bootBuiltinIntegrationProviders`; move MCP discovery (`collectPerAppMcpProviders` etc. `:1909-2060`) into `McpDiscoveryService`. **Note:** do this _with_ the OPS registration fixes below (you'll be editing the same block).
- **REF-6 — Extract ObjectsController request-parsing / file-upload concerns** _(medium)._ `lib/Controller/ObjectsController.php` (3952 lines). **Action:** `Support/ObjectRequestParser.php`, `Support/MultipartFileExtractor.php`, `Support/GeoQueryParser.php`; delete the duplicated `isUuid`/`collectUuids*` (see REF-10).
- **REF-10 — De-duplicate UUID/name-collection helpers** _(medium)._ `ObjectsController.php:3729-3834` vs `ObjectService.php:2656-2844`. **Action:** create `Service/Object/UuidCollector.php` from the safer depth-guarded version; replace both call-sites.
- **REF-5 — Controller exception→JSONResponse middleware + identifier trait** _(medium)._ ~1801 `new JSONResponse`, 47 hand-rolled 500s, 41 copies of UUID detection. **Action:** `Middleware/ControllerExceptionMiddleware.php` (`afterException` → canonical `['error'=>...]` envelope, mapped per domain exception), register in Application.php; `Controller/Trait/ResolvesIdentifiersTrait.php` for the one canonical `isUuid()`. Migrate controllers to *throw* incrementally. (Closes `SEC-CTRL-7` at the same time.)

### Phase 4 — consolidation
- **REF-13 — Consolidate SaveObject ↔ SaveObjects shared logic** _(low, after REF-3)._ Point `SaveObjects/BulkValidationHandler` at the same `DefaultValueHandler`/`ReferenceValidationHandler`. (Also the structural fix that makes `BUG-OBJ-1`/`BUG-OBJ-15` parity natural.)
- **REF-14 — Split ObjectService search/facet surface + collapse `find`/`findSilent`** _(low)._ `lib/Service/ObjectService.php` (3658 lines). Extract `ObjectSearchService.php`; make `find`/`findSilent` wrap a private `doFind(..., bool $throw)`.

### Phase 5 — layering hygiene (opportunistic, audit-gated)
- **REF-11 — Controllers reaching into mappers instead of ObjectService** _(low)._ 40 controllers inject `*Mapper` directly. **Do NOT blind-sweep:** definition-CRUD controllers (Registers/Schemas/Scopes managing their own entity) are legitimate; only object-data controllers (Deleted/Bulk/EntityRelations) should route through `ObjectService` for RBAC. Classify first, convert the misclassified, run each test.
- **REF-12 — HTTP import in the Db trait** _(low)._ `lib/Db/MultiTenancyTrait.php:35` imports `Http\JSONResponse`. **Fix:** remove the import / move any response-building out to a controller; trait should throw a domain exception.

---

# P4 — Frontend & ops hygiene

## Frontend (`src/`) — state is uniformly Pinia (no legacy Vuex)
- **FE-1 — Stored XSS in ViewObject editability-warning toast** _(critical)._ `src/modals/object/ViewObject.vue:2311` assigns `notification.innerHTML` with an interpolated user object value (`:2122-2131`). **Fix:** replace the hand-rolled toast with `import { showWarning } from '@nextcloud/dialogs'` → `showWarning(warning)` (text-escapes); delete `showWarningNotification` (`:2290-2328`, this is also `FE-10`). **Verify:** immutable property set to `<img src=x onerror=alert(1)>` → no alert, literal text shown.
- **FE-2 — XSS in chat via unsanitized `marked.parse` → `v-html`** _(critical)._ `src/views/chat/ChatIndex.vue:81, 876`. `marked` v16 has no built-in sanitizer; `dompurify>=3.4` is already a dependency. **Fix:** `import DOMPurify from 'dompurify'` → `return DOMPurify.sanitize(marked.parse(content || ''))`. **Verify:** chat message with `<img onerror>` / `[x](javascript:...)` doesn't execute.
- **FE-3 — Unvalidated `href` bound to imported configuration URLs** _(high)._ `src/components/cards/ConfigurationCard.vue:149` (org url), `:138` (repo url). **Fix:** computed `safeOrgUrl` returning the URL only if `/^https?:\/\//i.test(url)` else null; bind `:href` + `v-if`. **Verify:** imported config with `url:"javascript:alert(1)"` → link inert.
- **FE-4 — Solr setup spinner/interval runs forever on failure** _(high)._ `src/views/settings/sections/SolrConfiguration.vue:1600-1609` — no try/finally around `setupSolr()`. **Fix:** wrap in `try/catch(showError)/finally(stopGameLoading)`. 
- **FE-5 — Solr loading `setInterval` leaks on unmount** _(medium)._ same file `:1626`, no `beforeDestroy`. **Fix:** add `beforeDestroy() { this.stopGameLoading() }`.
- **FE-14 — N8n editor `window.open` of unvalidated configured URL** _(low)._ `src/views/settings/sections/N8nConfiguration.vue:587-590`. **Fix:** `if (/^https?:\/\//i.test(this.n8nUrl)) window.open(this.n8nUrl,'_blank','noopener')`.
- **FE-16 — `SettingsSection.sanitizeHtml` strips all formatting (silent UX bug)** _(low)._ `src/components/shared/SettingsSection.vue:214-218` uses `textContent`. **Fix:** `DOMPurify.sanitize(html, { ALLOWED_TAGS:['a','strong','em','code','br','p'], ALLOWED_ATTR:['href','target','rel'] })`.
- **FE-6 — Dutch strings used as i18n keys** _(medium)._ `src/dialogs/avg/EditActivityDialog.vue:40,50,55,60,65,143,144`. **Fix:** English keys (`'Technical measures'`, `'Edit processing activity'`, …) + Dutch values in `l10n/nl.*`.
- **FE-7 — Hardcoded English loading strings not in `t()`** _(low)._ `SolrConfiguration.vue:1207-1220, 1620`. **Fix:** wrap each tip/message in `t('openregister', ...)`.
- **FE-8 — `console.log` inside a computed (fires every re-render)** _(medium, perf)._ `src/components/FacetComponent.vue:397-413` in `metadataDateFields`. **Fix:** delete all logs in the computed.
- **FE-9 — ~470 `console.log` calls across stores/components** _(low)._ Every store setter logs. **Fix:** remove non-error logs from `src/store/modules/*` + components; keep `console.error` in catches; drop the file-level `eslint-disable no-console`.
- **FE-11 — `SolrConfiguration.vue` is a 4646-line god-component** _(medium, refactor)._ **Fix:** extract `components/LoadingTips.vue`, `dialogs/settings/SolrFacetConfigDialog.vue`, a connection-form child; keep the section a thin orchestrator. One extraction per PR.
- **FE-12 — Inconsistent data-fetch: adapter-store vs hand-rolled fetch** _(medium)._ `object.js` uses `@conduction/nextcloud-vue`'s store factory; `register/schema/configuration.js` hand-roll `fetch`. Only `register.js` has single-flight/abort. **Fix:** converge on the shared store factory (ADR-022) or factor a `useApiFetch()` composable with abort + single-flight.
- **FE-13 — `ViewObject.vue` is 2706 lines with inline selection-step markup** _(low)._ **Fix:** extract `components/object/RegisterSchemaSelector.vue` + a property-table child.
- **FE-15 — `settings.js` is a 1756-line monolith with broad refetch** _(low)._ **Fix:** split into `settings/solr.js`, `settings/llm.js`, …; have save actions patch the changed slice from the response instead of full refetch.

## Ops / background jobs / migrations
- **OPS-1 — Entire event-driven Actions feature is dead (`ActionListener` never registered)** _(high, verified)._ `lib/Listener/ActionListener.php` is referenced only in its own file; absent from `Application.php:1715-1856`. **Fix:** in `registerEventListeners()`, `registerEventListener(ObjectCreatedEvent::class, ActionListener::class)` + Updated/Deleted/Transitioned as the executor matches. **Verify:** configure an Action, save an object, confirm `ActionExecutor` ran.
- **OPS-2 — Webhooks only fire on object *create*** _(high, verified)._ `Application.php:1805` registers `WebhookEventListener` for `ObjectCreatedEvent` only, but the listener handles Updated/Deleted/Locked/Unlocked/Reverted/Register/Schema events. **Fix:** register the listener for every event class it handles (loop over the list). **Verify:** webhook on `object.updated` delivers on edit.
- **OPS-3 — `SyncConfigurationsJob` never scheduled** _(high, verified)._ `lib/Cron/SyncConfigurationsJob.php` (TimedJob) absent from `info.xml` `<background-jobs>` and never `IJobList::add`'d. **Fix:** add `<job>OCA\OpenRegister\Cron\SyncConfigurationsJob</job>` to `<background-jobs>`; bump `<version>`. **Verify:** `occ background-job:list | grep SyncConfigurations`.
- **OPS-4 — Three orphaned BackgroundJobs (dead code)** _(medium)._ `WebhookDeliveryJob`, `CacheWarmupJob`, `BackfillCalendarLinksJob` — zero references, not in info.xml. **Fix:** per-job decide: wire up (info.xml or `IJobList::add`) if needed (look closely at `WebhookDeliveryJob` given OPS-2), else delete the file.
- **OPS-6 — `ScheduledNotificationJob` loads all objects per fire** — see `PERF-3`.
- **OPS-7 — `DestructionCheckJob` full-table scan + unbounded appconfig "notified" list** _(medium)._ `lib/BackgroundJob/DestructionCheckJob.php:193-265` — `fetchAll()` all retention rows; appends UUIDs to a JSON appconfig blob that only ever grows. **Fix:** batch the retention query (`setMaxResults`+offset); prune notified UUIDs after destruction or replace the appconfig blob with a per-object flag/table. **Verify:** appconfig value size stays bounded over repeated runs.
- **OPS-8 — Notification-subscription repair re-runs every upgrade, re-enabling overrides** _(medium)._ `appinfo/info.xml:76` (`<post-migration>`) + `lib/Repair/MigrateNotificationSubscriptionsToUserConfig.php:104-165` forces `enabled:true` each run, never marks done. **Fix:** make it run-once (delete/flag legacy rows or set an appconfig marker + early-return); consider moving to a one-shot migration. **Verify:** `occ upgrade` twice — a manually-disabled override stays disabled.
- **OPS-9 — `DedupeRegistersCommand` deletes by default (no confirmation)** _(medium)._ `lib/Command/DedupeRegistersCommand.php:103-170` — dry-run is opt-in. **Fix:** default to dry-run (require `--apply`), or add an interactive confirmation with `--no-interaction`/`--force` bypass. **Verify:** no-flag invocation reports without deleting.
- **OPS-11 — Unbatched `fetchAll()` in data migrations (OOM risk on upgrade)** _(medium)._ `lib/Migration/Version1Date20250829120000.php:110,129` + `Version1Date20260521120000.php:131,217`. **Fix:** chunk with `setFirstResult`/`setMaxResults` paging; free each batch. **Verify:** `occ upgrade` against a large magic table — bounded peak memory.
- **OPS-14 — `FileChangeListener` synchronous extraction on the instance-wide `NodeWritten` hot path** _(low)._ `lib/Listener/FileChangeListener.php:100-215` — in `immediate`+`all` mode calls `extractFile()` synchronously (`:185`); `info`-level log per matching write (`:154`). **Fix:** always enqueue `FileTextExtractionJob` instead of extracting inline; demote the per-event log to `debug`.
- **OPS-13 — "Nightly" warmup jobs use a fixed 24h interval, not a night window** _(low)._ `SolrNightlyWarmupJob.php:80`, `NameCacheWarmupJob.php:67` — no `setTimeSensitivity(TIME_INSENSITIVE)` / time-of-day gate (cf. `LogCleanUpTask.php:81`). **Fix:** add `setTimeSensitivity(IJob::TIME_INSENSITIVE)`; gate `run()` to a configured hour window if true night-running is required.
- **OPS-15 — `TransferExecutionJob` (a QueuedJob) declared in `<background-jobs>`** _(low)._ `info.xml:94` — NC adds it with a null argument → spurious "missing transferList" error on every fresh install. **Fix:** remove it from `<background-jobs>`; rely on dynamic `IJobList::add(...)` (confirm `TransferCheckJob` enqueues it).
- **OPS-10 — Solr / migrate-storage occ commands disabled by circular DI** _(low)._ `info.xml:126-131` (commented out). **Fix:** lazy-resolve the heavy services inside the commands (factory closure / `ContainerInterface`) instead of constructor-injecting `SettingsService`→`IndexService`; re-enable. **Verify:** `occ openregister:migrate-storage --help` without a DI fatal.
- **OPS-5 — Stale `.backup_*` files shipped in `lib/Cron/`** _(low)._ `ConfigurationCheckJob.php.backup_20251230_001404`, `WebhookRetryJob.php.backup_20251230_001130`. **Fix:** delete both; add `*.backup_*` to `.gitignore` + build excludes.
- **OPS-12 — Migration version-number scheme mixes `Version1Date…` and `Version002003000Date…`** _(low, latent ordering hazard)._ The four `Version002…` files sort *after* all `Version1…` by `version_compare`. **Fix:** standardise going forward (rename the four to `Version1Date…` in a coordinated change — do **not** rename already-applied migrations on existing installs carelessly; NC tracks applied names in `oc_migrations`). Add a lint enforcing the convention.

---

## Appendix — suggested execution order for an autonomous agent

1. **P0 security batch A (no behaviour change for legit users):** `SEC-CTRL-1`, `SEC-CTRL-6`, `SEC-CTRL-3`, `SEC-CTRL-2`, `BUG-DB-2`, `BUG-DB-1`. Small, surgical, high impact.
2. **P0 SSRF/SSTI batch:** build the shared `assertSafeFetchUrl()` once (`SEC-SVC-1`), wire `SEC-SVC-2`/`SEC-SVC-6`; build the sandboxed-Twig pattern once, wire `SEC-SVC-3`/`SEC-SVC-4`.
3. **P0 data-loss/bulk batch:** `BUG-OBJ-1`, `BUG-OBJ-15`, `BUG-OBJ-8`, `BUG-OBJ-2` (add tests first — these are the highest-risk changes).
4. **OPS registration fixes** (`OPS-1/2/3/4`) — they're one-line wires but restore whole features; do them while editing `Application.php`/`info.xml` (combine with `REF-8`).
5. **P1 correctness** by area, then **P2 performance** (PERF-1/4/2 first).
6. **P3 refactors** only after `REF-15` tests exist; mechanical wins (`REF-4`, `REF-9`) before giants.
7. **P4 frontend** XSS first (`FE-1`, `FE-2`, `FE-3`), then the rest.

Each batch: branch → implement → `composer check:strict` + `bash scripts/run-hydra-gates.sh` → bump `appinfo/info.xml` `<version>` if behaviour/bundle changed → verify against `localhost:8080` → PR (no `Co-Authored-By`).
