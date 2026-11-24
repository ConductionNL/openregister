# Contract design: the Kernel area

Wave 5 design package D01 of the change `functional-areas-depend-in-one-direction` (hydra ADR-119,
Decisions 8, 9, 13 and 14). Kernel is rank 0 in `area-order.json`: every area may use it, and it may use
no area. This file is a design. It changes nothing in `lib/`.

Measured on `origin/main` `911c2702` with the module graph of the maintainability checker, the reading
`modularity_check.py` uses (`exposed 155651 of 459456 lines, Kernel 10440, violation
degree 0.0874, 727 references against the order`). Fleet use was measured with `fleet_surface.py` over
fresh `fleet_checkout.sh` checkouts of the 19 fleet apps (development branches,
2026-11-19), counting `Event\` and `Contract\` names as well, because the question here is which Kernel
classes other apps name at all. Lines are code lines as that tool counts them.

## Summary

| | Today | After this design |
|---|---|---|
| Kernel files | 456 (10,440 lines) | 299 (7,434 lines) |
| Kernel files another area names | 398 | 254 |
| Their lines (Kernel's exposed lines) | 8,515 | 5,889 |
| Exposed lines, whole app | 155,651 | 152,991 |
| References against the order out of Kernel | 29 | 7 (the deprecated `Schema` methods, decided) |
| References against the order, whole app | 727 | 705 |
| New files in Kernel | | 0 |
| Newly exposed files | | 0 |

The "after" column is a simulation, not an estimate. The script applied every move of §5 to the graph
measured on `911c2702` (csv rows reassigned, the two edges K5-04 removes taken out) and ran the exposure
and order arithmetic of `modularity_check.py` again, one package at a time; no step adds an exposed file,
a Kernel file or a reference against the order. How it was built is at the end.

Kernel is not a provider area like the others. It is the vocabulary every area shares: the object store's
operations and value objects, the three entities, the events, the shared refusals. Most of that is meant
to be named by everyone and has to stay exposed. What is not meant to be there is the part of Kernel that
only one area uses: 157 files that sit in Kernel although no two areas share them. Each is named by
exactly one other area (or only by files that move with it), so counting it to that area unexposes it;
the exception is the three organisation events, which move to Core, their dispatcher, to end three
references against the order and stay exposed. That is the whole design: no new port is needed in
Kernel, and a new Kernel file would fail the gate anyway.

## 1. The exposed files of Kernel today

398 files, 8,515 lines. Grouped by kind, with what this design does to each group:

| Kind | Exposed files | Lines | After | Lines after |
|---|---|---|---|---|
| Exceptions | 119 | 1,950 | 44 | 733 |
| Events | 96 | 1,810 | 44 | 603 |
| Entities (`Db/Schema`, `Db/ObjectEntity`, `Db/Register`) | 3 | 1,471 | 3 | 1,471 |
| Ports and values in `Kernel/Contracts/` | 78 | 1,165 | 78 | 1,165 |
| Value objects in `Kernel/Contracts/Value/` | 41 | 552 | 41 | 552 |
| `lib/Support/` | 10 | 505 | 10 | 505 |
| Db toolkit and mapper interfaces | 11 | 321 | 11 | 321 |
| Published contract (`lib/Contract/`) | 6 | 184 | 6 | 184 |
| `lib/Dto/` | 7 | 149 | 3 | 106 |
| `lib/Formats/` | 7 | 142 | 7 | 142 |
| `lib/Lifecycle/` | 9 | 115 | 2 | 29 |
| `lib/Service/` (value objects in Form, Flow, Handoff, Lifecycle, Object, Schemas, Oas) | 10 | 108 | 4 | 35 |
| `Kernel/Compat/ImplementationForm` | 1 | 43 | 1 | 43 |
| **Total** | **398** | **8,515** | **254** | **5,889** |

The full per-file list, with lines, outside callers per area and fleet apps, is Appendix A (what moves)
and Appendix B (what stays).

Split by how many other areas name a file:

| | Files | Lines |
|---|---|---|
| Named by two or more other areas | 192 | 4,860 |
| Named by one other area only | 206 | 3,655 |
| of which also named by another Kernel file | 81 | 1,114 |

Outside callers per area (a Kernel file counts once per area that names it): Core 219, Processes 175,
Integrations 117, Configuration 91, Files 60, Compliance 45, Notifications 42, Search 33, Platform 30.

### The references against the order out of Kernel (29)

Kernel may use no other area. These 29 references do:

| From (Kernel) | To | Count | Treatment |
|---|---|---|---|
| `Db/Schema.php` | Core `Db/Schema/*` validators (6), `Service/Schemas/UniqueConstraintEvaluator` | 7 | Kept. Decided: the entity methods that construct Core classes are deprecated and every openregister caller moves to a Core service (the D02 design), but they keep working, so the references stay until a major release removes them. |
| `Event/TaskTerminalEvent`, `TaskTransitionedEvent` | Processes `Db/Task`, Core `Event/AbstractTaskEvent` | 4 | K5-02, K5-04: the task events count to Processes. |
| `Event/TaskSequenceCompletedEvent`, `TaskSequenceUndecidedEvent` | Processes `Db/Task`, Configuration `Db/TaskSequence`, Core `Event/AbstractTaskSequenceEvent` | 6 | K5-02: count to Processes; the two to `TaskSequence` stay (now from Processes, same pair strings, see §6 note). |
| `Event/OrganisationCreatedEvent`, `…Deleted…`, `…Updated…` | Core `Event/AbstractOrganisationEvent`, `Db/Organisation` | 3 | K5-01: count to Core, which dispatches them. |
| `Event/BulkActionRegistrationEvent` | Processes `BulkAction/BulkActionInterface`, `Service/BulkActionRegistryInterface` | 2 | K5-02 |
| `Event/ToolRegistrationEvent` | Integrations `Mcp/Service/ToolRegistryInterface`, `Mcp/Tool/ToolInterface` | 2 | K5-03 |
| `Event/RegisterLeafProvidersEvent` | Integrations `Service/Integration/IntegrationProvider`, `LeafDescriptor` | 2 | K5-03 |
| `Event/DeepLinkRegistrationEvent` | Core `Service/DeepLinkRegistryServiceInterface` | 1 | K5-03: counts to Integrations, whose `DeepLinkRegistryService` dispatches it. |
| `Event/EntityRelationDecisionUpdatedEvent` | Core `Db/EntityRelation` | 1 | K5-01 |
| `Event/ObjectEventSubscription` | Core `Listener/ListenerClassesInterface` | 1 | K5-01 |

Each of these events is dispatched by exactly one area, and that area is the one its payload belongs to.
Keeping the event in Kernel and narrowing its payload to a Kernel contract was the other option. It is
not taken: fleet apps read these payloads (`TaskTerminalEvent` 7 apps, `RegisterLeafProvidersEvent` 8,
`DeepLinkRegistrationEvent` 6, `EntityRelationDecisionUpdatedEvent` filinq, `OrganisationUpdatedEvent`
dossiq), a getter that returns an interface instead of the entity narrows what they can call, and the
row change is the honest description: the dispatcher's area owns its event (BRIEF, allowed way 1: "an
existing or new event, in Kernel or the lower area"). No class name or namespace changes.

## 2. What outside callers actually need

The kept set, by capability. "Who" is the calling areas; the members are what they use.

| Capability | Who | What they use |
|---|---|---|
| Object store operations | all nine areas, 16 fleet apps (through the published contract) | `ObjectOperationsInterface` (`find`, `findAll`, `saveObject`, `deleteObject`, `searchObjectsPaginated`, `lockObject`, …; 50 methods; splitting it would not lower the count, every part would stay exposed) with its value objects `ObjectFindTarget`, `ObjectFindOptions`, `ObjectSaveOptions`, `ObjectDeletion`, `ObjectPatch`, `SaveActor`, `SavePolicy`, `Lock/UnlockRequest`, `Bulk*`; `ListQuery`, `ObjectRef`, `RegisterSchemaRef` |
| The entity model | all nine areas | `Schema`, `ObjectEntity`, `Register` (getters, `jsonSerialize`, `getProperties`, `getUuid`, …), `SchemaInterface`, `RegisterInterface`, `ObjectEntityInterface`, `SchemaMapperInterface`, `RegisterMapperInterface` |
| The Db toolkit every area's mapper uses | Core, Compliance, Processes, Integrations, Configuration | `SoftDeletion`, `JsonArrayColumn`, `JsonFieldsTrait`, `EmptyJsonHydration`, `HydrationKey`, `ManagedByConfigurationTrait`, `LockTerms`, `FreezeTerms`, `Db/Schema/AnnotationVocabulary` |
| The event bus | listeners in every area and in 15 fleet apps | object lifecycle events (`ObjectCreating/Created/Updating/Updated/Deleting/Deleted/Transitioned/Locked/Unlocked/Reverted`, `ObjectsMergedEvent`), entity events of registers, schemas, configurations, applications, sources, agents, conversations, views, `DraftCreated/Updated/Deleted`, `AppWebhookEvents`, `PermissionsDeclaringEvent`, `FlowRunTerminalEvent`, `ViewAlertCrossedEvent` |
| Shared refusals | thrown in one area, caught or declared in another | `NotAuthorizedException`, `ValidationException`, `CustomValidationException`, `HookStoppedException`, `ObjectStateWriteException`, `SchemaNotInRegisterException`, `RegisterNotFoundException`, the note refusals, `TaskAccessDenied/Conflict/ValidationException` (Processes' tasks, caught by one Integrations file), … |
| Cross-area ports already in `Kernel/Contracts/` | the areas below each implementer | `AppSettingsInterface`, `AppIdentity`, `NoteServiceInterface`, `ChatServiceInterface`, `AgentMapperInterface`, `ConversationMapperInterface`, `TaskManagerInterface`, `GitHubClientInterface`, `EntityEventsInterface`, `ObjectNameCacheInterface`, calendar, scheduled report, notification preference and timer ports with their values |
| Query parameter vocabulary | Core, Search, Integrations, Files, Configuration, Platform | `FilterParams`, `NullFilter`, `OrderParameter`, `QueryLimit`, `UnknownFilterKeys`, `AggregationFilterRequest`, `ContentDisposition`, `AppConfigValue`, `FleetAppId`, `RegisterSlugAliasesInterface` |
| The published contract | 8 fleet apps; internal callers | `lib/Contract/*` (`ObjectServiceInterface`, `ObjectEntityInterface`, `RegisterSlugResolverInterface`, …): gate 67, ADR-119 Decision 14 |
| Field formats | Core validation, Files text extraction, Search's GraphQL uuid scalar, Notifications templating | `UuidFormat::isValid`, `LicensePlateNlFormat`, `IdentifierFormats` via `FormatFactory` |
| Lifecycle guards other apps implement | Processes, Compliance, 9 fleet apps | `LifecycleGuardInterface`, `GuardResult` |
| Remaining small values | two areas each | `Dto/DeepLinkRegistration`, `DeletionAnalysis`, `DeletionEffects`, `Service/*` value objects, `Kernel/Compat/ImplementationForm` |

## 3. The proposed contract set

Kernel needs no new port. The contract set of Kernel is the kept set of §2: each row there is one
capability. Every file in it is named by two or more areas, or is held in Kernel by a Kernel file that
names it, or is one of the exclusions listed below. A new file in Kernel
fails `modularity_check.py`, so any port an area offers goes in `lib/Kernel/Contracts/<Area>/` and counts
to that area (the other designs, not this one).

What changes is what Kernel stops holding: 157 Kernel files (3,006 lines) count to the one area that uses
them, by csv row, without moving a file (BRIEF, allowed way 3), together with two Core abstract event bases
that only those files name (`AbstractTaskEvent`, `AbstractTaskSequenceEvent`, 26 lines). Appendix A lists
the exposed ones by target area, §5 the rest. They are of four kinds:

- **Exceptions thrown and caught inside one area** (76): `Task*Exception`, `Case*Exception`, `Flow*`,
  `Bpmn*`, `Form*` → Processes; `*Pack*`, `JobRunRefusedException`, `RepairRefusedException`,
  `RecordTemplateRefused` → Configuration; `ObjectFile*`, `OfficeOpenRefusedException`,
  `SanitizationException`, `PdfAnonymisationException`, `EmlParseException` → Files; the write, schema,
  reference and RBAC refusals of the object store → Core.
- **Events and their payload values dispatched and heard inside one area, or dispatched by it for other
  apps** (61, with the two Core bases): file events → Files; flow, form, decision, agent run, bulk action and task events →
  Processes; handoff, person link, deep link, MCP tool and integration leaf events → Integrations; object
  activation, custom scope, reference validation, registry subscription, organisation and relation
  decision events → Core.
- **Lifecycle action plumbing and form, flow and handoff values** (10 in `Lifecycle/`, 6 in `Service/`: `Lifecycle/LifecycleAction*`, `BuiltinLifecycleActionsInterface`,
  `Action/SetFieldsAction`, `Service/Form/*`, `Service/Flow/Timer/*`) → Processes; `Service/Handoff/*` →
  Integrations; `Service/Oas/OasValidationReportInterface` goes to Core.
- **Bulk save outcome values** (5 in `Dto/`: `Dto/BulkSave*`, `BulkRowCount`, `StreamedRowCount`) → Core's bulk writer.

Deliberately **not** moved, although one area uses them:

| Files | Lines exposed | Why it stays in Kernel |
|---|---|---|
| `lib/Support/NullFilter`, `OrderParameter`, `QueryLimit` (Core only), `AppConfigValue` (Integrations only) | 210 | `lib/Support/` is Kernel by its recursive pattern; only moving the file (a rename of `OCA\OpenRegister\Support\…`) would change that. Concrete classes keep their names and directories. |
| `Kernel/Contracts/DecisionInterface`, `ReviewPolicyInterface`, `CaseItemInterface`, `ContactLinkInterface`, `AdminSettingDelegation`, `ExampleSetInstallerInterface` | 47 | Kernel by path. Relocating them under `Kernel/Contracts/<Area>/` would unexpose 47 lines at the cost of six namespace renames; the first four are the payload contracts of events other apps read. Not worth it. |
| `lib/Formats/*` | 142 | One catalogue: `FormatFactory` (Core's validation) and `IdentifierFormats` (Files' extraction) both build `BsnFormat` and `LicensePlateNlFormat`. Splitting it newly exposes `BsnFormat`, which the gate refuses. |
| `Exception/NotImplementedException` | 5 | A generic refusal (portaliq reads it), not Integrations' work. |
| Everything named by two or more areas | 4,860 | Shared vocabulary; that is what Kernel is for. |

### Entities that cross the boundary

| Entity | Area | Decision |
|---|---|---|
| `Db/Schema` (699), `Db/ObjectEntity` (652), `Db/Register` (120) | Kernel | Stay, and stay exposed: 1,471 lines, the floor of Kernel's budget. Every area names them (Core in 420, 343 and 180 files respectively), the events other apps read carry them (`SchemaUpdatedEvent::getSchema()`, `ObjectCreatedEvent::getObject()`, 13 fleet apps name `ObjectEntity`), and `lib/Contract/ObjectServiceInterface` returns them. Counting them to Core would turn every Kernel event that carries one into a reference against the order. Callers that only read move to `SchemaInterface` / `RegisterInterface` / `ObjectEntityInterface` in the area designs where that is free, but as long as Core names the class it is exposed anyway. |
| `Db/Organisation` | Core | No longer named by Kernel once the organisation events count to Core (K5-01). |
| `Db/Task` | Processes | No longer named by Kernel once the task events count to Processes (K5-02, K5-04). Core's `EntityEventPublisher` stops naming it in K5-04. |
| `Db/TaskSequence` | Configuration | Still named by the two task sequence events, then from Processes (see "Notes for the other designs"). |
| `Db/EntityRelation` | Core | No longer named by Kernel (K5-01). |

### The Search contracts the D04 design asked about

D04 asked whether `PropertySearchProfileInterface`, `EmbeddingGeneratorInterface`, `KeywordChunkSearch`,
`ChunkMapperInterface`, `ViewInterface` and `ViewAlertInterface` should move under
`Kernel/Contracts/Search/`. Measured:

| Contract | Named by | Answer |
|---|---|---|
| `PropertySearchProfileInterface` (28) | Core 1, Search 2 | A port Core calls and Search implements: its owner is Core, the lower area. Under `Kernel/Contracts/Search/` the Core caller would point against the order. |
| `EmbeddingGeneratorInterface` (6) | Files 1, Search 2 | Search's own contract to Files. The one candidate; 6 lines. |
| `KeywordChunkSearch` (18), `ChunkMapperInterface` (11) | Core and Files only | Not Search's at all. |
| `ViewInterface` (11), `ViewAlertInterface` (9) | `ViewInterface`: Search 1 and the three Kernel view events; `ViewAlertInterface`: Core 1, Processes 1 and `ViewAlertCrossedEvent` | Named by Kernel events (`ViewCreated/Updated/DeletedEvent`, `ViewAlertCrossedEvent`); they must stay in Kernel. |

None moves in this design. A move under `Kernel/Contracts/<Area>/` renames the interface and only shifts
its lines from Kernel's column to another area's: the total does not change, and Kernel is under its
budget after K5-01 to K5-03. An area design may still re-home a contract when it is the lowest area that
names it, no Kernel file names it, and no fleet app does; `EmbeddingGeneratorInterface` is the only one
that qualifies today.

## 4. The budget

| Step | Files | Kernel exposed | Whole app exposed | Against the order |
|---|---|---|---|---|
| Today (`911c2702`) | | 8,515 | 155,651 | 727 |
| K5-01 rows → Core | 58 | 7,365 | 154,514 | 722 |
| K5-02 rows → Processes | 57 | 6,508 | 153,632 | 714 |
| K5-03 rows → Integrations, Files, Configuration | 42 | 5,913 | 153,028 | 709 |
| K5-04 task terminal event dispatched by Processes | 2 rows, 3 code files | 5,889 | 152,991 | 705 |

Per kind, after: entities 1,471; ports and values in `Kernel/Contracts` 1,165; exceptions 733; events 603; value objects 552;
`Support` 505; Db toolkit and mapper interfaces 321; published contract 184; formats 142; the rest 263.

Against the targets: the DESIGN-BRIEF gives Kernel about 9,700 of the 18,900 lines that an estimated 4.0
allows, and `design.md` about 8,000 of the 12,600 for 4.5. Kernel is at 8,515 today, so it already fits the
first and is over the second by about 500. After this design it is at 5,889: 3,800 under the first and
2,100 under the second, which leaves the other nine areas about 6,700 lines at 4.5 instead of the 2,900
the Core design (D02) worked with.

Nothing here only fits by breaking a rule. What a reviewer has to judge in each row package is whether
the file is genuinely that area's work and not merely used there; the exclusions in §3 are the cases
where it is not. The 7 references from `Db/Schema` to Core stay until the deprecated methods go.

## 5. The build packages

Four packages, in this order. K5-01 to K5-03 touch disjoint rows of one file, `scripts/component-map.csv`,
plus the two files generated from it, so they must land one after another (a conflict in the csv: keep
both sides' rows, regenerate). Each runs `python3 scripts/component-patterns.py` and `deptrac_areas.py`, then `modularity_check.py --check` (gone entries only), `deptrac.sh areas
--check` and `tests/Unit/Architecture`. Nine moved files have no file row yet (they fall under the
`Dto/` and `Lifecycle/` directory rows): they get one.

| Package | Change | Files | Depends on |
|---|---|---|---|
| K5-01 | Rows → Core: the 52 exposed files of Appendix A "To Core" and the 6 unexposed files that move with them (`Dto/BulkSaveOutcome`, `Event/AbstractReferenceValidationEvent`, `AbstractRegistrySubscriptionEvent`, `CustomScopeAccessors`, `EvaluatedSchemaActionAccessors`, `Exception/DatabaseConstraintMessages`). Removes 5 references against the order (organisation 3, relation decision 1, event subscription 1). | `scripts/component-map.csv`, the generated components file and the generated deptrac areas file | – |
| K5-02 | Rows → Processes: the 51 exposed files of Appendix A "To Processes" except `TaskTerminalEvent`, plus `Event/AbstractDecisionRequestEvent`, `Lifecycle/Action/SetFieldsAction`, `ContextLifecycleActionInterface`, `LegacyLifecycleActionInterface`, `Service/Form/PublishedForm` and `Event/AbstractTaskSequenceEvent` (Core row today, named only by the two Kernel task sequence events). Removes 8. | the same three | K5-01 (csv) |
| K5-03 | Rows → Integrations (14 + `Event/AbstractPersonLinkEvent`), Files (16 + `Event/AbstractObjectFileEvent`), Configuration (10). Removes 5 (MCP tools 2, integration leaves 2, deep links 1). | the same three | K5-02 (csv) |
| K5-04 | Processes dispatches its own task terminal event: `Db/TaskMapper` dispatches `TaskTerminalEvent` through `IEventDispatcher` at the same point, with the same `committed: false`, instead of calling `EntityEventsInterface::taskTerminal()`; the method leaves `EntityEventsInterface` (no fleet app names the interface) and `EntityEventPublisher`; then `Event/TaskTerminalEvent` and `Event/AbstractTaskEvent` (Core row today, named only by the two Kernel task events) count to Processes. Removes 4 (`EntityEventPublisher` → `Db/Task`, and the Kernel task event pairs). | `lib/Db/TaskMapper.php`, `lib/Kernel/Contracts/EntityEventsInterface.php`, `lib/Service/Registers/EntityEventPublisher.php`, `tests/Unit/Db/TaskMapperTerminalityTest.php`, `tests/Unit/Db/MapperEntityEventsTest.php` (lists the publisher's events), the three csv/generated files | K5-02 (`TaskTransitionedEvent` already in Processes, so `AbstractTaskEvent` has no Kernel user left) |

K5-04 is the only package with code. Behaviour stays identical: the dispatch is synchronous at the same
point in `TaskMapper`, the mapper still dispatches nothing when the task is not terminal, and the event
object is the same class with the same payload. `TaskMapper`'s optional `EntityEventsInterface`
dependency becomes an optional `IEventDispatcher`; a golden test pins that a terminal update dispatches
exactly one `TaskTerminalEvent` with the task and `committed: false`.

No package adds a listener, so the listener order pins do not move.

## 6. Fleet impact

117 Kernel classes are named by at least one fleet app (Event and Contract names included). Nothing in
this design renames, moves or removes a class, changes an event's name, namespace or payload, or narrows
a signature other apps use; 47 of the 117 change area only, which other apps cannot see. The one removed
method, `EntityEventsInterface::taskTerminal()`, is internal: no fleet app names the interface.

The most-used Kernel classes in the fleet:

| Class | Fleet apps | After |
|---|---|---|
| `Kernel\Contracts\Value\ObjectFindTarget`, `ObjectSaveOptions`, `ObjectDeletion` | 16 | Kernel |
| `Kernel\Contracts\Value\ObjectFindOptions` | 15 | Kernel |
| `Event\ObjectCreating/Created/Updating/UpdatedEvent` | 15 | Kernel |
| `Event\ObjectDeletedEvent` | 14 | Kernel |
| `Db\ObjectEntity` | 13 | Kernel |
| `Lifecycle\GuardResult`, `LifecycleGuardInterface` | 9 | Kernel |
| `Kernel\Contracts\Value\ObjectPatch`, `SaveActor` | 8 | Kernel |
| `Event\RegisterLeafProvidersEvent` | 8 | Integrations |
| `Event\ObjectEventSubscription` | 8 | Core |
| `Kernel\Contracts\Value\SavePolicy`, `Event\ObjectDeletingEvent`, `Event\TaskTerminalEvent` | 7 | Kernel, Kernel, Processes |

Candidates for promotion to `lib/Contract` (ADR-119 Decision 14), named here, not done:

- The object store value objects `ObjectFindTarget`, `ObjectFindOptions`, `ObjectSaveOptions`,
  `ObjectDeletion`, `ObjectPatch`, `SaveActor`, `SavePolicy`: the published `ObjectServiceInterface`
  already takes them, and 16 apps construct them, so they are de facto published.
- `Lifecycle\LifecycleGuardInterface` with `GuardResult`, and `Lifecycle\LifecycleActionInterface` with
  `LifecycleActionContext` and `LifecycleActionProviderInterface`: plug-in points other apps implement.
- `RegisterLeafProvidersEvent`'s `IntegrationProvider` and `LeafDescriptor` and `DeepLinkRegistrationEvent`'s
  registry: registration hooks 8 and 6 apps use.

## Notes for the other designs

- **D02 (Core):** K5-01 gives Core 58 files (1,413 lines), 52 of them exposed today and 3 after.
  The 3 are the organisation events, which become Core's and stay exposed (31 lines, Integrations and Processes listen);
  `AbstractOrganisationEvent` is then unexposed. The `Db/Schema` → Core references (7, 737 exposed Core
  lines) remain as D02 described.
- **Processes and Configuration designs:** `Db/TaskSequence` (Configuration row) is named by Processes'
  task sequence events and services (8 Processes files, 2 Core, 1 Integrations, 1 Platform, 1 Configuration,
  and the 2 Kernel events). If task sequences are Processes' work, its row closes the last 3 references the
  task events leave, but only after the 2 Core references are gone; that is their call.
- **D04 (Search):** answered in §3: no Kernel contract moves under `Kernel/Contracts/Search/` in this
  design; only `EmbeddingGeneratorInterface` would qualify.
- **All designs:** a contract an area adds goes under `lib/Kernel/Contracts/<Area>/`, never directly
  under `lib/Kernel/` (a new Kernel file fails the gate). Constants on ports are literals, not
  `= OtherClass::X`.

## How the numbers were made

1. The graph: the maintainability checker's `production_files` + `php_dependencies` + `component_of_factory`
   over the generated components file, line counts with `code_lines`, exactly as
   `modularity_check.measure()` does; exposure and the order with its `exposure()` and `against_order()`.
2. The moves: a fixed point over Kernel files. A file moves to area X when every outside file naming it
   is in X (or has already moved to X), no remaining Kernel file names it, and it names nothing ranked
   above X. Excluded up front: `lib/Contract/`, `lib/Support/`, `lib/Kernel/` (Kernel by path), `lib/Formats/`
   and `NotImplementedException` (§3). Added by hand: the organisation events (→ Core, their dispatcher),
   the task and task sequence events with their two abstract bases (→ Processes), and for K5-04 the two
   edges `EntityEventPublisher` → `TaskTerminalEvent`, `Db/Task` taken out.
3. The re-measure: the same `exposure()` / `against_order()` over the changed area map, after each package
   in order, checking that no step adds an exposed file or a reference against the order.
4. Fleet: `fleet_surface.measure()` over the 19 checkouts with its allow-list emptied, so `Event\` and
   `Contract\` names count too.

## Appendix A: Kernel files that count to another area

Exposed today; none exposed after, except the three organisation events (exposed from Core). Callers: outside files per area today (Cor Core, Not Notifications,
Sea Search, Com Compliance, Fil Files, Int Integrations, Pro Processes, Cfg Configuration, Pla Platform,
Ker other Kernel files). Fleet apps: how many of the 19 name the class. `TaskTerminalEvent` is in K5-04,
the rest of "To Processes" in K5-02.

#### To Core (52 exposed files, 1,150 lines)

| File | Lines | Callers | Fleet apps |
|---|---|---|---|
| `Dto/BulkRowCount.php` | 13 | Cor 1, Ker 2 |  |
| `Dto/BulkSaveOutcomeInterface.php` | 8 | Cor 2, Ker 2 |  |
| `Dto/BulkSaveOutcomes.php` | 12 | Cor 1 |  |
| `Dto/StreamedRowCount.php` | 10 | Cor 1, Ker 2 |  |
| `Event/ActionEvaluatedEvent.php` | 36 | Cor 1 |  |
| `Event/CustomScopeEvaluatedEvent.php` | 23 | Cor 1 |  |
| `Event/CustomScopeEvaluatingEvent.php` | 41 | Cor 1 |  |
| `Event/EntityRelationDecisionUpdatedEvent.php` | 30 | Cor 1 | 1 |
| `Event/IdentityClaimsCollectingEvent.php` | 29 | Cor 1 |  |
| `Event/MatrixRowsRequestedEvent.php` | 35 | Cor 1 | 1 |
| `Event/ObjectActivatedEvent.php` | 20 | Cor 1 | 1 |
| `Event/ObjectEventSubscription.php` | 130 | Cor 1 | 8 |
| `Event/OrganisationCreatedEvent.php` | 5 | Cor 1, Int 1, Pro 1 | 1 |
| `Event/OrganisationDeletedEvent.php` | 4 | Cor 1, Int 1, Pro 1 |  |
| `Event/OrganisationUpdatedEvent.php` | 22 | Cor 1, Int 1, Pro 1 | 1 |
| `Event/ReferenceValidatedEvent.php` | 5 | Cor 1 |  |
| `Event/ReferenceValidationFailedEvent.php` | 5 | Cor 1 |  |
| `Event/RegistrySubscriptionEndedEvent.php` | 5 | Cor 1 |  |
| `Event/RegistrySubscriptionRequestedEvent.php` | 29 | Cor 1 | 1 |
| `Event/UserProfileUpdatedEvent.php` | 41 | Cor 1 | 1 |
| `Exception/AppendOnlyException.php` | 31 | Cor 4, Ker 1 |  |
| `Exception/ArchiveNotOfferedException.php` | 10 | Cor 3 |  |
| `Exception/AuthorizationBlockException.php` | 10 | Cor 10 |  |
| `Exception/BreakingSchemaChangeException.php` | 32 | Cor 2 |  |
| `Exception/BrokenReference.php` | 12 | Cor 3, Ker 1 |  |
| `Exception/CircularReferenceException.php` | 33 | Cor 1 |  |
| `Exception/CodedValueException.php` | 15 | Cor 3 |  |
| `Exception/DatabaseConstraintException.php` | 24 | Cor 4 |  |
| `Exception/DraftActivationRefusedException.php` | 43 | Cor 3 |  |
| `Exception/EncryptedFieldSortException.php` | 7 | Cor 2 |  |
| `Exception/FieldDecryptionException.php` | 5 | Cor 1 |  |
| `Exception/FolderAccessDeniedException.php` | 15 | Cor 5 | 1 |
| `Exception/GrantExceedsGranterException.php` | 16 | Cor 2 |  |
| `Exception/InvalidAuthorizationRuleException.php` | 7 | Cor 3 |  |
| `Exception/LockedException.php` | 38 | Cor 14 | 1 |
| `Exception/OasValidationException.php` | 19 | Cor 2 |  |
| `Exception/ObjectLookupFailedException.php` | 6 | Cor 2 |  |
| `Exception/OrganisationSlugConflictException.php` | 13 | Cor 5 | 1 |
| `Exception/ReadOnlyTypeException.php` | 21 | Cor 1 |  |
| `Exception/ReferenceCycle.php` | 11 | Cor 1, Ker 1 |  |
| `Exception/ReferenceValidationException.php` | 47 | Cor 3 |  |
| `Exception/SchemaImportException.php` | 31 | Cor 9 |  |
| `Exception/SchemaNotFoundException.php` | 9 | Cor 6 | 1 |
| `Exception/SchemaRunConcurrencyException.php` | 6 | Cor 4 |  |
| `Exception/SearchTermSyntaxException.php` | 22 | Cor 4 |  |
| `Exception/SharedMasterDataWriteException.php` | 42 | Cor 5 |  |
| `Exception/SlugBackfillWriteException.php` | 28 | Cor 2 |  |
| `Exception/SystemContextUnavailableException.php` | 6 | Cor 1 |  |
| `Exception/TranslationTargetConflictException.php` | 43 | Cor 2 |  |
| `Exception/UniqueHintException.php` | 17 | Cor 2 |  |
| `Exception/ViewAlertRecipientException.php` | 22 | Cor 2 |  |
| `Service/Oas/OasValidationReportInterface.php` | 6 | Cor 1, Ker 1 |  |

#### To Processes (52 exposed files, 881 lines)

| File | Lines | Callers | Fleet apps |
|---|---|---|---|
| `Event/AgentRunDispatch.php` | 11 | Pro 1, Ker 1 |  |
| `Event/AgentRunInstruction.php` | 12 | Pro 1, Ker 1 |  |
| `Event/AgentRunRequestedEvent.php` | 98 | Pro 1 | 1 |
| `Event/BulkActionRegistrationEvent.php` | 16 | Pro 3 | 1 |
| `Event/CaseItemTransitionedEvent.php` | 32 | Pro 4 |  |
| `Event/DecisionChangeRequestedEvent.php` | 39 | Pro 1 | 1 |
| `Event/DecisionVisibilityRequestedEvent.php` | 25 | Pro 1 | 1 |
| `Event/FlowEmailItem.php` | 11 | Pro 1, Ker 1 |  |
| `Event/FlowEmailMessage.php` | 12 | Pro 1, Ker 1 |  |
| `Event/FlowEmailOrigin.php` | 12 | Pro 1, Ker 1 |  |
| `Event/FlowEmailSentEvent.php` | 48 | Pro 2 | 1 |
| `Event/FlowTimerFiredEvent.php` | 44 | Pro 6 | 1 |
| `Event/FormDestinationDependentsEvent.php` | 13 | Pro 1 | 3 |
| `Event/RegisterFlowTriggersEvent.php` | 13 | Pro 1 | 1 |
| `Event/TaskSequenceCompletedEvent.php` | 24 | Pro 3 | 2 |
| `Event/TaskSequenceUndecidedEvent.php` | 16 | Pro 1 |  |
| `Event/TaskTerminalEvent.php` | 24 | Cor 1, Pro 7 | 7 |
| `Event/TaskTransitionedEvent.php` | 29 | Pro 6 | 1 |
| `Exception/ActionParameterTypeException.php` | 6 | Pro 4 |  |
| `Exception/BpmnImportRefused.php` | 18 | Pro 3 |  |
| `Exception/BpmnSchemaInvalid.php` | 21 | Pro 5 |  |
| `Exception/BulkJobRefusedException.php` | 19 | Pro 11 |  |
| `Exception/CaseAccessDeniedException.php` | 6 | Pro 5 |  |
| `Exception/CaseCascadeBoundException.php` | 6 | Pro 2 |  |
| `Exception/CasePlanExistsException.php` | 5 | Pro 2 |  |
| `Exception/CaseTransitionException.php` | 6 | Pro 3 |  |
| `Exception/CaseValidationException.php` | 6 | Pro 10, Ker 1 |  |
| `Exception/FlowRunExpired.php` | 6 | Pro 3 |  |
| `Exception/FlowSignalRefused.php` | 26 | Pro 3 | 1 |
| `Exception/FlowTimerStateException.php` | 6 | Pro 1 |  |
| `Exception/FlowTimerValidationException.php` | 6 | Pro 29 | 1 |
| `Exception/FormSubmitRefusedException.php` | 29 | Pro 11 | 1 |
| `Exception/InvalidTransitionInputException.php` | 14 | Pro 5 |  |
| `Exception/LifecycleProviderException.php` | 6 | Pro 4 | 1 |
| `Exception/LifecycleSubjectNotFoundException.php` | 6 | Pro 3 | 1 |
| `Exception/PortalPartyNotFoundException.php` | 6 | Pro 1 |  |
| `Exception/PortalSubjectException.php` | 19 | Pro 3 |  |
| `Exception/TaskCompleteViaAppException.php` | 14 | Pro 2 |  |
| `Exception/TaskFormRefusedException.php` | 23 | Pro 4 |  |
| `Exception/TaskSeparationOfDutiesException.php` | 6 | Pro 1 |  |
| `Exception/TaskSubjectNotFoundException.php` | 6 | Pro 4 |  |
| `Exception/TaskSubjectWriteRefusedException.php` | 6 | Pro 5 |  |
| `Lifecycle/BuiltinLifecycleActionsInterface.php` | 10 | Pro 1 |  |
| `Lifecycle/LifecycleActionContext.php` | 12 | Pro 1, Ker 3 | 2 |
| `Lifecycle/LifecycleActionInterface.php` | 5 | Pro 1, Ker 4 | 2 |
| `Lifecycle/LifecycleActionInvoker.php` | 27 | Pro 1 |  |
| `Lifecycle/LifecycleActionProviderInterface.php` | 7 | Pro 1 | 1 |
| `Lifecycle/LifecycleGuardContext.php` | 19 | Pro 1, Ker 1 | 2 |
| `Lifecycle/LifecycleTransitionGuardInterface.php` | 6 | Pro 1 | 2 |
| `Service/Flow/Timer/EscalationNotice.php` | 12 | Pro 1, Ker 1 |  |
| `Service/Flow/Timer/TimerFiring.php` | 11 | Pro 1, Ker 1 |  |
| `Service/Form/FormDependent.php` | 21 | Pro 2, Ker 1 | 3 |

#### To Integrations (14 exposed files, 241 lines)

| File | Lines | Callers | Fleet apps |
|---|---|---|---|
| `Event/CredentialRelinkRequiredEvent.php` | 26 | Int 2 |  |
| `Event/DeepLinkRegistrationEvent.php` | 37 | Int 2 | 6 |
| `Event/HandoffExecutedEvent.php` | 50 | Int 1 |  |
| `Event/HandoffExecutedSource.php` | 12 | Int 1, Ker 1 |  |
| `Event/HandoffExecutedTarget.php` | 11 | Int 1, Ker 1 |  |
| `Event/PersonLinkUpdatedEvent.php` | 5 | Int 1 | 1 |
| `Event/PersonLinkedEvent.php` | 5 | Int 1 | 1 |
| `Event/PersonUnlinkedEvent.php` | 5 | Int 1 | 1 |
| `Event/RegisterLeafProvidersEvent.php` | 18 | Int 1 | 8 |
| `Event/ToolRegistrationEvent.php` | 15 | Int 3 | 1 |
| `Exception/AuthenticationException.php` | 13 | Int 3 | 1 |
| `Exception/ProviderUnavailableException.php` | 21 | Int 10 | 1 |
| `Service/Handoff/HandoffRequest.php` | 12 | Int 2, Ker 1 |  |
| `Service/Handoff/HandoffSourceRef.php` | 11 | Int 3, Ker 1 |  |

#### To Files (16 exposed files, 233 lines)

| File | Lines | Callers | Fleet apps |
|---|---|---|---|
| `Event/FileCopiedEvent.php` | 4 | Fil 1 |  |
| `Event/FileLockedEvent.php` | 4 | Fil 1 |  |
| `Event/FileMovedEvent.php` | 4 | Fil 1 |  |
| `Event/FileRenamedEvent.php` | 4 | Fil 1 |  |
| `Event/FileRestrictionAdmitEvent.php` | 30 | Fil 1 | 1 |
| `Event/FileUnlockedEvent.php` | 4 | Fil 1 |  |
| `Event/FileVersionRestoredEvent.php` | 4 | Fil 1 |  |
| `Event/ObjectNoteAddedEvent.php` | 30 | Fil 1 |  |
| `Exception/ChunkMatcherException.php` | 17 | Fil 2 |  |
| `Exception/EmlParseException.php` | 6 | Fil 2 |  |
| `Exception/ManualEntityException.php` | 22 | Fil 2 |  |
| `Exception/ObjectFileAccessDeniedException.php` | 21 | Fil 4 |  |
| `Exception/ObjectFolderConflictException.php` | 6 | Fil 3 |  |
| `Exception/OfficeOpenRefusedException.php` | 20 | Fil 2 |  |
| `Exception/PdfAnonymisationException.php` | 31 | Fil 6 |  |
| `Exception/SanitizationException.php` | 26 | Fil 7 |  |

#### To Configuration (10 exposed files, 121 lines)

| File | Lines | Callers | Fleet apps |
|---|---|---|---|
| `Exception/BuiltinPackSlugLockedException.php` | 8 | Cfg 3 | 1 |
| `Exception/ConsistencyCheckWouldWriteException.php` | 14 | Cfg 2 |  |
| `Exception/InvalidPackDefinitionException.php` | 11 | Cfg 5, Ker 2 |  |
| `Exception/JobRunRefusedException.php` | 19 | Cfg 3 |  |
| `Exception/MigrationPackRefusedException.php` | 9 | Cfg 3, Ker 3 |  |
| `Exception/MigrationPackRequestException.php` | 17 | Cfg 3 |  |
| `Exception/PackChangedException.php` | 5 | Cfg 3 | 1 |
| `Exception/PackSlugExistsException.php` | 8 | Cfg 3 |  |
| `Exception/RecordTemplateRefused.php` | 16 | Cfg 4 |  |
| `Exception/RepairRefusedException.php` | 14 | Cfg 3 |  |

## Appendix B: Kernel files that stay exposed

The kept set of §2, by kind, largest first. Same columns as Appendix A.

#### Entities (3 files, 1,471 lines)

| File | Lines | Callers | Fleet apps |
|---|---|---|---|
| `Db/Schema.php` | 699 | Com 43, Cfg 59, Cor 420, Fil 6, Int 21, Not 21, Pla 9, Pro 106, Sea 22, Ker 15 | 4 |
| `Db/ObjectEntity.php` | 652 | Com 100, Cfg 37, Cor 343, Fil 25, Int 49, Not 41, Pla 5, Pro 150, Sea 16, Ker 13 | 13 |
| `Db/Register.php` | 120 | Com 15, Cfg 37, Cor 180, Fil 6, Int 14, Not 6, Pla 2, Pro 20, Sea 6, Ker 7 | 5 |

#### Kernel ports and values (Kernel/Contracts) (78 files, 1,165 lines)

| File | Lines | Callers | Fleet apps |
|---|---|---|---|
| `Kernel/Contracts/ObjectOperationsInterface.php` | 90 | Com 15, Cfg 22, Cor 76, Fil 4, Int 72, Not 12, Pla 3, Pro 53, Sea 10 |  |
| `Kernel/Contracts/ListQuery.php` | 69 | Com 25, Cfg 23, Cor 46, Fil 2, Int 31, Not 11, Pla 7, Pro 33, Sea 4, Ker 5 | 2 |
| `Kernel/Contracts/SchemaInterface.php` | 69 | Cfg 1, Cor 1, Ker 7 | 2 |
| `Kernel/Contracts/AppSettingsInterface.php` | 37 | Cfg 10, Cor 5, Fil 3, Int 2, Pla 1, Pro 2, Sea 1 |  |
| `Kernel/Contracts/StructurePreservation.php` | 35 | Cor 1, Fil 5, Ker 1 |  |
| `Kernel/Contracts/PropertySearchProfileInterface.php` | 28 | Cor 1, Sea 2 |  |
| `Kernel/Contracts/EntityEventsInterface.php` | 27 | Cfg 2, Cor 4, Int 2, Pro 2 |  |
| `Kernel/Contracts/NoteServiceInterface.php` | 27 | Cfg 3, Cor 3, Fil 2, Int 3, Not 4, Pro 2 |  |
| `Kernel/Contracts/RegisterInterface.php` | 26 | Cfg 1, Cor 1, Ker 6 | 2 |
| `Kernel/Contracts/GitHubClientInterface.php` | 25 | Cfg 8, Cor 3 |  |
| `Kernel/Contracts/TaskManagerInterface.php` | 25 | Cfg 1, Cor 1, Pro 8 |  |
| `Kernel/Contracts/ConversationMapperInterface.php` | 24 | Cor 2, Int 2, Pla 3 |  |
| `Kernel/Contracts/TaskInterface.php` | 23 | Pro 19, Ker 1 |  |
| `Kernel/Contracts/ChatServiceInterface.php` | 22 | Cfg 1, Cor 3, Int 2, Pla 3 |  |
| `Kernel/Contracts/AgentMapperInterface.php` | 20 | Cfg 1, Cor 3, Int 4, Pla 4 |  |
| `Kernel/Contracts/KeywordChunkSearch.php` | 18 | Cor 2, Fil 2, Ker 1 |  |
| `Kernel/Contracts/ObjectExporterInterface.php` | 17 | Cfg 2, Cor 2 |  |
| `Kernel/Contracts/ScheduledReportsInterface.php` | 15 | Cfg 2, Not 4 |  |
| `Kernel/Contracts/DocumentProcessingHandlerInterface.php` | 14 | Cor 2, Fil 2 |  |
| `Kernel/Contracts/ObjectRef.php` | 14 | Com 5, Cfg 8, Cor 29, Fil 3, Int 45, Not 1, Pro 22, Ker 4 | 3 |
| `Kernel/Contracts/PreferenceAddress.php` | 14 | Not 3, Pla 1, Ker 2 | 2 |
| `Kernel/Contracts/ObjectMigration.php` | 13 | Cor 3, Ker 1 |  |
| `Kernel/Contracts/RelationDecisionUpdate.php` | 13 | Cor 2, Ker 1 |  |
| `Kernel/Contracts/TaggingHandlerInterface.php` | 13 | Cor 4, Fil 2 |  |
| `Kernel/Contracts/MassValidationRequest.php` | 12 | Cfg 2, Cor 1, Ker 1 |  |
| `Kernel/Contracts/MergeInstruction.php` | 12 | Com 1, Cor 2, Ker 1 | 1 |
| `Kernel/Contracts/NotificationTemplatesInterface.php` | 12 | Cfg 2, Not 3 |  |
| `Kernel/Contracts/RenderSelection.php` | 12 | Cor 6, Ker 1 | 2 |
| `Kernel/Contracts/ScheduledReportInterface.php` | 12 | Cfg 1, Not 2, Ker 1 |  |
| `Kernel/Contracts/TimerChange.php` | 12 | Pro 5, Ker 1 | 2 |
| `Kernel/Contracts/ChatStreamChannelInterface.php` | 11 | Int 6, Pla 1, Ker 1 |  |
| `Kernel/Contracts/ChunkMapperInterface.php` | 11 | Cor 4, Fil 4 |  |
| `Kernel/Contracts/ContactDetails.php` | 11 | Int 2, Pro 1, Ker 1 |  |
| `Kernel/Contracts/ObjectImporterInterface.php` | 11 | Cfg 2, Cor 2 | 1 |
| `Kernel/Contracts/PreferenceWrite.php` | 11 | Not 3, Pla 1, Ker 1 | 2 |
| `Kernel/Contracts/ScheduledReportUpdate.php` | 11 | Cfg 1, Not 1, Ker 1 |  |
| `Kernel/Contracts/TemplateRendering.php` | 11 | Cfg 1, Not 1, Ker 1 |  |
| `Kernel/Contracts/TimerAnchorMove.php` | 11 | Pro 3, Ker 1 |  |
| `Kernel/Contracts/ViewInterface.php` | 11 | Sea 1, Ker 3 |  |
| `Kernel/Contracts/DeckPlacement.php` | 10 | Cfg 1, Cor 1, Int 1, Ker 1 |  |
| `Kernel/Contracts/FlowTimerInterface.php` | 10 | Pro 2, Ker 2 |  |
| `Kernel/Contracts/LinkedTaskDraft.php` | 10 | Cfg 1, Int 1, Pro 3, Ker 1 |  |
| `Kernel/Contracts/NotificationPreferencesInterface.php` | 10 | Not 6, Pla 2 | 1 |
| `Kernel/Contracts/ObjectCalendarTasksInterface.php` | 10 | Cfg 2, Cor 2, Int 3, Pro 3 |  |
| `Kernel/Contracts/ObjectNameCacheInterface.php` | 10 | Cfg 4, Cor 7, Pro 1 |  |
| `Kernel/Contracts/RegisterSchemaRef.php` | 10 | Com 6, Cfg 3, Cor 16, Int 3, Pro 6, Sea 3, Ker 5 |  |
| `Kernel/Contracts/ReportCaller.php` | 10 | Cfg 1, Not 1, Ker 2 |  |
| `Kernel/Contracts/TaskLocation.php` | 10 | Int 1, Pro 5, Ker 1 |  |
| `Kernel/Contracts/TimerSubjectRef.php` | 10 | Pro 3, Ker 1 | 2 |
| `Kernel/Contracts/VocabularyImporterInterface.php` | 10 | Cfg 3, Cor 2, Pla 1 |  |
| `Kernel/Contracts/AccessLinkReaderInterface.php` | 9 | Cor 3, Fil 2, Int 1 |  |
| `Kernel/Contracts/CaseItemInterface.php` | 9 | Pro 1, Ker 1 |  |
| `Kernel/Contracts/ReviewPolicyInterface.php` | 9 | Pro 1, Ker 3 |  |
| `Kernel/Contracts/ViewAlertInterface.php` | 9 | Cor 1, Pro 1, Ker 1 |  |
| `Kernel/Contracts/AgentInterface.php` | 8 | Int 1, Ker 2 |  |
| `Kernel/Contracts/DecisionInterface.php` | 8 | Pro 1, Ker 2 |  |
| `Kernel/Contracts/ExampleSetInstallerInterface.php` | 8 | Cfg 3 |  |
| `Kernel/Contracts/FlowTimerControlInterface.php` | 8 | Cfg 1, Pro 4 |  |
| `Kernel/Contracts/McpCatalogueInterface.php` | 8 | Cfg 2, Cor 2 |  |
| `Kernel/Contracts/ObjectMergerInterface.php` | 8 | Com 1, Cfg 1, Cor 2 | 1 |
| `Kernel/Contracts/AccessLinkInterface.php` | 7 | Cor 1, Fil 1, Ker 1 |  |
| `Kernel/Contracts/AppContainerFinderInterface.php` | 7 | Cfg 1, Int 3 |  |
| `Kernel/Contracts/AppEventWebhookListenerInterface.php` | 7 | Pro 2, Ker 1 |  |
| `Kernel/Contracts/ApplicationInterface.php` | 7 | Cfg 1, Cor 1, Ker 2 |  |
| `Kernel/Contracts/ConfigurationInterface.php` | 7 | Cfg 1, Cor 1, Int 1, Ker 2 |  |
| `Kernel/Contracts/ContactLinkInterface.php` | 7 | Int 1, Ker 1 |  |
| `Kernel/Contracts/ContactMatcherInterface.php` | 7 | Cfg 1, Int 4, Pro 1 |  |
| `Kernel/Contracts/ConversationInterface.php` | 7 | Int 1, Ker 2 |  |
| `Kernel/Contracts/MetricsRetentionInterface.php` | 7 | Com 1, Cor 1, Sea 2 |  |
| `Kernel/Contracts/ObjectCalendarEventsInterface.php` | 7 | Com 1, Cfg 2, Cor 2, Int 1, Pro 3 |  |
| `Kernel/Contracts/SchemaExplorationInterface.php` | 7 | Cfg 2, Cor 1 |  |
| `Kernel/Contracts/SourceInterface.php` | 7 | Cor 6, Int 1, Ker 2 |  |
| `Kernel/Contracts/TextExtractionServiceInterface.php` | 7 | Com 1, Fil 2, Pro 1 |  |
| `Kernel/Contracts/UploadServiceInterface.php` | 7 | Cfg 1, Cor 1, Fil 2 |  |
| `Kernel/Contracts/AdminSettingDelegation.php` | 6 | Pro 2 |  |
| `Kernel/Contracts/AppIdentity.php` | 6 | Com 24, Cfg 36, Cor 35, Fil 8, Int 34, Not 19, Pla 7, Pro 30, Sea 10 |  |
| `Kernel/Contracts/ConfigurationMembershipInterface.php` | 6 | Cfg 1, Ker 1 |  |
| `Kernel/Contracts/EmbeddingGeneratorInterface.php` | 6 | Fil 1, Sea 2 |  |

#### Exceptions (44 files, 733 lines)

| File | Lines | Callers | Fleet apps |
|---|---|---|---|
| `Exception/SchemaNotInRegisterException.php` | 69 | Com 1, Cor 5, Pro 2 |  |
| `Exception/ObjectStateWriteException.php` | 68 | Com 2, Cor 13, Fil 3, Pro 1 |  |
| `Exception/ArchivalImmutableException.php` | 37 | Com 1, Cor 7 | 1 |
| `Exception/UnknownMetadataFieldException.php` | 36 | Cor 1, Pla 1 |  |
| `Exception/RuleRefusedException.php` | 33 | Cfg 1, Cor 1 |  |
| `Exception/AnalyzeRequestRejectedException.php` | 26 | Com 2, Fil 1 |  |
| `Exception/EncryptedFieldFilterException.php` | 25 | Cor 3, Pla 1, Ker 1 |  |
| `Exception/HookStoppedException.php` | 24 | Cfg 1, Cor 7, Pro 7 | 3 |
| `Exception/ReferentialIntegrityException.php` | 24 | Cor 4, Int 1 |  |
| `Exception/ExportTooLargeException.php` | 23 | Cfg 2, Cor 2 |  |
| `Exception/InvalidViewAlertException.php` | 21 | Cor 1, Sea 1, Ker 1 |  |
| `Exception/ValidationException.php` | 18 | Com 3, Cfg 1, Cor 23, Int 1, Pro 3, Sea 1, Ker 6 | 5 |
| `Exception/ConditionGroupException.php` | 17 | Cor 3, Pla 1 |  |
| `Exception/DatabaseErrorChain.php` | 17 | Cor 1, Int 1 |  |
| `Exception/FlowRunRefused.php` | 17 | Int 1, Pro 4 |  |
| `Exception/MergeDecisionException.php` | 17 | Com 1, Cor 1 |  |
| `Exception/MergeNotFullyReadableException.php` | 17 | Com 1, Cor 1 |  |
| `Exception/NoteLockedException.php` | 17 | Fil 1, Ker 1 |  |
| `Exception/ObjectExistsException.php` | 17 | Cor 4, Pro 1 | 1 |
| `Exception/DuplicateBlockedException.php` | 15 | Cor 3, Pro 1, Sea 2 |  |
| `Exception/HandoffException.php` | 15 | Int 3, Pro 2 | 1 |
| `Exception/OfficeServerRequiredException.php` | 15 | Cfg 1, Cor 2, Fil 1, Sea 2 |  |
| `Exception/RegisterNotFoundException.php` | 15 | Cfg 1, Cor 10 | 1 |
| `Exception/NoteEditForbiddenException.php` | 14 | Fil 2, Ker 1 |  |
| `Exception/CustomValidationException.php` | 13 | Cor 10, Fil 1, Pro 7, Sea 1, Ker 2 | 3 |
| `Exception/InvalidNoteParentException.php` | 10 | Fil 1, Not 1 |  |
| `Exception/NoteWriteRefusedException.php` | 10 | Not 1, Ker 2 |  |
| `Exception/NoVtodoCalendarException.php` | 9 | Int 2, Pro 4 |  |
| `Exception/NotAuthorizedException.php` | 7 | Com 3, Cor 31, Fil 2, Int 7, Not 3, Pro 20, Sea 3, Ker 2 | 6 |
| `Exception/AuthorizationUnresolvableException.php` | 6 | Cor 3, Int 1 |  |
| `Exception/CalendarDateKindException.php` | 6 | Cor 1, Pro 2 |  |
| `Exception/ConfidenceThresholdOutOfRangeException.php` | 6 | Cfg 1, Fil 1 |  |
| `Exception/CorrectionRefusedException.php` | 6 | Com 1, Cor 2 |  |
| `Exception/DecisionConflictException.php` | 6 | Cor 1, Fil 1, Pro 1 |  |
| `Exception/ExplicitRevealQueryException.php` | 6 | Cor 4, Pla 1 |  |
| `Exception/RelatedRowFilterNotSupportedException.php` | 6 | Cor 1, Pla 1 |  |
| `Exception/TaskAccessDeniedException.php` | 6 | Int 1, Pro 12 |  |
| `Exception/TaskConflictException.php` | 6 | Int 1, Pro 8, Ker 1 |  |
| `Exception/TaskValidationException.php` | 6 | Int 1, Pro 14, Ker 1 |  |
| `Exception/UnknownMaskFormException.php` | 6 | Cfg 1, Fil 2 |  |
| `Exception/UnknownPatternSetException.php` | 6 | Cfg 2, Fil 1 |  |
| `Exception/DraftAlreadyLeftException.php` | 5 | Cor 3, Pro 2 |  |
| `Exception/NotImplementedException.php` | 5 | Int 7 | 1 |
| `Exception/PropertyAlreadySetException.php` | 5 | Cor 1, Pro 2 |  |

#### Events (44 files, 603 lines)

| File | Lines | Callers | Fleet apps |
|---|---|---|---|
| `Event/AppWebhookEvents.php` | 87 | Int 1, Pro 1 | 1 |
| `Event/ObjectTransitionedEvent.php` | 44 | Com 1, Cor 4, Pro 9, Sea 1 | 4 |
| `Event/PermissionsDeclaringEvent.php` | 43 | Cor 1, Ker 1 |  |
| `Event/ObjectsMergedEvent.php` | 26 | Cor 1, Pro 2 | 3 |
| `Event/ViewAlertCrossedEvent.php` | 26 | Cor 1, Pro 2 |  |
| `Event/AgentUpdatedEvent.php` | 23 | Int 2, Not 2, Pro 1 |  |
| `Event/ConfigurationUpdatedEvent.php` | 23 | Cor 1, Int 1, Not 2, Pro 1 |  |
| `Event/ConversationUpdatedEvent.php` | 23 | Int 2, Pro 1 |  |
| `Event/SourceUpdatedEvent.php` | 23 | Cor 1, Int 1, Not 2, Pro 1 |  |
| `Event/ViewUpdatedEvent.php` | 23 | Int 1, Pro 1, Sea 1 |  |
| `Event/ObjectUpdatedEvent.php` | 22 | Com 1, Cor 4, Int 3, Not 2, Pla 1, Pro 18, Sea 2, Ker 1 | 15 |
| `Event/ApplicationUpdatedEvent.php` | 20 | Cor 1, Int 1, Pro 1 |  |
| `Event/ObjectRevertedEvent.php` | 20 | Cor 1, Int 1, Pro 3 | 1 |
| `Event/RegisterUpdatedEvent.php` | 19 | Com 2, Cor 2, Int 1, Not 2, Pro 3 | 2 |
| `Event/SchemaUpdatedEvent.php` | 19 | Com 3, Cfg 1, Cor 2, Int 1, Not 4, Pro 7 | 3 |
| `Event/FlowRunTerminalEvent.php` | 18 | Cor 1, Pro 5 | 1 |
| `Event/ObjectUpdatingEvent.php` | 17 | Com 1, Cor 11, Int 1, Pro 20 | 15 |
| `Event/AbstractRegisterEvent.php` | 12 | Com 1, Ker 2 |  |
| `Event/AbstractSchemaEvent.php` | 12 | Com 1, Ker 3 |  |
| `Event/DraftCreatedEvent.php` | 5 | Cor 1, Pro 1 | 1 |
| `Event/DraftDeletedEvent.php` | 5 | Cor 1, Pro 1 |  |
| `Event/DraftUpdatedEvent.php` | 5 | Cor 1, Pro 1 | 1 |
| `Event/AgentCreatedEvent.php` | 4 | Int 2, Not 2, Pro 1 |  |
| `Event/AgentDeletedEvent.php` | 4 | Int 2, Pro 1 |  |
| `Event/ApplicationCreatedEvent.php` | 4 | Cor 1, Int 1, Pro 1 |  |
| `Event/ApplicationDeletedEvent.php` | 4 | Cor 1, Int 1, Pro 1 |  |
| `Event/ConfigurationCreatedEvent.php` | 4 | Cor 1, Int 1, Not 2, Pro 1 |  |
| `Event/ConfigurationDeletedEvent.php` | 4 | Cor 1, Int 1, Pro 1 |  |
| `Event/ConversationCreatedEvent.php` | 4 | Int 2, Pro 1 |  |
| `Event/ConversationDeletedEvent.php` | 4 | Int 2, Pro 1 |  |
| `Event/ObjectCreatedEvent.php` | 4 | Cor 4, Int 3, Not 2, Pla 1, Pro 13, Sea 2, Ker 1 | 15 |
| `Event/ObjectCreatingEvent.php` | 4 | Com 1, Cor 9, Int 1, Pro 18 | 15 |
| `Event/ObjectDeletedEvent.php` | 4 | Com 1, Cor 3, Int 3, Not 3, Pla 1, Pro 12, Sea 2, Ker 1 | 14 |
| `Event/ObjectDeletingEvent.php` | 4 | Cor 5, Int 1, Pro 4 | 7 |
| `Event/ObjectLockedEvent.php` | 4 | Cor 1, Int 1, Pro 3 | 1 |
| `Event/ObjectUnlockedEvent.php` | 4 | Cor 1, Int 1, Pro 3 | 1 |
| `Event/RegisterCreatedEvent.php` | 4 | Com 2, Cor 2, Int 1, Not 2, Pro 2 | 2 |
| `Event/RegisterDeletedEvent.php` | 4 | Com 2, Cor 2, Int 1, Not 1, Pro 3 | 1 |
| `Event/SchemaCreatedEvent.php` | 4 | Com 2, Cfg 1, Cor 2, Int 1, Not 4, Pro 5 | 1 |
| `Event/SchemaDeletedEvent.php` | 4 | Com 2, Cor 2, Int 1, Not 1, Pro 4 | 1 |
| `Event/SourceCreatedEvent.php` | 4 | Cor 1, Int 1, Not 2, Pro 1 |  |
| `Event/SourceDeletedEvent.php` | 4 | Cor 1, Int 1, Pro 1 |  |
| `Event/ViewCreatedEvent.php` | 4 | Int 1, Pro 1, Sea 1 |  |
| `Event/ViewDeletedEvent.php` | 4 | Int 1, Pro 1, Sea 1 |  |

#### Kernel value objects (41 files, 552 lines)

| File | Lines | Callers | Fleet apps |
|---|---|---|---|
| `Kernel/Contracts/Value/GitHubFileLocation.php` | 44 | Cfg 3, Cor 1, Ker 1 |  |
| `Kernel/Contracts/Value/ObjectFindTarget.php` | 26 | Com 10, Cfg 6, Cor 22, Int 13, Pro 16, Sea 1, Ker 3 | 16 |
| `Kernel/Contracts/Value/GitHubIssueSubmission.php` | 18 | Cfg 3, Ker 1 |  |
| `Kernel/Contracts/Value/ObjectDeletion.php` | 17 | Com 2, Cfg 3, Cor 6, Int 7, Pro 2, Ker 2 | 16 |
| `Kernel/Contracts/Value/LockRequest.php` | 16 | Cor 4, Pro 1, Ker 2 | 3 |
| `Kernel/Contracts/Value/ObjectFindOptions.php` | 16 | Com 4, Cfg 4, Cor 18, Fil 3, Int 15, Not 2, Pla 2, Pro 11, Ker 3 | 15 |
| `Kernel/Contracts/Value/ObjectValidationVerdict.php` | 16 | Cor 1, Ker 1 |  |
| `Kernel/Contracts/Value/ImportScope.php` | 15 | Cor 1, Ker 1 | 1 |
| `Kernel/Contracts/Value/ObjectExportRequest.php` | 15 | Cfg 3, Cor 2, Ker 1 |  |
| `Kernel/Contracts/Value/GitHubIssueSelection.php` | 14 | Cfg 2, Ker 1 |  |
| `Kernel/Contracts/Value/GrantQuery.php` | 14 | Cor 1, Ker 2 |  |
| `Kernel/Contracts/Value/ObjectPatch.php` | 14 | Cor 2, Pro 6, Ker 2 | 8 |
| `Kernel/Contracts/Value/WordReplacementRequest.php` | 14 | Cor 1, Fil 1, Ker 1 | 1 |
| `Kernel/Contracts/Value/ChecklistTick.php` | 13 | Pro 3, Ker 1 |  |
| `Kernel/Contracts/Value/NoteDraft.php` | 13 | Cfg 1, Cor 1, Fil 2, Int 1, Not 2, Pro 1, Ker 1 |  |
| `Kernel/Contracts/Value/ObjectSaveOptions.php` | 13 | Com 10, Cfg 6, Cor 19, Int 13, Pro 10, Sea 1, Ker 2 | 16 |
| `Kernel/Contracts/Value/DocumentAnonymisationRequest.php` | 12 | Cor 1, Fil 1, Ker 1 |  |
| `Kernel/Contracts/Value/GrantObject.php` | 12 | Cor 1, Ker 1 |  |
| `Kernel/Contracts/Value/ImportOptions.php` | 12 | Cor 1, Ker 1 |  |
| `Kernel/Contracts/Value/ImportRequest.php` | 12 | Cfg 1, Cor 1, Ker 1 | 1 |
| `Kernel/Contracts/Value/ObjectNotesQuery.php` | 12 | Fil 1, Int 1, Not 2, Pro 1, Ker 1 | 1 |
| `Kernel/Contracts/Value/TaskAuditEntry.php` | 12 | Pro 5, Ker 1 |  |
| `Kernel/Contracts/Value/UnlockRequest.php` | 12 | Cor 3, Pro 1, Ker 2 | 3 |
| `Kernel/Contracts/Value/BulkBatchDefaults.php` | 11 | Cfg 2, Cor 5, Pro 1, Ker 1 | 3 |
| `Kernel/Contracts/Value/BulkSaveOptions.php` | 11 | Cfg 2, Cor 4, Pro 1, Ker 2 | 3 |
| `Kernel/Contracts/Value/ConversationScope.php` | 11 | Int 1, Pla 1, Ker 1 |  |
| `Kernel/Contracts/Value/DocumentAnonymisationOptions.php` | 11 | Cor 1, Ker 1 |  |
| `Kernel/Contracts/Value/GitHubFileWrite.php` | 11 | Cfg 2, Cor 1, Ker 1 |  |
| `Kernel/Contracts/Value/NoteEdit.php` | 11 | Fil 1, Int 1, Not 1, Pro 1, Ker 1 |  |
| `Kernel/Contracts/Value/RepositorySearchPage.php` | 11 | Cfg 5, Ker 1 |  |
| `Kernel/Contracts/Value/SaveActor.php` | 11 | Cfg 4, Cor 2, Pro 4, Ker 1 | 8 |
| `Kernel/Contracts/Value/SavePolicy.php` | 11 | Com 3, Cfg 1, Cor 4, Pro 1, Ker 1 | 7 |
| `Kernel/Contracts/Value/TitleClaim.php` | 11 | Int 3, Ker 1 |  |
| `Kernel/Contracts/Value/AgentAudience.php` | 10 | Int 1, Pla 1, Ker 1 |  |
| `Kernel/Contracts/Value/GitHubIssueText.php` | 10 | Cfg 2, Ker 1 |  |
| `Kernel/Contracts/Value/ImportMapping.php` | 10 | Cor 1, Ker 1 |  |
| `Kernel/Contracts/Value/LockRunStep.php` | 10 | Cor 2, Pro 1, Ker 1 |  |
| `Kernel/Contracts/Value/NoteAuthor.php` | 10 | Cor 1, Fil 1, Ker 1 |  |
| `Kernel/Contracts/Value/TaskActorRef.php` | 10 | Pro 5, Ker 1 | 2 |
| `Kernel/Contracts/Value/TaskDelegation.php` | 10 | Pro 3, Ker 1 |  |
| `Kernel/Contracts/Value/TaskResolution.php` | 10 | Pro 3, Ker 1 |  |

#### Support (10 files, 505 lines)

| File | Lines | Callers | Fleet apps |
|---|---|---|---|
| `Support/FilterParams.php` | 188 | Cor 5, Sea 2 |  |
| `Support/NullFilter.php` | 93 | Cor 4 |  |
| `Support/OrderParameter.php` | 58 | Cor 7 |  |
| `Support/FleetAppId.php` | 44 | Cfg 1, Cor 1, Int 12, Pla 1 |  |
| `Support/QueryLimit.php` | 40 | Cor 4 |  |
| `Support/ContentDisposition.php` | 23 | Cor 1, Fil 1 |  |
| `Support/AppConfigValue.php` | 19 | Int 2 |  |
| `Support/RegisterSlugAliasesInterface.php` | 17 | Cor 1, Ker 1 |  |
| `Support/UnknownFilterKeys.php` | 12 | Cor 1, Sea 1, Ker 1 |  |
| `Support/AggregationFilterRequest.php` | 11 | Sea 1, Ker 1 |  |

#### Db toolkit and mapper interfaces (11 files, 321 lines)

| File | Lines | Callers | Fleet apps |
|---|---|---|---|
| `Db/Schema/AnnotationVocabulary.php` | 63 | Cfg 1, Cor 2, Ker 1 |  |
| `Db/SchemaMapperInterface.php` | 52 | Com 25, Cfg 22, Cor 132, Fil 5, Int 23, Not 15, Pla 4, Pro 44, Sea 9 |  |
| `Db/SoftDeletion.php` | 32 | Cor 1, Ker 1 |  |
| `Db/EmptyJsonHydration.php` | 30 | Int 1, Ker 1 |  |
| `Db/LockTerms.php` | 29 | Cor 1, Ker 1 |  |
| `Db/RegisterMapperInterface.php` | 26 | Com 16, Cfg 17, Cor 88, Fil 3, Int 13, Not 5, Pla 1, Pro 17, Sea 6 |  |
| `Db/HydrationKey.php` | 22 | Cor 2, Int 2, Ker 1 |  |
| `Db/JsonArrayColumn.php` | 21 | Int 1, Ker 1 |  |
| `Db/ManagedByConfigurationTrait.php` | 21 | Cfg 1, Int 1, Ker 3 |  |
| `Db/JsonFieldsTrait.php` | 15 | Com 2, Cfg 2, Cor 3, Int 6, Pro 1, Ker 3 |  |
| `Db/FreezeTerms.php` | 10 | Cor 1, Ker 1 |  |

#### Published contract (lib/Contract) (6 files, 184 lines)

| File | Lines | Callers | Fleet apps |
|---|---|---|---|
| `Contract/ObjectServiceInterface.php` | 128 | Cor 2, Int 1 | 8 |
| `Contract/RegisterSlugResolution.php` | 25 | Cor 2, Ker 1 | 1 |
| `Contract/ObjectEntityInterface.php` | 12 | Cfg 1, Fil 2, Ker 4 | 4 |
| `Contract/RegisterSlugResolverInterface.php` | 7 | Cor 2, Int 1 | 7 |
| `Contract/EmailLinkReaderInterface.php` | 6 | Cor 1, Int 1 | 1 |
| `Contract/ObjectFileReaderInterface.php` | 6 | Cor 2 | 1 |

#### Formats (7 files, 142 lines)

| File | Lines | Callers | Fleet apps |
|---|---|---|---|
| `Formats/LicensePlateNlFormat.php` | 47 | Fil 1, Ker 2 |  |
| `Formats/UuidFormat.php` | 25 | Cfg 2, Cor 13, Int 1, Not 1, Pro 1, Sea 1 |  |
| `Formats/FormatFactory.php` | 24 | Cor 4 |  |
| `Formats/ExtendedFieldTypeValidatorInterface.php` | 12 | Cor 1, Ker 2 |  |
| `Formats/IdentifierFormats.php` | 12 | Fil 2 |  |
| `Formats/RecurrenceWindow.php` | 12 | Cor 1, Ker 2 |  |
| `Formats/FormattedProperty.php` | 10 | Cor 1, Ker 3 |  |

#### Dto (3 files, 106 lines)

| File | Lines | Callers | Fleet apps |
|---|---|---|---|
| `Dto/DeepLinkRegistration.php` | 63 | Cor 1, Int 1, Pla 1, Ker 1 |  |
| `Dto/DeletionAnalysis.php` | 32 | Cor 7, Ker 1 |  |
| `Dto/DeletionEffects.php` | 11 | Cor 3, Ker 1 |  |

#### Kernel other (1 file, 43 lines)

| File | Lines | Callers | Fleet apps |
|---|---|---|---|
| `Kernel/Compat/ImplementationForm.php` | 43 | Int 1, Pro 2, Ker 1 |  |

#### Service (4 files, 35 lines)

| File | Lines | Callers | Fleet apps |
|---|---|---|---|
| `Service/Lifecycle/TransitionMove.php` | 11 | Pro 4, Ker 1 |  |
| `Service/Lifecycle/TransitionActor.php` | 10 | Pro 1, Ker 1 |  |
| `Service/Object/BatchOperationStatusInterface.php` | 7 | Cfg 1, Cor 2, Ker 2 |  |
| `Service/Schemas/PropertyValidatorHandlerInterface.php` | 7 | Cfg 1, Cor 4, Ker 1 |  |

#### Lifecycle (2 files, 29 lines)

| File | Lines | Callers | Fleet apps |
|---|---|---|---|
| `Lifecycle/GuardResult.php` | 23 | Com 1, Pro 1, Ker 2 | 9 |
| `Lifecycle/LifecycleGuardInterface.php` | 6 | Com 1, Pro 2, Ker 1 | 9 |
