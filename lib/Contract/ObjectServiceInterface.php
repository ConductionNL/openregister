<?php

/**
 * The data-access surface OpenRegister publishes to consuming apps.
 *
 * @license EUPL-1.2
 * @copyright Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Contract;

use OCP\IUser;

/**
 * OpenRegister's object store, as a consuming app sees it (ADR-022, ADR-083).
 *
 * ## Why this exists
 *
 * A leaf app cannot mock what it cannot load. OpenRegister's concrete classes
 * are absent from a leaf app's standalone composer test environment, so every
 * consuming app either reaches through an untyped `ContainerInterface` --
 * hiding the dependency from readers and tooling alike -- or hand-rolls a
 * double.
 *
 * The doubles are not a hypothetical cost. Measured 2026-08-14, TEN of the
 * sixteen consuming apps already ship one:
 *
 *     opencatalogi 13   docudesk 12   openbuild 12   openconnector 10
 *     hermiq        8   pipelinq  7
 *     softwarecatalog, zaakafhandelapp, scholiq, decidesk: 0
 *
 * Against a real class of 88 methods. Their UNION is 23 -- ten files, ten
 * maintainers, and between them barely a quarter of the surface. The four
 * declaring zero methods are empty shells: they satisfy a type-hint and
 * nothing else, so any call through them is unchecked by construction.
 *
 * That is what this interface replaces: one definition, owned by the app that
 * implements it, which cannot drift from the implementation without PHP
 * refusing to declare the class.
 *
 * ## Scope is measured, not guessed
 *
 * The first draft of this file carried the eight most-called methods. Counted
 * per CALL that looked convincing; counted per CLASS it was not, because a
 * class can only type-hint the interface if EVERY method it calls is on it:
 *
 *     8 methods  ->  587 of 1001 consumer classes ( 58%)
 *
 * 414 classes -- the apps that would have had to keep their container hop --
 * were invisible in the per-call ranking. Re-measured per class, resolving the
 * receiver to OpenRegister's `ObjectService` (two apps ship a local class of
 * the same name, which the first count silently folded in):
 *
 *     829 consumer classes call 28 distinct methods.
 *
 * This interface declares 25 of them, covering 819 of the 829 classes (98.8%).
 * The four it omits are omitted for a reason, and the ten classes they hold
 * back are listed rather than left to be discovered:
 *
 *     renderEntity()     takes a concrete `ObjectEntity`. An interface
 *                        parameter of `ObjectEntityInterface` would be WIDER
 *                        than the implementation accepts, which PHP rejects.
 *                        Widening the implementation instead is a real change
 *                        with real risk, so it is a separate decision.
 *                        Blocks 5: opencatalogi PublicationsController,
 *                        openconnector SynchronizationService, zaakafhandelapp
 *                        ZGWZaak{Lifecycle,Validation}Service and ZGWLogicService.
 *     getMapper()        returns ObjectServiceMapperAdapter -- an OpenRegister
 *                        type. It is an escape hatch that leaks the
 *                        implementation whatever we do here. Blocks 3:
 *                        openconnector EndpointService, LtiNrpsService,
 *                        PdokConnector.
 *     getOpenRegisters() is not ours. It is declared on zaakafhandelapp's OWN
 *                        `ObjectService` -- a locator returning this service.
 *                        Two apps ship a local class of that name, which is
 *                        why receiver resolution matters here. Blocks 3
 *                        openconnector classes, all of which mean their own.
 *     getLockInfo()      is not a method of ObjectService at all; it lives on
 *                        LockHandler. openbuild's VersionPromotionService
 *                        calls it on an ObjectService-typed property and
 *                        suppressed the resulting PHPStan `method.notFound`.
 *                        Wrapped in `catch (Throwable)`, so it has been
 *                        silently returning null rather than locking. Blocks 1,
 *                        and wants fixing on its own terms.
 *
 * ## Types
 *
 * Parameters are narrowed to what a consumer can express -- identifiers, not
 * OpenRegister entities. PHP's parameter contravariance lets the
 * implementation go on accepting `Register|Schema` objects as well, so nothing
 * inside OpenRegister changes.
 *
 * Returns are the entity INTERFACE, satisfied covariantly by the concrete
 * `ObjectEntity`.
 *
 * `saveObject()` takes `array`, not `array|ObjectEntityInterface`. The
 * implementation accepts `array|ObjectEntity`, and `array|ObjectEntity` is
 * NARROWER than `array|ObjectEntityInterface` -- so declaring the union here
 * makes OpenRegister's own class illegal. A compatibility probe caught that
 * before this shipped.
 *
 * ## RBAC
 *
 * `$_rbac` and `$_multitenancy` are part of the contract, not an
 * implementation detail. ADR-022 makes OpenRegister the authorisation boundary
 * for object access; a consumer passing `_rbac: false` is declaring that
 * OpenRegister is NOT authorising the call, and gate-7 reads it that way.
 *
 * ## Changing this file
 *
 * Widening the contract is a deliberate act and should follow a measurement,
 * not a convenience. Narrowing it is a BC break for every consuming app.
 *
 * ## Suppressions
 *
 * The same three PHPMD rules `ObjectService` itself already suppresses, for the
 * same reasons, because THIS FILE CANNOT DIFFER FROM THE SIGNATURES IT
 * DESCRIBES. Splitting `saveObject()` into flag-free methods here would simply
 * make the interface no longer implementable.
 *
 * `$_rbac` and `$_multitenancy` in particular are not incidental flags: ADR-022
 * makes them the authorisation boundary, and gate-7 reads `_rbac: false` as a
 * consumer declaring it has taken that responsibility on. They belong in the
 * contract precisely because they are load-bearing.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   RBAC and multitenancy flags — see above.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Mirrors ObjectService::saveObject().
 * @SuppressWarnings(PHPMD.ExcessivePublicCount)  25 methods, measured — see Scope above.
 */
