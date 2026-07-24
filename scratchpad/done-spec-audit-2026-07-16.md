# OpenRegister — audit of specs marked `done` (2026-07-16)

READ-ONLY audit. No app code changed. OR is the data abstraction; every Conduction app
inherits its defects, so severity is weighted toward compliance / data-integrity /
security / audit-trail.

Scope: 138 specs carry `status: done` (135 in `openspec/specs/`, 3 in `openspec/changes/`).

---

## 1. ROOT-CAUSE CLASS — `ANNOTATION_VOCABULARY` ↔ engine drift

`Schema::setConfiguration()` (lib/Db/Schema.php:1702) → `validateConfigurationArray()`
(:1761) drops any `x-openregister-*` key not listed in the private
`ANNOTATION_VOCABULARY` whitelist (:1984, 19 keys). The drop is logged as a
`logger->warning()` (lib/Db/SchemaMapper.php:667 → :681) but **the save succeeds** —
so an unlisted key silently no-ops instead of failing.

Critically: **`Register::setConfiguration()` (lib/Db/Register.php:753) applies NO such
filter.** The same annotation therefore works at register level and is silently dropped
at schema level. That asymmetry is the trap.

The vocabulary is hand-maintained with **no test or gate keeping it in sync with the
engines that read the keys**. It has drifted in BOTH directions.

### 1a. Engine reads a key that is NOT in the vocabulary → silently dropped

| key | engine (reads schema config) | vocabulary? | effect |
|---|---|---|---|
| `x-openregister-processing` | `ProcessingLogService::ANNOTATION_KEY` :75, read :344 · `AvgComplianceService::DIALECT_KEY` :66, read :343 | **ABSENT** | schema-level declaration dropped |
| `x-openregister-manifest-user-fields` | `ManifestService::FIELD_ALLOWLIST_KEY` :85, read :480 | **ABSENT** | allowlist always resolves null |

**`x-openregister-processing` — HIGH (compliance / audit-trail).**
This is the *current* AVG/GDPR processing dialect; `x-openregister-processing-activity`
is explicitly its `LEGACY_ANNOTATION_KEY` (ProcessingLogService.php:82). Only the
**legacy** key is in the vocabulary. Both services load config via
`$schema->getConfiguration()` (ProcessingLogService.php:515; AvgComplianceService.php:315),
so on a **schema** the new dialect is dropped and only the legacy single-string fallback
(:354) survives. Consequences:
- `logReads` (AVG read-access logging) is reachable **only** via the new dialect
  (ProcessingLogService.php:347) → **read-access logging can never be enabled per-schema**.
- `attribution` map and `subjectIdFields` (:348-349) likewise unreachable per-schema.
- Works at **register** level only (`loadRegisterConfig` :533, Register has no filter) →
  per-schema granularity silently lost.

**Precision — this capability is PARTIAL, not dead.** The engine is genuinely wired:
`ProcessingLogService` is invoked from `ObjectService::find()` (lib/Service/ObjectService.php:706,
lazily resolved :744). Register-level `logReads` therefore works. Only the **schema-level**
opt-in is dead. The spec `openspec/specs/avg-verwerkingsregister/spec.md:678` explicitly
claims opt-in "via the `x-openregister-processing` annotation (`logReads: true`)" for a
"schema (or register)" — the *schema* half of that sentence is not live at HEAD.
Status caveat: that spec carries `status: implemented`, **not** `status: done`, so it is
outside the 136-`done` set — it is reported here because the claim is load-bearing for AVG
compliance, not as a `done`-spec failure.

**`x-openregister-manifest-user-fields` — MEDIUM (dead capability, fails CLOSED).**
`loadFieldAllowlist()` (ManifestService.php:468-493) reads the key off
`getConfiguration()`; it is dropped at save, so `$explicit` is always null and
`resolveAllowedFieldNames()` (:436) falls back to `DEFAULT_SAFE_FIELDS` + materialised
calculations. Author-declared profile allowlists never take effect. Note: this fails
**closed** (fewer fields exposed, no leak) — the capability is dead, not unsafe.

### 1b. Vocabulary key with NO engine → phantom that apps declare and trust

| key | engines in lib/ | verdict |
|---|---|---|
| `x-openregister-seed` | **0** | **PHANTOM — confirmed dead** |
| `x-openregister-widgets` | validator only (SchemaMapper:662 → WidgetAnnotationValidator) | UNSURE — likely rendered by nc-vue |
| `x-openregister-relations` | 1 comment only (AnnotationNotificationDispatcher:1852) | UNSURE — likely consumed by nc-vue |

The other 16 vocabulary keys (`lifecycle`, `aggregations`, `calculations`, `references`,
`aggregate-refs`, `notifications`, `processing-activity`, `archival`, `object-source`,
`quality`, `dedup`, `flows`, `survivorship`, `merge`, `handoff`, `mcp`) each have real
engine consumers — verified live.

**`x-openregister-seed` — HIGH (data-integrity, MDM trust).**
It is in the vocabulary (Schema.php:1995) so it round-trips into the config column, but
**nothing anywhere in `lib/`, `appinfo/`, `src/` reads it**. The real seeding engine
consumes a *different* shape: top-level `x-openregister.seedData`
(ImportHandler.php:3736). Consequence:
- `lib/Settings/trust_configuration_register.json:90` declares **6 trustConfiguration
  objects** under per-schema `x-openregister-seed`, and that file has **no top-level
  `seedData`** → **the MDM trust rules are never planted.** Trust-tier / freshness-decay
  resolution starts with zero rules and silently falls back to defaults.

**KEY INSIGHT — why a `done` spec produced a dead key.**
`openspec/specs/archival-annotation-vocabulary/spec.md` is **`status: done` and its
requirement is literally satisfied**: it says ANNOTATION_VOCABULARY "SHALL include the keys
`x-openregister-archival` and `x-openregister-seed`" and that they "MUST round-trip …
without being silently dropped" (:10-12). Every scenario tests round-trip / not-dropped /
present in `GET /api/schemas/<slug>` (:14-18). **None requires an engine to read the key.**
So `archival` got a vocabulary entry *and* an engine (8 files); `seed` got the entry and
nothing else — and the spec is *correctly* done by its own terms.

