# L3-core-processes: what is left (input for the cloud work packages)

The local lane stopped after round 2 (2026-11-19).

- Merged: #10655, #10656, #10657 (round 1) and #10671 (F11 fix, cherry-picked).
- Being merged: #10684 (relation bundles) and #10685 (schema declaration checks).

The source list is `~/or-lanes/shared/L3-core-processes.txt`. Re-measure on main with `python3 tools/sig/modularity_check.py`; the per-file counts below are from before round 2.

## Rules learned in this lane (put them in every package)

- Run ALL of `tests/Unit/Architecture`, never one test from it.
  - Several architecture rules catch a port or a moved class: F11 (value interfaces), C12 and F02 (validators named only through their interfaces), D7 (hidden classes).
  - On a loaded box the suite takes 45 to 50 minutes. Give it at least 6000 s.
- A test that builds `SchemaMapper` by hand needs `tests/Unit/Support/ProcessDeclarationChecksDispatcher`, or the contributed checks do not run.
- Adding or removing a listener changes two pins:
  - `tests/Unit/Listener/fixtures/listener-order.json`;
  - `ListenerRegistrationOrderGoldenTest` (its count and sha256).
- After merging main, `component-map.csv` conflicts row by row: keep both sides' rows, then regenerate with `python3 scripts/component-patterns.py && python3 tools/sig/deptrac_areas.py`.
- Never `git reset --soft origin/main` after a fetch moved main. It silently reverts main's newer commits in your tree.
- Inherited and red on main, not this lane's work:
  - gate-67: `lib/Contract/ObjectFileReaderInterface.php` and `ObjectServiceInterface.php` parity;
  - gate-102: the "Daily report by e-mail" nl.json key;
  - `MappingSlugFallbacksTest::testAMissingTransliteratorKeepsTheName`;
  - `MagicMapperCrossTableScanGoldenTest`: fails only inside a large mixed run.

## Package 1: Compliance and Integrations schema checks onto the same event (#10685)

- **Goal:** empty the rest of `lib/Service/Schemas/SchemaDeclarationValidators.php`. Before #10685 it held 22 upward references, of which the Processes part is gone; the Compliance and Integrations part remains.
- **Mechanism:** `SchemaDeclarationChecksEvent`, `SchemaDeclarationOrder` and `PositionedDeclarationCheck`, exactly as Processes does in `lib/Listener/SchemaDeclarationChecksListener.php`.
- **Compliance slots and calls:**
  - `MDTO_MAPPING`: `RecordAnnotationRefusals::checkMdtoMapping`, using `elementMapping()` and `MdtoElementCatalogue`.
  - `ARCHIVAL`: `RecordAnnotationRefusals::checkArchival`, using `archival()`.
  - Decide whether consent (`SubjectDeclarationRefusals::checkConsent`, `consent()`) is Compliance work; its validator lives in `Service/Consent`.
- **Integrations slots and calls:**
  - `HANDOFF`: `ExchangeAnnotationRefusals::checkHandoff`, using `handoff()`.
  - `HANDOFF_CONTRACT`: `checkHandoffContract`, using `handoffContractBinding()`.
- **What each area adds:**
  - a listener registered in its own registrar;
  - a refusals class for the moved methods;
  - a factory class in `lib/Service/` for the validators, to keep C12.
- **Remove** the moved methods from `RecordAnnotationRefusals` and `ExchangeAnnotationRefusals`, and their factories from `SchemaDeclarationValidators`.
- **Tests:**
  - Retarget every hand-built `SchemaMapper` test. `ProcessDeclarationChecksDispatcher` will need to run the new listeners too; consider renaming it to a general one.
  - `SchemaDeclarationOrderTest::testEverySlotIsFilledOnce` must include the new contributions.
  - Add several-faults cases that cross Compliance or Integrations with Core.
  - Run each several-faults case against main first (before and after).

## Package 2: `lib/BackgroundJob/JobHandles.php` (15 references)

