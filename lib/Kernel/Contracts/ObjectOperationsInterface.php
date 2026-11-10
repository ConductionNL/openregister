<?php

/**
 * ObjectOperationsInterface: the object operations openregister's own code calls.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use Exception;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\Object\RenderSelection;
use OCA\OpenRegister\Service\ObjectMigration;
use OCP\AppFramework\Db\DoesNotExistException as OcpDoesNotExistException;
use OCP\IUser;

/**
 * The ObjectService methods called inside openregister. Other apps type against
 * Contract\ObjectServiceInterface, which this interface leaves unchanged.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The signatures are ObjectService's, which carries the same flags
 */
interface ObjectOperationsInterface {

    /**
     * Run a callable as a trusted system operation.
     *
     * For app-initiated maintenance that legitimately runs without a user
     * session — repair steps triggered from web requests, event listeners
     * reacting to webcron-created objects, boot-time migrations. Inside the
     * callable, RBAC treats the caller as a trusted system principal
     * (mirroring the existing CLI-cron trust) instead of denying every write
     * as anonymous. The elevation is scoped strictly to the callable and is
     * released even when it throws.
     *
     * Only pass operations whose inputs originate from code or the app's own
     * shipped data — never wrap handling of user-supplied request data.
     *
     * 🔴 REACHABILITY BOUNDARY (ADR-099). This is code-initiated only. It MUST
     * NOT be reachable from:
     *
     *  - a flow node,
     *  - a tool invoked by an agent,
     *  - the handling of an inbound request.
     *
     * The reason is not that those callers are untrusted in principle — it is
     * that all three carry user-authored definitions or user-supplied input, and
     * that this method is sitting right next to every place a missing identity
     * makes something fail. An escape hatch that turns a refusal into a success
     * gets taken. Where an identity cannot be resolved the correct outcome is a
     * refusal naming what is missing; escalating to a userless principal instead
     * answers a different question than the one that was asked.
     *
     * The legitimate callers are installation, migration, repair, and seeding of
     * the application's own shipped data — work that genuinely has no user to
     * act for, because a schema migration runs on nobody's behalf.
     *
     * Enforcement is structural first (the service is not injected into node,
     * tool or endpoint classes) and a gate second. `SystemOperationContextBoundaryTest`
     * pins the current call-site set so that adding one is a deliberate act with
     * a visible diff rather than an accident.
     *
     * @param callable $operation The trusted operation to execute.
     *
     * @return mixed Whatever the callable returns.
     *
     * @SuppressWarnings(PHPMD.StaticAccess) SystemOperationContext::run is the canonical scoped-elevation API
     *
     * @spec openspec/specs/delegated-identity/spec.md
     * @spec openspec/specs/rbac-scopes/spec.md
     */
    public function runAsSystem(callable $operation);

    /**
     * Run a callable as a NAMED user, with that user's RBAC and multitenancy.
     *
     * The opposite of runAsSystem(): this narrows rather than elevates. It
     * exists because the read side of the query layer has no acting-user
     * parameter at all — `findAll()` and everything under it resolve the
     * subject from the session, and the two handlers that build the RBAC and
     * organisation predicates (MagicRbacHandler, MagicOrganizationHandler) read
     * `IUserSession::getUser()` directly at roughly a dozen points. A caller who
     * has an explicit user in hand therefore has nowhere to put it.
     *
     * Threading a `?IUser` through would mean changing about twenty signatures
     * across six layers plus OrganisationService and its UID-keyed caches, and
     * missing any one of them yields a query that silently disagrees with the
     * others. Setting the session subject for the duration of the call moves
     * every one of those readers in lockstep instead, including the caches,
     * which are keyed by UID and so stay correct by construction.
     *
     * This is the pattern the background jobs already use — see
     * ActorForwardedJob, which sets the actor, runs, and restores in a
     * `finally` so a cron process never carries one job's identity into the
     * next. Restoring the PREVIOUS user rather than clearing means nesting
     * composes correctly.
     *
     * This does not grant anything. If the named user cannot see a row, neither
     * can the callable.
     *
     * 🔴 `setVolatileActiveUser()`, NOT `setUser()`. The two differ in one way
     * that matters here: `setUser()` also writes `user_id` into the PHP session,
     * and `lib/base.php` starts a real session on every request but
     * `status.php`. A `finally` covers a throw, but not a fatal and not an
     * `exit()` — so with `setUser()` a dying request leaves the acting identity
     * written into the caller's session, and the response carries a session
     * cookie for it. `setVolatileActiveUser()` (Nextcloud 29.0+; every fleet app
     * declares `min-version="32"`) sets the active user and nothing else, so
     * there is no persisted state to leak in the first place. The restore below
     * is then a correctness measure for the rest of THIS request rather than the
     * only thing standing between a scope and a hijacked session.
     *
     * This is a workaround for a missing parameter, not the end state. ADR-099
     * keeps threading an acting user through the query layer as the target and
     * records why it is not reachable in one change.
     *
     * @param IUser    $user      The user to act as.
     * @param callable $operation The operation to execute as that user.
     *
     * @return mixed Whatever the callable returns.
     *
     * @spec openspec/specs/delegated-identity/spec.md
     * @spec openspec/specs/rbac-scopes/spec.md
     */
    public function runAs(IUser $user, callable $operation);

    /**
     * Set the current register context.
     *
     * @param Register|string|int $register The register object or its ID/UUID
     *
     * @return static Returns self for method chaining
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    public function setRegister(Register | string | int $register): static;

    /**
     * Set the current schema context.
     *
     * @param Schema|string|int $schema The schema object or its ID/UUID
     *
     * @return static Returns self for method chaining
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    public function setSchema(Schema | string | int $schema): static;

    /**
     * Get the register entity resolved by the last setRegister() call.
     *
     * Exposes the already-resolved entity so callers (e.g. controllers that
     * resolved slugs via setRegister()) can reuse it instead of re-fetching
     * the same register from the database.
     *
     * @return Register|null The current register entity, or null when no register context is set.
     *
     * @spec exclude Context getter exposing the entity resolved by setRegister(); no business rule.
     */
    public function getCurrentRegisterEntity(): ?Register;

    /**
     * Get the schema entity resolved by the last setSchema() call.
     *
     * Exposes the already-resolved entity so callers (e.g. controllers that
     * resolved slugs via setSchema()) can reuse it instead of re-fetching
     * the same schema from the database.
     *
     * @return Schema|null The current schema entity, or null when no schema context is set.
     *
     * @spec exclude Context getter exposing the entity resolved by setSchema(); no business rule.
     */
    public function getCurrentSchemaEntity(): ?Schema;

    /**
     * Set the current object context.
     *
     * @param ObjectEntity|string|int $object The object entity or its ID/UUID
     *
     * @return static Returns self for method chaining
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    public function setObject(ObjectEntity | string | int $object): static;

    /**
     * Get the current object context.
     *
     * @return ObjectEntity|null The current object entity or null if not set.
     *
     * @spec exclude Context getter returning the current object field; no business rule.
     */
    public function getObject(): ?ObjectEntity;

