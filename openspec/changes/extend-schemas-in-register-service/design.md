## Context

OpenRegister's `RegisterService::findAll()` and `RegisterService::find()` advertise an `_extend` parameter (e.g. `_extend: ['schemas']`) that should replace schema-ID references on each register with fully-hydrated schema objects. The parameter is declared end-to-end — service → mapper — but the only place it is actually honored is inside `RegistersController::index()` (≈ `lib/Controller/RegistersController.php:286-345`), which loops over the serialized register arrays after the fact and expands them using `SchemaMapper`.

This means the contract is only honored over HTTP. Any consumer that calls the service via DI (DocuDesk's `RegisterDiscoveryService`, and almost certainly others) gets back entities whose `jsonSerialize()` yields schemas as an ID array (`Register.php:540-546` comment: *"Always return schemas as array of IDs."*). Downstream UIs that expect objects (e.g. DocuDesk's admin settings dropdown) silently render empty.

Current state, annotated:

```
┌─── HTTP consumer (frontend) ───────────────────────────┐
│  GET /api/registers?_extend=schemas                    │
│  → RegistersController::index()                        │
│     → registerService->findAll(...)                    │
│     → array_map(jsonSerialize, …)   [IDs]              │
│     → inline expansion loop         [IDs → objects] ✔  │
│     → inline @self.stats loop       [+ stats] ✔        │
│  Response: schemas as objects. Works.                   │
└────────────────────────────────────────────────────────┘

┌─── DI consumer (DocuDesk, OpenCatalogi) ───────────────┐
│  $registerService->findAll(_extend: ['schemas'])       │
│  → RegisterService::findAll forwards _extend           │
│  → RegisterMapper::findAll  [IGNORES _extend]          │
│     (@SuppressWarnings PHPMD.UnusedFormalParameter)    │
│  → returns Register[] whose jsonSerialize() gives IDs  │
│  Consumer gets IDs. Broken.                             │
└────────────────────────────────────────────────────────┘
```

Internal audit: a grep for `$register['schemas']` across `lib/` shows only the controller's own expansion block mutating the value. No other internal OpenRegister callsite assumes the field is either IDs *or* objects — which gives us room to introduce a new opt-in serialization path without touching `Register::jsonSerialize()`.

## Goals / Non-Goals

**Goals:**

- `RegisterService` exposes a serializer API such that a DI caller can obtain a register (or collection) with `_extend`-based post-processing applied — identical data to the HTTP endpoint.
- The serializer is reusable and the foundation for future entity serializers (`SchemaSerializer`, `ObjectSerializer`, …).
- `RegistersController::index()` becomes thin: calls the service, passes `_extend`, formats the response. No expansion logic in the controller.
- Contract is testable in isolation: unit tests cover no-extend, `['schemas']`, `['schemas', '@self.stats']`, missing schema IDs.
- Architectural alignment with ADR-008: expansion is business logic and belongs in the service layer.

**Non-Goals:**

- **Not** changing `Register::jsonSerialize()`. It stays ID-only by contract. Any consumer still calling `jsonSerialize()` directly keeps receiving IDs. This avoids breaking internal callsites and any external consumer that currently relies on the ID shape.
- **Not** fixing DocuDesk as part of this change. Once the serializer API ships, DocuDesk's `RegisterDiscoveryService::serializeRegister()` can swap its `$register->jsonSerialize()` call for the new serializer in a follow-up change. The bug-fix-for-DocuDesk is tracked as an acceptance criterion in `tasks.md` but the code change lives in the DocuDesk repo.
- **Not** designing a generic `_extend` resolver for arbitrary keys yet. The serializer supports exactly the keys the controller supports today (`schemas`, `@self.stats`). Extending the vocabulary (e.g. `@self.permissions`, nested `@self.stats.bytes`) is out of scope; the design leaves the door open but does not build it.
- **Not** changing the HTTP response shape. `/api/registers?_extend=schemas` returns identical JSON before and after.
- **Not** addressing `_extend` on schemas or objects in this change. `SchemaService` and `ObjectService` have their own stories; they can follow the same pattern later.

