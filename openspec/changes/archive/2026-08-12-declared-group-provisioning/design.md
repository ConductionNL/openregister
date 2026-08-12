# Design — declared-group provisioning

## Where the declaration lives

OAS already has the slot: `components.securitySchemes.oauth2.flows.authorizationCode.scopes`,
a `{groupId: description}` map. `OasService` emits it for Swagger consumers.
Rather than invent a `groups[]` key, this change makes that existing slot
readable and adds it to the configuration document.

Three options were weighed:

| | Shape | Rejected because |
|---|---|---|
| Derive only | Walk authorization blocks; no new syntax | No way to declare a group before any block references it, and no place for a description |
| Authored only | Hand-write the scope map; importer provisions exactly it | The authored map can silently drift from the blocks that actually enforce access |
| **Union (chosen)** | Derived floor ∪ authored superset | Derived guarantees every ENFORCED group exists; authored allows ahead-of-use declaration + descriptions |

## Why the collector is array-based

The obvious move was to extract `OasService::extractSchemaGroups()` into a shared
service. That method takes a `Schema` ENTITY and depends on
`resolveEffectiveAuthorization()` → `expandRolesForOas()` → `RegisterMapper` for
the register cascade and named-role expansion. Moving that machinery would risk
changing generated OAS output for no benefit to provisioning.

Instead `RbacGroupCollector` operates on ARRAYS, which serves both call sites
unchanged: the importer holds raw decoded JSON, and `Schema::getAuthorization()` /
`getProperties()` already return arrays. `OasService` keeps its own per-action
bucketing (OAS needs it) and delegates only rule PARSING to the collector, so the
advertised scope set and the provisioned set cannot diverge on rule grammar.

## The two shapes inside an `authorization` block

Parsed separately, mirroring `expandRolesForOas()`:

- **action keys** (`create`/`read`/`update`/`delete`/`manage`) hold a LIST of
  rules, each a bare group id or `{group: <id>, match: {...}}`;
- **`roles`** holds a MAP of role-name → group(s), where the KEYS are role names
  defined in the register's `configuration.roles[]` and only the VALUES are group
  ids.

Two failure modes this guards, both of which CREATE state and are therefore
expensive to retract:

- A rule's `match` conditions are **deliberately not walked.** Their values are
  field names and literals (`{status: "open"}`), and recursing would manufacture
  Nextcloud groups called `status` and `open` out of ordinary data.
- Treating `roles` as an action list would silently drop list-valued assignments
  (`(array) $groups` in the OAS expander) and, if keys were read, would create
  groups named after ROLES.

`public: true` is a boolean flag, not an action list, and is skipped by the
`is_array()` guard.

## Placement of the import hook

Provisioning runs immediately after `computeDefinitionHash()` and BEFORE the
version/hash skip. The skip's meaning is "importing would write exactly what is
already stored" — a statement about STORED DATA, not about whether the Nextcloud
groups those blocks name still exist. Placing provisioning after it would mean a
hand-deleted group is never restored on re-import, because the configuration that
declares it is the one being skipped. Cost of running it every time is one
`groupExists()` per declared group.

## Why the reconciler reads live entities with filtering disabled

`RegisterMapper::findAll()` and `SchemaMapper::findAll()` default to
`_rbac: true, _multitenancy: true`. The job runs from cron: no logged-in user, no
active organisation. A filtered `findAll()` would return a short list — possibly
empty — and the sweep would report a clean pass over rows it never read. Both
flags are therefore passed `false` explicitly.

Hourly rather than the schedule reconciler's 60s: this drives no execution, and
the set only changes on import or manual group deletion. The import path already
provisions immediately; this is the safety net.

## Persistence without a migration

The declared set is written to `IAppConfig` as `declared_groups_<appId>`, beside
the existing `imported_config_<appId>_version`/`_hash` keys the same method
already writes. A `Configuration` entity field was considered — the entity has
precedent (`registers`, `schemas`, `notificationGroups` are all array columns) —
but it would require a database migration for data that is a per-app cache of a
declaration, not a first-class entity relation.

## Failure posture

Both `provision()` and `reconcile()` are total: they never throw. Provisioning
runs inside configuration import and a background job, where an exception would
fail work that is otherwise complete. Each group is handled independently, so a
read-only or LDAP-backed group backend refusing one creation does not cost the
rest; failures are collected and logged.

`inventory()` reports `members` as `null`, not `0`, when `IGroup::count()` returns
`false` (its signature is `int|bool`, and some LDAP configurations cannot count).
Collapsing that to zero would present a fully populated group as empty and raise
exactly the false alarm the surface exists to prevent.

## Testing note

The first round-trip test read the exported document back through
`RbacGroupCollector::fromDocument()` and stayed GREEN with the export change
reverted — because `fromDocument()` re-derives the same groups from the register
and schema definitions the document always carried. It had to assert through
`fromScopeMap()` to pin anything at all. Every test in this change has a recorded
positive control confirming it fails when the behaviour is removed.
