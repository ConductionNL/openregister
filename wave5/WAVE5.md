# Wave 5 addendum: fewer exposed lines

`BRIEF.md` and `wave3/WAVE3.md` apply in full. Wave 5 differs in these points.

## 1. The aim

Component independence. On main `3ec81519`, 145,534 of 461,257 code lines in `lib/` (31.6%) are exposed: named by a
file of another functional area. The targets: independence 4.0 at most about 18,900 exposed lines (4.1%), 4.5 at most
about 12,700 (2.75%). Ruben's order: reach 4.0 first, then try 4.5 with allowed moves only, and report honestly where
it does not fit.

A package lowers exposed lines **without breaking the one-direction rule**: no new reference against the area order.
The lander refuses a PR that adds one, whatever it saves.

## 2. Packages, ids, branches

- Package files are `wave5/packages/X00.md` to `X96.md`; `wave5/index.tsv` lists id, round, design reference, expected
  reduction (negative = the package adds exposed lines), file count, key and title.
- Your branch is `cloud/mod-X<nn>-<topic>`.
- Each package implements a build package of one of the ten contract designs on main,
  `openspec/changes/functional-areas-depend-in-one-direction/contracts/<area>.md`. Read the design section the package
  names before you start; the package file is the cut, the design is the reasoning.
