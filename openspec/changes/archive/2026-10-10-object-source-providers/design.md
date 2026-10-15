# Design: object-source-providers

## Context

The object read path is hardcoded to the database:

```
ObjectService::find()        lib/Service/ObjectService.php
  → GetObject::find()        lib/Service/Object/GetObject.php
    → MagicMapper::find()    (register/schema magic table)
```

`findAll()` and `count()` follow the same shape. There is no seam for "where do this schema's
objects come from". This design adds that seam **without** touching the default DB path.

Parallels to reuse rather than reinvent:
- `IntegrationRegistry` / `IntegrationProvider` (`lib/Service/Integration/`) — DI-tag provider
  discovery, `query-time` storage strategy = live read, mutations throw `NotImplementedException`.
- `SystemEntityObjectAdapter` (`lib/Service/Notification/`) — wraps a foreign entity as a virtual
  `ObjectEntity` for an existing pipeline without persisting it.
- `integration-leaf-foundation` read semantics — RBAC enforced on the canonical read path, uniform
  404s, fail-closed.
- `TaskService` / CalDAV plumbing (`lib/Service/TaskService.php`, `lib/Controller/TasksController.php`)
  — VTODO read/link via `X-OPENREGISTER-*` properties; reuse for the CalDAV provider.

## Decisions

### D1 — Provider interface (read-only)
```php
interface ObjectSourceProvider {
    public function getId(): string;                  // e.g. 'caldav-vtodo'
    public function isEnabled(): bool;                // required NC app present, etc.
    public function find(Register $r, Schema $s, string $id, array $opts = []): ?ObjectEntity;
    public function findAll(Register $r, Schema $s, array $query = []): array; // ObjectEntity[]
    public function count(Register $r, Schema $s, array $query = []): int;
}
```
No create/update/delete. Returned `ObjectEntity` instances are built in memory (uuid/id from the
source key, `register`/`schema` set, `@self` populated) and **never** saved. This mirrors the
`query-time` integration strategy's read-only contract.

**Rejected:** a full read/write provider. The whole point (REQ-AI-DECK-006) is that the external
source stays authoritative; a writable projection would re-introduce the dual-write problem.

### D2 — Registry via DI tag
`ObjectSourceRegistry` collects all services tagged `openregister.object_source` at bootstrap, keyed
by `getId()`. Collision policy = first wins; in `NODE_ENV !== 'production'`/debug, a duplicate id
throws — identical to `IntegrationRegistry` (AD-13). Lookup is `get(string $id): ?ObjectSourceProvider`.

### D3 — Schema key + validator
```jsonc
"x-openregister-object-source": {
  "provider": "caldav-vtodo",     // must resolve in the registry
  "readOnly": true,                // always true in this change; reserved for future RW providers
  "config": { /* provider-specific, e.g. calendar selector, field mapping */ }
}
```
The schema validator accepts the key (object with required `provider` string). An unknown provider
id is a load-time/validation warning, and at read time degrades to "no objects" + a logged warning
(never a 500), consistent with graceful integration degradation.

### D4 — Read-path delegation (the one hot-path change)
In `GetObject::find()/findAll()/count()` (and the `ObjectService` entry points that call them), add a
single guard **before** the MagicMapper call:

```php
$src = $schema->getObjectSource();           // parsed x-openregister-object-source or null
if ($src !== null) {
    $provider = $this->objectSourceRegistry->get($src['provider']);
    if ($provider?->isEnabled()) {
        return $provider->find($register, $schema, $id, $opts); // RBAC applied inside
    }
    // provider missing/disabled → empty result + warning, NOT a DB read
}
// unchanged: MagicMapper path for every schema without an object source
```
The guard is a cheap array-key lookup on the already-loaded `Schema`. Zero behavioural change for
existing schemas. Filtering/pagination/sort in `$query` are passed to the provider, which applies
what it can and documents what it cannot (a provider MAY ignore unsupported operators, like the
`query-time` integration list()).

### D5 — RBAC + fail-closed
The provider applies the same object-level authorization the native path would (the schema's
`authorization.read` rules, current user). Absent/denied → uniform 404 (find) / omitted from list
(findAll), never an error that distinguishes "exists but denied" from "absent" — same anti-oracle
stance as `integration-leaf-foundation`. CalDAV reads are scoped to the acting user's calendars.

### D6 — Writes rejected
`SaveObject`/`DeleteObject` (and their `ObjectService` entry points) check for an object source and
throw a clear `\RuntimeException` ("schema <x> is a read-only projection of provider <id>") before
any DB write. The authoritative write path is the source system (for CalDAV: a VTODO write, owned by
the consuming app), not OR.

### D7 — CalDAV-VTODO reference provider
`CalDavVtodoObjectSourceProvider` (`getId() = 'caldav-vtodo'`) reads VTODOs from the acting user's
calendars (config selects which calendar/collection), maps:
`SUMMARY→title`, `DESCRIPTION→description`, `ATTENDEE→assignee`, `DUE→dueDate`,
`STATUS→status`, `X-OPENREGISTER-LINK`/`X-OPENREGISTER-*`→relation hints, `UID→uuid`. Reuses
`TaskService` for the CalDAV access. `isEnabled()` = Tasks/Calendar capability present.

## Risks / mitigations
- **Hot-path cost** → guard is an in-memory key check; MagicMapper path byte-for-byte unchanged.
- **CalDAV latency on list()** → provider documents pagination support; large collections capped +
  logged (no silent truncation).
- **RBAC leak** → provider MUST run the schema read rules; covered by tests asserting denied/absent
  both yield 404.
- **Migration of decidesk data** → out of scope here; decidesk's change owns projecting/retiring its
  legacy `ActionItem` rows.

## Out of scope
- Writable/bi-directional providers (D6 keeps it read-only).
- Providers other than CalDAV-VTODO (Deck/Calendar/Contacts providers are follow-ups using this
  interface).
- The decidesk-side `ActionItem` binding + legacy-data handling (decidesk change).