**This is the generative mechanism of the whole class: "not dropped" ≠ "consumed".** A
vocabulary entry makes a key persist, which makes it *look* supported to every app that
declares it (round-trips, API returns it, no warning). It just never acts. Future
annotation specs MUST carry a consumption criterion, not only a persistence one.

**Superseded — correctly NOT a defect (checked, not assumed):**
- `dsar_policy_pack_register.json:316` `x-openregister-seeds` (plural, also not in the
  vocabulary and with no engine) **is superseded** — the file DOES carry a top-level
  `x-openregister.seedData` block, and its own `description` states the per-schema
  blocks "are the historical declaration and are NOT processed by importFromApp".
  Wiring the per-schema block would DUPLICATE the seeded packs. Leave it.
- `data_subject_request_register.json:477` `x-openregister-seeds` is never planted (no
  seedData in that file) but the content is **demo fixture data**
  (`j.jansen@example.org`) → LOW; arguably correct that it does not plant.

### Fix direction (root cause)
Add a gate / unit test that cross-checks `ANNOTATION_VOCABULARY` against engine key
constants **bidirectionally**: every vocabulary key must have ≥1 engine reading it, and
every `x-openregister-*` constant read from `getConfiguration()` must be in the
vocabulary. Then: add `x-openregister-processing` + `x-openregister-manifest-user-fields`
to the vocabulary; either implement a per-schema seed engine or migrate
`trust_configuration_register.json` to top-level `seedData` and delete
`x-openregister-seed` from the vocabulary. Consider making a dropped key **fail the save**
rather than warn, and align `Register::setConfiguration()` with `Schema`.

---

## 2. Orphaned write capabilities (16 flagged by gate)

Triaged all 16. **Only 4 are defects worth acting on.** `class-injected != method-called`
held throughout; the reliable discriminator was hunting the *superseding* implementation by
domain concept, not by method name.

| # | method | verdict | evidence | severity |
|---|---|---|---|---|
| 4 | `ObjectService::clearCurrents` | **LIVE — gate false positive** | `openconnector/lib/Service/EndpointService.php:634,1583,1592,1899` → `$this->objectService->getOpenRegisters()->clearCurrents()`; OR docblock ObjectService.php:3972-3974 says "Called by external apps (e.g. OpenConnector)" | **DO NOT DELETE** |
| 6 | `SaveObject::saveObjectsStreaming` | DEAD — but see correction | prerequisite primitive; docblock :4516 "this is the prerequisite, not the feature" | med (points at a real gap) |
| 16 | `StreamYieldChannel::emitHeartbeat` | **DEAD — live subscriber waiting** | subscriber registered `ChatStreamController.php:339` `$channel->onHeartbeat(...)`; nothing ever fires it; `forwardWithHeartbeat:523-533` only piggybacks onto the next real frame | med |
| 12 | `PermissionHandler::clearInheritFromPublicCache` | DEAD | cache read :1513, written :1557, cleared only :732/:736. Docblock :723-726 states a **MUST** nothing honors | med (security) |
| 3 | `SettingsService::clearDeckDefault` | DEAD | `get/setDeckDefault` live (`DeckLinksController.php:233/359/395`) but **no DELETE route** | low |
| 11 | `PermissionHandler::clearPermissionCache` | DEAD | cleared only :714; mitigated — key includes userId+action+schemaId+uuid (:257) | low-med |
| 1 | `MetricsService::recordMetric` | **SUPERSEDED** | live pull engine: `appinfo/routes.php:243` `/api/metrics` → `Application.php:2606` MetricsEngine → `src/manifest.json:401` descriptors read `openregister_audit_trails` | do not wire |
| 2 | `ArchivalService::generateDestructionList` | **SUPERSEDED** | `RetentionService::createDestructionList` :752 ← `BackgroundJob/DestructionCheckJob.php:139`, registered `appinfo/info.xml:108` | **do not wire — would race the cron** |
| 14 | `DestructionService::generateCertificate` | **SUPERSEDED** | `RetentionService::generateDestructionCertificate` :816 ← `BackgroundJob/DestructionExecutionJob.php:212`, persisted :222 | do not wire |
| 9 | `Object\ExportHandler::export` | **SUPERSEDED** | `routes.php:726` → `ObjectsController.php:3950-3957` calls `ExportService` directly ("bypassing ObjectService which has circular dependency issues"); `ObjectService.php:264` injection commented out, :301 "REFACTORED: Removed ExportHandler" | low |
| 15 | `CredentialAppTokenService::issueToken` | **UNSURE — by-design API half; KEEP** | counterpart `verify()` live: `CredentialController.php:452`, routed :44. Consumers get a secret via `registerApp` (:412) and sign themselves | low, keep |
| 5 | `SaveObject::clearReferenceValidationCache` | DEAD | only consumer would be #6 | low |
| 8 | `BatchOperationStatus::recordUnchanged` | DEAD | `SaveObject.php:4565-4570`: "unchanged bucket is a future enhancement… out of scope" | low |
| 7/13 | `MetadataHandler`/`DataManipulationHandler::generateSlugFromValue` | DEAD (byte-identical clones) | live slugging elsewhere: `OrganisationController.php:1229`, `OasService.php:909` | low |
| 10 | `RelationshipOptimizationHandler::createLightweightObjectEntity` | DEAD stub | body :145-149 = `return null;` "placeholder" | low |

**Re-derivation of the 4 prior-audit flags:** the note said *two* of
{recordMetric, generateDestructionList, generateCertificate, issueToken} were superseded.
Evidence says **three**: `generateDestructionList` + `generateCertificate` (superseded by
`RetentionService` on the live cron path — deleting was correct; wiring them WOULD have
created the duplicate destruction pipeline), **plus `recordMetric`** (superseded by the
AppHost Observability pull engine). `issueToken` is *not* superseded — it is an
unused-by-design consumer-facing API half.

