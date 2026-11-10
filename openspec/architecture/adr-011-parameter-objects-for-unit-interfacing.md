# ADR-011: Parameter objects for unit interfacing. Value objects for recurring clusters, operation objects for wide entry points, two-parameter constructors for autowired classes

**Status**: accepted (2026-11-09). SIG round r12 implements it in two waves of cloud lanes.

**Date**: 2026-11-09

## Context

SIG rates unit interfacing on the share of unit lines that sit in units with
more than 2, 4 and 6 parameters. The 5-star caps are 12.5%, 2.5% and 0.6%. A
rating of 5.0 needs half of each cap: 6.25%, 1.25% and 0.3%. Our estimate on
main (e39b44f0, measured with the gate-122 tool) is:

| band | unit LOC | share | 5.0 needs |
|---|---|---|---|
| more than 2 parameters | 67,578 of 324,755 | 20.8% | at most 6.25% |
| more than 4 parameters | 6,814 | 2.1% | at most 1.25% |
| more than 6 parameters | 2,701 | 0.83% | at most 0.3% |

The property rates 2.47 today. It is the lowest of the seven SIG properties and
it caps the overall rating.

Rounds 10 and 11 reduced parameters one unit at a time and moved about one
percentage point per round on the first band. Two rules of those rounds held the
pace down. A constructor of 3 or 4 injected services was left alone, and a
value object was not allowed when only one method used it. The band is also
spread thin: 2,720 files carry it, and the 400 widest files hold only 58% of it.

What the band is made of (LOC in units with more than 2 parameters):

| kind of unit | LOC | share of total |
|---|---|---|
| public service methods | 19,338 | 5.95% |
| constructors (2,195 units) | 17,702 | 5.45% |
| private helpers | 17,238 | 5.31% |
| controller route actions | 3,415 | 1.05% |
| mapper methods | 3,966 | 1.22% |
| the rest (static, listeners, jobs, commands, MCP) | 5,919 | 1.82% |

Three parameter clusters recur across the code and are not written down as
types today:

- `register` and `schema` together: 646 units, 10,188 LOC. In 263 of them an
  object `id` travels with the pair.
- `limit`, `offset`, `filters`, `sort`, `search` in some combination: 134
  units, 2,064 LOC.
- the mappers `RegisterMapperInterface`, `SchemaMapperInterface` and
  `MagicMapperInterface` injected together: 122 constructors.

The code already has the pattern this ADR scales up. `lib/Service/Object/`
holds `ObjectSaveRun`, `CascadeRun`, `LockRequest`, `FacetRun` and
`ObjectFindRequest`. `lib/Controller/Support/` holds the `*ControllerServices`
bundles that round 10 introduced for controllers. ADR-003 (hydra) fixes
constructor injection with `private readonly`.

## Decision

**A recurring parameter cluster is a type. A wide operation takes one request
object. An autowired class takes at most two constructor parameters. Public
signatures other apps call keep their shape and delegate.**

### Rule 1. Shared value objects for the three clusters

Three value objects are introduced once, in lane Q01, and used everywhere the
cluster appears:

- `OCA\OpenRegister\Db\RegisterSchema`: a `Register` and a `Schema` entity
  that belong together, with `tableName()`, `registerId()` and `schemaId()`.
  It lives in `lib/Db` because it is the identity of a magic table and the
  mappers consume it.
- `OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef` and
  `OCA\OpenRegister\Kernel\Contracts\ObjectRef`: the same pair, and the pair
  plus an object identifier, as the strings or integers a request carries
  before resolution. They live beside `AppIdentity` because other apps may
  build them. `ObjectRef` has `registerSchema(): RegisterSchemaRef`.
- `OCA\OpenRegister\Db\ListQuery`: `limit`, `offset`, `filters`, `sort` and
  `search` for one listing, with the defaults the controllers apply today.
  A listing without a page size is `ListQuery::unbounded()`; mappers read
  `limitOrNull()`.

A method that takes `Register $register, Schema $schema` takes
`RegisterSchema $table`. A method that takes `string $register, string $schema,
string $id` takes `ObjectRef $ref`. Nothing else changes in that method.

### Rule 2. Component-local value objects and operation objects

Any other recurring cluster is a value object inside the component that owns
it, next to the class that uses it, as `lib/Service/Object/` does today. It is
`final`, its fields are `public readonly`, it has no setters and no behaviour
beyond derivations of its own fields.

A public method with four or more parameters, or with three parameters and a
body of twelve lines or more, takes one operation object instead. It is named
after the operation it describes, `ExportRequest`, `MergeInstruction`,
`TransitionCommand`, never after the method or the class that receives it. It
may be used by one method. This replaces the round 10 rule that forbade a value
object with one user: a request object per operation is a recognised pattern,
and the ban kept 9,000 LOC in the band.

A method whose parameters are mostly optional takes its required parameters
plus one `<Operation>Options` object. An options object is a typed class with
named fields and defaults. An `array $options` bag, a variadic, or a value
object that mirrors the parameter list of one method under a generic name
(`FooArgs`, `BarParams`) is not allowed.

### Rule 3. Run objects for helper chains

