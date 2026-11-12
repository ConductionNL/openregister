# Design: store-plane-publish

## Context

See proposal.md for why. The plane today is `GenericStoreService` (discovery), a
`StoreDescriptor` value object (per-app parameters) and `StoreActionAuthorizer` (resolves
an install posture against the leaf app's ADR-023 matrix). Every outbound call already
goes through one private `fetch()` that applies the SSRF guard, refuses redirects, sets
10 second timeouts and sends the token as a Bearer header. The registry's objects API
answers a create with `201` and the object's `jsonSerialize()` (properties at top level,
metadata under `@self`), `409` on a duplicate and `400`/`422` on validation.

## Goals / Non-Goals

**Goals:**

- One write method with the same guard chain as discovery, so no leaf app builds an
  objects-API URL again (hydra gate 62).
- A descriptor that has not opted in cannot publish, whatever the caller passes.
- The publish body is an allowlist, not a denylist.

**Non-Goals:**

- No engine route for publish. The payload is app-specific: learniq's package only
  exists after its sharing gate and a consent record. An engine route would have to
  accept an arbitrary payload from the browser, which is a worse boundary than an app
  controller that builds it.
- No manifest key for publishing. `StoreManifest` feeds the engine-hosted routes, and
  there is no engine publish route to feed. A leaf app builds its descriptor in PHP, as
  learniq's `CourseStoreDescriptor` already does.
- No update or delete of a published object. A new version is a new slug (learniq's slug
  already carries a content hash).
- Federated configuration publishing (`FederatedConfigService`) is untouched. That path
  signs bundles for a repository; this one writes one object into one registry.

## Decisions

### D1: The descriptor opts in with two lists, both empty by default

`publishFields` (allowed remote properties) and `publishGroups` (who may publish) are new
optional constructor parameters on `StoreDescriptor`. Empty means read-only. Considered:
a separate `PublishDescriptor` class. Rejected, because the URL, register fallback and
token are exactly the discovery descriptor's, and two objects describing one store would
drift. Considered: a boolean `publishable`. Rejected, because the two lists are what the
plane actually needs to enforce, and requiring them non-empty is the opt-in.

The spec's descriptor requirement says no other per-app parameter may be read. This
change states the two new parameters as an ADDED requirement rather than MODIFYING that
one, because `store-over-federated-config` already modifies it (to add `types`) and two
open deltas rewriting one requirement conflict on archive. Whichever archives second folds
the other's parameters into the list.

### D2: The body is `slug` plus the allowlist, minus identity keys

`slug` always travels, because the plane verifies it on the way back and the install
route resolves by it. Everything else must be listed. `id`, `uuid` and `@self` are
removed after filtering, even when listed: `ObjectService::saveObject()` resolves its
target from the payload, so a body carrying the uuid of an object that already lives on
the registry would replace it. The install requirement strips the same keys for the same
reason in the other direction.

The slug must match `/^[a-z0-9][a-z0-9-]*[a-z0-9]$/`, the pattern
`GenericStoreController::install()` accepts, so a published item is installable. The
pattern is a public constant (`StorePublishRules::SLUG_PATTERN`); the controller keeps its
own private copy for now, since changing the controller is outside this change.

### D3: Outcomes

| Condition | Outcome |
|---|---|
| Descriptor names no fields or no group, or payload slug invalid | `not_publishable` |
| `registry_url` empty | `not_configured` |
| JSON body over 20 MiB | `too_large` |
| SSRF guard refuses, transport throws, 3xx, 5xx | `store_unreachable` |
| 429 | `rate_limited` |
| other 4xx | `store_rejected` |
| 2xx, body not a JSON object, or returned slug differs | `store_invalid_response` |
| 2xx, returned slug matches | `ok` |

`not_publishable` is checked before `not_configured`: a descriptor that cannot publish is
a code defect, and it should surface on every instance, not only on one with a registry.
`store_rejected` is split from `store_unreachable` for the same reason `rate_limited` is:
the remedy differs. A rejected object means fix the payload or the token's rights; an
unreachable registry means the network or the server. Search keeps mapping every non-2xx
to `store_unreachable`, unchanged.

The 20 MiB cap is learniq's `CourseStorePublisher::MAX_BYTES`, moved into the plane so
learniq can drop its own check.

### D4: One shared request method, and the pure rules in their own class

`fetch()` becomes a thin GET wrapper over a private `send()` that takes the method and the
request options, so publish and discovery share the guard, the redirect refusal, the
timeouts and the Bearer header. The caller's options cannot loosen them: the timeouts,
the redirect refusal and the Authorization header are applied last. The status mapping
stays per caller, because search and publish map a 4xx differently (D3).