## Decisions

### Decision 1: Where does the serializer live?

**Choice:** `lib/Service/Serializer/RegisterSerializer.php`.

**Alternatives considered:**

| Option | Path | Verdict |
|---|---|---|
| **A (chosen)** | `lib/Service/Serializer/` | Consistent with existing grouped subfolders under `lib/Service/` (`Archival/`, `Chat/`, `Configuration/`, `Edepot/`, `File/`). Serializers are a service-layer concern — the caller is almost always `RegisterService`. Clear scope. |
| B | `lib/Serializer/` (top-level, parallel to `Formats/`, `Dto/`) | Valid, but invents a new top-level namespace when one is already established inside `Service/`. Preferred only if we ever expect non-service callers (we don't, today). |
| C | Keep expansion inline on `RegisterService` as a private method | Simplest short-term, but doesn't give `SchemaSerializer` / `ObjectSerializer` a natural home when we need them next. The user explicitly called this out: "I can imagine this is not the last serializer we have to build." |

**Rationale:** Option A gives us a named home for the pattern without over-engineering. When the second serializer arrives, we add `SchemaSerializer.php` next to `RegisterSerializer.php`; when a third lands, we can introduce `AbstractEntitySerializer` if duplication warrants it (YAGNI until then).

### Decision 2: What is the serializer's public shape?

**Choice:** `RegisterSerializer` exposes two methods:

```php
public function serialize(
    Register $register,
    array $extend = [],
    ?array $schemaStats = null
): array;

public function serializeMany(
    array $registers,
    array $extend = [],
    ?array $schemaStatsByRegisterId = null
): array;
```

- Accepts **entities** (not pre-serialized arrays) — the serializer owns the full assembly so callers never touch `jsonSerialize()` directly when extending.
- `$extend` contains string keys (`'schemas'`, `'@self.stats'`) matching the controller's current vocabulary.
- `$schemaStats` / `$schemaStatsByRegisterId` is **pre-computed input**, not fetched inside the serializer. The service orchestrates the stats fetch and passes results in. This keeps the serializer free of DI dependencies on `RegisterService` and avoids a circular dep (`RegisterService` → `RegisterSerializer` → `RegisterService`).

**Alternatives considered:**

- *Return a union type (entity or array) from `findAll`/`find` depending on `_extend`*. Rejected — ugly PHP, breaks callers who want entities.
- *Inject `RegisterService` into the serializer*. Rejected — circular dependency; couples serialization to business logic.
- *Fetch stats lazily inside the serializer via `SchemaMapper`*. Partially viable but it makes the serializer opinionated about *how* stats are computed. Keeping stats as an injected precomputed blob is cleaner and matches how the controller already calls `registerService->getSchemaObjectCounts()` before the expansion loop.

### Decision 3: What does `RegisterService` expose, and how does the controller use it?

**Choice:** Add one new service method:

```php
public function findAllSerialized(
    ?int $limit = null,
    ?int $offset = null,
    ?array $filters = [],
    ?array $searchConditions = [],
    ?array $searchParams = [],
    array $_extend = [],
    bool $_multitenancy = true
): array; // array of serialized register arrays
```

And an analogous `findSerialized(string|int $id, array $_extend = [], ...): array`. These methods:

1. Call the existing `findAll` / `find`.
2. If `_extend` contains `'@self.stats'` *and* `'schemas'`, fetch counts via `getSchemaObjectCounts()` up front.
3. Delegate to `RegisterSerializer::serializeMany()` / `::serialize()` with the entities and (optionally) the precomputed stats.

The existing `findAll` / `find` methods **keep their current signatures and entity return types**. The `_extend` parameter on the mapper-facing signature becomes vestigial; we document it as "currently unused at the mapper layer; honored only via the `…Serialized` methods" and optionally drop it from the mapper signature in a cleanup step.

**Alternatives considered:**

- *Make `findAll` / `find` themselves return arrays when `_extend` is non-empty*. Rejected (union return types; breaks callers).
- *Put the methods on `RegisterSerializer` instead of `RegisterService`*. Rejected — `RegisterSerializer` shouldn't know about the database or query builders. Keep the DB concern in the service.
- *Introduce a separate `RegisterQueryService` and push everything there*. Overkill. The existing `RegisterService` is the right home.

### Decision 4: Keep `Register::jsonSerialize()` ID-only (no behavioral change).

**Choice:** Do not change `Register::jsonSerialize()`. Schemas stay as IDs. Expansion is available only via `RegisterSerializer` / the new `…Serialized` methods.

**Rationale:** An internal audit (grep across `lib/` for `$register['schemas']` and callers of `->jsonSerialize()`) showed no internal OpenRegister callsite depends on schemas being *either* IDs or objects — but external apps might, in either direction. Keeping `jsonSerialize()` as-is means zero risk of breaking any external consumer, zero need to add an `extend` parameter to a PHP-standard interface method, and a clean separation between "raw entity shape" (stable, ID-only) and "assembled output shape" (serializer's job, extensible).

**Alternatives considered:**

- *Make `jsonSerialize()` accept an `$extend` context*. Rejected — the `JsonSerializable` interface is parameter-less; overloading it via internal state is fragile and makes the entity stateful.
- *Deprecate `Register::jsonSerialize()` and route all serialization through the serializer*. Larger scope; saved for a later cleanup if ever needed.

### Decision 5: What does `_extend` support on day one?

**Choice:** Exactly the values the controller recognizes today, with identical semantics:

- `'schemas'` — replace the `schemas` field (array of IDs) with an array that contains a full schema object *where expansion succeeded* and the original **schema ID** *where expansion failed* (see next bullet). Schemas are fetched via `SchemaMapper::find()` with `_multitenancy: false`. `properties` stripping is deferred to the consumer — the serializer matches the controller's current behavior, which does NOT strip `properties`. DocuDesk's `RegisterDiscoveryService::filterSchemaProperties()` is a DocuDesk-side concern.
- `'@self.stats'` — only meaningful alongside `'schemas'`. Attaches per-schema `stats.objects.total` counts from `registerService->getSchemaObjectCounts()` to each *successfully expanded* schema object. Matches the controller's current loop (≈ line 324-345). IDs that failed to expand receive no stats (they remain bare IDs).
- **Missing / deleted schema IDs — keep the original ID in its array position.** This is a **deliberate divergence** from `RegistersController::index()`'s current behavior, which silently drops missing schemas (`lib/Controller/RegistersController.php:295-302`). The new behavior:
  - Preserves information (orphan references remain visible to callers rather than vanishing).
  - Matches OpenRegister's established convention for failed hydration (e.g. `RenderObject.php:1261` — *"Object not found in preloaded cache - preserving original UUID"*).
  - Still logs a warning via the injected `LoggerInterface`, matching the controller's current log intent.
  - Produces a mixed-type `schemas` array (object | int | string) when some IDs fail to resolve. Downstream consumers MUST handle this — DocuDesk's frontend already does (`Settings.vue:353` filters `typeof schema === 'object'`). This is treated as a behavior improvement bundled with this change, not a breaking API change (see Risks below).

Unknown `_extend` values are ignored silently (no error) — same as the controller. An ADR-style "strict mode" for unknown keys is deferred.

### Decision 6: Cleanup — `@SuppressWarnings(PHPMD.UnusedFormalParameter)`

The mapper's `_extend` parameter remains declared for BC but is documented as unused at that layer. We have two options:

- **Keep it** (cheapest): keeps any existing call graph intact, but the PHPMD suppression stays — the parameter really is unused.
- **Remove it** (cleaner): drop `_extend` from `RegisterMapper::findAll()` / `find()` signatures and remove the `@SuppressWarnings`. `RegisterService` still declares it because it meaningfully routes to the serializer.

**Choice:** Remove from the mapper, keep on the service. The suppression annotation exists precisely to silence a lint warning about a lie; removing the lie is better than silencing the warning. Any internal caller of the mapper's `_extend` argument is spurious by construction (the argument was never honored).

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| External consumers call `Register::jsonSerialize()` directly and expect schemas-as-objects once they see the new serializer exists | `jsonSerialize()` contract is unchanged. Clearly document the new serializer as the path for expanded output. DocuDesk's follow-up PR is the reference consumer update. |
| Circular DI between `RegisterService` and `RegisterSerializer` via `SchemaMapper` | Stats are pre-computed in the service and passed into the serializer. `SchemaMapper` is injected into the serializer directly; no back-reference to `RegisterService`. |
| `_extend` semantics drift between the HTTP endpoint and the serializer-based path | The controller is refactored to delegate to `findAllSerialized()`. Both paths share the same serializer. A unit test asserts byte-identical output between "controller path" and "direct serializer call" for a representative input. |
| Performance regression on the HTTP endpoint from the extra service method indirection | No: the same DB calls happen in the same order (findAll → per-schema find → optional stats query). The refactor is structural, not algorithmic. |
| Downstream app (DocuDesk) still broken until DocuDesk ships its own follow-up PR | Out of scope for this change. But flagged as an acceptance criterion in `tasks.md` and a cross-repo dependency in the PR description. |
| `properties` stripping behavior differs between controller and DocuDesk's local filter | Resolved: the serializer does not strip `properties` (matches current controller). DocuDesk's filter remains a DocuDesk-side concern and stays in DocuDesk. |
| HTTP endpoint response changes shape for registers that reference missing schema IDs (IDs now retained instead of dropped) | Treated as a bug fix, not a breaking change. The DocuDesk frontend already filters for `typeof schema === 'object'` and will safely ignore orphan IDs. Documented in the release note. If a consumer turns out to depend on the drop-on-missing behavior, they can post-filter; the new behavior is strictly more informative. |

## Migration Plan

This is not a migration-heavy change — no DB schema changes, no external API changes.

1. **Merge in OpenRegister.** Ships the new serializer + `…Serialized` methods. Controller refactored to delegate. HTTP response unchanged.
2. **Test locally** with DocuDesk against the new OpenRegister build — confirm `RegisterDiscoveryService::fetchAvailableRegisters()` still returns ID-only schemas (no regression) because DocuDesk hasn't swapped its serialization call yet.
3. **Follow-up DocuDesk change** (separate PR): swap `$register->jsonSerialize()` for `$this->registerSerializer->serialize($register, ['schemas'])`. Admin settings dropdown starts populating.
4. **Optional cleanup:** audit OpenCatalogi and softwarecatalog for `RegisterService::findAll(_extend: [...])` calls; migrate them to `findAllSerialized` or the direct serializer call.

**Rollback:** The refactor is contained within OpenRegister's service/serializer/controller layer. Revert the PR; the controller goes back to inline expansion; nothing else is affected.

## Open Questions

1. **Should unknown `_extend` values warn?** The controller silently ignores them. Current proposal: match that (silent). Revisit if this leads to bugs (unknown key → silently wrong output).
2. **Do we want `findSerialized` / `findAllSerialized` on the service, or is the serializer injection enough?** Inclination: yes to both convenience methods — the controller becomes a one-liner. If the team prefers minimal surface area on `RegisterService`, we can skip the convenience methods and have consumers inject the serializer directly.

## Seed Data

Not applicable. Per ADR-016 *Exceptions*: *"Changes that only modify frontend components or non-schema backend logic (e.g., settings, permissions) do not require seed data."* This change is a pure service-layer / serializer refactor — no OpenRegister schemas are introduced or modified.
