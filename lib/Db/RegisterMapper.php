<?php

/**
 * OpenRegister Register Mapper
 *
 * This file contains the class for handling register mapper related operations
 * in the OpenRegister application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterDeletedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Exception\ValidationException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use Symfony\Component\Uid\Uuid;

/**
 * RegisterMapper handles database operations for Register entities
 *
 * Handles database operations for Register entities with multi-tenancy support.
 * Provides CRUD operations with automatic organisation filtering, RBAC checks,
 * and event dispatching.
 *
 * @category Mapper
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @method Register insert(Entity $entity)
 * @method Register update(Entity $entity)
 * @method Register insertOrUpdate(Entity $entity)
 * @method Register delete(Entity $entity)
 * @method Register find(int|string $id, bool $_rbac=true, bool $_multitenancy=true)
 * @method Register findEntity(IQueryBuilder $query)
 * @method list<Register> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<Register>
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class RegisterMapper extends QBMapper {
	use MultiTenancyTrait;

	/**
	 * Maximum number of expressions allowed in a single SQL IN() list.
	 *
	 * Nextcloud's QueryBuilder refuses more than 1000 expressions in an IN()
	 * list (Oracle limit); exceeding it logs an error and emits an "Undefined
	 * array key 0" PHP warning. Batched id lookups must chunk below this bound.
	 *
	 * @var integer
	 */
	private const MAX_IN_LIST_SIZE = 1000;

	/**
	 * Schema mapper instance
	 *
	 * Used for finding schemas associated with registers.
	 *
	 * @var SchemaMapper Schema mapper instance
	 */
	private readonly SchemaMapper $schemaMapper;

	/**
	 * User session for multi-tenancy (from trait)
	 *
	 * Used to get current user context for multi-tenancy filtering.
	 *
	 * @var IUserSession User session instance
	 */
	protected IUserSession $userSession;

	/**
	 * Group manager for RBAC (from trait)
	 *
	 * Used to check user group memberships for permission verification.
	 *
	 * @var IGroupManager Group manager instance
	 */
	protected IGroupManager $groupManager;

	/**
	 * Event dispatcher instance
	 *
	 * Dispatches events when registers are created, updated, or deleted.
	 *
	 * @var IEventDispatcher Event dispatcher instance
	 */
	private readonly IEventDispatcher $eventDispatcher;

	/**
	 * Container for lazy resolution of MagicMapper (avoids circular DI).
	 *
	 * @var \Psr\Container\ContainerInterface
	 */
	private readonly \Psr\Container\ContainerInterface $container;

	/**
	 * Organisation mapper for multi-tenancy (from trait)
	 *
	 * Used to get active organisation and apply organisation filters.
	 *
	 * @var OrganisationMapper Organisation mapper instance
	 */
	protected OrganisationMapper $organisationMapper;

	/**
	 * App configuration for multitenancy settings
	 *
	 * Used by MultiTenancyTrait for checking multitenancy configuration.
	 *
	 * @var IAppConfig App configuration instance
	 */
	protected IAppConfig $appConfig;

	/**
	 * Request-scoped in-memory cache for find() results
	 *
	 * Prevents redundant DB queries when the same register is looked up
	 * multiple times within one request.
	 *
	 * @var array<string, Register>
	 */
	private array $findCache = [];

	/**
	 * Constructor
	 *
	 * Initializes mapper with database connection and required dependencies
	 * for multi-tenancy, RBAC, and event dispatching.
	 *
	 * @param IDBConnection $db Database connection for queries
	 * @param SchemaMapper $schemaMapper Schema mapper for schema operations
	 * @param IEventDispatcher $eventDispatcher Event dispatcher for register events
	 * @param \Psr\Container\ContainerInterface $container Container for lazy MagicMapper resolution
	 * @param OrganisationMapper $organisationMapper Organisation mapper for multi-tenancy
	 * @param IUserSession $userSession User session for current user context
	 * @param IGroupManager $groupManager Group manager for RBAC checks
	 * @param IAppConfig $appConfig App configuration for multitenancy settings
	 *
	 * @return void
	 */
	public function __construct(
		IDBConnection $db,
		SchemaMapper $schemaMapper,
		IEventDispatcher $eventDispatcher,
		\Psr\Container\ContainerInterface $container,
		OrganisationMapper $organisationMapper,
		IUserSession $userSession,
		IGroupManager $groupManager,
		IAppConfig $appConfig,
	) {
		// Initialize parent mapper with table name and entity class.
		parent::__construct(db: $db, tableName: 'openregister_registers', entityClass: Register::class);

		// Store dependencies for use in mapper methods.
		$this->schemaMapper = $schemaMapper;
		$this->eventDispatcher = $eventDispatcher;
		$this->container = $container;
		$this->organisationMapper = $organisationMapper;
		$this->userSession = $userSession;
		$this->groupManager = $groupManager;
		$this->appConfig = $appConfig;
	}//end __construct()

	/**
	 * Find a register by its ID, with optional extension for statistics
	 *
	 * Includes RBAC and organisation filtering for multi-tenancy.
	 *
	 * @param int|string $id The ID of the register to find
	 * @param bool $_rbac Whether to apply RBAC permission checks (default: true)
	 * @param bool $_multitenancy Whether to apply multi-tenancy filtering (default: true)
	 *
	 * @return Register The found register, possibly with stats
	 *
	 * @throws \Exception If RBAC permission check fails
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Flags control security filtering behavior
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Find operation requires multiple lookup strategies
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 */
	public function find(
		string|int $id,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): Register {
		// Check request-scoped cache to avoid redundant DB queries for the same register.
		$rbacFlag = '0';
		$mtFlag = '0';
		if ($_rbac === true) {
			$rbacFlag = '1';
		}

		if ($_multitenancy === true) {
			$mtFlag = '1';
		}

		$cacheKey = strtolower((string)$id) . ':' . $rbacFlag . ':' . $mtFlag;
		if (isset($this->findCache[$cacheKey]) === true) {
			return $this->findCache[$cacheKey];
		}

		// Log search attempt for debugging.
		if (isset($this->logger) === true) {
			$this->logger->info(
				message: '[RegisterMapper] Searching for register',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'identifier' => $id,
					'rbac' => $_rbac,
					'multi' => $_multitenancy,
				]
			);
		}

		// Verify RBAC permission to read registers if RBAC is enabled.
		if ($_rbac === true) {
			// @todo: remove this hotfix for solr - uncomment when ready
			// $this->verifyRbacPermission('read', 'register');
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('openregister_registers');

		// Build OR conditions for matching against id, uuid, or slug.
		// Note: Only include id comparison if $id is actually numeric (PostgreSQL strict typing).
		// Slug comparison is case-insensitive using LOWER() function.
		$lowerId = strtolower((string)$id);
		$orConditions = $qb->expr()->orX(
			$qb->expr()->eq('uuid', $qb->createNamedParameter($id, IQueryBuilder::PARAM_STR)),
			$qb->expr()->eq(
				$qb->func()->lower('slug'),
				$qb->createNamedParameter($lowerId, IQueryBuilder::PARAM_STR)
			)
		);

		if (is_numeric($id) === true) {
			$orConditions->add($qb->expr()->eq('id', $qb->createNamedParameter((int)$id, IQueryBuilder::PARAM_INT)));
		}

		$qb->where($orConditions);

		// Check if register exists before applying filters (for debugging).
		// Cap to a single row + deterministic order so duplicate-slug rows from
		// env churn cannot raise MultipleObjectsReturnedException out of this
		// debug-only probe (which previously surfaced as a 500 on slug lookups
		// — e.g. the object lock path). See the deterministic resolution below.
		$qbBeforeFilter = clone $qb;
		$qbBeforeFilter->orderBy('id', 'ASC');
		$qbBeforeFilter->setMaxResults(1);
		$existsBeforeFilter = false;
		try {
			$testResult = $this->findEntity(query: $qbBeforeFilter);
			$existsBeforeFilter = true;
			if (isset($this->logger) === true) {
				$this->logger->debug(
					message: '[RegisterMapper] Register exists before filters',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'identifier' => $id,
						'registerId' => $testResult->getId(),
						'organisation' => $testResult->getOrganisation(),
					]
				);
			}
		} catch (\OCP\AppFramework\Db\DoesNotExistException|\OCP\AppFramework\Db\MultipleObjectsReturnedException $e) {
			if (isset($this->logger) === true) {
				$this->logger->warning(
					message: '[RegisterMapper] Register does not exist (or is duplicated) before filters',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'identifier' => $id,
					]
				);
			}
		}//end try

		// Apply organisation filter.
		$this->applyOrganisationFilter(
			qb: $qb,
			columnName: 'organisation',
			allowNullOrg: true,
			multiTenancyEnabled: $_multitenancy
		);

		// Deterministic slug/uuid resolution: env churn can leave multiple rows
		// sharing a slug, which would make findEntity() raise
		// MultipleObjectsReturnedException → a 500 fleet-wide. Order by id ASC
		// and cap to a single row so the oldest (lowest-id) register
		// deterministically wins. The duplicates are still detectable/mergeable
		// via the `openregister:registers:dedupe` occ command.
		$qb->orderBy('id', 'ASC');
		$qb->setMaxResults(1);

		// Just return the entity; do not attach stats here.
		try {
			$register = $this->findEntity(query: $qb);

			// Cache by all possible identifiers to handle lookups by id, uuid, or slug.
			$rbacChar = '0';
			$mtChar = '0';
			if ($_rbac === true) {
				$rbacChar = '1';
			}

			if ($_multitenancy === true) {
				$mtChar = '1';
			}

			$rbacSuffix = ':' . $rbacChar . ':' . $mtChar;
			$this->findCache[$cacheKey] = $register;
			$this->findCache[(string)$register->getId() . $rbacSuffix] = $register;

			// BUG-DB-10: guard against a null uuid before strtolower().
			$registerUuid = $register->getUuid();
			if ($registerUuid !== null) {
				$this->findCache[strtolower($registerUuid) . $rbacSuffix] = $register;
			}

			if ($register->getSlug() !== null) {
				$this->findCache[strtolower($register->getSlug()) . $rbacSuffix] = $register;
			}

			return $register;
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			// Log detailed error information.
			if (isset($this->logger) === true) {
				$this->logger->error(
					message: '[RegisterMapper] Register not found after filters',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'identifier' => $id,
						'existsBeforeFilter' => $existsBeforeFilter,
						'multiEnabled' => $_multitenancy,
						'rbacEnabled' => $_rbac,
						'error' => $e->getMessage(),
					]
				);
			}

			throw $e;
		}//end try
	}//end find()

	/**
	 * Clear the request-scoped find cache for a specific register
	 *
	 * Used by the runtime-schema-api CRUD path to drop the in-memory
	 * cache entry after a mutation, so the next find() call re-reads
	 * from the database. Clears every cache key that referenced the
	 * given register (by id, uuid, slug) across both RBAC/multi-tenancy
	 * flag combinations.
	 *
	 * @param int $registerId The register ID to drop from the find cache.
	 *
	 * @return void
	 */
	public function clearFindCache(int $registerId): void {
		// Find every cache key whose value points at this register ID and unset.
		foreach (array_keys($this->findCache) as $key) {
			$cached = $this->findCache[$key];
			if ($cached instanceof Register && $cached->getId() === $registerId) {
				unset($this->findCache[$key]);
			}
		}
	}//end clearFindCache()

	/**
	 * Finds multiple registers by id
	 *
	 * @param array $ids The ids of the registers
	 * @param bool $_rbac Whether to apply RBAC permission checks (default: true)
	 * @param bool $_multitenancy Whether to apply multi-tenancy filtering (default: true)
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If a register does not exist
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple registers are found
	 * @throws \OCP\DB\Exception If a database error occurs
	 *
	 * @todo: refactor this into find all
	 *
	 * @return Register[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Register>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control security filtering behavior
	 */
	public function findMultiple(array $ids, bool $_rbac = true, bool $_multitenancy = true): array {
		$result = [];
		foreach ($ids as $id) {
			try {
				$result[] = $this->find(id: $id, _rbac: $_rbac, _multitenancy: $_multitenancy);
			} catch (\OCP\AppFramework\Db\DoesNotExistException|\OCP\AppFramework\Db\MultipleObjectsReturnedException) {
				// Catch all exceptions but do nothing.
			} catch (\OCP\DB\Exception) {
				// Catch all exceptions but do nothing.
			}
		}

		return $result;
	}//end findMultiple()

	/**
	 * Find multiple registers by IDs using a single optimized query
	 *
	 * This method performs a single database query to fetch multiple registers,
	 * register: * significantly improving performance compared to individual queries.
	 *
	 * @param array $ids Array of register IDs to find.
	 *
	 * @return Entity&Register[]
	 *
	 * @psalm-return array<Entity&Register>
	 */
	public function findMultipleOptimized(array $ids): array {
		if ($ids === []) {
			return [];
		}

		$registers = [];

		// Chunk below the 1000-expression IN() ceiling (Oracle limit enforced by
		// Nextcloud's QueryBuilder). Registers are fewer than schemas today, but
		// this list is caller-supplied and unbounded.
		foreach (array_chunk($ids, self::MAX_IN_LIST_SIZE) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from('openregister_registers')
				->where(
					$qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY))
				);

			$result = $qb->executeQuery();

			while (($row = $result->fetch()) !== false) {
				$register = new Register();
				$register = $register->fromRow($row);
				$registers[$row['id']] = $register;
			}

			$result->closeCursor();
		}

		return $registers;
	}//end findMultipleOptimized()

	/**
	 * Find all registers, files: with optional extension for statistics
	 *
	 * @param int|null $limit The limit of the results
	 * @param int|null $offset The offset of the results
	 * @param array|null $filters The filters to apply
	 * @param array|null $searchConditions Array of search conditions
	 * @param array|null $searchParams Array of search parameters
	 * @param bool $_rbac Whether to apply RBAC permission checks (default: true)
	 * @param bool $_multitenancy Whether to apply multi-tenancy filtering (default: true)
	 *
	 * @return Register[]
	 *
	 * @psalm-return                                list<\OCA\OpenRegister\Db\Register>
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control security filtering behavior
	 */
	public function findAll(
		?int $limit = null,
		?int $offset = null,
		?array $filters = [],
		?array $searchConditions = [],
		?array $searchParams = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array {
		// Verify RBAC permission to read registers if RBAC is enabled.
		if ($_rbac === true) {
			// @todo: remove this hotfix for solr - uncomment when ready
			// $this->verifyRbacPermission('read', 'register');
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('openregister_registers')
			->setMaxResults($limit)
			->setFirstResult($offset ?? 0);

		foreach ($filters ?? [] as $filter => $value) {
			if ($value === 'IS NOT NULL') {
				$qb->andWhere($qb->expr()->isNotNull($filter));
				continue;
			}

			if ($value === 'IS NULL') {
				$qb->andWhere($qb->expr()->isNull($filter));
				continue;
			}

			$qb->andWhere($qb->expr()->eq($filter, $qb->createNamedParameter($value)));
		}

		if (empty($searchConditions) === false) {
			$qb->andWhere('(' . implode(' OR ', $searchConditions) . ')');
			foreach ($searchParams ?? [] as $param => $value) {
				$qb->setParameter($param, $value);
			}
		}

		// Apply organisation filter.
		$this->applyOrganisationFilter(
			qb: $qb,
			columnName: 'organisation',
			allowNullOrg: true,
			multiTenancyEnabled: $_multitenancy
		);

		// Just return the entities; do not attach stats here.
		return $this->findEntities(query: $qb);
	}//end findAll()

	/**
	 * Insert a new entity
	 *
	 * Includes RBAC permission check and auto-sets organisation from active session.
	 *
	 * @param Entity $entity The entity to insert
	 *
	 * @return Entity The inserted entity
	 *
	 * @throws \Exception If RBAC permission check fails
	 * @psalm-suppress LessSpecificImplementedReturnType - Register is more specific than Entity
	 */
	public function insert(Entity $entity): Entity {
		// Verify RBAC permission to create registers.
		$this->verifyRbacPermission(action: 'create', entityType: 'register');
		// Auto-set organisation from active session.
		$this->setOrganisationOnCreate(entity: $entity);

		// Auto-set owner from current user session.
		$this->setOwnerOnCreate(entity: $entity);

		$entity = parent::insert(entity: $entity);

		// Dispatch creation event.
		$this->eventDispatcher->dispatchTyped(new RegisterCreatedEvent(register: $entity));

		return $entity;
	}//end insert()

	/**
	 * Ensures that a register object has a UUID and a slug.
	 *
	 * @param Register $register The register object to clean
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 is standard Symfony UID pattern
	 */
	private function cleanObject(Register $register): void {
		// Check if UUID is set, if not, generate a new one.
		if ($register->getUuid() === null) {
			$register->setUuid((string)Uuid::v4());
		}

		// Ensure the object has a slug.
		if (empty($register->getSlug()) === true) {
			// Convert to lowercase and replace spaces with dashes.
			$slug = strtolower(trim($register->getTitle() ?? 'register'));
			// Assuming title is used for slug.
			// Remove special characters.
			$slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
			// Remove multiple dashes.
			$slug = preg_replace('/-+/', '-', $slug);
			// Remove leading/trailing dashes.
			$slug = trim($slug, '-');

			$register->setSlug($slug);
		}

		// Ensure the object has a version.
		if ($register->getVersion() === null) {
			$register->setVersion('0.0.1');
		}

		// Ensure the object has a source set to 'internal' by default.
		if ($register->getSource() === null || $register->getSource() === '') {
			$register->setSource('internal');
		}
	}//end cleanObject()

	/**
	 * Create a new register from an array of data
	 *
	 * @param array $object The data to create the register from
	 *
	 * @return Register The created register
	 */
	public function createFromArray(array $object): Register {
		$register = new Register();
		$register->hydrate(object: $object);

		// Clean the register object to ensure UUID, slug, and version are set.
		$this->cleanObject(register: $register);

		$register = $this->insert(entity: $register);

		return $register;
	}//end createFromArray()

	/**
	 * Update an entity
	 *
	 * @param Entity $entity The entity to update
	 *
	 * @return Entity The updated entity
	 *
	 * @psalm-suppress LessSpecificImplementedReturnType - Register is more specific than Entity
	 */
	public function update(Entity $entity): Entity {
		// Verify RBAC permission to update registers.
		$this->verifyRbacPermission(action: 'update', entityType: 'register');
		// Verify entity belongs to active organisation.
		$this->verifyOrganisationAccess(entity: $entity);

		// Fetch old entity directly without organisation filter for event comparison.
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('openregister_registers')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($entity->getId(), IQueryBuilder::PARAM_INT)));
		$oldSchema = $this->findEntity(query: $qb);

		// Clean the register object to ensure UUID, slug, and version are set.
		$this->cleanObject(register: $entity);

		$entity = parent::update(entity: $entity);

		// Dispatch update event.
		$this->eventDispatcher->dispatchTyped(new RegisterUpdatedEvent(newRegister: $entity, oldRegister: $oldSchema));

		return $entity;
	}//end update()

	/**
	 * Update an existing register from an array of data
	 *
	 * @param int $id The ID of the register to update
	 * @param array $object The new data for the register
	 *
	 * @return Register The updated register
	 */
	public function updateFromArray(int $id, array $object): Register {
		// Disable multitenancy filtering for update operations.
		// When updating by ID, we want to find the register regardless of organisation.
		// Access verification happens in update() method via verifyOrganisationAccess().
		$register = $this->find(id: $id, _multitenancy: false);

		// Set or update the version.
		if (isset($object['version']) === false) {
			$currentVersion = $register->getVersion() ?? '0.0.0';
			$register->setVersion($this->bumpPatchVersion(version: $currentVersion));
		}

		$register->hydrate(object: $object);

		// Clean the register object to ensure UUID, extend: slug, files: and version are set.
		$this->cleanObject(register: $register);

		$register = $this->update(entity: $register);

		return $register;
	}//end updateFromArray()

	/**
	 * Increment the patch component of a semantic version string.
	 *
	 * BUG-DB-12: the previous naive `explode('.')` + `(int)` bump turned a
	 * pre-release like `1.0.0-beta` into `1.0.1`, silently dropping the
	 * `-beta` suffix. This parser preserves any pre-release/build suffix and
	 * pads missing segments so a bare `1` or `1.2` still bumps cleanly.
	 *
	 * @param string $version The current version string (e.g. `1.0.0-beta`).
	 *
	 * @return string The version with its patch component incremented.
	 */
	private function bumpPatchVersion(string $version): string {
		// Capture: major.minor.patch followed by an optional -prerelease/+build suffix.
		if (preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?(.*)$/', trim($version), $matches) === 1) {
			$major = (int)$matches[1];
			// Groups 2-4 are always present: the trailing `(.*)` always matches,
			// so PHP fills the earlier optional groups with '' rather than
			// omitting them. (int)'' is 0, so the old `?? 0` was a no-op.
			$minor = (int)$matches[2];
			$patch = (int)$matches[3];
			$suffix = $matches[4];

			return $major . '.' . $minor . '.' . ($patch + 1) . $suffix;
		}

		// Fall back to a safe default when the version is unparsable.
		return '0.0.1';
	}//end bumpPatchVersion()

	/**
	 * Delete a register only if no objects are attached
	 *
	 * @param Register $entity The register to delete
	 *
	 * @throws \Exception If objects are still attached to the register
	 *
	 * @return Register The deleted register
	 */
	public function delete(Entity $entity): Register {
		// Verify RBAC permission to delete registers.
		$this->verifyRbacPermission(action: 'delete', entityType: 'register');
		// Verify entity belongs to active organisation.
		$this->verifyOrganisationAccess(entity: $entity);

		// Check for attached objects before deleting.
		$registerId = $entity->id;
		if (method_exists($entity, 'getId') === true) {
			$registerId = $entity->getId();
		}

		$objectEntityMapper = $this->container->get(MagicMapper::class);
		$stats = $objectEntityMapper->getStatistics(registerId: $registerId, schemaId: null);
		if (($stats['total'] ?? 0) > 0) {
			throw new ValidationException(message: 'Cannot delete register: objects are still attached.');
		}

		// Proceed with deletion if no objects are attached.
		$result = parent::delete(entity: $entity);

		// Dispatch deletion event.
		$this->eventDispatcher->dispatchTyped(
			new RegisterDeletedEvent(register: $entity)
		);

		return $result;
	}//end delete()

	/**
	 * Get all schemas associated with a register
	 *
	 * @param int $registerId The ID of the register
	 * @param bool $_rbac Whether to apply RBAC permission checks (default: true)
	 * @param bool $_multitenancy Whether to apply multi-tenancy filtering (default: true)
	 *
	 * @return Schema[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Schema>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control security filtering behavior
	 */
	public function getSchemasByRegisterId(
		int $registerId,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array {
		$register = $this->find(
			id: $registerId,
			_rbac: $_rbac,
			_multitenancy: $_multitenancy
		);
		$schemaIds = $register->getSchemas();

		$schemas = [];

		// Fetch each schema by its ID.
		// Use $_multitenancy=false to bypass organization filter since the register has already passed access checks.
		// This ensures schemas linked to accessible registers can always be found.
		foreach ($schemaIds ?? [] as $schemaId) {
			try {
				$schemas[] = $this->schemaMapper->find(id: (int)$schemaId, _extend: [], _rbac: $_rbac, _multitenancy: false);
			} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
				// Schema not found, skip it (similar to RegistersController behavior).
				continue;
			}
		}

		return $schemas;
	}//end getSchemasByRegisterId()

	/**
	 * Retrieves the ID of the first register that includes the given schema ID.
	 *
	 * This method searches the `openregister_registers` table for a register
	 * whose `schemas` field (a string) contains the specified schema ID, register: using
	 * a regular expression for exact word matching. If a match is found, schema: the ID
	 * of the first such register is returned. Otherwise, extend: it returns null.
	 *
	 * @param int $schemaId The ID of the schema to search for.
	 *
	 * @return int|null The ID of the first matching register, files: or null if none found.
	 */
	public function getFirstRegisterWithSchema(int $schemaId): ?int {
		$matches = $this->getAllRegisterIdsWithSchema(schemaId: $schemaId);
		return ($matches[0] ?? null);
	}//end getFirstRegisterWithSchema()

	/**
	 * Retrieves the IDs of all registers that include the given schema ID.
	 *
	 * A schema may be referenced by more than one register. Callers that make a
	 * security decision off register-level configuration (e.g. the
	 * inheritFromPublic cascade) must consider every register rather than an
	 * arbitrary first match, so the verdict is deterministic across nodes and
	 * restores. IDs are returned in ascending order for stability.
	 *
	 * @param int $schemaId The ID of the schema to search for.
	 *
	 * @return int[] The IDs of all matching registers (empty when none found).
	 */
	public function getAllRegisterIdsWithSchema(int $schemaId): array {
		// Three platforms in production: SQLite (no REGEXP function),
		// MariaDB / MySQL (has REGEXP), and Postgres (has SIMILAR TO
		// but stores the `schemas` column as `json`, which doesn't
		// even cast cleanly to text for a LIKE prefilter without an
		// explicit `::text` cast). The intersection of "portable" and
		// "works regardless of `schemas` column type" is "fetch every
		// register row and decode in PHP".
		//
		// Registers are O(10s) per install, so the cost is trivial.
		// The previous MySQL-only REGEXP query (with `[[:<:]]N[[:>:]]`
		// word-boundary syntax) is replaced wholesale — see #50.
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'schemas')
			->from('openregister_registers');

		// NC's IResult (OC\DB\ResultAdapter) does not expose Doctrine's
		// fetchAllAssociative() on every supported server (absent on NC 32) —
		// calling it throws "undefined method". This method runs inside the
		// PermissionHandler authorization resolver (getRegisterForSchema), whose
		// catch re-throws as AuthorizationUnresolvableException and FAIL-CLOSES
		// the action — so on NC 32 EVERY RBAC-checked read (find(): dashboard
		// widgets, status-transition engine, single-object reads) is denied,
		// even for admin. Iterate fetch() instead, matching MarkerLookupTrait.
		$result = $qb->executeQuery();
		$candidates = [];
		$row = $result->fetch();
		while ($row !== false) {
			$candidates[] = $row;
			$row = $result->fetch();
		}

		$result->closeCursor();
		$needle = (string)$schemaId;
		$matches = [];

		foreach ($candidates as $row) {
			$schemas = $this->decodeSchemasField(raw: ($row['schemas'] ?? null));
			foreach ($schemas as $candidate) {
				if ((string)$candidate === $needle) {
					$matches[] = (int)$row['id'];
					break;
				}
			}
		}

		sort($matches);

		return $matches;
	}//end getAllRegisterIdsWithSchema()

	/**
	 * Decode the persisted `schemas` column into a flat ID list.
	 *
	 * Accepts the column's raw value (typically a JSON array) and
	 * returns the contained schema IDs. Tolerates legacy shapes
	 * (comma-separated string) and unexpected types by returning [].
	 *
	 * @param mixed $raw The raw column value
	 *
	 * @return array<int,int|string>
	 */
	private function decodeSchemasField(mixed $raw): array {
		if (is_array($raw) === true) {
			return $raw;
		}

		if (is_string($raw) === true && $raw !== '') {
			$decoded = json_decode($raw, true);
			if (is_array($decoded) === true) {
				return $decoded;
			}

			// Legacy comma-separated fallback.
			return array_filter(array_map('trim', explode(',', $raw)));
		}

		return [];
	}//end decodeSchemasField()

	/**
	 * Check if a register has a schema with a specific title
	 *
	 * @param int $registerId The ID of the register
	 * @param string $schemaTitle The title of the schema to look for
	 *
	 * @return Schema|null The schema if found, multi: null otherwise
	 */
	public function hasSchemaWithTitle(int $registerId, string $schemaTitle): ?Schema {
		$schemas = $this->getSchemasByRegisterId(registerId: $registerId);

		// Check each schema for a matching title.
		foreach ($schemas as $schema) {
			if ($schema->getTitle() === $schemaTitle) {
				return $schema;
			}
		}

		return null;
	}//end hasSchemaWithTitle()

	/**
	 * Get all register ID to slug mappings
	 *
	 * @return array<string,string> Array mapping register IDs to their slugs
	 */
	public function getIdToSlugMap(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'slug')
			->from($this->getTableName());

		$result = $qb->executeQuery();
		$mappings = [];
		while (($row = $result->fetch()) !== false) {
			$mappings[$row['id']] = $row['slug'];
		}

		return $mappings;
	}//end getIdToSlugMap()

	/**
	 * Get all register slug to ID mappings
	 *
	 * @return array<string,string> Array mapping register slugs to their IDs
	 */
	public function getSlugToIdMap(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'slug')
			->from($this->getTableName());

		$result = $qb->executeQuery();
		$mappings = [];
		while (($row = $result->fetch()) !== false) {
			$mappings[$row['slug']] = $row['id'];
		}

		return $mappings;
	}//end getSlugToIdMap()

	/**
	 * Resolve a bounded set of register slugs to their primary-key ids.
	 *
	 * The register twin of {@see SchemaMapper::findIdsBySlugs()}; see that
	 * docblock for why filtered event subscription must not use
	 * {@see getSlugToIdMap()} on the write path.
	 *
	 * @param array<int,string> $slugs Register slugs to resolve.
	 *
	 * @return array<string,array<int,string>> Lower-cased slug => matching ids.
	 *                                         Empty when $slugs is empty.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function findIdsBySlugs(array $slugs): array {
		if ($slugs === []) {
			return [];
		}

		$lowered = array_values(array_unique(array_map('strtolower', $slugs)));

		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'slug')
			->from($this->getTableName())
			->where(
				$qb->expr()->in(
					$qb->func()->lower('slug'),
					$qb->createNamedParameter(value: $lowered, type: IQueryBuilder::PARAM_STR_ARRAY)
				)
			);

		$result = $qb->executeQuery();
		$map = array_fill_keys($lowered, []);
		while (($row = $result->fetch()) !== false) {
			$key = strtolower((string)$row['slug']);
			if (isset($map[$key]) === false) {
				$map[$key] = [];
			}

			$map[$key][] = (string)$row['id'];
		}

		$result->closeCursor();

		return $map;
	}//end findIdsBySlugs()
}//end class
