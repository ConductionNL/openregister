# flow-builder

## ADDED Requirements

### Requirement: Visual flow authoring on a schema

The system SHALL provide a visual builder that authors a schema's
`x-openregister-flows` as a node graph and persists it, unchanged in shape, through
the existing `GET`/`PATCH /apps/openregister/api/schemas/{id}` contract (key
`x-openregister-flows`, an array of `{ name, trigger, actions[] }`). The builder and
the existing form editor SHALL be interchangeable: either MAY edit the same flows,
and each SHALL round-trip fields it does not understand.

#### Scenario: Author a flow on the canvas
- **WHEN** an editor opens "Edit flows…" for a page whose config names a register/schema
- **THEN** each existing flow renders as a trigger node connected to its action nodes in order, and adding/removing/reconnecting nodes and saving writes the equivalent `x-openregister-flows` array back to the schema configuration.

#### Scenario: Form and canvas edit the same data
- **WHEN** a flow authored in the canvas is opened in the form editor (or vice versa)
- **THEN** its name, trigger, and actions are identical, and any action `type` unknown to one editor is preserved verbatim on save.

### Requirement: Graph ⇄ flow serialization

The builder SHALL map a graph to a flow deterministically: exactly one trigger node
per flow; the ordered path of action nodes reachable from the trigger becomes
`actions[]`; node canvas positions SHALL be persisted so the layout is restored on
reload without altering execution semantics.

#### Scenario: Layout survives a reload
- **WHEN** a saved flow is reopened
- **THEN** nodes appear at their saved positions and the trigger→action order matches `actions[]`.

### Requirement: Event catalog triggers

A flow's `trigger` SHALL be selectable from a declarative event catalog rather than a
fixed created/updated/deleted list. The catalog SHALL expose object events
(`object.created`, `object.updated`, `object.deleted`, …) and, additively, other
Nextcloud events (e.g. `file.created`, `share.created`) each with a stable id, a
human label, and a resolver that maps the event payload to the object the flow runs
against. Bare `created`/`updated`/`deleted` triggers SHALL remain valid and continue
to fire, so existing flows are unaffected.

#### Scenario: A non-CRUD event fires a flow
- **WHEN** a catalog event a flow subscribes to is dispatched and its payload resolves to an object of the flow's schema
- **THEN** `FlowActionService::run()` executes the flow with that object and trigger.

#### Scenario: Legacy object-CRUD trigger still fires
- **WHEN** a flow whose trigger is the bare string `updated` exists on a schema and an object of that schema is updated
- **THEN** the flow runs, with no catalog id required.

### Requirement: Object-CRUD action nodes

The flow engine SHALL support action types that act on the object graph —
`object.create`, `object.update`, `object.delete`, `object.set-field` — and a
`condition` guard that stops the flow when its expression is false. These SHALL be
added to `FlowActionService::runAction()` and SHALL be guarded against unbounded
recursion (a flow acting on objects that re-trigger the same flow).

#### Scenario: A flow updates a related object
- **WHEN** a flow with an `object.update` action runs
- **THEN** the named target object is updated with the mapped fields, and re-entrant execution caused by that write does not loop indefinitely.

#### Scenario: A condition halts the flow
- **WHEN** a `condition` action's expression evaluates false
- **THEN** no subsequent actions in that flow execute.