    /**
     * Finds an object by ID or UUID and renders it.
     *
     * @param int|string             $id            The object ID or UUID.
     * @param ObjectFindOptions|null $options       Register, schema, extend and files, plus `render`
     *                                              (false when the caller performs its own single render,
     *                                              e.g. ObjectsController::show(); permission checks and
     *                                              read logging still run) and `audit` (false for
     *                                              machine-to-machine reads inside one operation).
     * @param bool                   $_rbac         Whether to apply RBAC checks (default: true).
     * @param bool                   $_multitenancy Whether to apply multitenancy filtering (default: true).
     *
     * @return ObjectEntity|null The rendered object (or the raw entity when `render` is false) or null.
     *
     * @throws Exception If the object is not found.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex permission and context handling requires multiple branches
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple optional parameters create many execution paths
     *
     * @spec exclude Facade coordinating GetObject + permission check + RenderObject handlers;
     *   read/RBAC/render behavior owned by object-interactions / rbac-scopes / files-render-extension.
     */
    public function find(
    	int | string $id, ?ObjectFindOptions $options=null, bool $_rbac=true, bool $_multitenancy=true
    ): ?ObjectEntity;

    /**
     * Gets an object by its ID without creating an audit trail.
     *
     * This method is used internally by other operations (like UPDATE) that need to
     * retrieve an object without logging the read action.
     *
     * @param string                 $id            The ID of the object to get.
     * @param ObjectFindOptions|null $options       Register, schema, extend and files; render and audit are ignored.
     * @param bool                   $_rbac         Whether to apply RBAC checks (default: true).
     * @param bool                   $_multitenancy Whether to apply multitenancy filtering (default: true).
     *
     * @return ObjectEntity The retrieved object.
     *
     * @throws Exception If there is an error during retrieval.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec exclude Facade variant of find() that skips audit logging; read behavior owned by object-interactions / audit-trail-immutable.
     */
    public function findSilent(
    	string $id, ?ObjectFindOptions $options=null, bool $_rbac=true, bool $_multitenancy=true
    ): ObjectEntity;

    /**
     * Find all objects matching the configuration.
     *
     * @param array $config        Configuration array containing:
     *                             - limit: Maximum number of
     *                             objects to return - offset:
     *                             Number of objects to skip -
     *                             filters: Filter criteria -
     *                             sort: Sort criteria - search:
     *                             Search term - extend:
     *                             Properties to extend - files:
     *                             Whether to include file
     *                             information - uses: Filter by
     *                             object usage - register:
     *                             Optional register to filter by
     *                             - schema: Optional schema to
     *                             filter by - unset: Fields to
     *                             unset from results - fields:
     *                             Fields to include in results -
     *                             ids: Array of IDs or UUIDs to
     *                             filter by
     * @param bool  $_rbac         Whether to apply RBAC checks (default: true).
     * @param bool  $_multitenancy Whether to apply multitenancy filtering (default: true).
     *
     * @return array Array of objects matching the configuration
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex configuration handling requires multiple branches
     * @SuppressWarnings(PHPMD.NPathComplexity)       Many configuration options create many execution paths
     *
     * @spec exclude Facade preparing config then delegating to the GetObject handler; list/search behavior owned by zoeken-filteren.
     */
    public function findAll(array $config=[], bool $_rbac=true, bool $_multitenancy=true): array;

    /**
     * Counts the number of objects matching the given criteria.
     *
     * @param array $config Configuration array containing:
     *                      - limit: Maximum number of objects to return
     *                      - offset: Number of objects to skip
     *                      - filters: Filter criteria
     *                      - sort: Sort criteria
     *                      - search: Search term
     *                      - extend: Properties to extend
     *                      - files: Whether to include file information
     *                      - uses: Filter by object usage
     *                      - register: Optional register to filter by
     *                      - schema: Optional schema to filter by
     *                      - unset: Fields to unset from results
     *                      - fields: Fields to include in results
     *                      - ids: Array of IDs or UUIDs to filter by
     *
     * @return int The number of matching objects.
     *
     * @throws \Exception If register or schema is not set
     *
     * @spec exclude Facade injecting register/schema context then delegating to ObjectMapper::countAll(); count behavior owned by zoeken-filteren.
     */
    public function count(array $config=[]): int;

    /**
     * Get logs for an object.
     *
     * @param string $uuid          The UUID of the object
     * @param array  $filters       Optional filters to apply
     * @param bool   $_rbac         Whether to apply RBAC checks (default: true).
     * @param bool   $_multitenancy Whether to apply multitenancy filtering (default: true).
     *
     * @return \OCA\OpenRegister\Db\AuditTrail[] Array of log entries
     *
     * @psalm-return array<\OCA\OpenRegister\Db\AuditTrail>
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec exclude Find-then-delegate to GetObject::findLogs(); audit-log read owned by audit-trail-immutable.
     */
    public function getLogs(string $uuid, array $filters=[], bool $_rbac=true, bool $_multitenancy=true): array;