- X00 is task 7.2 (Ruben's decision): the published contract's value types move into `lib/Contract`.

## 3. Rounds

A package runs only in a round after every package it depends on has merged. Within one round the packages own
disjoint files, so they can run at the same time. Two exceptions, shared by everyone:

- `scripts/component-map.csv` and the two files generated from it (`sigrid.components.yaml`,
  `tools/sig/deptrac/areas.yaml`). On a merge conflict keep both sides' rows, then rerun
  `python3 scripts/component-patterns.py && python3 tools/sig/deptrac_areas.py` (BRIEF.md "Traps").
- `tools/sig/modularity-baseline.json` and `tools/sig/deptrac/areas.baseline.json`: never commit them; the
  coordinator records them at merge.

Every other file belongs to one package per round. The hot files are deliberately serialized across rounds: one
package per round edits `lib/Service/ContractBindings.php`, `lib/Listener/ListenerRegistrar.php`, `appinfo/routes.php`,
`lib/Controller/ObjectsController.php`, `lib/AppInfo/Application.php`, each area's binding map, the listener pins
(`listener-order.json`, `ListenerRegistrationOrderGoldenTest`) and the architecture binding tests. If you find you must
change a file your package does not list and another package of your round lists it, stop and say so in the PR body;
do not edit it.

## 4. Before you start

1. **Waves 3 and 4 are still landing.** On 2026-11-20, 50 PRs on `cloud/mod-*` branches were open. Round 1 starts when
   the coordinator says so, normally after they are merged or closed. Each package file names the open PRs that touch
   its own files: build on main after they land, never on their branches.
2. **Re-measure first.** A row, binding, port or caller that already landed is done; say which in the PR body.
3. **Reuse a port that exists.** Waves 3 and 4 built many of the lower-area ports the designs ask for, under other
   names. Before you add a port, read section 7 below and `lib/Kernel/Contracts/`. If an existing port covers the
   capability, use it (widen it by a method if a caller needs one); never add a second port for the same capability.
4. Read what earlier waves said about your files (WAVE3.md point 2).

## 5. How to measure, and what goes in the PR body

Before and after, on your branch:

```
python3 tools/sig/rate_component_views.py          # the functional view: independence, entanglement, modularity
python3 tools/sig/modularity_check.py              # exposed lines per area (summary line)
python3 tools/sig/modularity_check.py --check      # no newly exposed file, no new Kernel file, no new reference against the order
tools/sig/deptrac.sh areas --check                 # no new violating class pair
```

`modularity_check.py` prints the total; the per-area numbers are `exposed_loc` in what `measure()` returns
(`python3 -c "import sys; sys.path.insert(0,'tools/sig'); import modularity_check as m; print(m.measure()['exposed_loc'])"`).

The PR body has, besides what BRIEF.md asks:

- **exposed lines before/after per area** (the `exposed_loc` table, before and after, and the difference);
- references against the order before/after (must not rise);
- newly exposed files and new Kernel files, each with the reason it is the deliberate contract of the change (a new
  port, a value on a port's signature); anything else is a failure;
- the package's expected change from its file next to what you measured.

## 6. The allowed moves for exposure

These are the only ways to lower exposure in this wave (ADR-119 Decisions 9 and 13; BRIEF.md "Allowed ways"):

- **A narrow port per capability, in the owner area's `lib/Kernel/Contracts/<Area>/`.** Callers depend on the port.
  The port lists the operations callers use, nothing more. Never an interface that copies a class method for method,
  and never a port that hands out one of the interfaces it replaces. A facade class may implement a port over several
  existing services, delegating without changing order, checks or failure behaviour.
- **The lower area owns the port** when a lower area needs a higher area's work; the higher area implements it and
  binds it in its own map.
- **Entities that cross a boundary become an id or a Kernel value object** where the design says so.
- **A class counts to the area whose work it does** (csv row), only when that is genuinely its work, never to hide a
  reference.
- **Each area binds its own classes in its own map**, registered by its own registrar, at the same point and with the
  same shared instance.
- **Concrete classes keep their names and directories.** No `class_alias`, no file moves out of the Nextcloud
  directory, no class or event other apps use renamed, nothing moved into Kernel to hide it, nothing internal into
  `lib/Contract/` (X00 is the one decided exception), no static service registry.
- **Behaviour stays identical.** Access checks keep their order and position; listener order is pinned; a golden test
  pins behaviour before a change where the package says so.
- **Every changed or added class has unit tests.** Tests keyed by a concrete class in a fake container are retargeted
  to the port.
- **Public signatures towards other apps only widen.**
- Constants on ports are literals equal to today's value, asserted equal by a unit test, never `= ConcreteClass::X`.

## 7. Ports that already exist

Matched by name and methods while the packages were cut; read the interface before you trust the match.

| A design asks for | Already on main (`lib/Kernel/Contracts/...`) |
|---|---|
| Core `OrganisationInterface` (core.md) | `OrganisationInterface` (Kernel) |
| Core `AuditRecorderInterface` methods (core.md, compliance.md P1) | check `Core/ObjectAuditRecorderInterface`, `Core/AuditEntryWriterInterface`, `Core/ObjectTrailReaderInterface`, `Core/AuditRecordInterface` first |
| Core `SearchRecordingInterface` (compliance.md P8) | `Core/SearchTrailRecorderInterface` |
| Core `AuthorizationChangeLogInterface` (compliance.md P9) | `Core/SchemaAccessChangeLogInterface`, `Core/RegisterAccessChangeLogInterface` |
| Core `ProcessingActivityLookupInterface` (compliance.md P7) | `Core/RetainingActivityInterface` |
| Core `ArchivalHoldInterface` (compliance.md P6) | `Core/CascadeRetentionCheckInterface`, `Core/TransferredRecordStatesInterface` |
| Core `ListingNarrowingInterface` (search.md C4) | `Core/SpatialFilterInterface` |
| Core `ObjectNoticesInterface` (notifications.md 3.2) | `Core/ReadNoticeClearingInterface` |
| Core `ObjectFollowStateInterface` (notifications.md 3.2) | `Core/FollowTableInterface` (partly) |
| Core `RetentionSettingsReaderInterface`, `FileSettingsReaderInterface`, `ObjectHitLabelsInterface`, `JobRunRecordingInterface` (configuration.md CF3a) | `Core/RetentionSettingsInterface`, `Core/FileSettingsReaderInterface`, `Core/RegisterSchemaLabelsInterface`, `Core/JobRunRecordingInterface` |
| Core `RegisterBundleImportInterface`, `BundleImportOptions` (configuration.md CF4a) | `Core/RegisterBundlesInterface`, `Core/BundleImport` |
| Core `ApiCallerAudienceInterface` (integrations.md L1), `ApiCallRecordingInterface` (platform.md) | `Core/RouteCallersInterface`, `Core/RouteCallWindow` |
| `DeepLinkResolverInterface::resolveDisplayName` (integrations.md L4) | `Core/AppDeepLinksInterface` |
| `ActionCheckInterface::requireAction` (platform.md PL4) | `Core/ActionRightCheckInterface` |
| Core `ServedLanguageInterface` (platform.md C8) | `Core/RequestLanguagesInterface` (partly) |
| Core `FlowRunScopeInterface` (processes.md 3.4) | `Core/CurrentFlowRunInterface` |
| Core `ObjectQuotaInterface`, `WriteElevationInterface` (processes.md 3.4) | same names exist (`Core/ObjectQuotaRefusal`; `Compliance/TransitionInFlightInterface` for `declaredFor`/`systemAppFor`) |
| Core `SchemaChangeCheckInterface` (processes.md 3.4) | `Core/SchemaRenameWarningsInterface`, `Core/SchemaFormDependentsInterface` |
| Core `VirtualValuesInterface` (processes.md 3.4) | `Core/ExpressionEvaluatorInterface`, `Core/ExpressionDefaultsInterface` |
| Core `PartyRulesInterface::guardPublication` (processes.md 3.4) | `Core/PublicationRefusalInterface` |
| Core sequence service (processes.md R4, configuration.md A3) | `Core/RunningNumbersInterface` |
| Core `SchemaDeclarationRefusalInterface` marker (notifications.md 3.2) | `Core/DeclarationRefusalInterface` |
| Compliance `CaseTransitionsInterface` (processes.md 3.4) | `Compliance/LifecycleTransitionInterface` |
| Compliance `ArchivalSettingsReaderInterface` (configuration.md CF5a, compliance.md R8) | `Compliance/ArchivalSettingsInterface` (done) |
| Compliance `TokenConsumersInterface`, `ConnectionHealthInterface` (integrations.md L5, L6) | `Compliance/AccountConsumerInterface`, `Compliance/ConnectionStatusReporterInterface` |
| Compliance `AutomaticWriteScopeInterface` (processes.md 3.4) | `Compliance/AuditProcessContextInterface` (partly) |
| Integrations `ObjectReferencePreviewInterface` (configuration.md CF6a) | `Integrations/ObjectPreviewInterface` |
| Integrations `FederatedConfigStoreInterface` (configuration.md CF6b) | `Integrations/ShareableConfigCatalogueInterface` |
| Integrations `DeclaredDateEventsInterface` (processes.md 3.4) | `Integrations/ObjectDateEventsSourceInterface` |
| Notifications `ObjectPartiesInterface`, `PartyAddressesInterface` (integrations.md L8, processes.md 3.4) | `Notifications/PartyRecipientsInterface` |
| Notifications `ScheduledMessageDispatchInterface` (integrations.md L8) | `Notifications/ScheduledMessageDeliveryInterface` |
| Notifications `TaskNoticesInterface`, `AutomationLedgerInterface` (processes.md 3.4) | `Notifications/TaskRowSourceInterface`, `Notifications/NotificationRunRecorderInterface` (partly) |
| Notifications timer units literal (processes.md 3.4) | `Notifications/TimerUnits` |
| Search `ReadableFilesInterface` (search.md S5) | `Search/FileScopeInterface` |
| Search `WebhookDeliveryStatsInterface`, `PageWidgetsInterface` (integrations.md L7) | `Search/WebhookLogStatisticsInterface`, `Search/PageWidgetDeclarationInterface` |
| Kernel `TaskSequenceInterface` (processes.md 3.5, kernel.md notes) | `TaskSequenceInterface` (Kernel) |

## 8. Decisions this wave takes where two designs disagree

Each is also in the package it affects. Ruben can overrule any of them before the round that builds it.

1. **Aggregation has one port, Search's.** core.md proposes a Core `AggregationInterface` implemented by
   `AggregationRunner`; search.md moves aggregation to Search (A5) with `AggregationResultsInterface`. The Core port is
   not built; callers core.md gives `AggregationInterface` use Search's port (X84 first).
2. **`AuditRecorderInterface` is built by Compliance's package** (X20), with core.md's four methods plus
   compliance.md's two typed writes, so only one package edits `AuditTrailMapper` per round.
3. **Final lifecycle states: Compliance's name.** processes.md `FinalStatesInterface` and compliance.md
   `LifecycleFinalStatesInterface` are one port; it is built once, as `LifecycleFinalStatesInterface`.
4. **`Db/DecisionMetadataEdit` counts to Files** (files.md A3, the value of F1), not to Core (processes.md 3.2).
5. **Translation: the store counts to Core** (configuration.md A1), which is the reading the open PRs #10929, #10887,
   #10928 and #10934 already carry. platform.md PL5 (Core ports implemented by Configuration, store stays
   Configuration) is not built. X93 finishes what the open PRs leave.
6. **`ArchivalSettingsReaderInterface` needs nothing**: `Kernel/Contracts/Compliance/ArchivalSettingsInterface` is on
   main (configuration.md CF5a and compliance.md R8 both resolved).
7. **Done already, not packaged:** configuration.md CF10 (the export endpoint, #10905) and compliance.md B8 / R12 (the
   e-Depot transfer job, #10915).
8. **Platform's admin-setting attribute (X16):** `ActionRightsController` writes `#[AuthorizedAdminSetting]` through
   the Kernel id `AdminSettingDelegation::OPEN_REGISTER_ADMIN`, as other controllers on main do (platform.md PL1).
   configuration.md section 4 calls the same move "hiding" for two Configuration controllers. Flagged for Ruben; if
   the lander refuses it, the reference stays with that reason.

## 9. Not in a package

- The 4.5 cuts the designs name but do not design: one `AuditEvent` value and scalar token attribution (Compliance,
  about −245), Search's single `VectorQuery` and internal `EmbeddingProbe` (about −34), a Notifications `deliver()`
  port behind the flow messaging (about −190), Processes' registrar folding (about −30), Core value objects folded into
  existing Kernel values (up to about −400), Configuration's `DescriptorConfigIdsInterface` and
  `MigrationPackServiceInterface` narrowing (about −48).
- The Kernel → Core references of the deprecated `Db/Schema` methods (737 Core lines stay exposed): decided to stay
  until a major release removes the methods.
- notifications.md N8 (Kernel contracts to `Kernel/Contracts/Notifications/`): a rename that moves 106 lines from
  Kernel to Notifications and changes the total by nothing.
- processes.md 3.4 lower-area ports with no equivalent on main (`ObjectRenderContributionInterface`,
  `ReadHistoryInterface`, `ObjectSaveCheckInterface`, `LifecycleStatesScopeInterface`, `RunRetentionInterface`,
  `TaskSearchInterface`, `ObjectFlowLinksInterface`, `FlowToolsInterface`): their callers belonged to wave 4 packages
  and the design gives them no package of its own. If a wave 5 measurement shows a Processes file still exposed to a
  lower area for one of these, the coordinator cuts a follow-up package.
- Promotions to `lib/Contract` other than X00 (the designs name candidates in their section 6; ADR-119 Decision 14).

## 10. The rounds and the expected exposed lines

Exposed lines per area after each round, on paper. "On paper" means: each design's modelled saving, rescaled so that
the ten designs together end at their own "after" numbers plus the port and value lines they add to each other
(about 1,300 lines the designs list under "exposure that lands elsewhere"), starting from main `3ec81519`. The
per-package numbers are estimates to compare against, not promises; each PR reports what it measured.

| After | Packages | Kernel | Core | Notifications | Search | Compliance | Files | Integrations | Processes | Configuration | Platform | Total | Share |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| main `3ec81519` |  | 8,506 | 64,287 | 2,049 | 2,035 | 15,375 | 2,665 | 18,152 | 29,038 | 3,134 | 293 | **145,534** | 31.55% |
| the open wave 3/4 PRs (estimate: #10844 Files, #10926/#10931/#10910 Processes) |  | 8,506 | 64,287 | 2,049 | 2,035 | 15,375 | 909 | 18,152 | 27,338 | 3,134 | 293 | **142,078** | 30.80% |
| round 1 | 8 | 8,571 | 35,050 | 1,724 | 1,362 | 14,923 | 909 | 18,152 | 27,338 | 2,787 | 293 | **111,109** | 24.09% |
| round 2 | 9 | 8,571 | 35,050 | 1,724 | 1,194 | 5,685 | 899 | 18,152 | 4,862 | 1,884 | 215 | **78,236** | 16.96% |
| round 3 | 7 | 8,571 | 35,050 | 1,724 | 971 | 5,685 | 756 | 7,482 | 4,862 | 1,884 | 215 | **67,200** | 14.57% |
| round 4 | 12 | 8,571 | 28,274 | 1,724 | 971 | 5,685 | 328 | 6,662 | 4,862 | 1,884 | 215 | **59,176** | 12.83% |
| round 5 | 12 | 8,571 | 21,228 | 1,724 | 971 | 5,685 | 166 | 5,773 | 4,862 | 1,691 | 215 | **50,886** | 11.03% |
| round 6 | 12 | 8,571 | 14,020 | 1,352 | 971 | 5,685 | 166 | 5,477 | 4,862 | 1,691 | 0 | **42,795** | 9.28% |
| round 7 | 12 | 8,571 | 12,769 | 1,352 | 382 | 2,371 | 166 | 4,489 | 2,341 | 1,691 | 0 | **34,132** | 7.40% |
| round 8 | 8 | 8,571 | 12,769 | 1,125 | 340 | 1,453 | 166 | 3,432 | 661 | 803 | 0 | **29,320** | 6.36% |
| round 9 | 5 | 8,571 | 9,501 | 428 | 231 | 1,453 | 166 | 3,432 | 661 | 803 | 0 | **25,246** | 5.47% |
| round 10 | 7 | 8,571 | 6,358 | 428 | 206 | 505 | 166 | 3,274 | 346 | 421 | 0 | **20,275** | 4.40% |
| round 11 | 2 | 8,571 | 6,358 | 428 | 206 | 505 | 166 | 1,545 | 346 | 135 | 0 | **18,260** | 3.96% |
| round 12 | 1 | 8,571 | 4,891 | 428 | 206 | 505 | 87 | 1,545 | 346 | 135 | 0 | **16,714** | 3.62% |
| round 13 | 1 | 5,984 | 4,891 | 428 | 206 | 505 | 87 | 1,545 | 346 | 135 | 0 | **14,127** | 3.06% |
| round 14 | 1 | 5,960 | 4,891 | 428 | 206 | 505 | 87 | 1,545 | 346 | 135 | 0 | **14,103** | 3.06% |

Packages per round:

- round 1: X00 (X00), X01 (C-map), X02 (C-listen), X03 (C-markers), X04 (CF-platform-contracts), X05 (F-pipeline-types), X06 (N-rows), X07 (S-rows)
- round 2: X08 (PC-map), X09 (P-example-sets-c), X10 (Endpoints), X11 (CF-settings-readers), X12 (CF-lower-contracts), X13 (F-ports), X14 (S-core), X15 (S-declaration-checks), X16 (PL)
- round 3: X17 (C-ports), X18 (I-map), X19 (P-seeds), X20 (Comp-audit), X21 (F-processes), X22 (S-core-callers), X23 (S-ports)
- round 4: X24 (C-callers-Compliance-2), X25 (C-callers-Compliance-3), X26 (C-callers-Compliance-4), X27 (C-callers-Compliance-5), X28 (C-callers-Compliance-6), X29 (C-callers-Compliance-7+Files-2), X30 (C-callers-Configuration-1), X31 (C-callers-Files-1), X32 (I-reg), X33 (Comp-archival), X34 (F-core-ports), X35 (F-core-readers)
- round 5: X36 (C-callers-Compliance-1), X37 (C-callers-Configuration-4), X38 (C-callers-Configuration-5), X39 (C-callers-Integrations-1), X40 (C-callers-Integrations-2), X41 (C-callers-Integrations-3), X42 (C-callers-Integrations-4), X43 (C-callers-Processes-1), X44 (C-callers-Processes-2), X45 (I-rows), X46 (Comp-ports-audit), X47 (F-configuration)
- round 6: X48 (C-callers-Configuration-2), X49 (C-callers-Configuration-3), X50 (C-callers-Processes-3), X51 (C-callers-Processes-5), X52 (C-callers-Processes-6), X53 (C-callers-Processes-7), X54 (C-callers-Notifications-1+2), X55 (C-callers-Platform-1), X56 (C-callers-Platform-2+3), X57 (I-lower), X58 (Comp-ports-other), X59 (N-wiring)
- round 7: X60 (C-rows), X61 (I-listen), X62 (P-example-sets-ab), X63 (Comp-history-readers), X64 (Comp-save-path), X65 (Comp-search-purpose-teardown), X66 (Comp-change-log-callers), X67 (Comp-callers-processes), X68 (Comp-callers-configuration), X69 (N-ports), X70 (S-callers-views), X71 (S-callers-vectors)
- round 8: X72 (I-values-checks), X73 (P-rows), X74 (P-console), X75 (Comp-rows), X76 (CF-thin-registrar), X77 (N-messaging-callers), X78 (N-rules-timeline), X79 (S-personal-state)
- round 9: X80 (C-callers-Integrations-5+6), X81 (N-follows), X82 (N-reports), X83 (N-schema-check), X84 (S-aggregation)
- round 10: X85 (C-callers-Processes-4), X86 (C-callers-Search-1), X87 (C-callers-Search-2), X88 (C-callers-Search-3), X89 (I-sources), X90 (Comp-callers-rest), X91 (CF-ports)
- round 11: X92 (I-ports), X93 (CF-translation)
- round 12: X94 (F-store)
- round 13: X95 (K-rows)
- round 14: X96 (K-task-terminal)

**4.0 (at most about 18,900 lines)** is reached on paper after round 11 (18,260). Rounds 1 to 3 do most of it: the
three area-map packages (X01 Core, X08 Processes and Compliance, X18 Integrations) and the Core markers (X03) take
about 69,500 lines out of exposure without changing a caller.

**4.5 (at most about 12,700 lines) is not reached on paper.** After every package the total is 14,103 (3.06%), about
1,418 over. Kernel alone keeps about 5,960 (the three entities, the shared events and refusals, the published
contract and X00's stubs) and Core about 4,900 (its ports, the value objects on their signatures, the Db toolkit,
the job base, and 737 lines the deprecated `Db/Schema` methods keep exposed). The allowed cuts named in section 9
add up to about 950 lines, which leaves about 470 over. The rest needs the deprecated `Db/Schema` methods removed in
a major release (about 737; with it the total would land near 12,400, a narrow fit), which is decided not to
happen in this wave. Nothing in this wave fits 4.5 by a
forbidden move, and no package should try.
