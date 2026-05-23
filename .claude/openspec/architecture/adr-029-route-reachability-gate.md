# ADR-029: Mechanical gate for controller-route reachability

**Status:** proposed

## Context

ADR-008 already requires every new API endpoint to ship with a Newman/Postman
collection plus a `curl` smoke check before PR. That guidance is advisory — no
mechanical gate enforces it. The 2026-05-01 audit on `openregister`
(`docs/development-notes/AUDIT_2026-05-01.md`) caught the consequences:

**Three production-bug classes hidden behind passing unit tests.**

1. **Route-gap.** A controller method returns `JSONResponse`/`StreamResponse`,
   has unit-test coverage, and the spec's `tasks.md` marks `[x] Register
   route` — but the route is missing from `appinfo/routes.php`. Endpoint
   returns 404 from the router. The audit found 41 such cases on `openregister`
   in a single day:
   - 13 in `profile-actions` (already archived as shipped)
   - 14 in `nextcloud-entity-relations`
   - 16 in `workflow-operations`
   - 10 in `file-actions`
   - others scattered across `tags`, `notes`, `tmlo`, `linked-entity-types`,
     `fileSidebar`.
2. **Wrong controller binding.** A route IS registered, but the controller
   class named in the route entry doesn't expose the method — typically because
   the method moved to a sibling controller during a namespace refactor. Calling
   the URL throws `ReflectionException` and 500s. Caught 4 instances in PR
   `#1402` (`Settings\SolrManagement#getObjectCollectionFields` and friends —
   the methods actually live on `Settings\ConfigurationSettings` /
   `Settings\FileSettings`).
3. **Per-instance state not persisted.** A handler stores cross-request state
   in `private array $foo = []`. Per-request unit tests are green; real
   PHP-FPM workers see an empty map after each request boundary. Caught on
   `FileLockHandler` (`openregister` commit `22c5625ef` — moved to ICache).

All three pass unit-test green because the unit-test fixture instantiates the
controller/handler in-process — never crossing the HTTP boundary or a worker
restart. The defects only surface when a real HTTP request reaches the router
or when a second request lands on a fresh handler instance.

## Decision

A new mechanical gate, `hydra-gate-route-reachability` (gate-12), runs in the
builder pre-flight and reviewer pre/post-flight positions. It enforces three
invariants on the PR diff:

### Invariant 1 — Every response-returning controller method is routed

For each PHP file under `lib/Controller/**Controller.php` in the diff, scan
public methods whose return type contains `JSONResponse`, `StreamResponse`,
`DataDownloadResponse`, `DataResponse`, `Response`, `RedirectResponse`, or
`TemplateResponse`. For each match, derive the expected route name as
`{snake_case_controller}#{methodName}` (e.g. `WorkflowExecutionController::index`
→ `workflow-executions#index`) and require a corresponding entry in
`appinfo/routes.php`.

Resource auto-routes (`registers`, `schemas`, `sources`, `configurations`,
`applications`, `agents`, `endpoints`, `mappings`, `consumers`) are excluded —
those auto-generate `index/show/create/update/destroy`. Methods named `helper*`,
`assert*`, `validate*`, or marked `@internal` are also excluded.

**Failure surface:** "Method `X::y` returns Response but no route in
`appinfo/routes.php` names `x#y`. Either register the route or drop the method."

### Invariant 2 — Every route binds to a method that exists

For each `['name' => 'foo#bar', ...]` entry in `appinfo/routes.php` in the diff,
load the resolved controller class (`FooController` for `foo#bar`,
`Settings\Foo` for `Settings\Foo#bar`) and assert it exposes a public method
named `bar`. This catches namespace-refactor leftovers like the four
`Settings\SolrManagement#getObjectCollectionFields` routes that pointed at
methods now living on `Settings\ConfigurationSettings`.

**Failure surface:** "Route `foo#bar` resolves to `FooController::bar` but
that method doesn't exist on the class. Probable cause: the method moved
during a namespace refactor."

