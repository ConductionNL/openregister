# ADR-013: Component dependency rules and the gate that enforces them

**Status**: accepted (2026-11-09)

**Date**: 2026-11-09

## Context

Sigrid rates component entanglement from the communication lines between components: a line is a pair of
components with at least one class reference between them, weighted by the number of references. A line is a
violation when the reverse line exists (cyclic), when it is the weakest edge of a longer cycle (indirect
cyclic), or when a lighter direct line duplicates a path that exists anyway (transitive). The violation degree
is the violated weight over the total weight.

Measured on main `e39b44f0`: 195 lines between 37 components, total weight 7,071, violated weight 423, degree
0.0598 (rating 3.91). 5.0 needs 0.01875. The 68 violated pairs, with the modules behind them:

| pattern | weight | example |
|---|---|---|
| mappers call services | 140 | `Db/MagicMapper/MagicRbacHandler` uses 16 classes in `Service/Rbac`; `Db/SchemaMapper/*Check` classes validate schema declarations with `Service/Schemas` validators |
| mappers dispatch events | 28 | `Db/SchemaMapper` dispatches `Event/SchemaCreatedEvent` |
| code names the bootstrap class | 18 files | `Files/Controller/*` and `Notifications/Controller/*` read `AppInfo\Application::APP_ID` |
| events name services and entities of other components | 21 | `Event/ToolRegistrationEvent` names `Mcp\Tool\ToolInterface`; `Event/ViewUpdatedEvent` names `Search\Db\View` |
| services call outward layers | 57 | `Service/*` uses `BackgroundJob/JobHandles`, `Search/Db/ViewMapperInterface`, `Listener/*Interface`, `AppHost/Service/GenericActionAuthService`, `Twig/MappingTwigInstaller`, `Repair/*Interface` |
| functional components use controller support from `lib/Controller` | 11 | `Files/Controller/FileTextController` uses `Controller/Support/ControllerSession` |
| shared leaves depend inward | 8 | `Kernel/Contracts/ObjectOperationsInterface` names `Db\ObjectEntity`; `Exception/LockedException` names `Db\ObjectEntity`; `Dto/BulkSaveOutcome` names a `Service` interface |
| functional components reference each other's internals | 50 | `Files` -> `Search` vectorisation interfaces, `Notifications/Db/Message` names `Mcp\Db\Conversation` |

Hydra ADR-003 already states "controller, service, mapper; controllers never call mappers directly; mappers
do CRUD only; events via dispatcher". None of it is checked mechanically in this repository. The only
architecture tests are `tests/Unit/Architecture/ContractIsALeafTest.php` (the Kernel and Contract directories
name only Kernel types) and about 170 interface-binding tests from earlier rounds.

Other apps write through `SchemaMapper->update()`, `RegisterMapper->createFromArray()` and three more
mappers in 31 files, so an event that a mapper dispatches today must keep firing when the mapper is called
from outside OpenRegister.

## Decision

### Rule 1: the direction of dependencies

Components are those of ADR-012. The allowed references between them form a star:

- A functional component (Core, Processes, Configuration, Compliance, Integrations, Files, Search,
  Notifications, Platform) may reference `lib/Kernel`, `lib/Contract`, `lib/Event`, `lib/Exception`,
  `lib/Support`, `lib/Db` and OCP. It references no other functional component.
- `lib/Kernel` and `lib/Contract` reference each other, `lib/Db`'s three entities through their Kernel entity
  interfaces, and OCP. They reference no functional component, no event, no exception.
- `lib/Event` references `lib/Kernel`, `lib/Contract`, `lib/Db` and OCP. An event carries an id, a Kernel DTO
  or one of the three core entities, never a class of a functional component.
- `lib/Exception` references `lib/Kernel`, `lib/Contract` and OCP.
- `lib/Support` references OCP only.
- `lib/Db` (the three core entities and two mappers) references `lib/Kernel`, `lib/Contract`, `lib/Exception`,
  `lib/Support` and OCP. It dispatches entity events through `Kernel\Contracts\EntityEventsInterface`, whose
  implementation lives in Core and names the `lib/Event` classes; the mapper itself names no event.
- `lib/AppInfo` references one registrar per component and `Kernel\Contracts\AppIdentity`. Nothing references
  `lib/AppInfo`; the app id is read from `AppIdentity::APP_ID`.

Until the moves of ADR-012 are done, the same rule applies to the layer directories with these readings:
`lib/Db` as a whole is a persistence layer that references nothing in `lib/Service`, `lib/Event`,
`lib/Listener`, `lib/BackgroundJob` or a functional component; `lib/Service` references no outward layer
(`lib/Controller`, `lib/Listener`, `lib/BackgroundJob`, `lib/Repair`, `lib/Command`, `lib/Middleware`) and no
functional component's internals; `lib/Controller`, `lib/Listener`, `lib/BackgroundJob`, `lib/Repair`,
`lib/Command`, `lib/Middleware` are outward layers that nothing references.

### Rule 2: within a component, controller to service to mapper

Inside a component the three layers of hydra ADR-003 hold: a controller calls services and never a mapper; a
service calls mappers; a mapper reads and writes rows and applies query policy, and holds no business rule.
Schema declaration validation is a service concern (`Core/Service/Schemas/Declaration/*`), not a mapper
concern; the mapper calls the validator through a Kernel interface. RBAC row matching (`RuleColumns`,
`RbacChecks`, `ConditionMatcher`, `ReadRuleTenancy`, `DraftStatusPolicy`) is query policy and lives next to
the Magic mapper.

