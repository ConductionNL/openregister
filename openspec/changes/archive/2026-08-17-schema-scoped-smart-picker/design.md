## Context

See proposal.md - Why. This design elaborates HOW the two new abstract base
classes are built and HOW the two `mail-smart-picker` gaps (cache
invalidation, public reference support) are closed. It does not re-litigate
whether to build them.

Today, Smart Picker / unified-search integration lives in two concrete,
non-extensible classes:

- `lib/Search/ObjectsProvider.php` (`openregister_objects`) — implements
  `OCP\Search\IFilteringProvider`, delegates to
  `ObjectService::searchObjectsPaginated(query, _rbac: true,
  _multitenancy: true)`, and formats results inline (icon precedence,
  deep-link URL, excerpt/subline building — lines ~475-605, ~649-868).
- `lib/Reference/ObjectReferenceProvider.php` (`openregister-ref-objects`) —
  extends `ADiscoverableReferenceProvider`, implements
  `ISearchableReferenceProvider`, and both parses candidate URLs
  (`parseReference()`, three regex patterns) and formats the rich preview
  card inline (`extractTitle()`, `extractDescription()`,
  `extractPreviewProperties()`, `resolveSchemaName()`,
  `resolveRegisterName()`).

Both registered once, statically, in `Application::register()` (lines
1036-1037). Nextcloud's registration API has no per-schema variant, so a
second (register, schema) pair can only get its own Smart Picker entry by
registering an additional, distinct `IReferenceProvider` /
`OCP\Search\IProvider` class — hence the two new abstract base classes
living under `lib/AppHost/` (the engine layer consuming apps build on, per
the existing `lib/AppHost/*` generalisation pattern — see
`GenericDeepLinkRegistrationListener` for the sibling manifest-driven
precedent).

## Goals / Non-Goals

**Goals:**
- Two abstract base classes that a consuming app subclasses by declaring
  only its register/schema slugs (`getRegisterSlug()`/`getSchemaSlug()`) —
  no id, title, icon, or other configuration the app must choose — to get
  a schema-scoped Smart Picker entry whose id, title, and icon are
  computed deterministically and kept in sync with the schema's own
  metadata.
- Zero behavior change to the existing generic providers.
- A `smartPickerEnabled` schema flag (mirroring `searchable`) that a
  schema owner can toggle to gate the provider's functionality, with the
  boot-time list-visibility limitation documented rather than hidden.
- Close the two documented-but-unimplemented `mail-smart-picker` gaps
  (cache invalidation on save, public reference support) for both the
  generic and the new schema-scoped reference providers, since both extend
  the same shared logic.

**Non-Goals:**
- Building any consuming-app concrete subclass (Pipelinq's `LeadReferenceProvider`
  etc.) — proposal.md marks this out of scope, follow-up change.
- A dynamic/manifest-driven registration mechanism (à la
  `GenericDeepLinkRegistrationListener`) for reference/search providers.
  Nextcloud's `registerReferenceProvider()` / `registerSearchProvider()`
  calls happen once per class in each app's own `Application::register()` —
  a consuming app still writes one `$context->registerReferenceProvider(...)`
  / `registerSearchProvider(...)` line per subclass, in its own app. This
  change only removes the need to reimplement the logic behind that line.
- `ICapability` exposure, `Notifier` deep-link integration, OpenGraph
  metadata — pre-existing `deep-link-registry` gaps, untouched here.

## Decisions

### D1: Extract shared logic into two internal services, not into the abstract base classes directly

Two new internal services carry the logic that both the existing generic
providers and the new abstract base classes call:

- `lib/Service/Reference/ObjectPreviewFormatter.php` — given a rendered
  object (array from `ObjectEntity::jsonSerialize()`), a schema id, and a
  register id, produces the rich-object data shape
  (`extractTitle()`, `extractDescription()`, `extractPreviewProperties()`,
  `resolveSchemaName()`, `resolveRegisterName()`, deep-link URL + icon
  resolution — the exact logic currently inline in
  `ObjectReferenceProvider::resolveReference()`). Also owns
  `parseReference()` (the three-URL-pattern regex parser) and
  `buildCanonicalUrls()` (see D4) as pure, stateless helpers.
