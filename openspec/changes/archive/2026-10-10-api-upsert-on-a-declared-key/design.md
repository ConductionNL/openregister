# Design: api-upsert-on-a-declared-key

Read at openregister development c53dd0685c.

## D-1: the key is a declared `refuse` constraint, not fields picked per call

`_upsertOn` names one entry of the schema's uniqueness constraints as `UniqueConstraintEvaluator::constraints($configuration, includeLegacy: true)` returns them (`lib/Service/Schemas/UniqueConstraintEvaluator.php:92-121`). The legacy `configuration.unique` key is included and is named by its properties joined with `+` (`:128-139`), so `_upsertOn=gemeentecode+zaaknummer` works on a schema that only has the old key.

Only a constraint with action `refuse` qualifies. Three reasons:

- A `refuse` constraint is the schema owner's statement that the combination identifies one record. A field set picked per call, such as `status`, would update whatever record happened to match first.
- A `refuse` constraint is enforced on every other write path by `UniqueConstraintListener` (`lib/Listener/UniqueConstraintListener.php:122-190`), so no other path can create the duplicates that would make an upsert refuse later.
- A `report` constraint allows duplicates on purpose, so matching on it would be refused as often as it succeeds.

Anything else answers 400 with `{"error": "...", "refuseConstraints": ["zaaksleutel"]}`.

## D-2: one handler, reusing the import's resolver

A new `lib/Service/Object/UpsertOnKeyHandler.php` does four things in order:

1. Resolve the constraint (D-1) and read its property values from the request body. A missing or empty value is a 400 naming the property, the same rule `MatchResolver::buildFilters()` applies to an import row (`lib/Service/Import/MatchResolver.php:181-203`).
2. Call `MatchResolver::resolve()` (`lib/Service/Import/MatchResolver.php:116-164`) with the constraint's properties as the match key. It runs `ObjectService::findAll()` under the caller's session, capped at three candidates (`:55`), and returns the uuids.
3. Decide: zero uuids, create; one uuid, update; more, refuse with 409 listing the uuids.
4. Hand the create or update to `ObjectService::saveObject()` exactly as `create()` does today (`lib/Controller/ObjectsController.php:3354-3364`), passing `uuid:` the matched uuid for an update. `_failIfExists` together with `_upsertOn` is a 400: the two ask opposite things.

`ObjectsController::create()` reads `_upsertOn` from the raw request, next to `_failIfExists` (`:3312-3322`), because the body filter strips `_`-prefixed keys (`:3293-3299`). When it is absent, nothing changes.

## D-3: a failed lookup writes nothing

`MatchResolver` throws `MatchLookupFailedException` when the lookup cannot run (`lib/Service/Import/MatchResolver.php:139-152`), precisely so a failure is not read as "no match". The upsert answers 503 with `Retry-After: 5` and writes nothing. Treating it as no match would create a duplicate of every record the key should have found.

## D-4: one lock per key closes the race

`MatchResolver` and `saveObject()` are two operations. Two calls with the same key can both find nothing and both create. The listener's check is a search too (`lib/Listener/UniqueConstraintListener.php:261-297`), so it does not close that window on its own.

The handler takes an exclusive lock through `OCP\Lock\ILockingProvider` on the synthetic path `openregister/upsert/{registerId}/{schemaId}/{sha256(constraint name and values)}` before step 2 and releases it after step 4, in a `finally`. A second call with the same key waits for the lock (Nextcloud's default wait), then finds the record the first call created and updates it. Calls with different keys never wait on each other. On an instance without memcache locking, Nextcloud's database locking provider serves the same interface.

## D-5: RBAC and multitenancy decide what the caller sees and changes

- The lookup runs under the caller's RBAC and organisation, because `MatchResolver` calls `findAll()` without overriding them. A record the caller cannot see is not matched.
- If that unseen record holds the key, the create in step 4 is refused by the listener with `unique-constraint-breached` and the uuid of the holder, which today surfaces as a 422 through `HookStoppedException` (`lib/Db/MagicMapper.php:7148-7156`, caught at `lib/Controller/ObjectsController.php:3379-3388`). On the upsert path the handler turns that into 409 `{"error": "A record with this key exists that you cannot change.", "constraint": "zaaksleutel"}` and drops `conflictingObject`, so an upsert cannot be used to learn uuids the caller may not read.
- An update the caller may read but not change is refused by the save path's RBAC as it is today, and answers 403.
- `_upsertOn` on an anonymous request answers 401. `create()` is `@PublicPage` (`lib/Controller/ObjectsController.php:3224`) for public form submissions; letting an anonymous caller overwrite a record by guessing its key is not what those forms are for.

## D-6: the generated document says it

`OasService::createPostOperation()` (`lib/Service/OasService.php:1425`) adds an `_upsertOn` query parameter when the schema declares at least one `refuse` constraint, with the constraint names as its `enum`, and documents 200, 201, 400, 401, 409 and 503 on that operation.

## Declarative-vs-imperative decision

Declarative for the key: the constraint is schema configuration an administrator already edits, and the handler reads it. Imperative for the decision between create and update, because it is one request's control flow, not a rule on the data. No new schema keyword is added.

## Risks

- **Security.** The upsert never updates or names a record the caller cannot read (D-5), and anonymous callers are refused. The lock path is a hash, so key values do not appear in lock tables or logs.
- **Performance.** One capped lookup and one save per call, the same cost as the search-then-write an integration does today in two calls. The lock is held for one save.
- **Legacy duplicates.** A schema that gains a `refuse` constraint over data that already breaks it answers 409 for the affected keys until the data is merged. The 409 lists the uuids so an administrator can find them.