The pure half of publish (the body allowlist and identity stripping, the slug check, the
failure status mapping and the decode of the registry's answer) lives in
`lib/AppHost/Store/StorePublishRules.php`, a final class with no dependencies. Keeping it
in `GenericStoreService` pushed the class to a phpmd complexity of 62 against a threshold
of 50. The service takes it as an optional constructor argument that defaults to a new
instance, so the container and every existing hand construction keep working.

### D5: `canPublish()` matches like ADR-023, and refuses when no group is named

`StoreActionAuthorizer::canPublish(StoreDescriptor, IUser)` needs `IGroupManager`, a new
constructor dependency (autowired; the only hand construction is in the unit test).
Matching mirrors `GenericActionAuthService::requireAction()`: an administrator passes,
`@authenticated` (ADR-023 EVERYONE) admits any signed-in user, otherwise the user must be in a named group. The
one difference is the empty list: ADR-023 treats an undeclared action as admin-only,
while the plane refuses everybody, administrators included, because an empty list means
the app never made the decision the brief puts on it.

Considered: no administrator bypass. Rejected, because learniq passes
`getAllowedGroups('course-package.share')` and its own `requireAction()` admits an
administrator; a plane that refused the same administrator would make the two checks
disagree, and the leaf app's matrix is the one an administrator actually edits.

Considered: enforcing membership inside `publish()` through `IUserSession`. Rejected,
because the service is session-free (discovery runs from background jobs) and the
existing install split is the same: the controller asks the authorizer, then calls the
service. `publish()` still refuses a descriptor with no group, so the decision cannot be
skipped entirely.

### Declarative-vs-imperative decision

| Behaviour | Path | Rationale |
|---|---|---|
| Write one object to a remote registry | Imperative (`GenericStoreService`) | ADR-031 exception: external integration, an outbound HTTP write to another instance. |
| Who may publish | Imperative (`StoreActionAuthorizer`) | A group check at call time, delegated in content to the leaf app's matrix. |

## How learniq adopts this

Learniq PR 1043 (`origin/feat/lesson-sharing-via-store-plane`) changes three files.

`lib/Service/CourseStore/CourseStoreDescriptor.php` names what may travel and who may
send it. It needs learniq's `ActionAuthService` in its constructor:

```php
public const PUBLISH_FIELDS = [
    'kind', 'title', 'description', 'subject', 'level', 'levels', 'goals',
    'goalsCovered', 'language', 'license', 'author', 'cardLine', 'version',
    'lessonCount', 'sharedAt', 'package',
];

public function __construct(private readonly ActionAuthService $actionAuth) {
}

public function descriptor(): StoreDescriptor {
    return new StoreDescriptor(
        appId: Application::APP_ID,
        schema: self::SCHEMA,
        defaultRegister: self::DEFAULT_REGISTER,
        cardFields: self::CARD_FIELDS,
        publishFields: self::PUBLISH_FIELDS,
        publishGroups: $this->actionAuth->getAllowedGroups(action: 'course-package.share')
    );
}
```

`lib/Service/CourseStore/CourseStorePublisher.php` loses `IClientService`, `IAppConfig`,
`CourseStoreUrlGuard`, `objectsUrl()`, `post()`, `MAX_BYTES` and `TIMEOUT`, and becomes:

```php
public function __construct(
    private readonly GenericStoreService $storeService,
    private readonly StoreActionAuthorizer $authorizer,
    private readonly CourseStoreDescriptor $descriptor,
    private readonly CourseStoreRegistryObject $registryObject,
) {
}

public function isConfigured(): bool {
    return $this->storeService->isConfigured(descriptor: $this->descriptor->descriptor());
}

public function mayPublish(IUser $user): bool {
    return $this->authorizer->canPublish(descriptor: $this->descriptor->descriptor(), user: $user);
}

public function publish(array $package): array {
    return $this->storeService->publish(
        descriptor: $this->descriptor->descriptor(),
        payload: $this->registryObject->build(package: $package)
    );
}
```

The outcome constants point at `GenericStoreService`: `OUTCOME_OK`, `OUTCOME_NOT_CONFIGURED`,
`OUTCOME_UNREACHABLE`, `OUTCOME_REJECTED` (`store_rejected`) and `OUTCOME_TOO_LARGE`
(`too_large`) keep the strings learniq already returns, so its frontend does not change.

`lib/Controller/StoreController.php::publish()` keeps `requireAction(ACTION_PUBLISH)`,
adds `mayPublish($user)` (403 when false), and adds three rows to `PUBLISH_STATUS`:
`not_publishable` => 500 (a learniq defect), `rate_limited` => 429 and
`store_invalid_response` => 502. `CourseStoreUrlGuard` and its psalm stub entry are
deleted; `tests/Stubs/AppHost/Service/GenericStoreService.php` gains the `publish()`
signature. With no `IClientService` and no objects-API URL left in learniq's `lib/`,
gate 62 passes.

## Seed Data

None. This change adds no schema and no register; it writes to whatever schema the
consuming app's descriptor names on a remote registry.

## Risks / Trade-offs

- [A registry that answers 201 but stores a different slug] → reported as
  `store_invalid_response`, not `ok`. The object may exist remotely under another slug;
  the log line names both slugs so an administrator can clean it up.
- [An app lists a field holding personal data in `publishFields`] → the plane cannot know
  what a field means. The allowlist makes the decision explicit and reviewable in the
  app's code; learniq's sharing gate runs before the payload exists.
- [Two copies of the slug pattern] → `StorePublishRules::SLUG_PATTERN` and the
  controller's private constant. A follow-up can point the controller at the public one.
- [`StoreActionAuthorizer` gains a constructor argument] → autowired by the container;
  a leaf app that constructs it by hand breaks at construction, loudly. None does today
  (`git grep 'new StoreActionAuthorizer'` finds only the unit test).

## Migration Plan

Additive. No data migration. Rollback is a revert: no descriptor in any app sets the new
lists until learniq adopts them.