interface ObjectServiceInterface {

	/**
	 * Persist an object, creating or updating it.
	 *
	 * @param array           $object        The object to store.
	 * @param ?array          $extend        Relations to expand on the result.
	 * @param string|int|null $register      Register id, UUID or slug.
	 * @param string|int|null $schema        Schema id, UUID or slug.
	 * @param ?string         $uuid          The object UUID.
	 * @param bool            $_rbac         Apply register RBAC.
	 * @param bool            $_multitenancy Apply organisation scoping.
	 * @param bool            $silent        Suppress events for this save.
	 * @param bool            $_validation   Validate against the schema.
	 * @param ?array          $uploadedFiles Files uploaded alongside the object.
	 * @param ?IUser          $currentUser   Explicit acting user; null uses the session.
	 * @param bool            $failIfExists  Fail instead of updating when the object already exists.
	 *
	 * @return ObjectEntityInterface The stored object.
	 */
	public function saveObject(
		array $object,
		?array $extend=[],
		string|int|null $register=null,
		string|int|null $schema=null,
		?string $uuid=null,
		bool $_rbac=true,
		bool $_multitenancy=true,
		bool $silent=false,
		bool $_validation=true,
		?array $uploadedFiles=null,
		?IUser $currentUser=null,
		bool $failIfExists=false
	): ObjectEntityInterface;

	/**
	 * Scope subsequent calls to a register.
	 *
	 * @param string|int $register Register id, UUID or slug.
	 *
	 * @return static This service, for chaining.
	 */
	public function setRegister(string|int $register): static;

