# Tasks: registry-subscriptions

## 1. Declaration and state

- [x] 1.1 Validate `x-openregister-registry` at schema save.
      `RegistryAnnotationValidator` (`lib/Service/Registry/`), wired as a
      BLOCKING check (unlike most `x-openregister-*` dialects, which only
      warn) in `SchemaMapper::validateRegistryAnnotation()`. Added to
      `Schema::ANNOTATION_VOCABULARY`, or `setConfiguration()` silently
      drops the key on save — see the vocabulary's own comment history for
      why that check exists.
- [x] 1.2 Subscription state table and `@self.registry` marker.
      Table `openregister_registry_subs`
      (`lib/Migration/Version1Date20260911170000.php`), entity/mapper
      `RegistrySubscription`/`RegistrySubscriptionMapper` (`lib/Db/`).
      `@self.registry` materialised via `ObjectEntity::$registryState` +
      `RenderObject::renderEntity()`, the same "materialise unconditionally
      at the render boundary" pattern as `@self.translationCompleteness`
      and `@self._retention` — looked up only when the schema actually
      declares the annotation, so the common case costs no extra query.

## 2. Endpoints and events

- [x] 2.1 Request and end subscription endpoints, dispatching the events.
      `RegistrySubscriptionController::subscribe()`/`unsubscribe()`
      (`POST`/`DELETE` `/api/objects/{register}/{schema}/{id}/registry-subscription`),
      per-object `update` guard via `PermissionHandler::hasPermission()`.
      Dispatches `RegistrySubscriptionRequestedEvent` /
      `RegistrySubscriptionEndedEvent` (the latter added beyond the
      original design — REQ 2 says "each" action dispatches an event, and
      the spec's own scenario only illustrated the request half).
- [x] 2.2 Inbound update endpoint applying owned properties only, audited
      with the registry as actor.
      `RegistryUpdatesController::update()` (`POST /api/registry/{registry}/updates`),
      `RegistrySubscriptionService::applyInboundUpdate()`. The owned-vs-
      supplied decision is a pure class, `RegistryOwnedPropertyGuard`, so
      it is unit-testable without a database. The "registry as actor" audit
      requirement is met with a SECOND, explicit audit row
      (`AuditTrailMapper::createAuditTrailEntry()`, extended with optional
      `$actorId`/`$actorName` params, backward compatible) alongside
      whatever `ObjectService::saveObject()`'s own ordinary-save-path audit
      records for the connector's actual Nextcloud app-password account —
      see design.md addendum below for why.

## 3. Query

- [ ] 3.1 `_registry[state]` and `_registry[updatedBefore]` lenses.
      DEFERRED, deliberately, not silently dropped. The injection point
      exists (`MagicSearchHandler::applyAccessControlFilters()`, beside
      `MagicRbacHandler::applyRbacFilters()` — both build `WHERE`/`EXISTS`
      SQL against the schema's magic table), but wiring a new EXISTS join
      against `openregister_registry_subs` into that hot, heavily-shared
      query path without a live database to verify the generated SQL
      against is not a change to make blind. This is also not what blocks
      dossiq `contacts-domain` task 4.3 (that needs the annotation + state
      + inbound endpoint above, not a list-query filter). Left for a
      follow-up change with DB-backed integration tests.

## 4. Tests

- [x] 4.1 Unit tests for validation and the owned-property guard: real,
      run, and mutation-checked in this session —
      `tests/Unit/Service/Registry/RegistryAnnotationValidatorTest.php` (9
      cases) and `RegistryOwnedPropertyGuardTest.php` (5 cases), both pure
      PHP with no Nextcloud/DB dependency. Lenses excluded (3.1 deferred).
      `tests/Unit/Service/Registry/RegistrySubscriptionServiceTest.php` (10
      cases, mocked collaborators, standard convention for this codebase's
      Service-layer tests) is WRITTEN but could not be EXECUTED in the
      sandbox this change was authored in: it needs `nextcloud/ocp`'s
      classes loaded from a real Nextcloud boot (`../../lib/base.php`),
      which this checkout's location does not provide — confirmed as a
      pre-existing, unmodified-code limitation by running an existing test
      in this repo (`LockHandlerRunLockTest`) and observing the identical
      `Class "OCP\AppFramework\Db\QBMapper" not found` failure. Expected to
      run green under `code-quality.yml`, which boots inside a real
      Nextcloud container.
- [ ] 4.2 `tests/e2e/api-direct/registry-subscriptions.spec.ts`: request a
      subscription, post an inbound update, read the changed property and
      the audit actor. NOT written in this change — it needs a running
      instance with a schema carrying `x-openregister-registry` and a way
      to authenticate as a "connector", neither of which exists in fixture
      form yet. Left for the change that adds the annotation to a real
      consumer schema (dossiq's `brpPerson`/`kvkCompany`, tracked in
      dossiq's `contacts-domain` task 4.3).