    /**
     * Save a single object (HIGH-LEVEL ORCHESTRATION METHOD)
     *
     * ARCHITECTURAL ROLE:
     * This is a high-level orchestration method that handles context management, permission checks,
     * and delegates the actual saving logic to the SaveObject handler. It manages the application
     * state and cross-cutting concerns before and after the save operation.
     *
     * RESPONSIBILITY SEPARATION:
     * - ObjectService.saveObject() = Context setup, RBAC, state management, rendering
     * - SaveObject.saveObject() = Actual saving logic, relations, validation, database operations
     *
     * WORKFLOW:
     * 1. Set register/schema context
     * 2. Handle ObjectEntity input conversion
     * 3. Perform RBAC permission checks
     * 4. Delegate to SaveObject handler for actual saving
     * 5. Render and return the result
     *
     * FOR BULK OPERATIONS: Use saveObjects() method for optimized bulk processing
     *
     * @param array|ObjectEntity       $object        The object data to save or ObjectEntity instance
     * @param array|null               $extend        Properties to extend the object with
     * @param Register|string|int|null $register      The register object or its ID/UUID
     * @param Schema|string|int|null   $schema        The schema object or its ID/UUID
     * @param string|null              $uuid          The UUID of the object to update (if updating)
     * @param bool                     $_rbac         Whether to apply RBAC checks (default: true)
     * @param bool                     $_multitenancy Whether to apply multitenancy filtering (default: true)
     * @param bool                     $silent        Whether to skip audit trail creation and events (default: false)
     * @param bool                     $_validation   Whether to validate the object against its schema (default: true).
     *                                                Was hardcoded true here, so a bulk importer with pre-validated
     *                                                rows had no way to decline re-validating every one of them.
     * @param array|null               $uploadedFiles Uploaded files from multipart/form-data (optional)
     * @param IUser|null               $currentUser   Explicit acting user for `@self.folder` access checks
     * @param bool                     $failIfExists  Insert-only: throw ObjectExistsException rather than update when taken (default: false = upsert)
     * @param bool                     $_unowned      Stamp the SYSTEM identity as owner even when a user session
     *                                                exists. For a genuinely anonymous write — a citizen submitting
     *                                                a form on a public portal with no account — where the row must
     *                                                not be attributed to whoever happens to be signed into the
     *                                                instance in that browser. Defaults to false, so nothing changes
     *                                                for any existing caller, and no controller forwards it, so no
     *                                                REST request can ask to be anonymised.
     *                                                (forwarded to `ensureObjectFolder` → `assertObjectFolderAccessible`).
     *                                                Defaults to null → `IUserSession::getUser()` resolution.
     *                                                Non-HTTP callers (cron, import pipelines, event listeners)
     *                                                MUST pass an explicit user to avoid the
     *                                                default-deny fall-through on every folder-bound save.
     * @param bool                     $_dedupOverride Save through a blocking duplicate match, when the caller is
     *                                                entitled to. Read from the RAW request by ObjectsController,
     *                                                because the body filter there strips `_`-prefixed keys.
     * @param string|null              $_owner        An explicit owner for a NEW object (a draft's scope value such as
     *                                                `portal:<subjectRef>`, decision 195). Service-level only: no
     *                                                controller forwards it. An update keeps the stored owner.
     *
     * @return ObjectEntity The saved and rendered object
     *
     * @throws Exception If there is an error during save
     *
     * @TODO Add property-level RBAC validation here
     * Before saving object data, check if user has permission to create/update specific properties
     * based on property-level authorization arrays in the schema.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Save options are flag-driven; `$currentUser` was added for `@self.folder` access checks.
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    public function saveObject(
    	array | ObjectEntity $object, ?array $extend=[], Register | string | int | null $register=null,
    	Schema | string | int | null $schema=null, ?string $uuid=null, bool $_rbac=true, bool $_multitenancy=true, bool $silent=false,
    	bool $_validation=true, ?array $uploadedFiles=null, ?IUser $currentUser=null, bool $failIfExists=false, bool $_unowned=false,
    	bool $_dedupOverride=false, ?string $_owner=null
    ): ObjectEntity;

    /**
     * Delete an object, optionally scoped to a (register, schema) magic table.
     *
     * When BOTH `$register` and `$schema` are supplied, the deletion is scoped to
     * exactly one magic table (`oc_openregister_table_{registerId}_{schemaId}`):
     * the lookup uses `MagicMapper::find($identifier, $register, $schema, includeDeleted: true)`
     * which targets a single table and throws `DoesNotExistException` if the
     * UUID is not present in that scope. A UUID that lives in a DIFFERENT
     * `(register, schema)` magic table MUST NOT be touched. See #1638.
     *
     * When EITHER `$register` or `$schema` is null, the legacy unscoped
     * cross-table lookup (`findAcrossAllSources`) is used — preserves backward
     * compatibility for the dozens of callers passing only `$uuid`. The
     * unscoped form is soft-deprecated: prefer the scoped signature for new
     * call sites so the storage layer can refuse cross-scope deletes by
     * construction.
     *
     * @param string                   $uuid            The UUID of the object to delete.
     * @param Register|string|int|null $register        Optional register scope (object, ID, UUID, or slug).
     *                                                  When non-null AND `$schema` is non-null, the lookup
     *                                                  targets exactly that magic table.
     * @param Schema|string|int|null   $schema          Optional schema scope (object, ID, UUID, or slug).
     *                                                  See `$register` — both must be supplied for the
     *                                                  scoped path.
     * @param bool                     $_rbac           Whether to apply RBAC checks (default: true).
     * @param bool                     $_multitenancy   Whether to apply multitenancy filtering (default: true).
     * @param bool                     $_retentionSweep Internal flag set by ArchivalRetentionTask
     *                                                  to bypass the archival-immutability gate.
     *                                                  Reachable only via PHP DI; no HTTP surface
     *                                                  exposes it. Defaults to false.
     * @param IUser|null               $currentUser     Explicit acting user for the RBAC `delete`
     *                                                  check. Defaults to null → the permission
     *                                                  subject is resolved from `IUserSession`,
     *                                                  which is today's behaviour for every HTTP
     *                                                  caller. A sessionless caller (flow run, cron,
     *                                                  import pipeline) MUST pass one: anonymous is
     *                                                  default-deny, so without it the delete is
     *                                                  neither attributable nor permitted.
     * @param bool                     $permanent       Destroy the row rather than tombstone it, so
     *                                                  its identifier becomes reusable. Defaults to
     *                                                  false — the soft delete every existing caller
     *                                                  already gets, and the thing that makes a
     *                                                  mistaken delete recoverable. Reserve it for
     *                                                  rows that are a CLAIM rather than a record;
     *                                                  see the parameter's full note on
     *                                                  `DeleteObject::deleteObject()` and
     *                                                  openregister#2459.
     *
     * @return bool Whether the deletion was successful
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If `$register` and `$schema` are both supplied
     *                                                    and the UUID is not present in that scope (even
     *                                                    if it exists in another magic table).
     * @throws \Exception If user does not have delete permission.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/specs/archival-annotation-vocabulary/spec.md
     * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
     */
    public function deleteObject(
    	string $uuid, Register | string | int | null $register=null, Schema | string | int | null $schema=null, bool $_rbac=true,
    	bool $_multitenancy=true, bool $_retentionSweep=false, ?IUser $currentUser=null, bool $permanent=false
    ): bool;

    /**
     * Build a search query from request parameters for faceting-enabled methods
     *
     * This method builds a query structure compatible with the searchObjectsPaginated method
     * which supports faceting, facetable field discovery, and all other search features.
     *
     * @param array           $requestParams Request parameters from the controller
     * @param int|string|null $register      Optional register identifier (should be resolved numeric ID)
     * @param int|string|null $schema        Optional schema identifier (should be resolved numeric ID)
     * @param array|null      $ids           Optional array of specific IDs to filter
     *
     * @psalm-param   array<string, mixed> $requestParams
     * @phpstan-param array<string, mixed> $requestParams
     *
     * @return array<string, mixed> Query array containing:
     *                               - @self: Metadata filters (register, schema, etc.)
     *                               - Direct keys: Object field filters
     *                               - _limit: Maximum number of items per page
     *                               - _offset: Number of items to skip
     *                               - _page: Current page number
     *                               - _order: Sort parameters
     *                               - _search: Search term
     *                               - _extend: Properties to extend
     *                               - _fields: Fields to include
     *                               - _filter/_unset: Fields to exclude
     *                               - _facets: Facet configuration
     *                               - _facetable: Include facetable field discovery
     *                               - _ids: Specific IDs to filter
     *
     * @psalm-return   array<string, mixed>
     * @phpstan-return array<string, mixed>
     *
     * A reference that names no register or schema is REFUSED here rather than
     * answered with an empty page, which is what the int-cast used to do
     * (openregister#3990). The published contract in lib/Contract/ is mirrored
     * in hydra-gates and is left untouched on purpose: changing it means
     * changing both copies in one change (ADR-084).
     *
     * @throws \OCA\OpenRegister\Exception\RegisterNotFoundException When the register reference names no register.
     * @throws \OCA\OpenRegister\Exception\SchemaNotFoundException When the schema reference names no schema.
     *
     * @spec exclude One-line delegation to SearchQueryHandler::buildSearchQuery(); query-building owned by zoeken-filteren.
     */
    public function buildSearchQuery(
    	array $requestParams, int | string | array | null $register=null, int | string | array | null $schema=null, ?array $ids=null
    ): array;