### 2a. CORRECTION to the agent's #6 claim — audit is NOT skipped
The agent claimed bulk writes "skip reference-existence validation **+ per-row audit**".
**The audit half is wrong** and I verified it: `SaveObjects.php` explicitly replays them —
*"Dispatch lifecycle events and write audit trail for persisted objects (BUG-OBJ-1). The
ultraFastBulkSave mapper path bypasses the per-row event/audit hooks the single-object
insert/update apply, so the bulk path replays them here. Audit trail rows are always
written."* That gap was closed by BUG-OBJ-1.

**The reference-validation half IS real — MEDIUM-HIGH (data-integrity, fleet-inherited).**
`grep validateReference|referenceValidation|checkReference lib/Service/Object/SaveObjects.php`
→ **zero hits**. `validateReferenceExists()` is invoked only from the single-object path
(`SaveObject.php:4067`; defined :4290). So **bulk/import writes can persist objects with
dangling relation UUIDs that a single-object save would reject** — every app imports via
bulk. `saveObjectsStreaming` (#6) is precisely the primitive that closes this (it loops
`saveObject()` per row, :4545), landed as "the prerequisite, not the feature" (:4516) —
its consumer never arrived.

### 2b. Two extra defects found while triaging
1. **`search_requests_total` is permanently 0 on the live `/api/metrics` endpoint.**
   `src/manifest.json:433-441` declares a counter sourced `tableCount` on
   `openregister_metrics` filtered `metric_type LIKE 'search_%'`. The dead `recordMetric`
   (:164) is that table's **only writer** — and its constants (:63/:70/:77) are
   `file_processed`/`object_vectorized`/`embedding_generated`, so **reviving it would not
   even fix this metric**. Real search recording lives in `openregister_search_trails` via
   `SearchTrailService::createSearchTrail` ← `SearchQueryHandler.php:560`. Fix = repoint the
   descriptor, not revive the method. Sibling descriptors already migrated
   (`objects_created_total` reads `openregister_audit_trails`, manifest :443-449) — this one
   was left behind. **This is an observability lie: a dashboard counter that always reads 0.**
2. **`CredentialController::sessionBrokerRequest` violates a documented invariant —
   MEDIUM-LOW after calibration.** The route (:503-512, routed :45, `#[NoAdminRequired]`)
   passes `appId` straight from an **unverified body param** (:509) into
   `broker->request()` (:515-522). `CredentialBrokerService.php:137` documents the
   invariant: *"On the HTTP path `appId` comes ONLY from a verified `X-Credential-Token`."*
   `appId` is an authorization input — `assertAppAllowed()` (:182, :466) checks
   `appId ∈ credential.allowedApps`. Because the client picks `appId`, that check is
   **trivially satisfiable**: an authenticated caller simply names any app already in
   `allowedApps`. So `allowedApps` is **not an effective control on the session route**.
   **Calibration — I checked the mitigations rather than assuming the worst:** credential
   *access* still holds. Guard 1 `loadAdmittedCredential()` (:320) enforces
   personal→`assertPersonalOwner` (owner check, session-user fallback, denies when
   unauthenticated) and organisation→`assertOrganisationMember` (requires a real session
   member). So this is **not** credential theft or cross-user access — it is an
   app-attribution control that does not bind on one route. Worth a dedicated fix
   (derive `appId` server-side), not an incident.

### 2c. Gate methodology finding
`clearCurrents` proves any single-repo gate will keep flagging **cross-app public API** as
orphaned — it is called through a resolver (`getOpenRegisters()->`) from openconnector and
is invisible to any `openregister/lib` grep. That class of method needs an explicit
allowlist or a fleet-wide grep in `check_orphaned_write_capability.py`.

## 3. Fabricated pass / fail-open / AuthorizationService

### 3a. HIGH — silent fail-open in register-authorization resolution (CWE-863)

Independently re-verified at HEAD (not taken on trust):
- `PermissionHandler::getRegisterAuthorization()` (lib/Service/Object/PermissionHandler.php:1727)
  wraps `RegisterMapper::find()` in `try { … } catch (\Throwable $e) { $this->cachedRegisterAuth[$registerId] = null; return null; }` (:1742-1745)
  — **no log**, and the `null` is **pinned in the per-request cache**.
- `resolveAuthorization()` (:1487-1491): `if (empty($registerAuth) === false)` → falls through → `return null` (:1493).
- `hasGroupPermission()` (:1048-1051): `// If no authorization is set, everyone has all permissions.` → `if (empty($authorization) === true) { return true; }`.

**Net effect:** any `RegisterMapper` failure (DB blip, missing register, container error)
silently converts a **register-level-restricted** schema into an **unrestricted** one for
the remainder of the request. "Resolution failed" and "no authorization configured"
collapse to the identical `null`, so the fail-open is indistinguishable from the
legitimate default **and leaves no trace**. Scope: schemas with no own `authorization`
block that rely on register-level auth.

Telling asymmetry: the sibling resolver `getRegisterForSchema()` **does** log a warning on
`\Throwable` (:1701-1711); `getRegisterAuthorization()` — the one whose `null` grants full
access — logs nothing. This is exactly hydra gate-8 (`unsafe-auth-resolver`).
Fix: distinguish failure from "unconfigured" (rethrow or sentinel); at minimum, log.
Caveat: established by static trace through the cited lines, not by runtime reproduction.

### 3b. MEDIUM — `default => true` fail-open filter arm
`AggregationRunner::checkOp()` (lib/Service/Aggregation/AggregationRunner.php:1157-1171):
the `default => true` arm (:1170) means an unknown/typo'd operator in a manifest-declared
aggregation filter **keeps the row** — the filter silently stops filtering rather than
erroring. Data-exposure risk on an aggregation surface.

