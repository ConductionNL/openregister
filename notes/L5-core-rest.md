# L5-core-rest: hand-over to the cloud work packages (2026-11-19)

The local lane stops here. Clone: ~/or-lanes/L5-core-rest/openregister (clean, on refactor/L5-core-rest-action-rights-port).

## Done (merged or being merged)
- #10661 RegisterSchema rowed to Core (45 refs)
- #10662, #10663 Core controllers use Kernel\Contracts\AppIdentity instead of Application::APP_ID (33 refs)
- #10670 Configuration controllers' Controller/Support bags rowed to Configuration (36 refs)
- #10675 presence: Core port Kernel\Contracts\Core\ObjectPresenceInterface, Notifications ObjectPresenceTracker (3 refs)
- #10688 action rights: Core port Kernel\Contracts\Core\ActionRightsInterface, catalogue interface extends it (3 refs)

## What is left
Per file, from origin/main's modularity baseline (counted before #10675/#10688 landed, so up to 6 of these entries
may already be gone): ~/or-lanes/L5-core-rest/remaining.txt (207 refs in 106 files, `source<TAB>targets`).
Rough split by source directory: Controller 36, Service/File 34, Service/Object 26, Controller/Support 26,
Service 17, Service/Search 9, Service/Aggregation 8, Service/UserMigration 7, Db 7, Service/GraphQL 5,
Db/SchemaMapper 5, others small.

## Decisions already taken by the coordinator (apply them)
1. EntityRelations* and GdprEntities* families belong to L3: leave them out of these packages.
2. Controller/Support bags that serve Configuration controllers are rowed to Configuration (done in #10670).
3. ObjectsController (about 25 refs left), one PR per kind:
   - Export: MAY MOVE. When the action's logic is another area's work (export), move the action into a controller of
     that area and keep the URL in appinfo/routes.php (ADR-016). Moving changes the route NAME (objects#export becomes
     e.g. objectExport#export). That is safe: a fleet-wide grep (2026-11-19) found no code reading the name (no
     linkToRoute or route-name generateUrl in openregister src/, templates/, lib/; in the fleet only prose in openspec,
     capabilities.json, an e2e comment). Callers use the URL /apps/openregister/api/objects/{register}/{schema}/export:
     openregister src/modals/register/ExportRegister.vue, nextcloud-vue src/utils/indexExportHelpers.js and
     src/components/CnIndexPage/selfModeIO.js, dq-proposals src/utils/caseExportHelpers.js. The export block is
     ObjectsController::export plus about 25 private helpers (lines ~6195-6860) and its tests
     (ObjectsControllerExportRecordingGoldenTest, ObjectsControllerTest). Do the same for Geo
     (geoSearch/geoJson/wfs/geocode, Search work) if it is genuinely Search's work.
   - Read state, change gate (Processes) and the file download (Files): Core-owned ports in
     lib/Kernel/Contracts/Core/, implemented and bound by the higher area. Use the presence port (#10675) as the pattern.
4. Files: lib/Service/File/* and FileService are file storage, i.e. Files work. Do NOT reassign their csv rows yet.
   First add a Core-owned port for what Core needs from file storage, one slice at a time; the rows follow after.
5. Platform action rights, language and translation: Core-owned ports, not csv moves. Action rights is done.
   Language and translation are open (LanguageServiceInterface and TranslationMapperInterface in MagicSearchHandler,
   TranslationMappers and TranslationControllerServices; Configuration's LanguageService in AggregationRunner and
   others).
6. ActionRightsController stays in Core with the port. Its remaining refs (Settings\OpenRegisterAdmin in the
   AuthorizedAdminSetting attribute, Flow FlowNodeRightsGuardInterface and IFlowNodeRequiresRight) are open.

## Not yet decided (ask before building)
- Controller/Support bags whose only users sit in other areas: ActivityLinksControllerServices, RelationsRecords and
  ObjectIntegrationsAccess (Integrations); HybridSearchDiagnostics (Search); SearchTrailControllerServices
  (Compliance); TranslationControllerServices (Processes); MaintenanceReachableControllersInterface (Platform
  middleware). Rowing each to its user's area looks like the same move as #10670. Check first that L4 has not
  already done Integrations/Compliance.
- Core Db/Event classes that name Configuration entities: Db/Configuration (ManagedByConfigurationTrait,
  AbstractConfigurationEvent), Db/Application, Db/TaskSequence, Db/JobRun, Db/RecordTemplate,
  HydrateBySettersTrait, IsoTimestampsTrait. Candidates are an id or a Kernel value object (ADR-119 Decision 5).
  Two traits are generic helpers, so rowing them to Core may be right.
- Search references from Core services: AggregationQuery, TimeseriesRequestValidatorInterface, ViewMapperInterface,
  PropertySearchProfile, Geo/vectorization interfaces.

## Lessons for whoever picks this up
- tests/Unit/Architecture takes about 2 h on this box when the box is loaded; give it no timeout.
- A worktree with a symlinked vendor loads the MAIN clone's lib (Composer's baseDir resolves the symlink). Do not do it.
- tests/Db and tests/Service integration tests need OPENREGISTER_TEST_NC_ROOT; they error locally whatever the change.
- A new port under lib/Kernel/Contracts/Core/ shows as "newly exposed" in modularity_check --check. That is intended;
  list it in the PR body.
- psalm reports InvalidConstantAssignmentValue on ContractBindings::BINDINGS on main as well (inherited).
- Prefix pgrep/pkill matches by PID only; `pgrep -f <path>` matched and killed my own shell once.