    /**
     * Search objects using clean query structure
     *
     * This method provides a cleaner search interface that uses the searchObjects
     * method from MagicMapper with proper query structure. It automatically
     * handles metadata filters, object field searches, and search options.
     *
     * **Numeric-ID contract (runtime-schema-api):** `@self.register` and
     * `@self.schema` MUST be numeric IDs (int). Passing a slug string here
     * is the documented foot-gun the OpenBuild smoke test surfaced — it
     * silently returns zero results instead of resolving the slug.
     * Slug-aware callers MUST use {@see ObjectService::searchObjectsBySlug()},
     * which resolves the slugs via the mappers and then delegates here on
     * the fast path. Keeping this method strict means the next misuse
     * fails loudly at the call site rather than silently returning empty.
     *
     * @param array       $query         The search query array containing filters and options
     *                                   - @self: Metadata filters (register, schema, uuid,
     *                                   etc.) - Direct keys: Object field filters for JSON
     *                                   data - _limit: Maximum results to return - _offset:
     *                                   Results to skip (pagination) - _order: Sorting
     *                                   criteria - _search: Full-text search term -
     *                                   _includeDeleted: Include soft-deleted objects -
     *                                   _ids: Array of
     *                                   IDs/UUIDs to filter by - _count: Return count instead
     *                                   of objects (boolean)
     * @param bool        $_rbac         Whether to apply RBAC checks (default: true)
     * @param bool        $_multitenancy Whether to apply multitenancy filtering (default: true)
     * @param array|null  $ids           Optional array of IDs to filter by
     * @param string|null $uses          Optional filter by object usage
     * @param array|null  $views         Optional view IDs to apply
     * @param bool $_viewScopeRequired   Whether the view filter is this caller's ONLY bound.
     *                                   A caller that switches `$_rbac` and `$_multitenancy`
     *                                   off because it carries its own authorization (a
     *                                   published access link naming one view) MUST set this:
     *                                   it makes an unresolvable or non-narrowing view throw
     *                                   instead of silently yielding an unbounded search.
     *
     * @psalm-param array<string, mixed> $query
     *
     * @phpstan-param array<string, mixed> $query
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity[]|int
     *
     * @throws \OCP\DB\Exception If a database error occurs
     * @throws \Exception If `$_viewScopeRequired` is set and the view cannot be applied
     *
     * @psalm-return int<0, max>|list<\OCA\OpenRegister\Db\ObjectEntity>
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Mirrors the `_rbac` / `_multitenancy` convention
     *
     * @spec exclude One-line delegation to QueryHandler::searchObjects(); search behavior owned by zoeken-filteren.
     */
    public function searchObjects(
    	array $query=[], bool $_rbac=true, bool $_multitenancy=true, ?array $ids=null, ?string $uses=null, ?array $views=null,
    	bool $_viewScopeRequired=false
    ): array|int;

    /**
     * Search objects by register and schema slugs
     *
     * Slug-aware bridge to {@see ObjectService::searchObjects()}. Resolves both slugs
     * to numeric IDs via the mappers (scoped to the active organisation by
     * the mappers' standard multi-tenancy filter) and delegates to the
     * numeric-ID search path.
     *
     * This helper exists to close the OpenBuild smoke-test foot-gun where
     * callers passed slugs in `@self.register` / `@self.schema` and got
     * zero results back. The strict numeric-ID contract on `searchObjects`
     * means any future misuse fails loudly at the call site; slug-aware
     * callers (the controller layer, OpenCatalogi, softwarecatalog) get a
     * one-method-call upgrade path.
     *
     * Multi-tenancy honours the same `_multitenancy` flag every other
     * lookup uses — when true (default), slug resolution and the
     * downstream object search are both scoped to the caller's
     * organisation. A slug that exists in another organisation but not
     * the caller's MUST throw `DoesNotExistException` (the mappers do
     * this via the standard organisation filter).
     *
     * @param string $registerSlug  The register slug (must exist in caller's org).
     * @param string $schemaSlug    The schema slug (must exist in caller's org).
     * @param array  $filters       Additional filters merged into the @self block.
     *                              Direct keys like 'status' are merged at the top
     *                              level.
     * @param bool   $_rbac         Whether to apply RBAC checks (default: true).
     * @param bool   $_multitenancy Whether to apply multi-tenancy filter (default: true).
     *
     * @psalm-param array<string, mixed> $filters
     *
     * @phpstan-param array<string, mixed> $filters
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity[]|int Same shape as searchObjects.
     *
     * @throws OcpDoesNotExistException If either slug fails to resolve in the caller's
     *                                  organisation. The exception message identifies
     *                                  which slug (register vs schema) failed.
     * @throws \OCP\DB\Exception        If a database error occurs.
     *
     * @psalm-return int<0, max>|list<\OCA\OpenRegister\Db\ObjectEntity>
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags mirror searchObjects() upstream.
     *
     * @spec exclude Slug-resolution bridge delegating to searchObjects(); search behavior owned by zoeken-filteren.
     */
    public function searchObjectsBySlug(
    	string $registerSlug, string $schemaSlug, array $filters=[], bool $_rbac=true, bool $_multitenancy=true
    ): array|int;

    /**
     * Count objects using clean query structure
     *
     * This method provides an optimized count interface that mirrors the searchObjects
     * functionality but returns only the count of matching objects. It uses the new
     * countSearchObjects method which is optimized for counting operations.
     *
     * @param array<string, mixed> $query         The search query array containing filters and options
     *                                            - @self: Metadata filters (register, schema, uuid,
     *                                            etc.) - Direct keys: Object field filters for JSON
     *                                            data - _includeDeleted: Include soft-deleted objects
     *                                            - _search: Full-text search term
     * @param bool                 $_rbac         Whether to apply RBAC checks (default: true)
     * @param bool                 $_multitenancy Whether to apply multitenancy filtering (default: true)
     * @param array|null           $ids           Optional array of object IDs to filter by
     * @param string|null          $uses          Optional uses parameter for filtering
     *
     * @psalm-param   array<string, mixed> $query
     * @phpstan-param array<string, mixed> $query
     *
     * @return int The number of objects matching the criteria
     *
     * @psalm-return   int
     * @phpstan-return int
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @spec exclude Resolves org context then delegates to ObjectMapper::countSearchObjects(); count behavior owned by zoeken-filteren.
     */
    public function countSearchObjects(array $query=[], bool $_rbac=true, bool $_multitenancy=true, ?array $ids=null, ?string $uses=null): int;

    /**
     * Get facets for objects matching the given criteria
     *
     * This method provides comprehensive faceting capabilities for object data,
     * supporting both metadata facets (like register, schema, dates) and object
     * field facets (like status, category, priority). It uses the new facet
     * handlers for optimal performance and consistency.
     *
     * @param array $query The search query array containing filters and options
     *                     - @self: Metadata filters (register, schema, uuid, etc.)
     *                     - Direct keys: Object field filters for JSON data
     *                     - _search: Full-text search term
     *                     - _facets: Facet configuration (required)
     *
     * @psalm-param array<string, mixed> $query
     *
     * @phpstan-param array<string, mixed> $query
     *
     * @return array The facets for objects matching the criteria
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @psalm-return array<string, mixed>
     *
     * @spec exclude One-line delegation to FacetHandler::getFacetsForObjects(); faceting owned by faceting-configuration.
     */
    public function getFacetsForObjects(array $query=[]): array;