	/**
	 * Find a single object by id, UUID or slug.
	 *
	 * @param int|string      $id            Object id, UUID or slug.
	 * @param ?array          $_extend       Relations to expand on the result.
	 * @param bool            $files         Include file metadata.
	 * @param string|int|null $register      Register id, UUID or slug.
	 * @param string|int|null $schema        Schema id, UUID or slug.
	 * @param bool            $_rbac         Apply register RBAC.
	 * @param bool            $_multitenancy Apply organisation scoping.
	 * @param bool            $_render       Render the entity before returning it.
	 * @param bool            $_audit        Write an audit-trail entry.
	 *
	 * @return ?ObjectEntityInterface The object, or null when absent or not permitted.
	 */
	public function find(
		int|string $id,
		?array $_extend=[],
		bool $files=false,
		string|int|null $register=null,
		string|int|null $schema=null,
		bool $_rbac=true,
		bool $_multitenancy=true,
		bool $_render=true,
		bool $_audit=true
	): ?ObjectEntityInterface;

	/**
	 * Find every object matching a configuration.
	 *
	 * @param array $config        Filters, limit, offset, sort and search.
	 * @param bool  $_rbac         Apply register RBAC.
	 * @param bool  $_multitenancy Apply organisation scoping.
	 *
	 * @return array The matching objects.
	 */
	public function findAll(
		array $config=[],
		bool $_rbac=true,
		bool $_multitenancy=true
	): array;

	/**
	 * Scope subsequent calls to a schema.
	 *
	 * @param string|int $schema Schema id, UUID or slug.
	 *
	 * @return static This service, for chaining.
	 */
	public function setSchema(string|int $schema): static;

	/**
	 * Search objects with a query.
	 *
	 * @param array   $query         The search query.
	 * @param bool    $_rbac         Apply register RBAC.
	 * @param bool    $_multitenancy Apply organisation scoping.
	 * @param ?array  $ids           Restrict the search to these ids.
	 * @param ?string $uses          Restrict to objects used by this one.
	 * @param ?array  $views         Restrict the search to these views.
	 *
	 * @return array|int Results, or a count when the query asks for one.
	 */
	public function searchObjects(
		array $query=[],
		bool $_rbac=true,
		bool $_multitenancy=true,
		?array $ids=null,
		?string $uses=null,
		?array $views=null
	): array|int;

	/**
	 * Delete an object by UUID.
	 *
	 * @param string          $uuid            The object UUID.
	 * @param string|int|null $register        Register id, UUID or slug.
	 * @param string|int|null $schema          Schema id, UUID or slug.
	 * @param bool            $_rbac           Apply register RBAC.
	 * @param bool            $_multitenancy   Apply organisation scoping.
	 * @param bool            $_retentionSweep Run as part of a retention sweep.
	 * @param ?IUser          $currentUser     Explicit acting user; null uses the session.
	 * @param bool            $permanent       Delete permanently instead of soft-deleting.
	 *
	 * @return bool True when the object was deleted.
	 */
	public function deleteObject(
		string $uuid,
		string|int|null $register=null,
		string|int|null $schema=null,
		bool $_rbac=true,
		bool $_multitenancy=true,
		bool $_retentionSweep=false,
		?IUser $currentUser=null,
		bool $permanent=false
	): bool;

	/**
	 * Search objects and return a paginated result set.
	 *
	 * @param array   $query         The search query.
	 * @param bool    $_rbac         Apply register RBAC.
	 * @param bool    $_multitenancy Apply organisation scoping.
	 * @param bool    $deleted       Include soft-deleted objects.
	 * @param ?array  $ids           Restrict the search to these ids.
	 * @param ?string $uses          Restrict to objects used by this one.
	 * @param ?array  $views         Restrict the search to these views.
	 *
	 * @return array Results plus pagination metadata.
	 */
	public function searchObjectsPaginated(
		array $query=[],
		bool $_rbac=true,
		bool $_multitenancy=true,
		bool $deleted=false,
		?array $ids=null,
		?string $uses=null,
		?array $views=null
	): array;