### Rule 3: where a contract lives

- An interface, DTO or value type that one component offers another is in `lib/Kernel/Contracts`. It carries
  no implementation and names only Kernel types, `lib/Db` core entities, events and exceptions.
- Request-scoped context objects shared by several components (`WriteCause`, `WritePhase`,
  `OrganisationContext`, `SystemOperationContext`, `AnonymousEvaluationContext`) are value types in
  `lib/Kernel/Context`.
- `lib/Contract` holds only what other apps consume and gate 67 checks (ADR-084). An internal contract never
  goes there.
- Base classes shared by several components (`RecordedQueuedJob`, `ActorForwardedJob`, `ControllerSession`,
  `HandlesExceptionsTrait`, `RequiresJsonBodyTrait`) are in `lib/Support/Jobs` and `lib/Support/Http`.
- Each component binds its implementations to the Kernel interfaces in its own `<Component>Registrar`;
  `Application.php` calls the registrars and binds nothing itself.

### Rule 4: events

Events are published by the component that owns the entity or process and are dispatched from its service,
or from `lib/Db` through `EntityEventsInterface`. A listener lives in the component that reacts. The
listener registration order for the 21 events with listeners from several components stays in the one
ordered list (`Listener/Registrar/*`, coordinator decision of 1 Nov); the list names listener classes by
string, which the dependency reader counts, so the list is in `lib/AppInfo` where references out are allowed.

### Rule 5: the gate

`tests/Unit/Architecture/ComponentDependencyRulesTest.php` builds the module dependency graph of `lib/` the
way `check_sig_maintainability.py` does (use imports, fully qualified names, same-namespace names; comments
stripped) and asserts:

1. no reference violates Rule 1 (listed exceptions shrink per PR, as `ContractIsALeafTest::KERNEL_NAMING_DB`
   does today);
2. `lib/Event` and `lib/Exception` name only Kernel, Contract and `lib/Db` core types;
3. nothing outside `lib/AppInfo` names `OCA\OpenRegister\AppInfo\`;
4. the exposed LOC total (ADR-012 Rule 3) is at or below the budget recorded in
   `tests/Unit/Architecture/surface-budget.json`, and the violation degree computed with the tool's
   `violation_degree` algorithm is at or below the recorded ceiling; a PR may lower either number and must
   update the file when it does.

`scripts/component-deps.py` prints the same graph, the exposed modules per component and the violated pairs,
for a developer before a push. The hydra gate runner calls the test in the mandatory block.

## Consequences

Positive:

- The 68 violated pairs are removed by changes that are each a layering fix a reviewer recognises:
  mappers stop calling services and dispatching events, events carry ids and Kernel types, the bootstrap
  class is referenced by nothing, shared helpers live in one leaf. Simulated on today's graph: waves A to D of
  the analysis give 0.0141, wave E 0.003.
- After the moves of ADR-012 the graph is a star and the degree is bounded by leaf back-edges, which the test
  forbids. New cycles cannot land: the test fails the PR that adds one.
- The surface budget and the degree ceiling make the two SIG numbers visible on every PR, in the tool's own
  arithmetic, so the fleet learns the cost of a new cross-component call when it is written.

Negative and costs:

- About 60 interfaces move from `lib/Service`, `lib/Search`, `lib/Files`, `lib/Notifications`, `lib/Listener`,
  `lib/BackgroundJob`, `lib/AppHost`, `lib/Repair` and `lib/Twig` into `lib/Kernel/Contracts`: their
  implementers and callers change `use` lines. Ten of them are referenced by other apps and get aliases.
- Events that carry `Mcp\Db\Agent`, `Mcp\Db\Conversation` and `Search\Db\View` change to carry ids or Kernel
  interfaces; their listeners change with them, in the same PR.
- The `EntityEventsInterface` indirection in the mappers is one more hop in a hot path (object save). It is a
  method call on an injected service; no I/O.
- The test is regex-based like the estimate tool, and Sigrid's parser may count a few edges differently. The
  test is the floor; Sigrid is the arbiter.

## Alternatives considered

- **Mappers stop dispatching events; services dispatch after the mapper call.** Rejected: 31 files in other
  apps write through the mappers directly, and their events would stop firing without an error.
- **Deptrac or PHPArkitect as the gate.** Workable, but a second dependency reader with its own rules. Rejected
  for now: the estimate tool's reader is the one whose numbers we track, and a test using the same reading
  cannot disagree with the report. Deptrac can be added later for the richer rule syntax.
- **Allow functional components to reference each other's interfaces where they live.** Rejected: a
  component-to-component line, even to an interface, is a communication line and a potential cycle; the star
  through the Kernel has none.
- **Keep the registrar bindings in `Application.php`.** Rejected: measured on 29 Oct in integriq, registrars in
  `AppInfo` expose every bound implementation to `AppInfo` and drop independence by half.
- **Split the ordered listener list per component.** Ruled out on 1 Nov: 21 events have listeners from several
  components whose order matters.

## Source

The r12 design analysis of the SIG programme. Violation algorithm:
`check_sig_maintainability.py::violation_degree`.

## Related

- ADR-012: the component list and the published surface.
- Hydra ADR-003 (layering), ADR-041 (cross-app commands via events), ADR-084 (contract parity).
- `tests/Unit/Architecture/ContractIsALeafTest.php`: the existing leaf test this one extends.
