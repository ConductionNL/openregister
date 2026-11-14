<?php

/**
 * The data-access surface OpenRegister publishes to consuming apps.
 *
 * @license EUPL-1.2
 * @copyright Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Contract;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\LockRequest;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectPatch;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectValidationVerdict;
use OCA\OpenRegister\Kernel\Contracts\Value\UnlockRequest;
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
 * This interface declared 25 of them, covering 819 of the 829 classes (98.8%).
 * `patchObject()` was added afterwards and is the 26th. It is NOT part of that
 * measurement — no consumer called it, because it was not reachable through
 * this contract. It is published because `updateObject()` REPLACES, so a
 * consumer holding a partial payload had no correct method to call and either
 * hand-rolled read-merge-write or silently erased the fields it omitted.
 * `appendObjectsRaw()` and `purgeExpiredObjectsRaw()` were added 2026-09-04
 * for the traffic-analytics collector, which writes thousands of rows a
 * minute that no person edits: the normal save path's per-row RBAC, audit,
 * validation and events are exactly the cost it cannot pay. They are the raw
 * bulk path made reachable through the contract, and they carry the same
 * responsibility model as `_rbac: false`.
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
 * @SuppressWarnings(PHPMD.ExcessivePublicCount)  28 methods: 26 measured (see Scope above) plus the two raw entry points.
 */
interface ObjectServiceInterface {