### Invariant 3 — Newman case present for new routes

For each new route added in the PR diff, require a Postman/Newman entry under
`tests/integration/` whose `request.url` matches the route's URL pattern.
Soft-fail (warning, not block) on first introduction; hard-fail after a 30-day
grace period to give legacy debt time to be migrated.

**Failure surface:** "Route `foo#bar` added but no Newman case under
`tests/integration/` exercises `<URL pattern>`. ADR-008 mandates per-endpoint
integration coverage."

### Out of scope (Invariant 0)

Per-instance state vs distributed cache (the FileLockHandler class) is NOT
covered by this gate — detecting "this private array should have been an
ICache" needs semantic understanding the gate cannot mechanically derive.
That class of bug is owned by the security/code reviewer's runtime-semantics
checks and by ADR-005's per-attribute pairing.

## Consequences

### Positive

- The 41 `openregister` route-gap cases caught by the audit could not have
  shipped under this gate.
- The 4 wrong-controller-binding ReflectionException cases cannot ship — gate
  reflects the route's target class and asserts the method exists at lint time.
- The advisory guidance in ADR-008 ("every endpoint → Newman case") gets a
  mechanical backstop.

### Negative

- Inherited debt at the moment of gate landing: any pre-existing unrouted
  Response method or wrong-binding entry will block PRs touching unrelated
  files in the same controller. Mitigation: **gate scopes to the PR diff per
  ADR-020** (only files added/modified between the diff's BASE_REF and HEAD).
  Inherited debt is closed by a one-shot full-repo cleanup PR, not enforced
  on every PR.

- The Newman invariant (#3) requires a working Newman environment in CI. Apps
  that don't yet run Newman against their stack get a 30-day grace window
  before the warning becomes a hard fail; the cleanup is a `tests/integration/`
  scaffold, not a per-PR ask.

### Migration

1. Implement `hydra-gate-route-reachability.sh` under `scripts/`. Honour
   `--scope-to-diff [BASE_REF]` per ADR-020.
2. Wire into `images/builder/entrypoint.sh` post-build, `images/reviewer/`
   pre-flight, `images/security/` pre-flight.
3. Run a one-shot full-repo audit on `openregister`, `decidesk`, `procest`,
   `pipelinq`, `opencatalogi`, `larpingapp`, `mydash` to catalog inherited
   route-gap and wrong-binding debt. Each app gets a single cleanup PR.
4. After 30 days from gate landing, flip the Newman warning to a hard fail.

### Cross-references

- **ADR-008** — testing harness (Newman + curl smoke). This ADR makes the
  mechanical enforcement of the Newman bullet.
- **ADR-016** — `appinfo/routes.php` is the only registration path. This ADR
  enforces that every method that needs routing IS routed there.
- **ADR-020** — gate scope follows the PR diff. Required for this gate not
  to bounce on inherited debt.
- **ADR-021** — reviewer bounded-fix scope by shape. A gate finding scoped
  to the PR diff is in shape; a finding in unchanged files is not.

## Alternatives considered

1. **Strengthen ADR-008 wording without a new gate.**
   Rejected. The wording was already there. The audit caught what advisory
   wording cannot prevent: the human writing the controller method genuinely
   forgot to register the route, or pasted the wrong controller name. Only a
   mechanical lint catches both.

2. **Run Newman against a live NC instance in CI as the gate.**
   Rejected as a first step — too slow per PR, and conflates "endpoint is
   reachable" (this ADR) with "endpoint is correct" (separate concern). The
   gate stays static-analysis only; the live-env integration suite stays in
   the existing `composer check:strict` flow.

3. **Auto-generate routes from controller annotations.**
   Rejected per ADR-016: "Every route entry names `controller#method`
   explicitly — no wildcard auto-discovery, no regex generators." Auto-gen
   solves the route-gap problem but loses the explicit single-file URL
   surface that ADR-016 mandates for grep-ability and the auth gate.