	/**
	 * Search objects by register and schema SLUG.
	 *
	 * Resolves the slugs to ids first. `findAll()` and `searchObjects()` take
	 * NUMERIC ids and return nothing for a slug, silently -- which is why this
	 * method exists and why it is on the contract.
	 *
	 * @param string $registerSlug  The register slug.
	 * @param string $schemaSlug    The schema slug.
	 * @param array  $filters       Equality filters.
	 * @param bool   $_rbac         Apply register RBAC.
	 * @param bool   $_multitenancy Apply organisation scoping.
	 *
	 * @return array|int The matching objects, or a count.
	 */
	public function searchObjectsBySlug(
		string $registerSlug,
		string $schemaSlug,
		array $filters=[],
		bool $_rbac=true,
		bool $_multitenancy=true
	): array|int;

	/**
	 * Drop the register/schema scope set by setRegister()/setSchema().
	 *
	 * @return void
	 */
	public function clearCurrents(): void;

	/**
	 * Translate raw request parameters into a search query.
	 *
	 * @param array                 $requestParams Raw request parameters.
	 * @param int|string|array|null $register      Register id, UUID or slug.
	 * @param int|string|array|null $schema        Schema id, UUID or slug.
	 * @param ?array                $ids           Restrict the search to these ids.
	 *
	 * @return array The search query.
	 */
	public function buildSearchQuery(
		array $requestParams,
		int|string|array|null $register=null,
		int|string|array|null $schema=null,
		?array $ids=null
	): array;

	/**
	 * Persist many objects in one bulk operation.
	 *
	 * @param array           $objects        The objects to store.
	 * @param string|int|null $register       Register id, UUID or slug.
	 * @param string|int|null $schema         Schema id, UUID or slug.
	 * @param bool            $_rbac          Apply register RBAC.
	 * @param bool            $_multitenancy  Apply organisation scoping.
	 * @param bool            $validation     Validate against the schema.
	 * @param bool            $events         Emit events for each object.
	 * @param bool            $deduplicateIds Drop duplicate ids within the batch.
	 * @param bool            $enrich         Enrich each object with derived metadata.
	 * @param bool            $_audit         Write an audit-trail entry.
	 *
	 * @return array The stored objects.
	 */
	public function saveObjects(
		array $objects,
		string|int|null $register=null,
		string|int|null $schema=null,
		bool $_rbac=true,
		bool $_multitenancy=true,
		bool $validation=false,
		bool $events=false,
		bool $deduplicateIds=true,
		bool $enrich=true,
		bool $_audit=true
	): array;

	/**
	 * Run an operation with system privileges, bypassing user scoping.
	 *
	 * @param callable $operation The operation to run.
	 *
	 * @return mixed Whatever the operation returns.
	 */
	public function runAsSystem(callable $operation);

	/**
	 * Count objects in the current register/schema context.
	 *
	 * @param array $config Filters, limit, offset, sort and search.
	 *
	 * @return int The number of matching objects.
	 */
	public function count(array $config=[]): int;

	/**
	 * Release a lock on an object.
	 *
	 * @param string|int $identifier The object id or UUID.
	 * @param bool       $advisory   Take an advisory (non-blocking) lock.
	 *
	 * @return bool True when the lock was released.
	 */
	public function unlockObject(string|int $identifier, bool $advisory=false): bool;

	/**
	 * Take a lock on an object.
	 *
	 * @param string  $identifier The object id or UUID.
	 * @param ?string $process    A label for the process holding the lock.
	 * @param ?int    $duration   Lock duration in seconds.
	 * @param bool    $advisory   Take an advisory (non-blocking) lock.
	 *
	 * @return array The resulting lock state.
	 */
	public function lockObject(
		string $identifier,
		?string $process=null,
		?int $duration=null,
		bool $advisory=false
	): array;

	/**
	 * Delete many objects by UUID.
	 *
	 * @param array $uuids         The object UUIDs.
	 * @param bool  $_rbac         Apply register RBAC.
	 * @param bool  $_multitenancy Apply organisation scoping.
	 *
	 * @return array Per-UUID delete results.
	 */
	public function deleteObjects(
		array $uuids=[],
		bool $_rbac=true,
		bool $_multitenancy=true
	): array;