	/**
	 * Persist an object, creating or updating it.
	 *
	 * Takes main's value-object form or the release's separate arguments, by name or by position.
	 * The value object keeps its main position; the release's names follow, and a positional release
	 * call is recognised by the type of what lands in the shared slots. Separate arguments, when given, win.
	 *
	 * @param array                        $object                 The object data to save or ObjectEntity instance.
	 * @param array|ObjectSaveOptions|null $options                The save options; an array here is the release's positional `$extend`.
	 * @param bool|string|int|null         $_rbac                  Whether to apply RBAC checks; a register here is the release's positional
	 *                                                             `$register`.
	 * @param bool|string|int|null         $_multitenancy          Whether to apply multitenancy filtering; a schema here is the release's
	 *                                                             positional `$schema`.
	 * @param string|null                  $uuid                   The UUID of the object to update.
	 * @param bool                         $positionalRbac         The release's positional `$_rbac`, read only with a positional register.
	 * @param bool                         $positionalTenancy The release's positional `$_multitenancy`, read only with a positional register.
	 * @param bool                         $silent                 Suppress events for this save.
	 * @param bool                         $_validation            Validate against the schema.
	 * @param array|null                   $uploadedFiles          Files uploaded from multipart/form-data.
	 * @param IUser|null                   $currentUser            Explicit acting user; null uses the session.
	 * @param bool                         $failIfExists           Fail instead of updating when the object already exists.
	 * @param bool                         $_unowned               Create the row with the SYSTEM owner.
	 * @param bool                         $_dedupOverride         Save through a blocking duplicate match.
	 * @param string|null                  $_owner                 Explicit owner of a new row.
	 * @param array|null                   $extend                 Properties to extend the result with.
	 * @param string|int|null              $register               The register object or its ID/UUID/slug.
	 * @param string|int|null              $schema                 The schema object or its ID/UUID/slug.
	 *
	 * @return ObjectEntityInterface The stored object.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Main's form plus the release's separate arguments.
	 */
	public function saveObject(
		array $object,
		array|ObjectSaveOptions|null $options=new ObjectSaveOptions(),
		bool|string|int|null $_rbac=true,
		bool|string|int|null $_multitenancy=true,
		?string $uuid=null,
		bool $positionalRbac=true,
		bool $positionalTenancy=true,
		bool $silent=false,
		bool $_validation=true,
		?array $uploadedFiles=null,
		?IUser $currentUser=null,
		bool $failIfExists=false,
		bool $_unowned=false,
		bool $_dedupOverride=false,
		?string $_owner=null,
		?array $extend=[],
		string|int|null $register=null,
		string|int|null $schema=null
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
	 * Takes main's value-object form or the release's separate arguments, by name or by position.
	 * The value object keeps its main position; the release's names follow, and a positional release
	 * call is recognised by the type of what lands in the shared slots. Separate arguments, when given, win.
	 *
	 * @param int|string                   $id                     The object ID or UUID.
	 * @param array|ObjectFindOptions|null $options                Register, schema, extend, files, render and audit; an array here is the release's
	 *                                                             positional `$_extend`.
	 * @param bool                         $_rbac                  Whether to apply RBAC checks; the release's positional `$files` when the call is
	 *                                                             positional.
	 * @param bool|string|int|null         $_multitenancy          Whether to apply multitenancy filtering; a register here is the release's
	 *                                                             positional `$register`.
	 * @param string|int|null              $positionalSchema       The release's positional `$schema`.
	 * @param bool                         $positionalRbac         The release's positional `$_rbac`.
	 * @param bool                         $positionalTenancy The release's positional `$_multitenancy`.
	 * @param bool                         $_render                False when the caller performs its own single render.
	 * @param bool                         $_audit                 False for machine-to-machine reads inside one operation.
	 * @param array|null                   $_extend                Relations to expand on the result.
	 * @param bool                         $files                  Include file metadata.
	 * @param string|int|null              $register               Register object, id, UUID or slug.
	 * @param string|int|null              $schema                 Schema object, id, UUID or slug.
	 *
	 * @return ?ObjectEntityInterface The object, or null when absent or not permitted.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Main's form plus the release's separate arguments.
	 */
	public function find(
		int|string $id,
		array|ObjectFindOptions|null $options=null,
		bool $_rbac=true,
		bool|string|int|null $_multitenancy=true,
		string|int|null $positionalSchema=null,
		bool $positionalRbac=true,
		bool $positionalTenancy=true,
		bool $_render=true,
		bool $_audit=true,
		?array $_extend=[],
		bool $files=false,
		string|int|null $register=null,
		string|int|null $schema=null
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
	 * Takes main's value-object form or the release's separate arguments, by name or by position.
	 * The value object keeps its main position; the release's names follow, and a positional release
	 * call is recognised by the type of what lands in the shared slots. Separate arguments, when given, win.
	 *
	 * @param string|ObjectDeletion|null $deletion                 The deletion; a string here is the release's positional `$uuid`.
	 * @param bool|string|int|null       $_rbac                    Whether to apply RBAC checks; a register here is the release's positional
	 *                                                             `$register`.
	 * @param bool|string|int|null       $_multitenancy            Whether to apply multitenancy filtering; a schema here is the release's
	 *                                                             positional `$schema`.
	 * @param bool                       $_retentionSweep          Internal flag set by ArchivalRetentionTask to bypass the archival-immutability
	 *                                                             gate; the release's positional `$_rbac` when the call is positional.
	 * @param bool                       $positionalTenancy   The release's positional `$_multitenancy`.
	 * @param bool                       $positionalSweep The release's positional `$_retentionSweep`.
	 * @param IUser|null                 $currentUser              Explicit acting user for the RBAC `delete` check.
	 * @param bool                       $permanent                Destroy the row rather than tombstoning it.
	 * @param string|null                $uuid                     The UUID of the object to delete.
	 * @param string|int|null            $register                 The register scope (object, ID, UUID or slug).
	 * @param string|int|null            $schema                   The schema scope (object, ID, UUID or slug).
	 *
	 * @return bool True when the object was deleted.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Main's form plus the release's separate arguments.
	 */
	public function deleteObject(
		string|ObjectDeletion|null $deletion=null,
		bool|string|int|null $_rbac=true,
		bool|string|int|null $_multitenancy=true,
		bool $_retentionSweep=false,
		bool $positionalTenancy=true,
		bool $positionalSweep=false,
		?IUser $currentUser=null,
		bool $permanent=false,
		?string $uuid=null,
		string|int|null $register=null,
		string|int|null $schema=null
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
	 * Takes main's value-object form or the release's separate arguments, by name or by position.
	 * The value object keeps its main position; the release's names follow, and a positional release
	 * call is recognised by the type of what lands in the shared slots. Separate arguments, when given, win.
	 *
	 * @param array                           $objects                Array of objects in serialized format.
	 * @param BulkSaveOptions|string|int|null $options                The bulk save options; a register here is the release's positional `$register`.
	 * @param bool|string|int|null            $_rbac                  Whether to apply RBAC filtering; a schema here is the release's positional
	 *                                                                `$schema`.
	 * @param bool                            $_multitenancy          Whether to apply multi-organization filtering; the release's positional
	 *                                                                `$_rbac` when the call is positional.
	 * @param bool                            $positionalTenancy The release's positional `$_multitenancy`.
	 * @param bool                            $validation             Validate objects against schema definitions.
	 * @param bool                            $events                 Dispatch object lifecycle events.
	 * @param bool                            $deduplicateIds         Accepted for the published contract; has no effect.
	 * @param bool                            $enrich                 Accepted for the published contract; has no effect.
	 * @param bool                            $_audit                 Write audit trail rows (bulk mirror of `silent`).
	 * @param string|int|null                 $register               The register for rows that name none.
	 * @param string|int|null                 $schema                 The schema for rows that name none.
	 *
	 * @return array The stored objects.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Main's form plus the release's separate arguments.
	 */
	public function saveObjects(
		array $objects,
		BulkSaveOptions|string|int|null $options=new BulkSaveOptions(),
		bool|string|int|null $_rbac=true,
		bool $_multitenancy=true,
		bool $positionalTenancy=true,
		bool $validation=false,
		bool $events=false,
		bool $deduplicateIds=true,
		bool $enrich=true,
		bool $_audit=true,
		string|int|null $register=null,
		string|int|null $schema=null
	): array;

	/**
	 * Append rows with every safeguard switched OFF.
	 *
	 * No RBAC, no multitenancy, no validation, no audit trail, no lifecycle
	 * events, no search index, no relations, no files. Calling it is the same
	 * declaration as `_rbac: false`: the caller has taken the authorisation
	 * responsibility. It exists for high-volume, low-value rows such as traffic
	 * events and telemetry; use saveObjects() for anything a person will edit,
	 * audit or revert.
	 *
	 * Each row is a plain property map plus the optional metadata keys `uuid`
	 * (generated when absent), `expires` (ISO 8601 or DateTimeInterface, swept
	 * by purgeExpiredObjectsRaw()), `owner` and `organisation`.
	 *
	 * @param array      $objects  Rows to append, as plain arrays.
	 * @param string|int $register Register id, uuid or slug.
	 * @param string|int $schema   Schema id, uuid or slug, resolved within the register.
	 *
	 * @return int The number of rows written.
	 *
	 * @contract-shift announced — openregister#3406 names the fleet test doubles that
	 * must declare this method or fatal at class load: pipelinq
	 * tests/Stubs/Service/ObjectService.php (with its paired
	 * tests/Stubs/Contract/ObjectServiceInterface.php) and shillinq
	 * tests/Unit/Service/Support/{InMemoryObjectServiceStub,DuckObjectServiceAdapter}.php.
	 * createMock() sites are unaffected. The break lands on the
	 * `conduction/hydra-gates` RELEASE carrying this contract, not on this
	 * merge, because leaf apps read it from vendor/: land the doubles before
	 * the release, or pin.
	 */
	public function appendObjectsRaw(array $objects, string|int $register, string|int $schema): int;

	/**
	 * Hard-delete the rows of a register+schema whose `expires` has passed.
	 *
	 * The sweep for appendObjectsRaw(). Bypasses soft-delete and the audit
	 * trail on purpose: raw rows never had either.
	 *
	 * @param string|int $register Register id, uuid or slug.
	 * @param string|int $schema   Schema id, uuid or slug, resolved within the register.
	 *
	 * @return int The number of rows removed.
	 *
	 * @contract-shift announced — openregister#3406 names the fleet test doubles that
	 * must declare this method or fatal at class load: pipelinq
	 * tests/Stubs/Service/ObjectService.php (with its paired
	 * tests/Stubs/Contract/ObjectServiceInterface.php) and shillinq
	 * tests/Unit/Service/Support/{InMemoryObjectServiceStub,DuckObjectServiceAdapter}.php.
	 * createMock() sites are unaffected. The break lands on the
	 * `conduction/hydra-gates` RELEASE carrying this contract, not on this
	 * merge, because leaf apps read it from vendor/: land the doubles before
	 * the release, or pin.
	 */
	public function purgeExpiredObjectsRaw(string|int $register, string|int $schema): int;

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
	 * Takes main's value-object form or the release's separate arguments, by name or by position.
	 * The value object keeps its main position; the release's names follow, and a positional release
	 * call is recognised by the type of what lands in the shared slots. Separate arguments, when given, win.
	 *
	 * @param string|int|UnlockRequest|null $request    The unlock request; a string or int here is the release's positional `$identifier`.
	 * @param bool                          $advisory   Release the appConfig-backed advisory lock for this synthetic key without scanning tables.
	 * @param string|null                   $runUuid    The flow run releasing a run-scoped lock.
	 * @param string|int|null               $identifier The object id or UUID.
	 *
	 * @return bool True when the lock was released.
	 */
	public function unlockObject(
		string|int|UnlockRequest|null $request=null,
		bool $advisory=false,
		?string $runUuid=null,
		string|int|null $identifier=null
	): bool;

	/**
	 * Take a lock on an object.
	 *
	 * Takes main's value-object form or the release's separate arguments, by name or by position.
	 * The value object keeps its main position; the release's names follow, and a positional release
	 * call is recognised by the type of what lands in the shared slots. Separate arguments, when given, win.
	 *
	 * @param string|LockRequest|null $request            The lock request; a string here is the release's positional `$identifier`.
	 * @param bool|string|null        $advisory           When true, treat the identifier as a synthetic pre-creation key and take the appConfig-
	 *                                                    backed advisory lock without scanning object tables; a string here is the release's
	 *                                                    positional `$process`.
	 * @param int|null                $duration           Lock duration in seconds.
	 * @param bool                    $positionalAdvisory The release's positional `$advisory`.
	 * @param string|null             $runUuid            The flow run taking the lock.
	 * @param string|null             $nodeId             The flow node that took it, recorded for the sweep.
	 * @param string|null             $identifier         The object id or UUID.
	 * @param string|null             $process            A label for the process holding the lock.
	 *
	 * @return array The resulting lock state.
	 */
	public function lockObject(
		string|LockRequest|null $request=null,
		bool|string|null $advisory=false,
		?int $duration=null,
		bool $positionalAdvisory=false,
		?string $runUuid=null,
		?string $nodeId=null,
		?string $identifier=null,
		?string $process=null
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
	 * REPLACE an existing object's data wholesale. This does NOT merge.
	 *
	 * `$data` becomes the object's data in full. It is PUT semantics: a property
	 * that is stored but absent from `$data` is NOT left alone — it is written
	 * away. Sending `['status' => 'withdrawn']` for an object that also holds a
	 * title, a summary and three dates stores an object with a status and
	 * nothing else, and returns successfully while doing it.
	 *
	 * This docblock previously read "apply a partial update", which is the
	 * opposite of what the method does. A caller who migrated a one-key update
	 * onto that description silently erased every field it did not send. If you
	 * want partial-update semantics, call `patchObject()` — it reads, merges and
	 * saves, and it is on this contract for exactly that reason.
	 *
	 * Replace semantics are deliberate and unchanged: existing callers pass a
	 * complete object and depend on an omitted property being cleared.
	 *
	 * @param string $objectId      The object UUID.
	 * @param array  $data          The object's COMPLETE new data. Anything omitted is dropped.
	 * @param bool   $_rbac         Apply register RBAC; false lifts it for this save, as with saveObject().
	 * @param bool   $_multitenancy Apply organisation scoping; false lifts it for this save, as with saveObject().
	 *
	 * @return ObjectEntityInterface The replaced object.
	 *
	 * @see self::patchObject() for the merging counterpart.
	 */
	public function updateObject(
		string $objectId,
		array $data,
		bool $_rbac=true,
		bool $_multitenancy=true
	): ObjectEntityInterface;

	/**
	 * MERGE a partial payload onto an existing object, leaving the rest intact.
	 *
	 * This is the PATCH-semantic counterpart to `updateObject()`, and the one a
	 * caller holding an incomplete payload wants. The read-merge-save cycle
	 * lives here, once, rather than being reimplemented by every consumer.
	 *
	 * MERGE RULES (RFC 7386 shaped):
	 *  - a key present with a non-null value overwrites the stored value;
	 *  - a key ABSENT from the payload leaves the stored value untouched;
	 *  - a key present with an explicit `null` clears the stored value, so
	 *    "unset this" stays expressible and distinct from "not mentioned";
	 *  - two associative arrays merge recursively on the same three rules;
	 *  - lists (JSON arrays) are replaced wholesale, never element-merged.
	 *
	 * The merged result goes through the same save path as `saveObject()`, so
	 * schema validation, the audit trail and event dispatch all still apply.
	 *
	 * Takes main's value-object form or the release's separate arguments, by name or by position.
	 * The value object keeps its main position; the release's names follow, and a positional release
	 * call is recognised by the type of what lands in the shared slots. Separate arguments, when given, win.
	 *
	 * @param string|ObjectPatch|null $patch                  The patch; a string here is the release's positional `$objectId`.
	 * @param bool|array              $_rbac                  Whether to apply RBAC checks; an array here is the release's positional `$data`.
	 * @param bool|string|int|null    $_multitenancy          Whether to apply multitenancy filtering; a register here is the release's positional
	 *                                                        `$register`.
	 * @param string|int|null         $positionalSchema       The release's positional `$schema`.
	 * @param bool                    $positionalRbac         The release's positional `$_rbac`.
	 * @param bool                    $positionalTenancy The release's positional `$_multitenancy`.
	 * @param IUser|null              $currentUser            Explicit acting user forwarded to `saveObject()`.
	 * @param string|null             $objectId               The object id, uuid or slug.
	 * @param array|null              $data                   The partial data to merge; required with `$objectId`.
	 * @param string|int|null         $register               The register scope.
	 * @param string|int|null         $schema                 The schema scope.
	 *
	 * @return ObjectEntityInterface The patched object.
	 *
	 * @see self::updateObject() for the replacing counterpart.
	 *
	 * @contract-shift announced — openregister#2543 sweeps every fleet app for
	 * `implements ObjectServiceInterface` and names the THREE test doubles that
	 * must declare this method or fatal at class load: pipelinq
	 * tests/Stubs/Service/ObjectService.php (with its paired
	 * tests/Stubs/Contract/ObjectServiceInterface.php), and shillinq
	 * tests/Unit/Service/Support/{InMemoryObjectServiceStub,DuckObjectServiceAdapter}.php.
	 * docudesk tests/stubs/OpenRegisterStubs.php wants the same for parity but
	 * implements nothing, so it cannot fatal. All 239 createMock() sites are
	 * unaffected — a generated mock tracks whatever the interface declares. The
	 * break lands on a `conduction/hydra-gates` RELEASE carrying this contract,
	 * not on this merge, because leaf apps read it from vendor/: land those
	 * three doubles before the release, or pin.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Main's form plus the release's separate arguments.
	 */
	public function patchObject(
		string|ObjectPatch|null $patch=null,
		bool|array $_rbac=true,
		bool|string|int|null $_multitenancy=true,
		string|int|null $positionalSchema=null,
		bool $positionalRbac=true,
		bool $positionalTenancy=true,
		?IUser $currentUser=null,
		?string $objectId=null,
		?array $data=null,
		string|int|null $register=null,
		string|int|null $schema=null
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
	 * Takes main's value-object form or the release's separate arguments, by name or by position.
	 * The value object keeps its main position; the release's names follow, and a positional release
	 * call is recognised by the type of what lands in the shared slots. Separate arguments, when given, win.
	 *
	 * @param string                       $id                     The ID of the object to get.
	 * @param array|ObjectFindOptions|null $options                Register, schema, extend and files; an array here is the release's positional
	 *                                                             `$_extend`.
	 * @param bool                         $_rbac                  Whether to apply RBAC checks; the release's positional `$files` when the call is
	 *                                                             positional.
	 * @param bool|string|int|null         $_multitenancy          Whether to apply multitenancy filtering; a register here is the release's
	 *                                                             positional `$register`.
	 * @param string|int|null              $positionalSchema       The release's positional `$schema`.
	 * @param bool                         $positionalRbac         The release's positional `$_rbac`.
	 * @param bool                         $positionalTenancy The release's positional `$_multitenancy`.
	 * @param array|null                   $_extend                Relations to expand on the result.
	 * @param bool                         $files                  Include file metadata.
	 * @param string|int|null              $register               Register object, id, UUID or slug.
	 * @param string|int|null              $schema                 Schema object, id, UUID or slug.
	 *
	 * @return ObjectEntityInterface The object.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Main's form plus the release's separate arguments.
	 */
	public function findSilent(
		string $id,
		array|ObjectFindOptions|null $options=null,
		bool $_rbac=true,
		bool|string|int|null $_multitenancy=true,
		string|int|null $positionalSchema=null,
		bool $positionalRbac=true,
		bool $positionalTenancy=true,
		?array $_extend=[],
		bool $files=false,
		string|int|null $register=null,
		string|int|null $schema=null
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
	 * Whether a sample record would be accepted, without saving it.
	 *
	 * Checks the sample against the schema and every save rule that decides
	 * acceptance (coded values, dependent values, unique fields, declared
	 * bounds, read-only fields), as a create, or with an id as an update of that
	 * object. Writes nothing and dispatches no object event. The sample is
	 * invalid exactly when a save of it would be refused for one of these rules.
	 *
	 * @param array      $object   The sample record.
	 * @param string|int $register The register (id, uuid or slug).
	 * @param string|int $schema   The schema (id, uuid or slug).
	 * @param ?string    $id       The object the sample updates, or null for a create.
	 *
	 * @return ObjectValidationVerdict `valid`, and one error per refused property with the rule that refused it.
	 *
	 * @spec openspec/changes/records-validate-without-saving/specs/objects-crud/spec.md#requirement-a-record-can-be-validated-without-saving-it
	 */
	public function validateObject(array $object, string|int $register, string|int $schema, ?string $id=null): ObjectValidationVerdict;

	/**
	 * The object currently held as context, if any.
	 *
	 * @return ?ObjectEntityInterface The context object, or null when none is set.
	 */
	public function getObject(): ?ObjectEntityInterface;
}