When three or more private helpers of a class share the same two or more
parameters, those parameters are the state of one run. They move into a
`<Operation>Run` object that the public entry point builds, and the helpers
take the run plus at most one more parameter. `CascadeRun` in `SaveObject` is
the model. A run object may carry mutable progress fields; it never leaves the
class that built it.

### Rule 4. Constructors

An autowired class (service, handler, listener, job, command, repair step, MCP
tool) takes at most two constructor parameters. A controller takes
`IRequest $request` and one `<Name>ControllerServices` bundle; the app id comes
from `Application::APP_ID`, and `ControllerSession` sits inside the bundle.

Where a class needs more than two collaborators, cohesive collaborators form a
named aggregate: a `final` class with `public readonly` members, autowired by
the container, named after the capability it serves, with at most four members
and at least three consuming classes. `SchemaCatalog` (the register, schema and
magic mappers) is the first and is introduced in Q01. An aggregate is never
named after one consumer, never holds another aggregate deeper than one level,
and never holds a permission or tenancy guard: those stay direct parameters.

A constructor that only stores its parameters uses promoted properties and has
an empty body. A value object or event with three or more fields keeps its
fields as constructor parameters. These constructors stay in the band and are
accepted as the residue of the measure.

### Rule 5. Fixed public signatures keep their shape

The public methods of the top-level classes in `lib/Service/*.php`, the
classes in `lib/Kernel/Contracts` and `lib/Contract`, the events, `IFlowNode`,
`IMcpToolProvider`, and every class another Conduction app names (ObjectService,
FileService, ConfigurationService, OrganisationService, SchemaMapper,
RegisterMapper, AuditTrailMapper, MagicMapper, TransitionEngine,
CredentialBrokerService) do not change their existing signatures. A new method
taking the value object is added next to the old one; the old one builds the
value object and delegates in at most five lines. Controller route actions keep
their route parameters, build the `ObjectRef` or `ListQuery` on the first line
and delegate.

The named guard flags `_rbac` and `_multitenancy` stay named parameters
wherever they appear today. Call-site tooling reads them by name.

### Rule 6. Construction and immutability

- A value object is built with `new` and named arguments, or with a static
  constructor named `from<Source>()` when it is built from a request or an
  array. It never reads a request or a container itself.
- A value object is never a service: it has no injected dependencies and the
  container never builds it.
- Derivations are methods without parameters (`tableName()`, `page()`).
- A value object does not grow a field that only one caller needs. That caller
  passes the field as a parameter.
- Strict-types mode follows the file the value is used in; a new value object
  is strict only when every value it receives is already typed.

## Consequences

- The two wider bands fall below their 5.0 caps with the plan: more than 4
  parameters to about 0.5%, more than 6 to about 0.25%.
- The first band falls to about 12.8% after the first wave of 44 lanes and to
  about 8.8% after a second wave of 39 lanes, with Rules 1 to 3 and 5. Rule 4
  takes it to about 6.5%, which is the first point at which the property reaches
  5.0. Constructors of value objects and events (about 4,600 LOC, 1.5%) are the
  accepted residue. The margin at 5.0 is thin; it depends on near-complete
  execution of both waves.
- Call sites get shorter and typed. `ObjectRef` replaces three loose strings
  that were passed in two different orders (`id, register, schema` in some
  controllers, `register, schema, id` in others).
- Every cluster value object becomes a module other components name, which
  counts against component independence. Three shared classes of about 50 lines
  each are negligible against the 40,865 exposed lines of today.
- Reviewers get a new question to ask: does this value object name a concept,
  or does it mirror one method's parameter list? Rule 2 draws the line.
- Lanes change many call sites. Each lane owns its production files, introduces
  its local value objects beside them, and uses the shared ones from Q01. A
  lane never adds a shared value object to `lib/Db` or `lib/Kernel/Contracts`.

## Alternatives rejected

- **Keep working one unit at a time** (rounds 10 and 11). About one point per
  round on a 14-point gap. Rejected for pace.
- **Setter injection for the logger** (`LoggerAwareInterface`). It would take
  586 constructors down one parameter, but it moves a dependency into mutable
  state and breaks ADR-003. Rejected.
- **Option arrays**. `array $options` hides the parameters without naming them
  and defeats static analysis. Rejected, as in rounds 10 and 11.
- **Compacting value-object constructors to one line**. It would take the first
  band to about 5% but changes only formatting. Rejected as a measurement trick.
- **One generic `Context` object per component.** It would become a bag that
  every method reads a different part of. Rejected; a value object names one
  concept.
- **Leaving constructors of 3 or 4 collaborators alone.** It is honest, and it
  caps the property at about 4.85. Rejected on 2026-11-09: the goal is a
  rating of at least 5.0, and Rule 4 is the step that reaches it.

## Cross-references

- ADR-003 (hydra): constructor injection with `private readonly`.
- ADR-009: performance invariants; a value object built once per request fits
  the hoisting rule.
- `lib/Service/Object/ObjectSaveRun.php`, `CascadeRun`, `LockRequest`: the
  existing per-call objects this ADR generalises.
- `lib/Controller/Support/*ControllerServices.php`: the controller bundles of
  round 10 that Rule 4 completes.