### 3c. MEDIUM — `AuthorizationService` is a dead auth surface (with a superseded subset)
Verdict: **DEAD**. Zero production references outside its own file (only baseline/cache
artefacts: `phpmd.baseline.xml:133`, `psalm-baseline.xml:287`). Not registered in
`lib/AppInfo/Application.php`; not among the 7 registered middlewares (:387-433).
`authorizeJwt`/`authorizeBasic`/`authorizeOAuth`/`authorizeApiKey` are **`protected`**
(:214,342,379,443) and **no class extends it** → structurally unreachable from production;
tests reach them only by reflection.
- `corsAfterController` (:411) is **legitimately SUPERSEDED** — `lib/Middleware/PublicApiCorsMiddleware.php:18`
  states it mirrors that logic via the middleware chain (reimplemented, not delegated).
- **But** `api/consumers` CRUD still ships (appinfo/routes.php:13,90;
  `ConsumersController.php:40`) and writes `authorizationConfiguration` (publicKey,
  algorithm) whose **only reader is the dead service** (:258). Admins can configure JWT
  issuers / API-key consumers that **nothing enforces** — a credential store with no
  consumer, i.e. misleading security posture.
- **Mitigating:** `ConsumersController` has zero `NoAdminRequired` → admin-only by NC
  default. Not an exploitable hole. Superseded-by-openconnector is consistent with the
  evidence; the defect is the *advertised-but-unenforced* surface, not a bypass.

### 3d. Verified CLEAN (stated explicitly)
- **Phantom handler refs: the prior "0" is CONFIRMED, not inherited.**
  `lib/Settings/register.d/` contains **only `README.md`** — zero JSON — so OR has none of
  the fleet-wide 17-missing-guard-class defect. An independent walk of
  `lib/Settings/*.json` found exactly one real class ref:
  `data_subject_request_register.json` → `…finaliseDenial.requires` =
  `OCA\OpenRegister\Service\Gdpr\Lifecycle\DenialFinaliseGuard`. It resolves
  (lib/Service/Gdpr/Lifecycle/DenialFinaliseGuard.php:48), implements
  `LifecycleGuardInterface` (clearing the `LifecycleGuardRegistry::resolve()` instanceof
  throw at :116-124), and its `check()` is fail-closed (:85-108). Other `handler`/`provider`
  matches are data fields (a NC uid, a catalogue key), not class refs.
- Archival/destruction: **no fabricated pass**. `DestructionService.php:661`
  `'immutable' => true` is a certificate payload field; `LegalHoldService.php:126,172`
  `'active' => true` is correct when placing a hold. No `segregation`-style analogue exists.
- `McpAnnotationValidator`: zero `return true` — no fabricated pass.
- `evaluatePermission()` is fail-closed (`return false` at :565); anonymous-write explicitly
  fail-closed (:429-436); unlisted actions denied once a schema opts in (:1054-1058).
- Judged **legitimate** (not fail-open): `PermissionHandler:1049-1051` empty-auth default
  (documented model — only the *error path reaching it* is the defect);
  `inheritFromPublicTenantDefault()` (:1574-1584) — logs at error level, purely additive;
  `:232-234`/`:243` explicit caller-scoped bypasses; `RateLimiter.php:145-159` documented
  fail-open on cache Throwable (notification delivery, not authz).

## 4. Phantom-ticked tasks / REQ never implemented — see filed issue

Filed as Codeberg **openregister#439**:
https://codeberg.org/Conduction/openregister/issues/439

The full, self-contained findings (including sections 4-6, the corrected selectielijst
near-miss, the bottom line, coverage statement and the two methodology warnings) are in
the issue body, reproduced below verbatim for the record.

---

Read-only audit (2026-07-16) of OpenRegister capabilities whose specs/tasks read as complete but which are **not live at HEAD** (`ebedbdd`). OR is the data abstraction — **every Conduction app inherits these defects**, so severity is weighted toward compliance / data-integrity / security / audit-trail.

Companion to #393 (orphaned write capabilities). This issue covers the **root cause** that #393's symptoms point at, plus findings #393 did not reach.

**Already-tracked, deliberately NOT re-reported here** (checked before filing): `sessionBrokerRequest`'s body-supplied `appId` → **#359** (independently corroborated below); the chat-engine heartbeat → **#414** (an agreed decommission — fixing it would invest in code slated for deletion).

**New actionable items in this issue:** the vocabulary↔engine drift (root cause), the CWE-863 fail-open, the bulk reference-validation gap, `search_requests_total` always 0, the dead `AuthorizationService`/`api/consumers` surface, `tenant-lifecycle`'s absent events, the `AggregationRunner` `default => true` arm, and two gate-methodology fixes.

---

## 🔴 ROOT CAUSE — `ANNOTATION_VOCABULARY` ↔ engine drift