- `lib/Service/Search/ObjectSearchResultFormatter.php` — given one search
  result row plus register/schema ids, builds one `SearchResultEntry`
  (icon precedence, deep-link URL, subline/excerpt — the logic currently
  inline in `ObjectsProvider::search()` lines ~475-605 and the
  `buildSubline()`/`buildExcerpt()`/`sliceExcerpt()` private methods).

**Why services, not shared traits or a common abstract parent for the
reference/search providers themselves:** `ObjectReferenceProvider` and
`AbstractSchemaReferenceProvider` differ in exactly one place — the
schema-match guard — so a shared trait would need a hook method anyway,
which is just a service call with extra indirection. A service is
injectable, independently testable (no need to instantiate a
`Reference`-implementing class to unit-test title extraction), and is the
existing pattern in this codebase (`DeepLinkRegistryService`,
`MdiIconRenderer`).

**Alternative considered:** a shared abstract parent
`AbstractObjectReferenceProvider` that both `ObjectReferenceProvider` and
`AbstractSchemaReferenceProvider` extend, with the schema-match guard as a
`protected` hook. Rejected: `ObjectReferenceProvider` is a shipped,
registered class with an existing constructor signature depended on by
`mail-smart-picker`'s spec (`@spec` tags throughout) — reshaping its
inheritance chain is a larger, riskier diff than composing over an
injected formatter service, for the same result.

### D2: AbstractSchemaReferenceProvider

