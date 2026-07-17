# Tasks: Retrofit — Reverse-Spec `activity-provider`

All tasks are observation-only retrofit annotations. No behavior change.

## Task 1 — RegisterSetting concrete contract values

- [x] Document the `RegisterSetting` ActivitySettings subclass concrete values: `getIdentifier()='openregister_registers'`, `getGroupIdentifier()='openregister'`, `getName()=t('Register changes')`, `getGroupName()=t('Open Register')`, `getPriority()=52`, `canChangeStream()=true`, `isDefaultEnabledStream()=true`, `canChangeMail()=true`, `isDefaultEnabledMail()=false`. Constructor takes `IL10N $l` (promoted property).

## Task 2 — ActivityProvider integration-surface contract

- [x] Document the `ActivityProvider` integration-provider contract (separate from NC's `IProvider`): `getId()='activity'`, `getLabel()=t('Activity')`, `getIcon()='Timeline'`, `getGroup()='workflow'`, `getRequiredApp()='activity'`, `getStorageStrategy()='query-time'`, `isEnabled()` returns `IAppManager::isInstalled('activity')`. Extends `AbstractIntegrationProvider`, uses `MarkerLookupTrait`.

## Task 3 — ActivityProvider list() marker-lookup convention

- [x] Document `ActivityProvider::list($register, $schema, $objectId, $filters=[])` behavior: when the NC Activity app is disabled, returns `[]`; otherwise queries `oc_activity` via `MarkerLookupTrait::findByMarker()` for rows whose `subject` column contains `[or:{objectId}]`, projects extra columns `type/affecteduser/timestamp/object_id`, and maps each row to the registry leaf-row shape `{id, title, url=/index.php/apps/activity/{id}, data}`.

## Task 4 — ActivityProvider health() response shape

- [x] Document `ActivityProvider::health()` returning `['status' => 'ok'|'unavailable', 'authStatus' => 'configured', 'message' => null|'NC Activity app is not installed']` — `status` is driven by `isEnabled()`, `authStatus` is always `'configured'` because no per-user auth is required.

## Task 5 — Account ActivitySection paginated user-activity feed

- [x] Document the account-page `ActivitySection.vue` behavior: on mount and on filter change, calls `loadActivity()` which resets `offset=0`/`activities=[]` and invokes `fetchActivity()` against `GET /apps/openregister/api/user/me/activity?_limit={limit}&_offset={offset}[&type={typeFilter}]`. Default `limit=25`, type filter options are `['create', 'update', 'delete']`. `loadMore()` increments `offset` by `limit` and appends. `hasMore` is true while `activities.length < total`. Errors are silently swallowed; loading flag toggled in `finally`.