    /**
     * Get facetable fields for discovery (ULTRA-OPTIMIZED)
     *
     * CRITICAL PERFORMANCE OPTIMIZATION**: This method now uses pre-computed facet
     * configurations stored directly in schema entities instead of runtime analysis.
     * This eliminates the ~15ms overhead for _facetable=true requests.
     *
     * Benefits:
     * - ~15ms eliminated per request (from ~15ms to <1ms)
     * - Consistent facet configurations across requests
     * - No runtime schema analysis overhead
     * - Cached and reusable facet definitions
     *
     * @param array $baseQuery  Base query filters to apply for context
     * @param int   $sampleSize Unused parameter, kept for backward compatibility
     *
     * @psalm-param array<string, mixed> $baseQuery
     * @psalm-param int $sampleSize
     *
     * @phpstan-param array<string, mixed> $baseQuery
     * @phpstan-param int $sampleSize
     *
     * @return array[] Comprehensive facetable field information from schemas
     *
     * @throws \Exception If facetable field discovery fails
     *
     * @psalm-return array{'@self': array, object_fields: array}
     *
     * @spec exclude One-line delegation to FacetHandler::getFacetableFields(); facetable-field discovery owned by faceting-configuration.
     */
    public function getFacetableFields(array $baseQuery=[], int $sampleSize=100): array;

    /**
     * Search objects with pagination and comprehensive faceting support
     *
     * **SEARCH ENGINE**: This method searches the database (magic tables).
     *
     * **PERFORMANCE OPTIMIZATION**: This method intelligently determines which operations
     * are needed based on the query parameters and only executes the required operations.
     * For simple requests without faceting, it skips facet calculations entirely.
     *
     * This method provides a complete search interface with pagination, faceting,
     * and optional facetable field discovery. It supports all the features of the
     * searchObjects method while adding pagination and URL generation for navigation.
     *
     * **Performance Note**: For requests with facets + facetable discovery,
     * consider using `searchObjectsPaginatedAsync()` which runs operations concurrently.
     * For simple requests, this optimized version provides sub-500ms performance.
     *
     * ### Supported Query Parameters
     *
     * **Pagination:**
     * - `_limit`: Maximum results per page (default: 20)
     * - `_offset`: Number of results to skip
     * - `_page`: Page number (alternative to offset)
     *
     * **Search and Filtering:**
     * - `@self`: Metadata filters (register, schema, uuid, etc.)
     * - Direct keys: Object field filters for JSON data
     * - `_search`: Full-text search term
     * - `_includeDeleted`: Include soft-deleted objects
     * - `_ids`: Array of IDs/UUIDs to filter by
     *
     * **Faceting:**
     * - `_facets`: Facet configuration for aggregations (~10ms performance impact)
     * - `_facetable`: Include facetable field discovery (~15ms performance impact)
     *
     * **Rendering:**
     * - `_extend`: Properties to extend
     * - `_fields`: Fields to include
     * - `_filter/_unset`: Fields to exclude
     *
     * ### Facet Types
     *
     * - **terms**: Categorical data with enumerated values and counts
     * - **date_histogram**: Time-based data with configurable intervals (day, week, month, year)
     * - **range**: Numeric data with custom range buckets
     *
     * ### Disjunctive Faceting
     *
     * Facets use disjunctive logic, meaning each facet shows counts as if its own
     * filter were not applied. This prevents facet options from disappearing when
     * selected, providing a better user experience.
     *
     * ### Performance Impact
     *
     * - Simple queries (no facets): Target <500ms response time
     * - With `_facets`: Adds ~10ms to response time
     * - With `_facetable=true`: Adds ~15ms to response time
     * - Combined: Adds ~25ms total
     *
     * Use faceting and discovery strategically for optimal performance.
     *
     * @param array       $query         The search query array containing filters and options
     *                                   - @self: Metadata filters (register, schema, uuid,
     *                                   etc.) - Direct keys: Object field filters for JSON
     *                                   data - _limit: Maximum results to return - _offset:
     *                                   Results to skip (pagination) - _page: Page number
     *                                   (alternative to offset) - _order: Sorting criteria -
     *                                   _search: Full-text search term - _includeDeleted:
     *                                   Include soft-deleted objects - _ids: Array of IDs/UUIDs to
     *                                   filter by - _facets: Facet configuration for
     *                                   aggregations - _facetable: Include facetable field
     *                                   discovery (true/false) - _extend: Properties to
     *                                   extend - _fields: Fields to include - _filter/_unset:
     *                                   Fields to exclude - _queries: Specific fields for
     *                                   legacy facets - _content_search: opt-in bool
     *                                   (default false) to widen `_search` matches to
     *                                   attached-file/object chunk body text via
     *                                   `ChunkMapper::searchByKeyword()`; absent/false is
     *                                   byte-identical to pre-change behaviour (see
     *                                   openspec/specs/zoeken-filteren/spec.md)
     * @param bool        $_rbac         Whether to apply RBAC checks (default: true)
     * @param bool        $_multitenancy Whether to apply multitenancy filtering (default: true)
     * @param bool        $deleted       Whether to include deleted objects (default: false)
     * @param array|null  $ids           Optional array of object IDs to filter by
     * @param string|null $uses          Optional uses parameter for filtering
     * @param array|null  $views         Optional array of view IDs to apply filters from
     *
     * @psalm-param   array<string, mixed> $query
     * @phpstan-param array<string, mixed> $query
     *
     * @return array<string, mixed> Array containing:
     *                              - results: Array of rendered ObjectEntity objects
     *                              - total: Total number of matching objects
     *                              - page: Current page number
     *                              - pages: Total number of pages
     *                              - limit: Items per page
     *                              - offset: Current offset
     *                              - facets: Comprehensive facet data with counts and metadata (if _facets provided)
     *                              - facetable: Facetable field discovery (if _facetable=true)
     *                              - next: URL for next page (if available)
     *                              - prev: URL for previous page (if available)
     *
     * @psalm-return   array<string, mixed>
     * @phpstan-return array<string, mixed>
     *
     * @throws \OCP\DB\Exception If a database error occurs
     * @throws \Exception If the search fails and cannot be recovered
     *
     * @spec exclude Facade routing the unified paginated/faceted search to the query + facet handlers;
     *   behavior owned by zoeken-filteren / faceting-configuration.
     */
    public function searchObjectsPaginated(
    	array $query=[], bool $_rbac=true, bool $_multitenancy=true, bool $deleted=false, ?array $ids=null, ?string $uses=null,
    	?array $views=null
    ): array;

    /**
     * Returns the current schema
     *
     * @deprecated
     *
     * @return int The current schema
     *
     * @spec exclude Deprecated context getter returning the current schema id; no business rule.
     */
    public function getSchema(): int;

    /**
     * Returns the current register
     *
     * @deprecated
     *
     * @return int
     *
     * @spec exclude Deprecated context getter returning the current register id; no business rule.
     */
    public function getRegister(): int;

    /**
     * Returns all registers with their schemas expanded.
     *
     * @return array List of registers, each with a 'schemas' array of schema objects.
     *
     * @spec exclude Read-only lister returning registers with their schemas inlined; no business rule.
     */
    public function getRegisters(): array;

    /**
     * Renders the rendered object.
     *
     * A top-level render: depth 0, nothing preloaded.
     *
     * @param ObjectEntity         $entity        The entity to be rendered
     * @param RenderSelection|null $selection     The extend, filter, fields and unset lists; null renders the plain object
     * @param bool                 $_rbac         Whether to apply RBAC checks (default: true)
     * @param bool                 $_multitenancy Whether to apply multitenancy filtering (default: true)
     *
     * @return array Rendered entity data
     *
     * @spec exclude Facade delegating object rendering to the render handler;
     *   render contract owned by files-render-extension / schema-driven-read-coercion.
     */
    public function renderEntity(ObjectEntity $entity, ?RenderSelection $selection=null, bool $_rbac=true, bool $_multitenancy=true): array;

