# L2-kernel (Kernel references nothing above it): remaining work for the cloud packages

Written 2026-11-19 at the end of the local lanes. Area order: Kernel < Core < Notifications < Search < Compliance < Files < Integrations < Processes < Configuration < Platform (`tools/sig/area-order.json`).

## State
- Merged: #10652, #10653, #10654 (round 1) and #10664, #10668, #10669 (round 2).
- Being merged by the coordinator: #10689, #10678, #10682 (round 3; AnnotationVocabulary in Kernel was accepted).
- On main 960fddd70f before round 3 landed, Kernel had 61 upward references (`~/or-lanes/L2-kernel/remaining-r3.txt`). After round 3 about 37 remain; recompute with the snippet at the end.
- Local clone `~/or-lanes/L2-kernel/openregister` is parked detached on origin/main. It can be deleted.

## Decisions already taken (apply them, do not re-ask)
1. A helper moves to Kernel by csv row only when its only users are Kernel classes and it holds no Core logic (no wholesale moves). Exception granted: AnnotationVocabulary.
2. **No static port registry or service locator for entities.** An entity method that constructs a Core class gets deprecated (ADR-084 deprecation path). Every openregister caller moves to a Core service, and the method keeps working until the fleet no longer calls it. Removal happens later, as a separate step.
3. An event keeps its class name and namespace. Its payload may change to a Kernel read contract (`lib/Kernel/Contracts/<X>Interface`: JsonSerializable plus `@method` tags, and real methods only where listeners need them) when the readers stay compatible. Check readers with a local grep of `~/nextcloud-docker-dev/workspace/server/apps-extra/*/lib` AND GitHub code search. GitHub search missed dossiq once and rate-limits at about 10 queries a minute.
4. Registration events may name contracts owned by the lower area.
5. Constants: when Kernel only needs a value, Kernel owns the constant and the higher class defines its constant as the Kernel one. Precedents: `SoftDeletion::DEFAULT_RETENTION_DAYS`, `Schema::*_ANNOTATION`.

## Work package A: Schema entity methods that construct Core classes (decision 2)
Each entry: the method, the Core class it constructs, and its callers at 960fddd70f. The fleet column comes from a local grep and needs a GitHub check.

| Schema method | Core class | openregister callers | fleet callers |
|---|---|---|---|
| `validateAuthorization()` | `Db/Schema/AuthorizationRulesValidator` | 0 outside Schema | none |
| `hasPermission(GrantQuery)` | `Db/Schema/SchemaGrantEvaluator` | many (grep is noisy: other classes have a `hasPermission`) | possibly opencatalogi, integriq, pipelinq, tables (verify, same noise) |
| `hasPropertyAuthorization()` | `Db/Schema/PropertyAuthorization` | 14 | none |
| `getPropertyAuthorization(name)` | same | 3 | none |
| `getPropertiesWithAuthorization()` | same | 6 | humaniq (hrmq) |
| `declaredActionNames()` | `Db/Schema/DeclaredActions` | 5 | none |
| `getUpsertKeys()` | `Service/Schemas/UniqueConstraintEvaluator` | 1 | none |
| `getLinkRoles()`, `getPartyKinds()` | `Db/Schema/VocabularyEntries` (also used by Core `ConfigurationEntryRules`) | 2 each | none |

**Special case:** `setConfiguration()` runs `ConfigurationValidator` (through `validatedConfiguration()` and `validatedArray()`) on every set. A setter cannot be deprecated. Options are:
- move the validation to the write path in Core (SchemaMapper or a save service), so the setter only stores; or
- keep it, and record it as the one accepted Kernel to Core reference.

This needs care because `hydrate()` and imports go through `setConfiguration()`. Characterise the behaviour with a golden test first.

Per method: add a Core service method (for example `SchemaAuthorization::allows(Schema, GrantQuery)`). Switch the openregister callers to it and add `@deprecated` with ADR-084 wording on the entity method. Then open issues or PRs in the fleet apps that call it. The reference only disappears when the method is removed.

## Work package B: ObjectEntity
- `ObjectEntity -> Db/EmptyJsonHydration` (Core). EmptyJsonHydration's docblocks name `Source` (Integrations), so moving it alone adds a Kernel to Integrations deptrac pair. It needs a small Kernel JSON-fields contract (`getJsonFields()`), implemented through `JsonFieldsTrait` by ObjectEntity and Source. Then EmptyJsonHydration (and `HydrationKey`, pure data) can move.