- Targets: 4 Compliance jobs, 3 Configuration jobs, 1 Integrations job, and 7 Processes jobs. The Processes jobs are AnnotationNotificationDispatch, AutoTransition, BulkJobRunner, FileTextExtraction, ObjectTextExtraction, SourceRecordRecompute and TranslationProjection.
- It looks like a Core class naming every area's job class (a job catalogue or handle map).
- Likely fix: each area registers its own job handles in its own registrar or listener (a collection event or a port per capability). Or, if `JobHandles` is only wiring, it is L1-style wiring and its rows move to the areas.
- Read it first: a "kind of job" lookup that needs answers suggests a Core port `JobHandleSourceInterface` that every area contributes to.

## Package 3: `lib/Service/ObjectService.php` (11 references)

- Targets:
  - Compliance: `SchemaDeletionService`, `SearchTrailService`
  - Configuration: `SettingsService`
  - Files: `FileFolderServiceInterface`
  - Integrations: `DeclaredRow`, `TableDeletionOptions`
  - Processes: `ProcessingListRead`, `ReadHistoryService`, `Lifecycle\AutoTransitionPass`
  - Search: `RegisterSchema`, `ViewMapperInterface`
- Hot path: no new I/O or per-object lookups on object save. A port call on an injected service is fine.
- Other apps use `ObjectService` by name about 6,000 times. Its public signatures may only widen.
- Per target, decide between an event at the same point (a read-history write, the auto-transition pass after a save) and a Core port (settings read, view mapper).

## Package 4: `lib/Service/Object/RenderObject.php` (8 references)

- Targets:
  - Configuration: `Db/Translation`
  - Files: `FileRestrictionGuardInterface`
  - Platform: `TranslationSlot`
  - Processes: `CalculationPayload`, `ReadStateService`, `WatcherService`, `ObjectActionList` (and `PropertyRbacHandler`, which is gone since #10657)
  - Search: `RegisterSchema`
- Rendering enrichments (read state, watchers, actions, calculations) fit one Core port: "render contributions", a tagged set every area adds to. Or one port per capability where a single answer is needed.
- `RenderObject` already resolves some services through the container to keep its constructor down. Keep render cost flat: no per-object container lookups added.

## Package 5: `lib/Controller/SchemasController.php` (7 left)

- Targets:
  - Compliance: `AuditStatisticsScope`, `AuthorizationAuditService`, `AuthorizationChange`
  - Notifications: `NotificationDeclarationException`
  - Processes: `FlowTriggerRenameCheck`, `FormDependentsCheckInterface`, `ApprovalPositionFormGuard`
- The four declaration exceptions are Core since #10656.
- Schema rename and delete guards (flow trigger rename, form dependents, approval positions) fit one Core port: "schema change checks" as a collection. This is the same pattern as #10685, and it could even share its event if the slots are extended.
- Audit statistics and authorization audit: an event after the change, or move the endpoint into a Compliance controller (option 3, only if it is that area's work).

## Other files still in the list (not packaged; re-measure first)

- `ObjectCleanupJob` (4), `ListenerBootstrap` (4: L1-style wiring), `EntityRelationMapper` (4).
- `RecomputeTimersForCalendarJobServices` and `ChainGateRun` moved to Processes in #10655.
- `CaseTokenControllerServices` and `FormLinksControllerServices`: they serve Integrations controllers, so their references stay against the order until Integrations→Processes is solved.
- Single references in `lib/Service/Object/*` (`BulkRowRules`, `DraftActivation*`, `DraftStatusPolicy`, `ObjectSaveChecks`, `PermissionHandler`, `RenderDerivations`, `VirtualCalculations`, `SaveObjectRuntime`, `BulkObjectQuota`, `SearchLenses`). Several are save-path checks that could join a "save checks" collection the way #10685 did for schema checks.
- `ExpressionDefaultException` and `FreezeTerms`: leave them, L2 owns the Kernel side (decision 3).