	/**
	 * The audit-trail rows for an object.
	 *
	 * @param string $uuid          The object UUID.
	 * @param array  $filters       Equality filters.
	 * @param bool   $_rbac         Apply register RBAC.
	 * @param bool   $_multitenancy Apply organisation scoping.
	 *
	 * @return array The audit-trail rows.
	 */
	public function getLogs(
		string $uuid,
		array $filters=[],
		bool $_rbac=true,
		bool $_multitenancy=true
	): array;

	/**
	 * Apply a partial update to an existing object.
	 *
	 * @param string $objectId      The object UUID.
	 * @param array  $data          The fields to change.
	 * @param bool   $_rbac         Apply register RBAC.
	 * @param bool   $_multitenancy Apply organisation scoping.
	 *
	 * @return ObjectEntityInterface The updated object.
	 */
	public function updateObject(
		string $objectId,
		array $data,
		bool $_rbac=true,
		bool $_multitenancy=true
	): ObjectEntityInterface;

	/**
	 * The objects this object refers to.
	 *
	 * @param string $objectId      The object UUID.
	 * @param array  $query         The search query.
	 * @param bool   $_rbac         Apply register RBAC.
	 * @param bool   $_multitenancy Apply organisation scoping.
	 *
	 * @return array The referenced objects.
	 */
	public function getObjectUses(
		string $objectId,
		array $query=[],
		bool $_rbac=true,
		bool $_multitenancy=true
	): array;

	/**
	 * The objects that refer to this object.
	 *
	 * @param string $objectId      The object UUID.
	 * @param array  $query         The search query.
	 * @param bool   $_rbac         Apply register RBAC.
	 * @param bool   $_multitenancy Apply organisation scoping.
	 *
	 * @return array The referring objects.
	 */
	public function getObjectUsedBy(
		string $objectId,
		array $query=[],
		bool $_rbac=true,
		bool $_multitenancy=true
	): array;

	/**
	 * Find objects that relate to a given search term.
	 *
	 * @param string $search       The term to match relations against.
	 * @param bool   $partialMatch Match relations partially.
	 *
	 * @return array The related objects.
	 */
	public function findByRelations(string $search, bool $partialMatch=true): array;

	/**
	 * Find an object without emitting audit or read events.
	 *
	 * @param string          $id            Object id, UUID or slug.
	 * @param ?array          $_extend       Relations to expand on the result.
	 * @param bool            $files         Include file metadata.
	 * @param string|int|null $register      Register id, UUID or slug.
	 * @param string|int|null $schema        Schema id, UUID or slug.
	 * @param bool            $_rbac         Apply register RBAC.
	 * @param bool            $_multitenancy Apply organisation scoping.
	 *
	 * @return ObjectEntityInterface The object.
	 */
	public function findSilent(
		string $id,
		?array $_extend=[],
		bool $files=false,
		string|int|null $register=null,
		string|int|null $schema=null,
		bool $_rbac=true,
		bool $_multitenancy=true
	): ObjectEntityInterface;

	/**
	 * Count the objects a search query would return.
	 *
	 * @param array   $query         The search query.
	 * @param bool    $_rbac         Apply register RBAC.
	 * @param bool    $_multitenancy Apply organisation scoping.
	 * @param ?array  $ids           Restrict the search to these ids.
	 * @param ?string $uses          Restrict to objects used by this one.
	 *
	 * @return int The number of matching objects.
	 */
	public function countSearchObjects(
		array $query=[],
		bool $_rbac=true,
		bool $_multitenancy=true,
		?array $ids=null,
		?string $uses=null
	): int;

	/**
	 * The object currently held as context, if any.
	 *
	 * @return ?ObjectEntityInterface The context object, or null when none is set.
	 */
	public function getObject(): ?ObjectEntityInterface;
}