    /**
     * Get the objects cache containing all extended/related objects indexed by UUID.
     *
     * This method returns all objects that were loaded during rendering (via _extend).
     * Objects are indexed by their UUID for easy lookup by the frontend.
     * Should be called after renderEntity() to get the extended objects.
     *
     * @return array<string, array> Objects indexed by UUID
     */
    public function getExtendedObjects(): array;

    /**
     * Clear the created sub-objects cache.
     *
     * Should be called before processing a new parent object.
     *
     * @return void
     *
     * @spec exclude One-line delegation to SaveObject::clearCreatedSubObjects(); cache-reset plumbing.
     */
    public function clearCreatedSubObjects(): void;

    /**
     * Handle validation exceptions
     *
     * @param ValidationException|CustomValidationException $exception The exception to handle
     *
     * @return \OCP\AppFramework\Http\JSONResponse JSON error response
     *
     * @deprecated
     *
     * @spec exclude Deprecated one-line delegation to ValidateObject::handleValidationException(); error-shaping plumbing.
     */
    public function handleValidationException(ValidationException|CustomValidationException $exception): \OCP\AppFramework\Http\JSONResponse;

    /**
     * Lock an object
     *
     * Locks an object to prevent concurrent modifications.
     *
     * @param string      $identifier Object ID or UUID
     * @param string|null $process    Process ID (for tracking who locked it)
     * @param int|null    $duration   Lock duration in seconds
     * @param bool        $advisory   When true, treat the identifier as a synthetic
     *                                pre-creation key and take the appConfig-backed
     *                                advisory lock without scanning object tables
     * @param string|null $runUuid    Flow run taking the lock, for a run-scoped lock
     *                                that refuses every other caller including the
     *                                run's own runAs user
     * @param string|null $nodeId     Flow node that took it, recorded for the sweep
     *
     * @return array Lock information
     *
     * @throws \Exception If lock operation fails
     *
     * @spec exclude One-line delegation to lock handler; lock behavior owned by object-lifecycle.
     */
    public function lockObject(
    	string $identifier, ?string $process=null, ?int $duration=null, bool $advisory=false, ?string $runUuid=null, ?string $nodeId=null
    ): array;

    /**
     * Unlock an object
     *
     * Removes the lock from an object, allowing other processes to modify it.
     *
     * @param string|int $identifier The object to unlock
     * @param bool       $advisory   When true, release the appConfig-backed advisory
     *                               lock for this synthetic key without scanning tables
     * @param string|null $runUuid   Flow run releasing the lock, for a run-scoped lock
     *
     * @return bool True if unlocked successfully
     *
     * @throws \Exception If unlock operation fails
     *
     * @spec exclude One-line delegation to lock handler; unlock behavior owned by object-lifecycle.
     */
    public function unlockObject(string|int $identifier, bool $advisory=false, ?string $runUuid=null): bool;

    /**
     * Bulk Save Operations Orchestrator (HIGH-PERFORMANCE BULK PROCESSING)
     *
     * ARCHITECTURAL ROLE:
     * This is the primary bulk operations orchestrator that coordinates high-performance bulk saving
     * of multiple objects. It implements advanced performance optimizations including schema analysis
     * caching, memory optimization, single-pass processing, and batch database operations.
     *
     * PERFORMANCE OPTIMIZATIONS IMPLEMENTED:
     * 1. ✅ Eliminate redundant object fetch after save - reconstructed from existing data
     * 2. ✅ Consolidate schema cache - single persistent cache across operations
     * 3. ✅ Batch writeBack operations - bulk UPDATEs instead of individual calls
     * 4. ✅ Single-pass inverse relations - combined scanning and applying
     * 5. ✅ Optimize object transformation - in-place operations, minimal copying
     * 6. ✅ Comprehensive schema analysis - single pass for all requirements
     * 7. ✅ Memory optimization - pass-by-reference, selective updates
     *
     * RESPONSIBILITY SEPARATION:
     * - ObjectService.saveObjects() = Bulk orchestration, performance optimization, chunking
     * - SaveObject methods = Individual object complexities (cascading, writeBack)
     * - MagicMapper.saveObjects() = Actual database bulk operations
     *
     * WORKFLOW:
     * 1. Comprehensive schema analysis and caching
     * 2. Memory-optimized object preparation with relation processing
     * 3. Optional validation with minimal copying
     * 4. In-place format transformation
     * 5. Batch database operations
     * 6. Optimized inverse relation processing
     * 7. Bulk writeBack operations
     *
     * FOR INDIVIDUAL OBJECTS: Use saveObject() method for full feature set
     *
     * PERFORMANCE GAINS:
     * - Database calls: ~60-70% reduction
     * - Memory usage: ~40% reduction
     * - Time complexity: O(N*M*P) → O(N*M)
     * - Processing speed: 2-3x faster for large datasets
     *
     * @param array                    $objects        Array of objects in serialized format
     * @param Register|string|int|null $register       Optional register filter for validation
     * @param Schema|string|int|null   $schema         Optional schema filter for validation
     * @param bool                     $_rbac          Whether to apply RBAC filtering
     * @param bool                     $_multitenancy  Whether to apply multi-organization filtering
     * @param bool                     $validation     Whether to validate objects against schema definitions
     * @param bool                     $events         Whether to dispatch object lifecycle events
     * @param bool                     $deduplicateIds Whether to deduplicate objects with same ID
     * @param bool                     $enrich         Whether to enrich objects with metadata
     * @param bool                     $_audit         Whether to write audit trail rows (bulk mirror of `silent`)
     *
     * @throws \InvalidArgumentException If required fields are missing from any object
     * @throws \OCP\DB\Exception If a database error occurs during bulk operations
     *
     * @psalm-param   array<int, array<string, mixed>> $objects
     * @phpstan-param array<int, array<string, mixed>> $objects
     *
     * @return array Comprehensive bulk operation results with statistics and categorized objects
     *
     * @phpstan-return array<string, mixed>
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Every argument after $schema is a per-call
     *   policy switch that the single-object saveObject() also takes, and the two paths have to
     *   offer the same switches or a caller cannot move between them — which is exactly what the
     *   `$_audit` flag exists for. Collapsing them into an options object is worth doing, but it
     *   is a change to BOTH save paths and all their callers, not to this signature alone.
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    public function saveObjects(
    	array $objects, Register|string|int|null $register=null, Schema|string|int|null $schema=null, bool $_rbac=true,
    	bool $_multitenancy=true, bool $validation=false, bool $events=false, bool $deduplicateIds=true, bool $enrich=true,
    	bool $_audit=true
    ): array;

    /**
     * Migrate objects between registers and/or schemas
     *
     * This method migrates multiple objects from one register/schema combination
     * to another register/schema combination with property mapping.
     *
     * @param ObjectMigration $migration Which objects move, from where to where, and the target-to-source property mapping.
     *
     * The caller needs create permission on the target schema. Only the source
     * objects the caller may read (RBAC and multitenancy of the source table) and
     * update are moved; any other requested id is reported as not found.
     *
     * @return array Migration report with success status, statistics, details, warnings, and errors.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If register or schema not found.
     * @throws \InvalidArgumentException If invalid parameters provided.
     * @throws NotAuthorizedException If the caller may not create objects in the target schema.
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    public function migrate(ObjectMigration $migration): array;

    /**
     * Perform bulk delete operations on objects by UUID
     *
     * EVERY DELETE HERE IS A SOFT DELETE. This docblock used to claim the loop
     * escalated to a hard delete for a row already carrying a deleted timestamp;
     * it does not, and never did — it calls `DeleteObject::deleteObject()` without
     * `permanent: true`, which is the only thing that destroys a row. The claim
     * was unfalsifiable while the loop ran zero iterations. Deleting a trashed row
     * again re-tombstones it and reports success. Destroying a row for good is
     * `DELETE /api/deleted/{uuid}` or `occ openregister:objects:purge`, both of
     * which refuse an archival record (openregister#3428).
     *
     * EVERY REQUESTED UUID LANDS IN EXACTLY ONE OUTCOME BUCKET: the union of
     * 'deleted_uuids' and 'skipped_uuids' is the submitted set. It used not to be
     * — a UUID the permission filter removed, and a UUID whose handler answered
     * false rather than throwing, left the loop in neither — so a caller could be
     * told `requested 1, deleted 0, skipped 0` about a row that is still there.
     *
     * @param array $uuids         Array of object UUIDs to delete
     * @param bool  $_rbac         Whether to apply RBAC filtering
     * @param bool  $_multitenancy Whether to apply multi-organization filtering
     *
     * @return array Associative array with 'deleted_uuids', 'skipped_uuids',
     *               'skipped_reasons' and 'cascade_count' keys.
     *               'deleted_uuids' contains UUIDs of successfully deleted objects.
     *               'skipped_uuids' contains UUIDs skipped by a gate, a RESTRICT
     *               constraint or an error; it is the complement of 'deleted_uuids'.
     *               'skipped_reasons' maps each skipped UUID to a structured
     *               {error, message} pair, so an archival refusal is legible as
     *               something other than a RESTRICT block.
     *               'cascade_count' contains total count of objects affected by cascade operations.
     *
     * @phpstan-param  array<int, string> $uuids
     * @psalm-param    array<int, string> $uuids
     * @phpstan-return array{
     *     deleted_uuids: array<int, string>,
     *     skipped_uuids: array<int, string>,
     *     skipped_reasons: array<string, array<string, mixed>>,
     *     cascade_count: int
     * }
     * @psalm-return array{
     *     deleted_uuids: array<int, string>,
     *     skipped_uuids: array<int, string>,
     *     skipped_reasons: array<string, array<string, mixed>>,
     *     cascade_count: int
     * }
     *
     * @spec exclude Bulk-delete loop over deleteHandler->deleteObject(); per-object RESTRICT/CASCADE behavior owned by referential-integrity.
     */
    public function deleteObjects(array $uuids=[], bool $_rbac=true, bool $_multitenancy=true): array;

