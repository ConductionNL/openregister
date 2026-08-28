## Context

Gate 6 (`orphan-auth`) on clean `origin/development` (recursive enumerator,
full `lib/Service` + `lib/Controller` tree, 621 tracked files) reports **14**
public `is*/requires?*/validate*/authorize*/check*/ensure*/verify*/assert*`
methods with **zero** callers in `lib/` or `src/` (the `->method(` grep is the
reliable "actually called" signal; DI injection of the class is NOT a call).

Each finding was read end-to-end and traced to its live call path (or proven to
have none). `class-injected ≠ method-called` was the recurring trap — several of
these classes ARE registered in the DI container yet no consumer invokes the
flagged method.

## Per-method verdict table

| # | file:line | method | verdict | evidence (live path / supersession) |
|---|-----------|--------|---------|-------------------------------------|
| 1 | `lib/Service/Object/AuditHandler.php:137` | `validateObjectOwnership` | **DELETE (superseded)** | Audit-log reads go through `LogService::getLogs()` (`AuditTrailController::objects` @400 `requireAdmin()` + `ObjectsController` path), which re-validates register/schema ownership INLINE (`LogService.php:158-175`, throws `InvalidArgumentException` on mismatch). `AuditHandler::getLogs()` is itself off the live path — `ObjectService::getLogs()` delegates to `GetObject::findLogs`; `$this->auditHandler` is injected into `ObjectService` but never called. Method + its two private-only helpers `extractSchemaId`/`extractSchemaSlug` removed. No test referenced it. |
| 2 | `lib/Service/Archival/DestructionService.php:511` | `validateDestructionList` | **DELETE (superseded)** | Legal holds are enforced fail-closed at execution by `DestructionExecutionJob` ("Re-check legal hold at execution time" @160-161 `hasActiveLegalHold` → skip + `notifySkippedHolds`) and at scan by `findEligibleObjects()` @201. `approveList()` only queues the job. Pre-flight method never called anywhere; no test. |
| 3 | `lib/Service/Object/SearchQueryHandler.php:669` | `isSearchTrailsEnabled` | **DELETE (superseded)** | `logSearchTrail()` gates on `getEffectiveRecordingMode() === 'none'` (@594); `resolveRecordingMode()` reads the same `searchTrailsEnabled` setting (@722). The archived `optimize-request-hygiene` change explicitly memoised this to replace "a second `isSearchTrailsEnabled()` read". Dead unit-adjacent test in `ObjectHandlersIntegrationTest` removed. |
| 4 | `lib/Service/Object/ValidationHandler.php:779` | `validateSchemaObjects` | **DELETE (superseded)** | The live `/api/objects/validate` route (`ObjectsController::validate` @4390) calls `ObjectService::validateAndSaveObjectsBySchema()`, not this method. The Vue `validateSchemaObjects(schema)` in `RegisterSchemaCard.vue` is a frontend method hitting that same endpoint — unrelated to the PHP method. Two dead tests in `ObjectHandlersIntegrationTest` removed. |
| 5 | `lib/Service/Aggregation/AggregationQuery.php:223` | `isGrouped` | **LEAVE (value-object predicate)** | Public accessor on the `AggregationQuery` value object; unit-tested (`AggregationQueryTest`). Not an authorization check; presently unused in prod but part of the query object's public API. Not force-deleted (removes tested API) nor wired (no consumer needs it). |
| 6 | `lib/Service/Aggregation/AggregationQuery.php:290` | `isMultiFieldGroupBy` | **LEAVE (value-object predicate)** | Same object, added by archived `aggregation-multi-field-groupby`; unit-tested. `getGroupByFields()` is the consumed sibling. Not auth. |
| 7 | `lib/Service/Edepot/Transport/TransportResult.php:109` | `isPartialSuccess` | **LEAVE (interface seam)** | Documented part of the `TransportResult`/`TransportInterface` contract (`isSuccess` IS consumed); unit-tested (`TransportTest`). Result-state predicate, not auth. |
| 8 | `lib/Service/File/FilePreviewHandler.php:122` | `isPreviewAvailable` | **LEAVE (value-object predicate)** | Availability predicate, unit-tested (`FilePreviewHandlerTest`). Not auth. |
| 9 | `lib/Service/Gdpr/Identity/IdentityVerifyResult.php:190` | `isVerified` | **LEAVE (value-object predicate) / UNSURE** | Three-state GDPR verification RESULT accessor, unit-tested. `DsarCaseController::identityVerify` @416 records the outcome via `toArray()`; no downstream fulfilment path reconstructs the result to call `isVerified()` (status is persisted/read as an array field). No unprotected mutating path depends on this method. Left as an honest UNSURE rather than force-wire a hypothetical gate. |
| 10 | `lib/Service/Geo/GeoJsonGeometryValidator.php:73` | `isGeoType` | **LEAVE (format predicate)** | Type/format predicate, unit-tested (`GeoJsonGeometryValidatorTest`). Not auth. |
| 11 | `lib/Service/Geo/RdCrsTransformer.php:75` | `isSupportedCrs` | **LEAVE (format predicate)** | CRS support predicate, documented in the class docblock, unit-tested. Not auth. |
| 12 | `lib/Service/Integration/PropertyReferenceTypeValidator.php:136` | `validateAll` | **LEAVE (deferred opt-in) / UNSURE** | DI-registered (`Application.php:1200`) but injected into NO consumer. `validate()` is only "called" transitively by `validateAll()` itself, masking it from the gate's same-file caller allowance. The AD-18 `referenceType` marker is an OPT-IN schema-property marker (data-quality, not access control). Wiring into schema-save is speculative feature work with schema-rejection regression risk; superseding path does not exist. Follow-up filed on #444. |
| 13 | `lib/Service/Integration/PropertySemanticReferenceValidator.php:149` | `validateAll` | **LEAVE (deferred opt-in) / UNSURE** | DI comment (`Application.php:1212`) explicitly: "wire into schema-save when write-time enforcement is desired" — deliberately deferred. Validates IRI well-formedness of the `referenceSemanticType` marker, not authorization. Follow-up filed on #444. |
| 14 | `lib/Service/Notification/NotificationReadState.php:94` | `isRead` | **LEAVE (value-object predicate)** | Read-state predicate on a value object, unit-tested (`NotificationReadStateTest`). Not auth. |

