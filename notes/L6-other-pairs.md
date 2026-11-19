# L6-other-pairs: handed over to cloud work (2026-11-19)

The local lane stops here. Round 3 was not started.

## Done (all merged or being merged)

| Round | PR | What it removes |
|---|---|---|
| 1 | #10665, #10666, #10667 | 147 files read `Kernel\Contracts\AppIdentity::APP_ID` instead of `AppInfo\Application::APP_ID`: 147 references. |
| 2 | #10677 | 8 contract and value-object csv rows moved to the area of the class they describe: 18 references. |
| 2 | #10686 | `lib/Service/Deferral/`, `ActorForwardedJob` and `ActorForwardingServices` count to Core: 17 references. |
| 2 | #10687 | Schedule-to-flow compilation counts to Processes, and its binding moves to `FlowWiring`: 12 references, plus 5 newly exposed AppHost schedule values, a trade the coordinator accepted. |

## Remaining list

The full list is in `~/or-lanes/L6-other-pairs/remaining.tsv`, in the same format as the lane list.

It was derived from the original lane list minus what the PRs above removed. It is an estimate: regenerate the exact set from `python3 tools/sig/modularity_check.py` on main after the merges.

About 352 references from 203 files remain:

| Pair | References | Main targets, and the suggested work package |
|---|---|---|
| Integrations → Processes | 48 | See the separate section below. |
| Compliance → Configuration | 32 | `Service/Settings/ArchivalSettingsReaderInterface` (10). Probably a read port owned by Compliance (archival settings are Compliance's question), implemented by Configuration. Also `Db/NewRowStamps`, `Db/HydrateBySettersTrait`, `Service/Export/SpreadsheetCell` and `Service/RecordUpdate`. These generic Db and Service helpers are probably filed too high: check whose work they are (Core?). |
| Notifications → Processes | 31 | `Db/Task` (5), `Service/Interaction/WatcherServiceInterface` (4), `TaskInboxServiceInterface`, `AddressedObject`, `AutomationRecordWriter` and `PartySendRefusalInterface`. Invert with events or ports owned by Notifications. |
| Processes → Configuration | 28 | `Db/TaskSequence` (8), `Service/SequenceScope` (3), `Db/Translation` and `Service/Translation/LanguagePair`. Check whether TaskSequence is Processes' work: it is probably filed under Configuration by mistake. |
| Integrations → Configuration | 26 | `Service/Capability/ToolReachLevels` (4), `Db/Application` (3), `Db/HydrateBySettersTrait`, `Db/Configuration`, `Service/Mcp/McpAgentScope` and `Service/FormsAppRows`. |
| Compliance → Integrations | 24 | `Db/LinkColumn`, `Db/SelectByColumnTrait` and `Db/JsonFieldsTrait`, generic Db helpers that are probably filed too high. Also `Service/Connection/ConnectionReporter` and its interface, and `CalendarLinkMapperInterface`. |
| Processes → Platform | 24 | `Settings/OpenRegisterAdmin`, `Db/TranslationSlot`, `Repair/Support/EveryFlow`, `TranslationProjectionServiceInterface`, `Repair/Support/ApprovalTaskStores` and `RepairPlatform`. |
| Compliance → Processes | 20 | `Service/Lifecycle/AutoTransitionPassInterface`, `TransitionEngine`, `Listener/Support/EventSchemaLookup`, `Db/FlowClaim`, and the BulkJob mapper and service. The background-job Support bundles under `BackgroundJob/Support/` count to Compliance through the folder row, but most are bundles of Processes or Integrations jobs. Give each bundle a row for its job's area. |
| Configuration → Platform | 19 | The translation entities (`TranslationEdit`, `TranslationFilter`, `TranslationSlot`, `TranslationMapperInterface` and `TranslationBackfillScope`), and `Settings/OpenRegisterAdmin`. The translation cluster is split between Platform and Configuration: decide its one area. |
| Notifications → Integrations | 18 | `Mcp/Db/Conversation` (4), `EmailLinkServiceInterface`, `Db/EmailLink` and its mapper interface, and `Service/Connection/ConnectionReportKind`. |
| Smaller pairs | about 80 | Search → Configuration (12), Notifications → Configuration (12), Compliance → Files (11), Search → Compliance (10), Search → Processes (8), Search → Integrations (5), Integrations → Platform (5), and the rest at 4 or fewer each. |

### Integrations → Processes in detail (48)

- `Calendar/DeclaredDateCalendars`: needs a port owned by Integrations (object date events for a principal and scope), implemented by `ObjectCalendarFeedService` (Processes). Today it uses `ObjectCalendarFeedService`, `ObjectCalendarFeedSources`, `ObjectDateDeclaration`, `ObjectDateEvents` and the `CalendarFeedToken` scope constants.
- `Service/CalendarLinkService` and `Service/Integration/Providers/CalendarProviderServices` use `CalendarEventService`.
- `Mcp/BuiltIn/FlowMcpToolProvider` and `FlowToolServices`: the MCP flow tools. Port or event, or check whether these tools are Processes' work.
- `Service/Bindings/*` (`FlowBindings`, `AccessBindings`, `MiscBindings`): Integrations-area files that bind Processes classes. This is wiring for L1, and each binding belongs in its own area's registrar.
- `Db/Flow/FlowJson`, `Service/Integration/Providers/FlowProvider` (`FlowLink` and `FlowJsonColumn`), `Dav/TaskVtodoWriteBackPlugin`, `Service/Observability/OpenRegisterMetricsProvider` (`TaskMetricsProviderInterface`), `BackgroundJob/CalendarRecomputeJobInterface`, `ContextChatSubmissionJob` and `Service/Integration/BuiltinIntegrationServices`.

## Also for the Core-source lanes

About 45 `Controller/Support/*ControllerServices` bags are filed under Core while their controller is in another area: Integrations, Configuration, Compliance, Processes or Platform. L5 did the Configuration ones in #10670.

## Trap

A git worktree with a symlinked `vendor/` autoloads `lib/` from the clone the symlink points to, because Composer resolves its base directory from the real path. Use `cp -al` (hard links) for `vendor` in a worktree.
