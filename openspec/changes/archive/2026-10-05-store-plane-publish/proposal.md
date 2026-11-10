---
kind: code
depends_on: []
---

# Store plane: publish one object to the registry

## Why

The store plane reads from a registry and never writes to one. `GenericStoreService`
exposes `isConfigured()`, `search()` and `resolve()`, and nothing else.

Learniq needs the write. Lesson sharing (learniq PR 1043, decision D22 of the learniq
round 1 decisions: "Lesson sharing is delivered by OpenRegister's store plane") lets a
teacher send a course package to a shared course registry. With no write path in the
plane, `OCA\Learniq\Service\CourseStore\CourseStorePublisher` builds the objects-API
URL and POSTs to it with its own `IClientService`. It copies the plane's rules by hand:
the SSRF guard, no redirects, the Bearer-only token, 10 second timeouts.

That copy fails hydra gate 62 (store-plane, ADR-080 D2/D3): "builds and fetches an
OpenRegister objects-API URL outside GenericStoreService". The gate is right. A second
copy of the guard chain is a second place to get it wrong, and learniq's own design
(`design.md` D2) says the class "becomes one call" once the plane can write.

## What changes

- `GenericStoreService::publish(StoreDescriptor $descriptor, array $payload)` writes one
  object of the descriptor's schema to the configured registry through the registry's
  objects API (`POST <base>/index.php/apps/openregister/api/objects/<register>/<schema>`).
- It keeps every rule the plane already has: the SSRF guard before any request, no
  redirects, the token only as a Bearer header, 10 second timeouts, generic outcomes,
  upstream detail logged server-side and never returned.
- It refuses when the store is unconfigured, and makes no request then.
- It sends only the fields the descriptor allows. Identity keys (`id`, `uuid`, `@self`)
  never travel, even when a descriptor lists them, so a publish cannot replace an
  object that already lives on the registry.
- It verifies that the object the registry answers with carries the slug that was sent.
- `StoreDescriptor` gains two optional lists, `publishFields` and `publishGroups`, both
  empty by default. An empty list means the descriptor cannot publish, so every existing
  descriptor stays read-only.
- `StoreActionAuthorizer::canPublish()` answers whether a user may publish. Who may
  publish is the consuming app's decision: the app names the groups, typically straight
  from its own ADR-023 action matrix. The plane only enforces that at least one group is
  named, and matches the user against the named groups the way ADR-023 does.
- Two new outcomes: `store_rejected` (the registry answered 4xx) and `too_large` (the
  body is over 20 MiB), next to the existing ones.

No new route. The payload is app-specific (learniq's is gated and consented before it
exists), so the consuming app keeps its own controller and calls the service.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `apphost-store-plane`: adds a write path (publish) with its own requirements for
  refusal, field allowlisting, identity stripping, slug verification, outcome mapping
  and publish authorization.

## Impact

- Code: `lib/AppHost/Service/GenericStoreService.php`, `lib/AppHost/Store/StorePublishRules.php` (new),
  `lib/AppHost/Service/StoreDescriptor.php`, `lib/AppHost/Store/StoreActionAuthorizer.php`.
- Tests: `tests/Unit/AppHost/GenericStoreServiceTest.php`,
  `tests/Unit/AppHost/StoreActionAuthorizerTest.php`, `tests/Unit/AppHost/StorePublishRulesTest.php` (fake client, no network).
- Dependent apps: none break. `StoreDescriptor`'s new parameters are optional and
  default to "cannot publish". `StoreActionAuthorizer` gains a constructor dependency
  (`IGroupManager`), which the container autowires; nobody constructs it by hand
  outside the tests. opencatalogi and softwarecatalog do not use the store plane.
- Learniq: `CourseStorePublisher` replaces its HTTP call with `publish()` and drops
  `IClientService`, `CourseStoreUrlGuard` and its URL builder, which clears gate 62.
  The exact replacement is in `design.md` under "How learniq adopts this".