**Counts:** wired **0**, deleted **4**, seam/leave **8**, unsure **2** (methods 9, 12, 13 carry an UNSURE note but stay in place).

**No LIVE unprotected mutating action was found.** Every security-relevant orphan
(audit-log ownership, destruction legal-hold, search-trail gate, object
validation) already has a proven, live, fail-closed enforcement point elsewhere;
the orphan was a duplicate, not the sole guard.

## Decisions

### Declarative-vs-imperative decision (ADR-031)

This change ships **no notifications** and touches **no** notification dialect.
It only removes dead PHP methods and their dead tests. ADR-031 (canonical
`x-openregister-notifications` dialect) is therefore **N/A**: there is no
`lib/Settings/*register*.json` change and no imperative object-notification
dispatch introduced or removed.

### Delete vs. leave threshold

A method was DELETED only when ALL held: (a) zero `->method(` callers in `lib/`
+ `src/`; (b) a DIFFERENT, live, currently-executing check enforces the same
rule (proven by reading the live route/job); (c) any test exclusively covering
it is removed in the same change. Methods failing (b) — no superseding path — or
that are non-auth value-object API were LEFT, because deleting tested public API
or force-wiring a deferred opt-in feature both carry more risk than the dead
predicate they would remove. This mirrors the cycle's caution against creating
duplicate pipelines by wiring superseded code.

## Seed Data (ADR-001)

N/A — this change adds no registers, schemas, or seeded objects. It deletes dead
service methods only. No `lib/Settings/*register.json` is added or modified.

## Risks / Trade-offs

- **Risk:** a cross-app or reflection caller of a deleted method that the `lib/`
  + `src/` grep cannot see. Mitigation: all four deleted methods are internal OR
  service helpers (not public API surface, not in `appinfo/routes.php`, not MCP
  provider methods); OR is the abstraction other apps consume via
  `/api/objects`, never these handler internals.
- **Trade-off:** the 10 left-in-place findings keep gate 6 (full-repo) at 10.
  Under diff-scoped CI (`--scope-to-diff` + `filter_preexisting`) they are
  pre-existing and non-blocking; the umbrella #444 tracks them as documented,
  non-auth debt rather than silent risk.

## Migration Plan

None. Pure code deletion, no schema/data/route change, no config migration.

## Open Questions

- Should the two `validateAll` integration-marker validators be wired into
  schema-save as a future opt-in enforcement (behind a setting)? Deferred to a
  dedicated feature change; noted on #444.