## Work package C: events with no outside reader (decision 3)
- `CaseItemTransitionedEvent -> Db/CaseItem` (Processes). The only reader is `Listener/CaseItemTimerListener`. It also uses `CaseItem::TERMINAL_STATES`; give that constant a Kernel owner (decision 5).
- `AppWebhookEvents -> Listener/WebhookEventListener` (Processes). It registers the listener class by name; move the registration to the Processes registrar (wiring) or pass the class name as a string.
- `TaskSequenceUndecidedEvent` has no outside reader, but it shares `AbstractTaskSequenceEvent` with `TaskSequenceCompletedEvent`, which buildiq and filinq read. Do it together with package D.

## Work package D: events other apps read (check compatibility per reader)
- Task events: `TaskTerminalEvent`, `TaskTransitionedEvent`, `TaskSequenceCompletedEvent` (→ `Db/Task`, `Db/TaskSequence`, `AbstractTaskEvent`, `AbstractTaskSequenceEvent`). Readers: buildiq, filinq, hermiq, decidiq, integriq, dossiq. A `TaskInterface` already exists in Kernel/Contracts: check whether the events can carry it.
- `FlowTimerFiredEvent` (→ `Db/FlowTimer`, `Flow/Timer/EscalationNotice`, `TimerFiring`). Read by dossiq. `FlowTimerInterface` exists in Kernel/Contracts.
- `DecisionChangeRequestedEvent`, `DecisionVisibilityRequestedEvent` (→ `Db/Decision`). Read by dossiq.
- `OrganisationCreated/Updated/DeletedEvent` (→ `AbstractOrganisationEvent`, `Db/Organisation`, Core). Readers: stackiq (Created), dossiq (Updated).
- `EntityRelationDecisionUpdatedEvent` (→ `Db/EntityRelation`, Core). Read by filinq.
- `ObjectTransitionedEvent` (→ `Lifecycle/TransitionActor`, `TransitionMove`, Processes value objects). Read by buildiq, learniq, pipelinq, shillinq, hermiq. If the value objects are pure, give them Kernel rows (as was done for HandoffRequest).
- `FormDestinationDependentsEvent` (→ `Form/FormDependent`). Read by buildiq, portaliq, dossiq.

## Work package E: registration events (decision 4)
- `ToolRegistrationEvent` (→ `Mcp/Service/ToolRegistryInterface`, `Mcp/Tool/ToolInterface`, Integrations). Read by hydra and opencatalogi.
- `RegisterLeafProvidersEvent` (→ `Integration/IntegrationProvider`, `LeafDescriptor`, Integrations). Read by about 9 apps.
- `BulkActionRegistrationEvent` (→ `BulkAction/BulkActionInterface` Processes, `BulkActionRegistryInterface` Core). Read by dossiq.
- `DeepLinkRegistrationEvent` (→ `DeepLinkRegistryServiceInterface`, Core). Read by many apps.
- `ObjectEventSubscription` (→ `Listener/ListenerClassesInterface`, Core, which names a Core listener). Read by many apps.

For contracts that apps implement or type against, moving them changes their namespace, and that is forbidden. Use csv-in-place where the interface is the event's own port, or add a Kernel parent interface the existing one extends (widening).

## Traps learned
- `composer check:modularity` stops at the modularity step when a PR adds Kernel files on purpose. Run `tools/sig/deptrac.sh areas --check` separately.
- The generated `sigrid.components.yaml` and `tools/sig/deptrac/areas.yaml` conflict between csv PRs. Resolve with `python3 scripts/component-patterns.py && python3 tools/sig/deptrac_areas.py`.
- The modularity tool only sees git-tracked files: `git add` a new file before measuring.
- The deps reader misses same-namespace uses without a `use` statement. deptrac catches them (EmptyJsonHydration → Source).
- `WebhookEventListenerPayloadGoldenTest` records each getter's declared return type. Changing an event's return type to a contract changes its fixture names (regenerate with `UPDATE_GOLDEN=1`, check that only the names change).
- On a loaded box `tests/Unit/Architecture` takes 40 to 60 minutes. Inherited failures: 5 or 6 in `DbalConnectionFactoryBehaviourTest`, `MappingSlugFallbacksTest::testAMissingTransliteratorKeepsTheName`.

## Recompute the remaining list
```
cd <clone> && python3 - <<'PY'
import sys,os; sys.path.insert(0,'tools/sig'); import modularity_check as m, check_sig_maintainability as t
n=m.measure(); os.environ["SIG_COMPONENTS_YAML"]=os.path.abspath('sigrid.components.yaml')
f={r:l for r,l in t.production_files('.',t.Scope('.')).items() if l=='php'}; d=t.php_dependencies('.',f); _,c=t.component_of_factory(sorted(d),'.')
[print(x) for x in n['against_order'] if c(x.split(' -> ')[0])=='Kernel']
PY
```