This is the highest-value finding and the class that produced the two previously-fixed phantoms (`x-openregister-approval-chains` → #396, `actions[]` → #433).

`Schema::setConfiguration()` (`lib/Db/Schema.php:1702`) → `validateConfigurationArray()` (`:1761`) **drops** any `x-openregister-*` key not in the private `ANNOTATION_VOCABULARY` whitelist (`:1984`, 19 keys). The drop is logged (`lib/Db/SchemaMapper.php:667` → `:681`) but **the save succeeds** — so an unlisted key silently no-ops.

Two aggravating factors:
1. **`Register::setConfiguration()` (`lib/Db/Register.php:753`) applies NO such filter.** The same annotation works at register level and is silently dropped at schema level. That asymmetry is the trap.
2. The vocabulary is hand-maintained with **no test or gate keeping it in sync with the engines**. It has drifted in **both** directions.

### A. Engine reads a key that is NOT in the vocabulary → silently dropped

| key | engine | effect |
|---|---|---|
| `x-openregister-processing` | `ProcessingLogService::ANNOTATION_KEY` `:75`, read `:344` · `AvgComplianceService::DIALECT_KEY` `:66`, read `:343` | schema-level declaration dropped |
| `x-openregister-manifest-user-fields` | `ManifestService::FIELD_ALLOWLIST_KEY` `:85`, read `:480` | allowlist always null |

**`x-openregister-processing` — HIGH (AVG/GDPR compliance + audit-trail).** This is the *current* processing dialect; `x-openregister-processing-activity` is explicitly its `LEGACY_ANNOTATION_KEY` (`ProcessingLogService.php:82`). **Only the legacy key is in the vocabulary.** Both services read via `$schema->getConfiguration()` (`ProcessingLogService.php:515`; `AvgComplianceService.php:315`), so on a **schema** the new dialect is dropped and only the legacy single-string fallback (`:354`) survives:
- `logReads` (AVG read-access logging) is reachable **only** via the new dialect (`:347`) → **per-schema read-access logging can never be enabled**.
- `attribution` map + `subjectIdFields` (`:348-349`) likewise unreachable per-schema.

**Precision — PARTIAL, not dead.** The engine *is* wired (`ObjectService::find()` → `lib/Service/ObjectService.php:706`, lazily resolved `:744`), and **register-level `logReads` works**. Only the schema-level opt-in is dead. `openspec/specs/avg-verwerkingsregister/spec.md:678` claims opt-in "via the `x-openregister-processing` annotation (`logReads: true`)" for a "schema (or register)" — the **schema half of that sentence is not live**. (That spec is `status: implemented`, not `done` — reported because the claim is load-bearing for AVG, not as a `done`-spec failure.)

**`x-openregister-manifest-user-fields` — MEDIUM (dead capability, fails CLOSED).** `loadFieldAllowlist()` (`ManifestService.php:468-493`) reads the key off `getConfiguration()`; it is dropped at save, so `$explicit` is always null and `resolveAllowedFieldNames()` (`:436`) falls back to `DEFAULT_SAFE_FIELDS` + materialised calculations. Author-declared profile allowlists never take effect. **Fails closed** (fewer fields, no leak) — dead, not unsafe.

### B. Vocabulary key with NO engine → a phantom apps declare and trust

| key | engines in `lib/` | verdict |
|---|---|---|
| `x-openregister-seed` | **0** | **PHANTOM — confirmed dead** |
| `x-openregister-widgets` | validator only (`SchemaMapper:662` → `WidgetAnnotationValidator`) | UNSURE — likely rendered by nc-vue |
| `x-openregister-relations` | 1 comment only (`AnnotationNotificationDispatcher:1852`) | UNSURE — likely consumed by nc-vue |

The other 16 keys (`lifecycle`, `aggregations`, `calculations`, `references`, `aggregate-refs`, `notifications`, `processing-activity`, `archival`, `object-source`, `quality`, `dedup`, `flows`, `survivorship`, `merge`, `handoff`, `mcp`) each have real engine consumers — **verified live**.

**`x-openregister-seed` — HIGH (data-integrity, MDM trust).** In the vocabulary (`Schema.php:1995`) so it round-trips into the config column, but **nothing in `lib/`, `appinfo/`, `src/` reads it**. The real seeding engine consumes a *different* shape: top-level `x-openregister.seedData` (`ImportHandler.php:3736`).
→ `lib/Settings/trust_configuration_register.json:90` declares **6 `trustConfiguration` objects** under per-schema `x-openregister-seed`, and that file has **no top-level `seedData`** → **the MDM trust rules are never planted.** Trust-tier / freshness-decay resolution starts with zero rules and silently falls back to defaults.

#### 🔑 Why a `done` spec produced a dead key — the acceptance criteria stop at persistence

`openspec/specs/archival-annotation-vocabulary/spec.md` is **`status: done`, and its requirement is literally satisfied**:

> *"The `Schema::ANNOTATION_VOCABULARY` constant SHALL include the keys `x-openregister-archival` and `x-openregister-seed`. Schemas declaring either annotation … MUST round-trip through the import path without being silently dropped by `validateConfigurationArray()`, and the … warning MUST NOT fire for these keys."* (`:10-12`)

Every scenario tests **round-trip / not-dropped / present in `GET /api/schemas/<slug>`** (`:14-18`). **None requires an engine to read the key.** So `x-openregister-archival` got both a vocabulary entry *and* an engine (8 consuming files), while `x-openregister-seed` got the vocabulary entry and **nothing else** — and the spec is *correctly* `done` by its own terms.

**This is the generative mechanism behind the whole defect class: "not dropped" ≠ "consumed".** A vocabulary entry makes a key *persist*, which makes it *look* supported to every app that declares it — the key round-trips, the API returns it, no warning fires. It just never acts. Any future annotation spec MUST carry a consumption criterion ("engine X reads it and does Y"), not only a persistence one.

**Checked for supersession rather than assuming dead** (the #393 near-miss discipline):
- `dsar_policy_pack_register.json:316` `x-openregister-seeds` (plural, also unlisted, also no engine) **IS SUPERSEDED** — the file *does* carry a top-level `x-openregister.seedData`, and its own `description` says the per-schema blocks "are the historical declaration and are NOT processed by importFromApp". **Wiring it would DUPLICATE the seeded packs. Leave it.**
- `data_subject_request_register.json:477` `x-openregister-seeds` is never planted, but the content is demo fixture data (`j.jansen@example.org`) → LOW; arguably correct.

### Fix direction (root cause first)
1. **Add a gate/unit test cross-checking `ANNOTATION_VOCABULARY` against engine key constants bidirectionally**: every vocabulary key must have ≥1 engine reading it; every `x-openregister-*` constant read from `getConfiguration()` must be in the vocabulary. This is the durable fix — it would have caught #396 and #433.
2. Add `x-openregister-processing` + `x-openregister-manifest-user-fields` to the vocabulary.
3. Either implement a per-schema seed engine **or** migrate `trust_configuration_register.json` to top-level `seedData` and delete `x-openregister-seed` from the vocabulary.
4. Consider making a dropped key **fail the save** rather than warn; align `Register::setConfiguration()` with `Schema`.

---

## 🔴 HIGH — silent fail-open in register-authorization resolution (CWE-863)

Independently re-verified, not taken on trust:
- `PermissionHandler::getRegisterAuthorization()` (`lib/Service/Object/PermissionHandler.php:1727`) wraps `RegisterMapper::find()` in `catch (\Throwable $e) { $this->cachedRegisterAuth[$registerId] = null; return null; }` (`:1742-1745`) — **no log**, and the `null` is **pinned in the per-request cache**.
- `resolveAuthorization()` (`:1487-1491`) → falls through → `return null` (`:1493`).
- `hasGroupPermission()` (`:1048-1051`): `// If no authorization is set, everyone has all permissions.` → `if (empty($authorization) === true) { return true; }`.

**Net effect:** any `RegisterMapper` failure (DB blip, missing register, container error) silently converts a **register-level-restricted** schema into an **unrestricted** one for the rest of the request. "Resolution failed" and "no authorization configured" collapse to the identical `null`, so the fail-open is indistinguishable from the legitimate default **and leaves no trace**. Scope: schemas with no own `authorization` block that rely on register-level auth.

Telling asymmetry: the sibling `getRegisterForSchema()` **does** log a warning on `\Throwable` (`:1701-1711`); `getRegisterAuthorization()` — the one whose `null` grants full access — logs nothing. Exactly hydra gate-8 (`unsafe-auth-resolver`).

**Fix:** distinguish failure from "unconfigured" (rethrow or sentinel); at minimum, log.
**Caveat:** established by static trace through the cited lines, **not** reproduced at runtime.

---

## 🟠 MEDIUM-HIGH — bulk/import writes skip reference-existence validation

`grep validateReference|referenceValidation|checkReference lib/Service/Object/SaveObjects.php` → **zero hits**. `validateReferenceExists()` runs only on the single-object path (`SaveObject.php:4067`; defined `:4290`). The live bulk path is `BulkController.php:390` → `ObjectService.php:3291` → `SaveObjects::saveObjects` → `ultraFastBulkSave`.

→ **Bulk/import writes can persist objects with dangling relation UUIDs that a single-object save would reject.** Every app imports via bulk, so this is inherited fleet-wide.

`SaveObject::saveObjectsStreaming` (`:4535`, zero callers) is precisely the primitive that closes this — it loops `saveObject()` per row (`:4545`) and was landed as *"the prerequisite, not the feature"* (`:4516`). Its consumer never arrived.

**Correction to an earlier reading — audit is NOT skipped.** `SaveObjects.php` explicitly replays it: *"Dispatch lifecycle events and write audit trail for persisted objects (BUG-OBJ-1). The ultraFastBulkSave mapper path bypasses the per-row event/audit hooks the single-object insert/update apply, so the bulk path replays them here. Audit trail rows are always written."* That gap was closed by BUG-OBJ-1; only the **reference-validation** half is open.

---

## 🟠 MEDIUM — observability lie: `search_requests_total` is permanently 0

`src/manifest.json:433-441` declares a counter sourced `tableCount` on `openregister_metrics` filtered `metric_type LIKE 'search_%'`. The dead `MetricsService::recordMetric` (`:164`) is that table's **only writer** — and its constants (`:63/:70/:77`) are `file_processed`/`object_vectorized`/`embedding_generated`, so **reviving it would not even fix this metric**.

Real search recording lives in `openregister_search_trails` via `SearchTrailService::createSearchTrail` ← `SearchQueryHandler.php:560`.

**Fix = repoint the descriptor, not revive the method.** Sibling descriptors already migrated (`objects_created_total` reads `openregister_audit_trails`, manifest `:443-449`) — this one was left behind. A dashboard counter that always reads 0 is worse than no counter.

---

## 🟠 MEDIUM — `AuthorizationService` is a dead auth surface, but `api/consumers` still ships

Verdict: **DEAD**. Zero production references outside its own file (only `phpmd.baseline.xml:133`, `psalm-baseline.xml:287`). Not registered in `lib/AppInfo/Application.php`; not among the 7 registered middlewares (`:387-433`). `authorizeJwt`/`authorizeBasic`/`authorizeOAuth`/`authorizeApiKey` are **`protected`** (`:214,342,379,443`) and **no class extends it** → structurally unreachable; tests reach them only by reflection.

- `corsAfterController` (`:411`) is **legitimately SUPERSEDED** — `lib/Middleware/PublicApiCorsMiddleware.php:18` states it mirrors that logic via the middleware chain.
- **But** `api/consumers` CRUD still ships (`appinfo/routes.php:13,90`; `ConsumersController.php:40`) and writes `authorizationConfiguration` (publicKey, algorithm) whose **only reader is the dead service** (`:258`). Admins can configure JWT issuers / API-key consumers that **nothing enforces** — a credential store with no consumer.
- **Mitigating:** `ConsumersController` has zero `NoAdminRequired` → admin-only by NC default. **Not an exploitable hole** — misleading posture + dead code. Superseded-by-openconnector is consistent with the evidence.

**Fix:** delete the service and either retire `api/consumers` or document it as openconnector-managed.

---

## 🟡 MEDIUM — other confirmed orphans

- **`StreamYieldChannel::emitHeartbeat` (`:215`) — DEAD with a live subscriber waiting, but SUPERSEDED by #414 → do NOT wire.** `ChatStreamController.php:339` registers `$channel->onHeartbeat(...)`; **nothing ever fires it**. `forwardWithHeartbeat` only piggybacks a heartbeat onto the *next real frame* → idle SSE keepalive never sent → long silent LLM generations killed by proxy idle timeout. **However**, #414 (*Delete OpenRegister chat engine — ChatService, Chat/\* handlers*) is an open, agreed decommission of this exact code. Fixing the heartbeat would invest in code slated for deletion and contradict #414. **Recommendation: let it die with the engine; track under #414, not here.**
- **`PermissionHandler::clearInheritFromPublicCache` (`:729`) — DEAD.** Docblock `:723-726` states a **MUST** nothing honors: *"Any path that mutates authorization and then re-reads it within the same request must bust this cache."* Cache read `:1513`, written `:1557`, cleared only `:732/:736`. Key is schema-id only. (UNSURE whether a mutate-then-read path exists in one request — not proven.)
- **`AggregationRunner::checkOp()` `default => true` (`lib/Service/Aggregation/AggregationRunner.php:1170`)** — an unknown/typo'd operator in a manifest-declared aggregation filter **keeps the row**: the filter silently stops filtering rather than erroring. **No save-time validation of operator names exists** (`grep` for an ops allow-list in `lib/Service/Aggregation/*Validator*.php` → zero hits), so the runtime `default` arm is the only arbiter.
- **`CredentialController::sessionBrokerRequest` trusts a body-supplied `appId` — ALREADY FILED as #359, not re-reported here.** My independent trace corroborates it: `:509` reads `appId` from an unverified body param and passes it to `broker->request()`, while `CredentialBrokerService.php:137` states *"On the HTTP path `appId` comes ONLY from a verified `X-Credential-Token`."* Since `appId` feeds `assertAppAllowed()` (`:182`, `:466`), a client-chosen `appId` makes Guard 2 **trivially satisfiable**. **Calibrated:** credential *access* still holds — Guard 1 `loadAdmittedCredential()` (`:320`) enforces personal→owner and organisation→membership, so this is app-attribution, not credential theft. **Track under #359.**

---

## 🟡 MEDIUM — `tenant-lifecycle` (`status: done`): all four spec'd events are absent

`openspec/specs/tenant-lifecycle/spec.md` (`status: done`) mandates dispatch of `OrganisationActivatedEvent` (`:28`), `OrganisationSuspendedEvent` (`:36`), `OrganisationDeprovisioningEvent` (`:49`), `OrganisationArchivedEvent` (`:79`). **None of the four classes exist** — `lib/Event/` ships only `OrganisationCreatedEvent`, `OrganisationDeletedEvent`, `OrganisationUpdatedEvent`.

`TenantLifecycleService` **injects `IEventDispatcher` (`:103`) and never dispatches anything** — `suspend()` (`:285-301`) and `reactivate()` (`:314-330`) contain no dispatch. Textbook *class-injected ≠ method-called*.

→ No fleet app can react to a tenant being suspended/deprovisioned (halt syncs, revoke sessions, stop background jobs). **The extension point silently does not exist, and any listener an app writes will never fire.**

**Correctly bounded — not an authz bypass:** suspension *is* enforced with a 403 at `lib/Middleware/TenantQuotaMiddleware.php:125-131`. This is a missing extension point, not a security hole.

---

## ⚠️ CORRECTED FINDING — selectielijst CRUD is **SUPERSEDED**, not a compliance gap

Recording this because it is the audit's most instructive near-miss, and the exact trap #393 warned about.

An initial pass flagged this as **HIGH / Archiefwet compliance**: `2026-05-01-archivering-vernietiging/tasks.md:9` ticks `[x]` a "CRUD via `lib/Controller/SelectionListController.php`" that **does not exist** (no file, no routes, no DI, no UI); `SelectionListMapper::createEntry()`/`updateEntry()` have **no production callers**; the migration creates `openregister_selection_list` but never seeds it. The obvious-but-wrong conclusion: *selectielijsten can never be configured → `archiefactiedatum` never computed → Archiefwet breach.*

**That conclusion is wrong.** The live path supersedes it entirely:
- `RetentionService::lookupSelectielijstEntry()` **does not use `SelectionListMapper` or that table at all.** It reads selectielijst entries as **ordinary OpenRegister objects** from an operator-configured register/schema (`selectielijstRegister` / `selectielijstSchema` from archival settings), via `objectMapper->findAll(filters: ['object->categorie' => $categorie])`.
- Those settings are real and operator-settable (`lib/Service/Settings/ObjectRetentionHandler.php:296,326,354`).
- `RetentionService` is the live cron path (`BackgroundJob/DestructionCheckJob.php:139`, registered `appinfo/info.xml:108`) and genuinely performs "selectielijst lookup, archiefactiedatum calculation" (`:6-7`, `:153-155`).
- The whole of `ArchivalService` — which holds the only `selectionListMapper->findByCategory()` call (`:452`) — is referenced **only by itself and its own unit test**. Dead alongside its dead branch.

**So selectielijst configuration works** (as OR objects through the normal UI), and no dedicated controller was ever needed. **Filing a controller-shaped fix would have rebuilt a superseded mechanism.**

**Residual — LOW (cleanup/doc debt, not compliance):** `SelectionList`, `SelectionListMapper`, `ArchivalService` and the `openregister_selection_list` table remain shipped as unreachable legacy, and the archived tasks.md still asserts a controller that never existed. Delete the legacy trio + table, and correct the archived task line.

---

## ✅ Verified CLEAN (stated explicitly, so the negative result is reusable)

- **Phantom handler refs: the prior "0" is CONFIRMED, not inherited.** `lib/Settings/register.d/` contains **only `README.md`** — zero JSON — so **OR has none of the fleet-wide 17-missing-guard-class defect**. An independent walk of `lib/Settings/*.json` found exactly one real class ref: `data_subject_request_register.json` → `…finaliseDenial.requires` = `OCA\OpenRegister\Service\Gdpr\Lifecycle\DenialFinaliseGuard`. It resolves (`lib/Service/Gdpr/Lifecycle/DenialFinaliseGuard.php:48`), implements `LifecycleGuardInterface` (clearing the `LifecycleGuardRegistry::resolve()` instanceof throw at `:116-124`), and its `check()` is fail-closed (`:85-108`). Other `handler`/`provider` matches are data fields, not class refs.
- **Archival/destruction: no fabricated pass.** `DestructionService.php:661` `'immutable' => true` is a certificate payload field; `LegalHoldService.php:126,172` `'active' => true` is correct when placing a hold. No `segregation`-style "always passes here" analogue exists in OR.
- `McpAnnotationValidator`: zero `return true` — no fabricated pass.
- `evaluatePermission()` is fail-closed (`return false` at `:565`); anonymous-write explicitly fail-closed (`:429-436`); unlisted actions denied once a schema opts in (`:1054-1058`).
- **Judged legitimate (NOT fail-open):** `PermissionHandler:1049-1051` empty-auth default (documented model — only the *error path reaching it* is the defect above); `inheritFromPublicTenantDefault()` (`:1574-1584`, logs at error level, purely additive); `:232-234`/`:243` explicit caller-scoped bypasses; `RateLimiter.php:145-159` documented fail-open on cache Throwable (notification delivery, not authz).

---

## Gate methodology findings

1. **`check_orphaned_write_capability.py` cannot see cross-app callers.** `ObjectService::clearCurrents` (`:3980`) was flagged orphaned but is **LIVE** — called from `openconnector/lib/Service/EndpointService.php:634,1583,1592,1899` via `$this->objectService->getOpenRegisters()->clearCurrents()`. OR's own docblock (`ObjectService.php:3972-3974`) says "Called by external apps (e.g. OpenConnector)". **Deleting it would break OpenConnector endpoint rendering.** Any single-repo gate will keep flagging cross-app public API — that class needs an allowlist or a fleet-wide grep.
2. Of the 16 flagged, **5 are correctly SUPERSEDED** (`recordMetric`, `generateDestructionList`, `generateCertificate`, `ExportHandler::export`, and `corsAfterController` in the sibling finding). Re-deriving the four #393 flags from evidence: **three** were superseded (`generateDestructionList` + `generateCertificate` by `RetentionService` on the live cron path — wiring them **would** have created the duplicate destruction pipeline; **plus `recordMetric`** by the AppHost Observability pull engine). `issueToken` is **not** superseded — it is an unused-by-design consumer-facing API half (`verify()` is live at `CredentialController.php:452`, routed `:44`; consumers get a secret via `registerApp` `:412` and sign themselves). **Keep it.**

---

## Bottom line — of 136 `done` specs

**No evidence that OR's `done` set is broadly untrustworthy.** The high-blast-radius compliance surface (GDPR/DSAR, e-depot SIP/BagIt, audit hash chain, retention destruction, RBAC hardening) was verified **genuinely implemented** at real symbols and line numbers.

- **~2 of ~24** properly-verified high-blast-radius `done` specs are partial phantoms (**~8%**) — `tenant-lifecycle` (events absent) and `archival-annotation-vocabulary` (`seed` key with no engine). Both are "the mechanism was built but nothing can feed or hear it" — the known orphaned-capability shape, **not** fabricated changes.
- **4 of 16** gate-flagged orphans are worth acting on; **5 are correctly superseded**; **1 (`clearCurrents`) is a gate false positive that is LIVE.**
- The genuinely fleet-dangerous items are the **vocabulary↔engine drift** (root cause) and the **CWE-863 fail-open**, neither of which is a `done`-spec failure — both are gaps *between* specs.

## Coverage / honesty statement

- **Exhaustive:** the `ANNOTATION_VOCABULARY` ↔ engine cross-check (all 19 keys, both directions, incl. a fleet-wide grep for `widgets`/`relations`); all 16 gate-flagged orphaned write capabilities; `register.d` handler resolution (trivially exhaustive — the dir holds no JSON); `AuthorizationService` (every public/protected method).
- **Mechanically complete, shallow depth:** all **375 archived changes** — 7,665 backticked symbols extracted from `[x]` task lines (842 paths / 285 `Class::method()` / 403 classes / 112 routes), every one existence-checked against HEAD. Real SHAPE-A coverage of the whole archive, but only at symbol-existence depth.
- **Properly verified (code read, verdict reached): ~24 of 136** `done` specs, weighted to blast radius.
- **Sampled shallowly: ~4.** **Untouched: ~107** — mostly the `retrofit-*` family (~60 of 375) and frontend/integration leaves, deprioritised as low blast radius. **A defect could hide there.**
- **Systematic but not exhaustive:** the fabricated-pass sweep (`=> true` / `default => true` / `catch → return true` across `lib/Service/**` + `lib/Db/**`, hand-judged; prioritised authz, lifecycle guards, validators, archival/DSAR).
- **Not established:** the CWE-863 fail-open is a **static trace, not a runtime reproduction**. `x-openregister-widgets` / `x-openregister-relations` are **UNSURE** — no engine in OR *or* nc-vue, but `openbuild` has schema-editor UI for both (`openbuild/src/components/schema-editor/RelationEditor.vue`), so they are plausibly authoring-only keys rather than defects. Not chased further.

## Two methodology warnings for future audits of this repo

1. **`custom_apps/` is an untracked nested tree of other Conduction apps inside openregister's working dir.** Indexing it produces **false negatives** — other apps' symbols mask OR's own gaps (it hid 14 classes + 6 methods in the first pass). **Restrict every scan to `git ls-files`.**
2. **The naive "symbol missing at HEAD ⇒ phantom" heuristic had a ~92% false-positive rate here** (236 candidates → 2 real). Every false positive was a legitimate pattern: annotated renames (the `integration-*` family names the *proposed* symbol then cites the real one), removal tasks (absence = success), deliberate supersession (Solr builders removed in `ea99a5004`), naming drift with the capability intact (`FileLockHandler::forceUnlock()` → `unlockFile(force: true)`), NC magic accessors (`@method` declarations at `lib/Db/ObjectEntity.php:97-100`, invisible to a `function get…` grep), and external symbols (NC Tables / DocuDesk). **Always hunt the superseding implementation before declaring anything dead** — as the corrected selectielijst finding above demonstrates.