    /**
     * Delete all objects belonging to a specific schema
     *
     * Every object is snapshotted to the (hash-chained) audit trail before its row is
     * touched, then the rows are removed in one bulk statement via MagicMapper.
     *
     * The work itself lives in SchemaDeletionService, which is shared with the schema
     * cascade (`DELETE /api/schemas/{id}?deleteObjects=true`). It is resolved lazily
     * from the container rather than constructor-injected: ObjectService is
     * constructed on virtually every request, and this is the only method that needs
     * it.
     *
     * @param RegisterSchemaRef $table      The register and schema ids whose objects are deleted
     * @param bool              $hardDelete Whether to force hard delete (default: false)
     *
     * @return (int|string[])[]
     *
     * @throws \Exception If the deletion operation fails
     *
     * @phpstan-return array{deleted_count: int, deleted_uuids: array<int, string>, schema_id: int}
     *
     * @psalm-return array{deleted_count: int<min, max>, deleted_uuids: array<int, string>, schema_id: int}
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The hard/soft toggle mirrors the mapper primitive it wraps.
     *
     * @spec openspec/changes/schema-delete-cascade/specs/runtime-schema-api/spec.md
     */
    public function deleteObjectsOfTable(RegisterSchemaRef $table, bool $hardDelete=false): array;

    /**
     * Delete all objects belonging to a specific register
     *
     * Empties every magic table of every schema the register lists, in one
     * transaction, snapshotting each object to the audit trail first. Refuses the
     * whole request with ArchivalImmutableException, before touching any row, when
     * any of those schemas is archival. The work lives in SchemaDeletionService,
     * resolved lazily for the reason deleteObjectsOfTable() gives.
     *
     * @param int  $registerId The ID of the register whose objects should be deleted
     * @param bool $hardDelete Whether to force hard delete (default: false)
     *
     * @return (int|string[])[]
     *
     * @throws \OCA\OpenRegister\Exception\ArchivalImmutableException If any schema of the register is archival
     * @throws \Exception If the deletion operation fails (nothing is deleted)
     *
     * @phpstan-return array{deleted_count: int, deleted_uuids: array<int, string>, register_id: int}
     *
     * @psalm-return array{deleted_count: int<min, max>, deleted_uuids: array<int, string>, register_id: int}
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The hard/soft toggle mirrors the mapper primitive it wraps.
     *
     * @spec openspec/specs/archival-annotation-vocabulary/spec.md#requirement-schema-wide-object-deletion-is-refused-on-an-archival-schema
     */
    public function deleteObjectsByRegister(int $registerId, bool $hardDelete=false): array;

    /**
     * Get object contracts
     *
     * @param string $objectId Object ID or UUID
     * @param array  $filters  Filters for pagination
     *
     * @return (array|int|mixed)[] Contracts data
     *
     * @psalm-return array{results: array|mixed, total: int<0, max>, limit: 30|mixed, offset: 0|mixed}
     */
    public function getObjectContracts(string $objectId, array $filters=[]): array;

    /**
     * Get objects that this object uses (outgoing relations)
     *
     * @param string $objectId      Object ID or UUID
     * @param array  $query         Search query parameters
     * @param bool   $_rbac         Apply RBAC filters
     * @param bool   $_multitenancy Apply multitenancy filters
     *
     * @return array Results with object entities and pagination info.
     *
     * @throws \Exception If retrieval fails.
     *
     * @spec exclude One-line delegation to RelationHandler::getUses(); outgoing-relation behavior owned by nextcloud-entity-relations.
     */
    public function getObjectUses(string $objectId, array $query=[], bool $_rbac=true, bool $_multitenancy=true): array;

    /**
     * Get objects that use this object (incoming relations)
     *
     * @param string $objectId      Object ID or UUID
     * @param array  $query         Search query parameters
     * @param bool   $_rbac         Apply RBAC filters
     * @param bool   $_multitenancy Apply multitenancy filters
     *
     * @return array Paginated results with referencing objects
     *
     * @throws \Exception If retrieval fails
     *
     * @spec exclude One-line delegation to RelationHandler::getUsedBy(); incoming-relation behavior owned by nextcloud-entity-relations.
     */
    public function getObjectUsedBy(string $objectId, array $query=[], bool $_rbac=true, bool $_multitenancy=true): array;

    /**
     * Vectorize objects in batch
     *
     * @param array|null $_views     Optional view filters
     * @param int        $_batchSize Number of objects to process per batch
     *
     * @return never Vectorization results
     *
     * @throws \Exception If vectorization fails
     *
     * @spec exclude Deprecated throwing stub; disabled pending VectorizationService circular-dependency refactor.
     */
    public function vectorizeBatchObjects(?array $_views=null, int $_batchSize=25);