**ID, title, and icon are computed by the base class — a subclass cannot
override them.** Earlier drafts of this design let the app supply the
provider id, title, and icon as constructor arguments. That is superseded:
since `(registerSlug, schemaSlug)` pairs are already globally unique (per
`RegisterMapper`/`SchemaMapper`'s slug uniqueness) and every consuming app's
`appId` namespace is unique, the base class can derive a collision-free id
by itself, and reading title/icon live from the schema keeps the picker
entry in sync with whatever an admin edits in the Schema settings UI,
without the app needing to duplicate or resend that metadata. A subclass
supplies only the two slugs; `getId()` and `getSupportedSearchProviderIds()`
are declared `final` on the abstract class so no subclass can reintroduce a
hand-picked id.

```php
abstract class AbstractSchemaReferenceProvider extends ADiscoverableReferenceProvider implements ISearchableReferenceProvider {
    public function __construct(
        private readonly ObjectPreviewFormatter $formatter,
        private readonly ObjectService $objectService,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly LoggerInterface $logger,
        private readonly ?string $userId,
    ) {}

    // A concrete subclass MUST implement these two — the only
    // configuration surface it has:
    abstract public function getRegisterSlug(): string;
    abstract public function getSchemaSlug(): string;

    /**
     * Computed id: `openregister-ref-{registerSlug}-{schemaSlug}`, matching
     * the naming convention of the existing generic
     * `openregister-ref-objects` id. Declared `final` so a subclass cannot
     * pick a colliding or inconsistent id.
     */
    final public function getId(): string {
        return 'openregister-ref-' . $this->getRegisterSlug() . '-' . $this->getSchemaSlug();
    }

    /**
     * Computed search-provider id this reference provider pairs with:
     * `openregister_objects_{registerSlug}_{schemaSlug}`, matching the
     * underscore-style naming of the existing generic
     * `openregister_objects` search provider. Declared `final` for the same
     * reason as getId().
     */
    final public function getSupportedSearchProviderIds(): array {
        return ['openregister_objects_' . $this->getRegisterSlug() . '_' . $this->getSchemaSlug()];
    }

    /** Read live from SchemaMapper — never cached beyond request scope, so
     *  a title edit in the Schema settings UI shows up immediately. */
    final public function getTitle(): string {
        return $this->schemaMapper->find($this->resolveSchemaId(), _multitenancy: false, _rbac: false)->getTitle();
    }

    /** Reuses the same MDI-icon resolution ObjectsProvider::resolveSchemaIcon()
     *  already does, rendered through the existing `openregister.icon.mdi`
     *  route (MdiIconRenderer) — not a URL the app supplies. */
    final public function getIconUrl(): string { /* delegates to the shared icon-resolution helper (D1) */ }

    public function matchReference(string $referenceText): bool {
        if ($this->isSmartPickerEnabled() === false) {
            return false;
        }
        $parsed = $this->formatter->parseReference($referenceText);
        return $parsed !== null
            && $parsed['registerId'] === $this->resolveRegisterId()
            && $parsed['schemaId'] === $this->resolveSchemaId();
    }

    public function resolveReference(string $referenceText): ?IReference {
        if ($this->matchReference($referenceText) === false) {
            return null;
        }
        // delegate to $this->formatter->buildReference(...) — identical
        // body to ObjectReferenceProvider::resolveReference() today, minus
        // the try/catch which also lives in the formatter now.
    }

    public function getCachePrefix(string $referenceId): string { /* delegates to formatter, same guard */ }
    public function getCacheKey(string $referenceId): ?string { return $this->userId ?? ''; }

    // resolveRegisterId()/resolveSchemaId() resolve getRegisterSlug()/
    // getSchemaSlug() once via RegisterMapper::findBySlug()/
    // SchemaMapper::findBySlug(), memoized per-instance — same lazy-slug
    // pattern DeepLinkRegistryService already uses (see below).
    // isSmartPickerEnabled() reads Schema::isSmartPickerEnabled() — see D2a.
}
```

A concrete subclass (out of scope here, shown for clarity) is now trivial —
just the two slug methods, no id/title/icon/constructor boilerplate:

```php
class LeadReferenceProvider extends AbstractSchemaReferenceProvider {
    public function getRegisterSlug(): string { return 'pipelinq'; }
    public function getSchemaSlug(): string { return 'lead'; }
}
```

Slug-to-id resolution happens lazily inside the base class, memoized per
request — mirroring how `DeepLinkRegistryService` resolves slugs lazily
(deep-link-registry spec, "Registration SHALL use slugs not database IDs").
Neither the app nor the base class's constructor needs pre-resolved
integer ids; `matchReference()`'s guard still ends up a cheap `===`
comparison once the ids are memoized.

### D2a: `smartPickerEnabled` schema flag — mirrors `searchable`

A new boolean column on `Schema`, gating Smart Picker participation the
same way the existing `searchable` column gates unified-search
participation (`lib/Db/Schema.php` line ~331,
`SchemaMapper::findSearchableIds()`/`findNonSearchableIds()`,
`Version1Date20250929120000` migration):

- **Column:** `Schema::$smartPickerEnabled` (`protected bool`, default
  `false` — opt-in, unlike `searchable`'s opt-out default, because exposing
  a schema as its own picker entry is a more visible, deliberate choice
  than being included in a generic search). Registered via
  `addType('smartPickerEnabled', Types::BOOLEAN)` in the same
  `getFieldTypes()`-style block as `searchable`. Getter/setter:
  `isSmartPickerEnabled(): bool` / `setSmartPickerEnabled(bool $smartPickerEnabled): void`
  (calls `markFieldUpdated('smartPickerEnabled')`, same as `setSearchable()`),
  and included in `jsonSerialize()`. Named `isSmartPickerEnabled()`, not
  `getSmartPickerEnabled()`, to match the class's real boolean-getter
  convention (`isSearchable()`) and avoid a PHPMD `BooleanGetMethodName`
  violation.
- **Migration:** a new `Version1DateYYYYMMDDHHMMSS.php` `SimpleMigrationStep`,
  structurally identical to `Version1Date20250929120000` (add-column-if-missing
  on `openregister_schemas`, `notnull: true, default: false`).
- **Mapper method:** `SchemaMapper::findSmartPickerEnabledIds(): array`
  (list of schema ids with `smartPickerEnabled = true`), same shape as
  `findSearchableIds()`. Only the "enabled" direction is needed — unlike
  `searchable`, no consumer currently needs the inverse "disabled ids" list,
  since the flag is consulted per-instance (see below), not as a bulk
  IN-filter over an already-generic provider.
- **Consulted by:** each `AbstractSchemaReferenceProvider`/
  `AbstractSchemaSearchProvider` instance, once per request, via its own
  resolved schema id — not a bulk filter, because a schema-scoped provider
  only ever concerns one schema. `matchReference()`, `resolveReference()`,
  and `search()` all check `isSmartPickerEnabled()` before doing any
  matching/resolving/searching work.

### Known limitation: the flag gates functionality, not list-visibility

Nextcloud's `IRegistrationContext::registerReferenceProvider()`/
`registerSearchProvider()` run once at app boot, driven purely by which PHP
classes a consuming app registers in its own `Application::register()` (see
Non-Goals). The Smart Picker's "Select provider" list is populated from
that static, boot-time registration — `smartPickerEnabled` is a runtime,
per-request database value the registration step never consults, because it
runs before any request (and any RBAC/tenant context) exists.

**Consequence:** flipping `smartPickerEnabled` to `false` for a schema whose
provider class is registered does **not** remove that entry from the Smart
Picker's provider list. The entry stays visible. What changes is behavior:

- `matchReference()` returns `false` for every reference text (so nothing
  ever resolves through this provider).
- `resolveReference()` / `resolveReferencePublic()` return `null`.
- `search()` returns an empty `SearchResult` (no matches, for every query).

The only way to remove the entry from the list is for the consuming app to
stop registering the class in `Application::register()` (a code change,
deployed like any other). `smartPickerEnabled` is a data-level kill switch
for functionality, not a UI-visibility toggle — documented here so a future
reader does not mistake a "disabled but still listed" report for a bug.

### D3: AbstractSchemaSearchProvider

Same supersession as D2: id and display name are computed by the base
class, not passed in.

```php
abstract class AbstractSchemaSearchProvider implements IProvider {
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly ObjectSearchResultFormatter $resultFormatter,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly LoggerInterface $logger,
    ) {}

    // A concrete subclass MUST implement these two — same slugs the
    // paired AbstractSchemaReferenceProvider subclass implements:
    abstract public function getRegisterSlug(): string;
    abstract public function getSchemaSlug(): string;

    /**
     * Computed id: `openregister_objects_{registerSlug}_{schemaSlug}`,
     * matching the underscore-style naming of the existing generic
     * `openregister_objects` search provider. Declared `final`.
     */
    final public function getId(): string {
        return 'openregister_objects_' . $this->getRegisterSlug() . '_' . $this->getSchemaSlug();
    }

    /** Read live from SchemaMapper — same source as
     *  AbstractSchemaReferenceProvider::getTitle(). Declared `final`. */
    final public function getName(): string {
        return $this->schemaMapper->find($this->resolveSchemaId(), _multitenancy: false, _rbac: false)->getTitle();
    }

    public function getOrder(string $route, array $routeParameters): ?int { return 10; }

    public function search(IUser $user, ISearchQuery $query): SearchResult {
        if ($this->isSmartPickerEnabled() === false) {
            return SearchResult::complete($this->getName(), []);
        }

        // Same cursor/limit/term handling as ObjectsProvider::search(), but
        // @self.schema is forced to $this->resolveSchemaId() (and
        // @self.register to $this->resolveRegisterId()) — not read from a
        // query filter — because the schema is fixed, not user-selectable,
        // unlike the generic provider's optional 'register'/'schema'
        // IFilteringProvider filters. That is also why this class
        // implements the plain IProvider, not IFilteringProvider: there is
        // no per-request choice to filter on.
        $searchQuery['@self']['schema'] = $this->resolveSchemaId();
        $searchQuery['@self']['register'] = $this->resolveRegisterId();
        $results = $this->objectService->searchObjectsPaginated(
            query: $searchQuery, _rbac: true, _multitenancy: true
        );
        // $this->resultFormatter->format(...) builds each SearchResultEntry.
    }

    // resolveRegisterId()/resolveSchemaId()/isSmartPickerEnabled() — same
    // lazy, memoized slug resolution and flag check as
    // AbstractSchemaReferenceProvider (D2/D2a).
}
```

An empty-but-`complete` `SearchResult` (not an error, not a missing
provider) is exactly the "flag off ⇒ functionally inert, still listed"
behavior from the Known Limitation above: the provider entry is still
offered by the Smart Picker, it simply never returns matches while the
flag is off.

Not implementing `IFilteringProvider`: the generic provider's `register`/
`schema` custom filters exist so a user can narrow an already-broad
provider. A schema-scoped provider is already narrow by construction —
exposing those filters again would let a caller ask it to search a
*different* schema, which the class must refuse anyway, making the filter
UI misleading. Per proposal.md, this exact trade-off is already decided;
noted here only as the resulting interface choice.

### D4: Cache invalidation — one pattern registry drives both recognizing and building

**The drift risk this closes:** `ObjectReferenceProvider::parseReference()`
already encodes the three URL shapes OpenRegister recognises as an object
reference — hash-routed UI (`#/registers/{r}/schemas/{s}/objects/{uuid}`),
API endpoint (`/api/objects/{r}/{s}/{uuid}`), and direct route
(`/objects/{r}/{s}/{uuid}`), each as its own `preg_match()` block. Cache
invalidation on save needs the *inverse*: given `(registerId, schemaId,
uuid)`, produce every URL shape someone might have a cached reference
under. An earlier draft of this design wrote that inverse by hand — three
patterns implicitly assumed to collapse to one hard-typed
`$registerId . '/' . $schemaId . '/' . $uuid'` string, maintained nowhere
near `parseReference()`'s regex blocks. That is exactly the two-list
failure mode called out in this change's own scope: `parseReference()`'s
pattern list and the invalidation code's URL-shape assumptions are
independently maintained pieces of code that happen to agree today. If a
future change adds a 4th recognised pattern to `parseReference()` — or
changes how one of the three collapses — nothing forces the invalidation
side to notice; that pattern's cached previews silently stop being
invalidated after a save, reintroducing the exact stale-cache bug this
change exists to close, for just that one pattern. (D1 already anticipated
this: it forward-references `ObjectPreviewFormatter::buildCanonicalUrls()`
"(see D4)" as a stateless helper — this section makes that helper real and
wires it into invalidation, instead of leaving `parseReference()` and
invalidation as two lists that happen to agree.)

**The fix: a single `PATTERNS` array, walked by both directions.**
`ObjectPreviewFormatter` (D1) owns one array of `{name, build, regex}`
pattern definitions — the sole place OpenRegister's three canonical URL
shapes are described. `parseReference()` (recognising) and
`buildCanonicalUrls()` (building) both iterate it; neither maintains its
own copy of the three shapes:

```php
final class ObjectPreviewFormatter {
    /** Single source of truth for OpenRegister's canonical object-reference
     *  URL shapes. parseReference() and buildCanonicalUrls() both iterate
     *  this — there is no second, independently maintained pattern list. */
    private const PATTERNS = [
        ['name' => 'hash-routed',  'build' => /* base + #/registers/{r}/schemas/{s}/objects/{uuid} */ null, 'regex' => /* ... */ null],
        ['name' => 'api',          'build' => /* base + api/objects/{r}/{s}/{uuid} */ null,                 'regex' => /* ... */ null],
        ['name' => 'direct-route', 'build' => /* base + objects/{r}/{s}/{uuid} */ null,                     'regex' => /* ... */ null],
    ];

    public function parseReference(string $referenceText): ?array {
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($this->buildRegex($pattern), $referenceText, $m) === 1) {
                return ['registerId' => (int)$m[1], 'schemaId' => (int)$m[2], 'uuid' => $m[3]];
            }
        }
        return null;
    }

    /** Forward-built, never reverse-parsed: every canonical URL for one object. */
    public function buildCanonicalUrls(int $registerId, int $schemaId, string $uuid): array {
        return array_map(
            fn (array $pattern): string => $pattern['build']($this->baseUrl(), $registerId, $schemaId, $uuid),
            self::PATTERNS
        );
    }

    /** Same collapsing/passthrough guard ObjectReferenceProvider::getCachePrefix()
     *  (and AbstractSchemaReferenceProvider's, D2) already delegate to — the ONE
     *  place that decides what cache prefix a reference text hashes under. */
    public function resolveCachePrefix(string $referenceText): string {
        $parsed = $this->parseReference($referenceText);
        return $parsed === null
            ? $referenceText
            : $parsed['registerId'] . '/' . $parsed['schemaId'] . '/' . $parsed['uuid'];
    }
}
```

Adding a 4th recognised pattern is a one-line addition to `PATTERNS`;
`parseReference()`, `buildCanonicalUrls()`, and every invalidation call
downstream of them pick it up automatically — there is nothing else to
remember to update.

**Hook point:** unchanged from the earlier draft — `ObjectService::saveObject()`,
immediately after `$savedObject = $this->saveHandler->saveObject(...)`
succeeds (line ~1388), in the same non-fatal try/catch style as the
adjacent contact-matching cache invalidation block (lines 1394-1420) —
lazy-resolve `IReferenceManager`/`ObjectPreviewFormatter`/
`DeepLinkRegistryService` via `\OC::$server->get(...)` (ObjectService does
not constructor-inject them, avoiding new hard dependency edges; this
mirrors how `DeepLinkRegistryService` itself lazy-resolves
`RegisterMapper`/`SchemaMapper` via `ContainerInterface` to sidestep
circular DI during boot — see deep-link-registry spec, "Deep link
resolution SHALL handle circular DI gracefully"). A failure here must
never fail the save, and is logged as a warning, same as the
contact-matching block.

**Invalidation calls the builder, never a hand-typed string:**

```php
$invalidated = [];
foreach ($formatter->buildCanonicalUrls($registerId, $schemaId, $uuid) as $canonicalUrl) {
    $prefix = $formatter->resolveCachePrefix($canonicalUrl);
    if (isset($invalidated[$prefix]) === false) {
        $referenceManager->invalidateCache($prefix); // no $cacheKey — see below
        $invalidated[$prefix] = true;
    }
}

$deepLinkUrl = $deepLinkRegistry->resolveUrl(registerId: $registerId, schemaId: $schemaId, objectData: $flatData);
if ($deepLinkUrl !== null) {
    $referenceManager->invalidateCache($deepLinkUrl);
}
```

Today all three canonical URLs still collapse to the identical
`"{registerId}/{schemaId}/{uuid}"` prefix, so the `$invalidated` dedup
keeps this a single `invalidateCache()` call in practice — but the code no
longer *assumes* that collapse; it asks `resolveCachePrefix()`, the exact
function `matchReference()`/`resolveReference()`/`getCachePrefix()`
already delegate to. A future pattern that collapses differently (or not
at all) is handled correctly without touching this hook.

**Deep-linked consuming-app URLs remain a second, separately-sourced call —
and that's fine, not a second hand-maintained list.**
`ObjectReferenceProvider::parseReference()` does not recognise
consuming-app URL shapes (e.g. `/apps/pipelinq/#/clients/{uuid}`) — only
the OpenRegister-owned patterns in `PATTERNS`. `DeepLinkRegistryService::resolveUrl()`
is already the *sole* place a deep-link URL is ever built anywhere in this
codebase (D1's rich-reference builder calls the same method to populate a
resolved reference's `url` field), so there is no independent deep-link
URL list to drift out of sync with it — only one call site to keep
correct, which already exists for a different purpose.

Both invalidation paths are cheap (no I/O beyond the in-memory deep-link
registry lookup already used elsewhere in the save path's neighbourhood)
and both are best-effort: wrapped in the same try/catch as the
`IReferenceManager` resolution above, so a missing/misconfigured cache
backend degrades to "stale preview until TTL expiry" rather than a failed
save.

**No `$cacheKey` — confirmed from the interface.**
`IReferenceManager::invalidateCache(string $cachePrefix, ?string $cacheKey = null): void`'s
own docblock: "Invalidate all cache entries with a prefix or just one if
the cache key is provided." Omitting `$cacheKey` — as every call above
does — invalidates *every* cached entry under that prefix, across every
user who has a cached preview of this reference (`getCacheKey()` on both
providers scopes cache entries per-`$userId`), not just the saving user's
own entry. That is the intended behavior: any user who previously
resolved a preview of this object should see the fresh version after an
edit, not only the one who made it.

**Why this stays inside `ObjectPreviewFormatter`, not a new registry
class.** D1 already plans `ObjectPreviewFormatter` as the owner of
`parseReference()` and (via forward reference) `buildCanonicalUrls()`.
Splitting the pattern array into a separate class would recreate the
same two-source-of-truth risk one level up — a parser in one file, a
builder in another — which is precisely what this section closes. Keeping
both directions as methods over the same `private const PATTERNS`, in one
class, is what makes them structurally unable to drift, not merely an
organisational choice.

### D5: Public reference support — reuse existing RBAC delegation, don't invent a public/private schema flag

**Finding:** OpenRegister has no schema-level `isPublic` boolean.
Anonymous read access is decided the same way for every anonymous request
today: `ObjectsController`'s object-read endpoints (`index`, `show`, etc.)
are `#[PublicPage]` and pass through to `ObjectService::find()` /
`searchObjectsPaginated()` with the caller's `$userId` — `null` for an
anonymous request — which the RBAC layer (`ObjectScopeResolver`,
`ObjectGrantResolver`) resolves against each object's `_authorization`
block (`{"read": ["public"]}` on the object, or a schema/register-level
group rule that includes an anonymous-eligible role). An object with no
matching rule fails closed — this is the exact "empty-auth-block is open
only when explicitly configured, otherwise closed" behavior already
governing every other anonymous read path in the app (see
`openregister-contract-programme` topic memory / ADR-084).

`ObjectReferenceProvider::resolveReference()` already calls
`ObjectService::find()` and already catches every exception (including
RBAC denial) to return `null` (lines 368-379 today) — it is *already*
anonymous-safe in its resolution logic, because the class is constructed
with a nullable `$userId` (already `null` for an unauthenticated DI
container) and passes no special bypass flags to `ObjectService::find()`.

**The only missing piece is the marker interface.** Nextcloud's
`ReferenceManager::resolveReference()` only calls `resolveReferencePublic()`
on providers implementing `IPublicReferenceProvider` when resolving in a
public-share context (`$public = true`); a provider that doesn't implement
it is simply skipped for anonymous/public-share requests, which is the
current (broken) behavior described in the proposal.

**Decision:** implement `IPublicReferenceProvider` on
`ObjectReferenceProvider` (and, since it shares the same formatter, get it
for free on `AbstractSchemaReferenceProvider` too) by delegating straight
to the existing `resolveReference()` logic:

```php
public function resolveReferencePublic(string $referenceText, string $sharingToken): ?IReference {
    // $sharingToken identifies the public share context, not an object-level
    // permission; the RBAC decision for the object itself still runs inside
    // ObjectService::find() exactly as it does for the authenticated path,
    // using the same null $userId this instance was already constructed
    // with for an anonymous request.
    return $this->resolveReference($referenceText);
}

public function getCacheKeyPublic(string $referenceId, string $sharingToken): ?string {
    // Different public shares can expose different objects/permissions for
    // the same underlying reference text (e.g. a share-link scoped grant),
    // so the cache MUST vary by token — never collapse to a single
    // anonymous-wide cache entry the way getCacheKey() collapses to ''.
    return $sharingToken;
}
```

No new "is this schema public" concept is introduced; the existing
per-object `_authorization` block (and the RBAC layer that already
enforces it identically for `#[PublicPage]` controller reads) is the sole
source of truth. `resolveReferencePublic()` performs no extra check beyond
what `resolveReference()` already performs.

**Alternative considered:** add a schema-level `isPublicSearchable` flag
consulted before calling `ObjectService::find()`, to fail fast without a
DB round-trip for schemas nobody intends to expose publicly. Rejected:
it would be a second, independent gate that could drift from the
per-object `_authorization` block (an object could be individually shared
public while its schema flag says no, or vice versa), reintroducing
exactly the kind of "declared and enforced nowhere" / "denylist rot"
failure mode this codebase has hit before. The existing RBAC layer is
already the single source of truth and is not meaningfully slower than a
flag check — `find()` already has to run for the object to resolve at
all.

### D6: Documentation corrections

- `openspec/specs/mail-smart-picker/spec.md` — "Current Implementation
  Status" is rewritten to move `ObjectReferenceProvider`, the Vue widget,
  widget registration, and translation strings from "Not yet implemented"
  to "Fully implemented", leaving only the two items this change closes
  (cache invalidation, public reference support) as the residual gap list
  — then, once this change ships, those move too. This is a prose-only
  correction; no requirement text changes.
- `openspec/specs/deep-link-registry/spec.md` — the "Apps SHALL register
  deep link patterns via boot-time events" requirement's prose currently
  only describes the bespoke per-app `DeepLinkRegistrationListener`
  pattern (Pipelinq/Procest's own `lib/Listener/...`). It is extended to
  also describe the AppHost `GenericDeepLinkRegistrationListener` /
  manifest-driven (`src/manifest.json` `deepLinks` block) path those same
  two apps have since migrated to, presented as an alternative
  registration mechanism alongside (not replacing) the bespoke-listener
  description, since a future app could still hand-roll a listener.

## Risks / Trade-offs

- **[Resolved] Provider id collisions.** An earlier draft left `getId()` to
  the subclass, risking two consuming apps picking colliding ids. This is
  now resolved by D2/D3: both abstract base classes compute `getId()`
  (and, for the reference provider, `getSupportedSearchProviderIds()`) as
  `final` methods derived from `(registerSlug, schemaSlug)`, which are
  already unique per `RegisterMapper`/`SchemaMapper`. A subclass has no way
  to override the computed id, so collisions are structurally impossible
  rather than merely discouraged by convention.
- **[Risk] `smartPickerEnabled = false` does not hide the picker entry.**
  See the "Known limitation" callout under D2a: because Nextcloud resolves
  the provider list from boot-time class registration, not from a runtime
  flag, an admin who disables the flag expecting the entry to disappear
  from "Select provider" will instead see it still listed but returning no
  results. → Mitigation: documented explicitly in design.md (D2a) and as a
  first-class spec scenario, so this is understood as intended behavior
  during review and support, not investigated as a bug later.
- **[Mitigated by construction] Cache-invalidation drift between
  recognised and invalidated URL shapes.** The original version of this
  risk — a future pattern added to `ObjectReferenceProvider::parseReference()`
  without a matching update to invalidation, silently reintroducing stale
  previews for that one pattern — is now structurally closed: `parseReference()`
  and `buildCanonicalUrls()` both iterate the same `PATTERNS` array (D4),
  so any pattern `parseReference()` can recognise, `buildCanonicalUrls()`
  also produces, and the invalidation hook invalidates it without further
  code changes. This is no longer "two lists that happen to agree today"
  — there is exactly one list. **Residual risk, honestly not closed by
  this change:** a consuming app that resolves an object reference through
  some URL mechanism entirely outside both `PATTERNS` and
  `DeepLinkRegistryService` — e.g. its own bespoke `IURLGenerator` route
  that was never registered with the deep-link registry, and that
  OpenRegister therefore has no visibility into — will still have a cached
  reference D4's invalidation never reaches, exactly as before this
  change. That gap cannot be closed from OpenRegister's side without the
  consuming app registering the URL somewhere OpenRegister can see (i.e.
  via `DeepLinkRegistryService`, the existing documented mechanism for
  exactly this). → Mitigation: this residual is bounded the same way as
  before (existing NC cache TTL), and is now the *only* remaining gap —
  every URL shape OpenRegister itself defines or is told about is covered
  by construction, not by convention.
- **[Risk] Refactor regression on the generic providers.** Extracting
  `ObjectsProvider`/`ObjectReferenceProvider`'s inline logic into shared
  services is a behavior-preserving refactor touching two
  already-shipped, spec-covered classes. → Mitigation: existing PHPUnit
  coverage for both classes (per `mail-smart-picker`'s `@e2e exclude ...
  covered by PHPUnit` note) must pass unchanged after the extraction, and
  the new services should be unit-testable in isolation, letting the
  refactor be verified at both the old class boundary and the new service
  boundary.

## Migration Plan

One schema migration (the `smartPickerEnabled` column), no data migration.
Deploy order:
1. Add the `Schema::$smartPickerEnabled` column, getter/setter, and the
   `Version1DateYYYYMMDDHHMMSS` migration (D2a), plus
   `SchemaMapper::findSmartPickerEnabledIds()`. Default `false`, so no
   existing schema is affected until an admin opts in.
2. Add the two internal formatter/result services, refactored out of the
   existing classes with their existing PHPUnit coverage passing unchanged.
3. Add `AbstractSchemaReferenceProvider` / `AbstractSchemaSearchProvider`
   on top of the now-shared services and the new flag, with `getId()` /
   `getSupportedSearchProviderIds()` / `getTitle()` / `getIconUrl()` /
   `getName()` computed and `final` per D2/D3.
4. Add the cache-invalidation call to `ObjectService::saveObject()` and
   `IPublicReferenceProvider` to `ObjectReferenceProvider`.
5. Correct the two spec documentation files.

Rollback: each step is independently revertible; the abstract base classes
have no consumers in this repo yet (Pipelinq's subclass is a follow-up
change), so reverting steps 2-5 has zero blast radius beyond this repo.
Reverting step 1's migration would need a companion down-migration dropping
the column — deferred until this change actually ships, per existing
migration conventions in this repo.
