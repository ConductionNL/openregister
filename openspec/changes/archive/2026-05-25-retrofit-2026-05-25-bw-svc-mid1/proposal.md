# Proposal: Reverse-spec a mid-cluster of Service settings/notification/object methods

## Why

A coverage scan flagged 62 uncovered public methods across three
`lib/Service/` sub-trees — `Settings/`, `Notification/`, and `Object/`.
Almost all of them implement behaviors that already-shipped capabilities
describe: the settings handlers are the typed per-domain persistence the
`settings-management` capability specifies; the notification primitives
(digest, read-state, rate limiter, coalescer, VNG envelope, recipient
resolver, annotation dispatcher/validator/installer) are the delivery and
governance layer the `notificatie-engine` capability already enumerates;
the streaming bulk-upsert and reference-cache reset belong to
`reference-existence-validation`; and the lightweight `@self.files` row
attachment belongs to `files-render-extension`. Two object behaviors have
no owning requirement yet — the object **locking** state contract
(`isLocked` / `getLockInfo`, companions to the already-annotated
`lockObject` / `unlock`) and object **merge/deduplication**
(`MergeHandler::mergeObjects`).

This is a reverse-spec retrofit: it documents observed behavior of
already-shipped code without changing it. It annotates the 62 methods —
extending `object-lifecycle` with two new requirements for locking and
merge, and pointing the rest at the existing capabilities — and excludes
the pure value-object accessors of `BatchOperationStatus` as boilerplate.

## What Changes

- **MODIFY** capability `object-lifecycle`: add REQ-011 (object locking
  state contract) and REQ-012 (object merge / deduplication).
- Annotate 51 methods with `@spec` pointers to this change's tasks,
  grouped by capability: `settings-management` (sliced per-domain
  get/update, environment introspection, multitenancy/organisation
  defaults, search-backend + SOLR config), `notificatie-engine`
  (dispatch, annotation validation/install, digest, read-state, rate
  limiting, coalescing, VNG envelope, recipient resolution),
  `object-lifecycle` (locking, merge), `reference-existence-validation`
  (streaming bulk-upsert + request-scope cache reset), and
  `files-render-extension` (lightweight `@self.files` list-row attach).
- **EXCLUDE** the 11 `BatchOperationStatus` value-object methods as
  boilerplate DTO accessors (`@spec exclude`) — the class itself is
  already anchored to `reference-existence-validation`.
- No production code behavior changes — annotations and documentation only.

## Counts

- Methods in batch: 62
- Spec'd (annotated against a requirement): 51
- Excluded as boilerplate: 11
- New requirements: 2 (`object-lifecycle` REQ-011, REQ-012)

## Impact

- Affected specs: `object-lifecycle` (2 new requirements).
- Capabilities referenced for annotation (no spec change):
  `settings-management`, `notificatie-engine`,
  `reference-existence-validation`, `files-render-extension`.
- Affected code (annotations only):
  - `lib/Service/Settings/ConfigurationSettingsHandler.php`
  - `lib/Service/Settings/FileSettingsHandler.php`
  - `lib/Service/Settings/SolrSettingsHandler.php`
  - `lib/Service/Notification/AnnotationNotificationDispatcher.php`
  - `lib/Service/Notification/NotificationAnnotationValidator.php`
  - `lib/Service/Notification/NotificationsAnnotationInstaller.php`
  - `lib/Service/Notification/NotificationCoalescer.php`
  - `lib/Service/Notification/NotificationDigest.php`
  - `lib/Service/Notification/NotificationReadState.php`
  - `lib/Service/Notification/RateLimiter.php`
  - `lib/Service/Notification/RecipientResolverInterface.php`
  - `lib/Service/Notification/VngNotificatiesEnvelope.php`
  - `lib/Service/Object/BatchOperationStatus.php` (excludes)
  - `lib/Service/Object/LockHandler.php`
  - `lib/Service/Object/MergeHandler.php`
  - `lib/Service/Object/RenderObject.php`
  - `lib/Service/Object/SaveObject.php`
- No migrations, no API changes, no behavioral change.