    /**
     * Get vectorization statistics
     *
     * @param array|null $_views Optional view filters
     *
     * @return never Statistics data
     *
     * @throws \Exception If stats retrieval fails
     *
     * @spec exclude Deprecated throwing stub; disabled pending VectorizationService circular-dependency refactor.
     */
    public function getVectorizationStatistics(?array $_views=null);

    /**
     * List objects with filtering and pagination
     *
     * @param array $query         Search query parameters
     * @param bool  $_rbac         Apply RBAC filters
     * @param bool  $_multitenancy Apply multitenancy filters
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity[]|int
     *
     * @throws \Exception If listing fails
     *
     * @psalm-return int<0, max>|list<\OCA\OpenRegister\Db\ObjectEntity>
     *
     * @spec exclude Facade alias delegating to searchObjects(); listing behavior owned by zoeken-filteren.
     */
    public function listObjects(array $query=[], bool $_rbac=true, bool $_multitenancy=true): array|int;

    /**
     * Create new object
     *
     * @param array $data          Object data
     * @param bool  $_rbac         Apply RBAC checks
     * @param bool  $_multitenancy Apply multitenancy filtering
     *
     * @return ObjectEntity Created object entity
     *
     * @throws \Exception If creation fails
     *
     * @spec exclude Facade delegating to saveObject(); create behavior owned by object-interactions / object-lifecycle.
     */
    public function createObject(array $data, bool $_rbac=true, bool $_multitenancy=true): ObjectEntity;

    /**
     * REPLACE an existing object's data wholesale. This does NOT merge.
     *
     * PUT semantics, inherited from `saveObject()`: `$data` becomes the object's
     * data in full, so a stored property absent from `$data` is written away
     * rather than left alone. There is no read of the existing object here — the
     * commented-out `find()` below is the whole history of that idea — so a
     * one-key payload stores a one-key object and reports success.
     *
     * Callers holding a PARTIAL payload want `patchObject()`, which reads,
     * merges and then saves. Both are published on `ObjectServiceInterface`;
     * the contract's docblock for this method used to say "apply a partial
     * update", which is the opposite of the behaviour and is what sent at least
     * one consuming app down the erasing path.
     *
     * Replace semantics are deliberate and must not change: existing callers
     * pass a complete object and rely on an omitted property being cleared.
     *
     * @param string $objectId      Object ID or UUID
     * @param array  $data          The object's COMPLETE new data. Anything omitted is dropped.
     * @param bool   $_rbac         Apply RBAC checks
     * @param bool   $_multitenancy Apply multitenancy filtering
     *
     * @return ObjectEntity Replaced object entity
     *
     * @throws \Exception If update fails
     *
     * @see ObjectService::patchObject() for the merging counterpart.
     *
     * @spec exclude Facade delegating to saveObject() with id; update behavior owned by object-interactions / object-lifecycle.
     */
    public function updateObject(string $objectId, array $data, bool $_rbac=true, bool $_multitenancy=true): ObjectEntity;

    /**
     * Patch an existing object (PATCH-semantic partial update).
     *
     * This is the fleet's supported PATCH-semantic write path. `saveObject()` is
     * PUT-semantic — a property absent from the payload is written as null — so
     * every partial-write caller either merges first or silently nulls live
     * data. That merge belongs here, once, rather than in each caller.
     *
     * MERGE RULES (RFC 7386 shaped):
     *  - a key present with a non-null value overwrites the stored value;
     *  - a key absent from the payload leaves the stored value untouched;
     *  - a key present with an explicit `null` clears the stored value, so
     *    "unset this" is expressible and distinguishable from "not mentioned";
     *  - two associative arrays merge recursively on the same three rules;
     *  - lists (JSON arrays) are replaced wholesale, never element-merged —
     *    there is no stable identity to merge elements on and a positional
     *    merge corrupts any reordered list.
     *
     * The identifier is NOT cast to int. It is resolved as a uuid, a slug or a
     * numeric id, scoped to exactly one magic table when both `$register` and
     * `$schema` are supplied (the #1638 defect class).
     *
     * The merged result goes through `saveObject()`, so schema validation, the
     * audit trail and event dispatch all apply — a patch is a save of a
     * different shape, not a privileged shortcut around one.
     *
     * @param string                   $objectId      Object id, uuid or slug.
     * @param array                    $data          Partial object data to merge.
     * @param Register|string|int|null $register      Optional register scope (object, id, uuid or slug).
     * @param Schema|string|int|null   $schema        Optional schema scope (object, id, uuid or slug).
     * @param bool                     $_rbac         Whether to apply RBAC checks (default: true).
     * @param bool                     $_multitenancy Whether to apply multitenancy filtering (default: true).
     * @param IUser|null               $currentUser   Explicit acting user, forwarded to `saveObject()`.
     *                                                Non-HTTP callers (cron, flow runs, import pipelines)
     *                                                MUST pass one to avoid the default-deny fall-through.
     *
     * @return ObjectEntity Patched object entity
     *
     * @throws \Exception If patch fails
     *
     * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
     */
    public function patchObject(
    	string $objectId, array $data, Register | string | int | null $register=null, Schema | string | int | null $schema=null,
    	bool $_rbac=true, bool $_multitenancy=true, ?IUser $currentUser=null
    ): ObjectEntity;

    /**
     * Merge objects using MergeHandler.
     *
     * @param string $sourceObjectId Source object ID
     * @param array  $mergeData      Merge data
     *
     * @return array Merge result with details
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex merge logic delegated to handler
     * @SuppressWarnings(PHPMD.NPathComplexity)       Many merge scenarios handled by handler
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Merge operations require comprehensive handling
     *
     * @spec exclude One-line delegation to MergeHandler::mergeObjects(); merge logic owned by the handler.
     */
    public function mergeObjects(string $sourceObjectId, array $mergeData): array;

    /**
     * Validate objects by schema using ValidationHandler (for testing - does not save).
     *
     * @param int $schemaId Schema ID
     *
     * @return array Validation result with valid and invalid objects
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    public function validateObjectsBySchema(int $schemaId): array;

    /**
     * Validate and save one page of a register and schema's objects, updating metadata like _name.
     *
     * This is useful for bulk updating object metadata after schema changes or imports.
     * An unbounded query processes every object.
     *
     * @param RegisterSchemaRef $table The register and schema ids
     * @param ListQuery         $query The page: its limit (or unbounded) and offset
     *
     * @return array{processed: int, updated: int, failed: int, total: int, errors: array} Validation statistics
     *
     * @spec exclude Delegation to ValidationHandler::validateAndSaveObjectsBySchema() with a saveObject callback; handler owns the loop.
     */
    public function validateAndSaveTable(RegisterSchemaRef $table, ListQuery $query): array;

    /**
     * Reset the current register, schema, and object context.
     *
     * Called by external apps (e.g. OpenConnector) before performing a fresh
     * lookup to prevent stale context from a previous request bleeding through.
     *
     * @return void
     *
     * @spec exclude Context-reset setter nulling the current register/schema/object fields; no business rule.
     *
     * @orphaned-write-capability exclude Live, called only from a sibling repo.
     *   openconnector's EndpointService calls it from six sites (lines 1480,
     *   2741, 2750, 3227, 3229 and 3684) before each fresh lookup. CI checks
     *   out this repo alone, so the caller index cannot see them and the method
     *   reads as dead. This is the exact case hydra#106 recorded against this
     *   very method: acting on that verdict would have broken endpoint
     *   rendering.
     */
    public function clearCurrents(): void;
}//end interface
