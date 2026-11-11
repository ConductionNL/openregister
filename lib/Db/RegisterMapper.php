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

use OCA\OpenRegister\Db\MagicMapper\StatisticsScope;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterDeletedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Rbac\GrantDeclaration;
use OCA\OpenRegister\Service\Rbac\AccessDeclarationValidators;
use OCA\OpenRegister\Service\Rbac\DenyResolverInterface;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogueInterface;
use OCA\OpenRegister\Service\Rbac\RbacChecks;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
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
class RegisterMapper extends QBMapper implements RegisterMapperInterface {

	use ColumnFiltersTrait;
	use MultiTenancyTrait;
	use SlugIdLookupTrait;

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
	 * @var SchemaMapperInterface Schema mapper instance
	 */
	private readonly SchemaMapperInterface $schemaMapper;

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
	 * Structured logger for the resolution diagnostics.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Organisation mapper for multi-tenancy (from trait)
	 *
	 * Used to get active organisation and apply organisation filters.
	 *
	 * @var OrganisationMapperInterface Organisation mapper instance
	 */
	protected OrganisationMapperInterface $organisationMapper;

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
	 * @param IDBConnection          $db        Database connection for queries
	 * @param TenancyServices        $tenancy   The organisation mapper, user session and group manager the tenancy and RBAC checks read
	 * @param IAppConfig             $appConfig App configuration for multitenancy settings
	 * @param RegisterMapperServices $services  The schema mapper, the container, the event dispatcher and the logger
	 *
	 * This class carried four `if (isset($this->logger))` logging sites, plus the
	 * ones it inherits from {@see MultiTenancyTrait}, and never declared or
	 * received a logger — the trait's own docblock says "classes should define
	 * this property themselves" and this one did not. `isset()` on an
	 * undeclared property is always false, so every one of those branches was
	 * dead code that read as working instrumentation.
	 *
	 * The cost was concrete: find() answers `DoesNotExistException` for a
	 * register whose row exists, the endpoint turns that into
	 * "Register not found: '19'", and the error-level line that would have said
	 * WHY — "Register not found after filters", carrying `existsBeforeFilter`,
	 * i.e. "the row matched, then a filter removed it" — silently did not fire.
	 * openregister#2820.
	 *
	 * @return void
	 */
	public function __construct(
		IDBConnection $db,
		TenancyServices $tenancy,
		IAppConfig $appConfig,
		RegisterMapperServices $services,
	) {
		// Initialize parent mapper with table name and entity class.
		parent::__construct(db: $db, tableName: 'openregister_registers', entityClass: Register::class);

		// Store dependencies for use in mapper methods.
		$this->schemaMapper = $services->schemaMapper;
		$this->eventDispatcher = $services->eventDispatcher;
		$this->container = $services->container;
		$this->logger = $services->logger;
		$this->organisationMapper = $tenancy->organisationMapper;
		$this->userSession = $tenancy->userSession;
		$this->groupManager = $tenancy->groupManager;
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
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public function find(
		string|int $id,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): Register {
		// Request-scoped cache, keyed by identifier and both flags.
		$cacheSuffix = $this->findCacheSuffix(_rbac: $_rbac, _multitenancy: $_multitenancy);
		$cacheKey = strtolower((string)$id) . $cacheSuffix;
		if (isset($this->findCache[$cacheKey]) === true) {
			return $this->findCache[$cacheKey];
		}

		$register = $this->findFiltered(
			lookup: new RegisterLookup(id: $id, rbac: $_rbac, multitenancy: $_multitenancy)
		);

		$this->findCache[$cacheKey] = $register;
		$this->cacheRegisterByIdentifiers(register: $register, cacheSuffix: $cacheSuffix);

		return $register;
	}//end find()

	/**
	 * Run one uncached register lookup through the organisation filter.
	 *
	 * Logs the attempt, probes whether the row exists before the filters (for
	 * the miss diagnostics), then reads the oldest matching row.
	 *
	 * @param RegisterLookup $lookup The identifier and filter flags.
	 *
	 * @return Register The register.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no row survives the filters.
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException Never in practice; findEntity() declares it.
	 */
	private function findFiltered(RegisterLookup $lookup): Register {
		$this->logger->info(
			message: '[RegisterMapper] Searching for register',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'identifier' => $lookup->id,
				'rbac' => $lookup->rbac,
				'multi' => $lookup->multitenancy,
			]
		);

		$qb = $this->buildFindByIdentifierQuery(id: $lookup->id);
		$existsBeforeFilter = $this->probeRegisterBeforeFilters(qb: $qb, id: $lookup->id);

		$this->applyOrganisationFilter(
			qb: $qb,
			columnName: 'organisation',
			allowNullOrg: true,
			multiTenancyEnabled: $lookup->multitenancy
		);

		// Deterministic slug/uuid resolution: env churn can leave several rows
		// sharing a slug, which would make findEntity() raise
		// MultipleObjectsReturnedException (a 500 fleet-wide). The oldest
		// (lowest-id) register wins; `openregister:registers:dedupe` merges
		// the duplicates.
		$qb->orderBy('id', 'ASC');
		$qb->setMaxResults(1);

		try {
			return $this->findEntity(query: $qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			// `existsBeforeFilter` separates "no such row" from "the row matched
			// and then a filter removed it": a bad identifier versus a scoping bug.
			$this->logger->error(
				message: '[RegisterMapper] Register not found after filters',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'identifier' => $lookup->id,
					'existsBeforeFilter' => $existsBeforeFilter,
					'multiEnabled' => $lookup->multitenancy,
					'rbacEnabled' => $lookup->rbac,
					'error' => $e->getMessage(),
				]
			);

			throw $e;
		}//end try
	}//end findFiltered()

	/**
	 * The find-cache key suffix for a pair of filter flags, such as `:1:0`.
	 *
	 * @param bool $_rbac         Whether RBAC applies.
	 * @param bool $_multitenancy Whether multitenancy applies.
	 *
	 * @return string The suffix.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flags are the cache key.
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	private function findCacheSuffix(bool $_rbac, bool $_multitenancy): string {
		return ':' . (int)$_rbac . ':' . (int)$_multitenancy;
	}//end findCacheSuffix()

	/**
	 * Cache a found register under its id, uuid and slug, so a later lookup by
	 * any of them is answered without a query.
	 *
	 * @param Register $register    The found register.
	 * @param string   $cacheSuffix The flag suffix of the lookup.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	private function cacheRegisterByIdentifiers(Register $register, string $cacheSuffix): void {
		$this->findCache[(string)$register->getId() . $cacheSuffix] = $register;

		// BUG-DB-10: guard against a null uuid before strtolower().
		$registerUuid = $register->getUuid();
		if ($registerUuid !== null) {
			$this->findCache[strtolower($registerUuid) . $cacheSuffix] = $register;
		}

		if ($register->getSlug() !== null) {
			$this->findCache[strtolower($register->getSlug()) . $cacheSuffix] = $register;
		}
	}//end cacheRegisterByIdentifiers()

	/**
	 * A query for the register whose uuid, slug (case-insensitive) or, for a
	 * numeric identifier, id matches. The id comparison is left out for a
	 * non-numeric identifier because PostgreSQL types it strictly.
	 *
	 * @param int|string $id The identifier.
	 *
	 * @return IQueryBuilder The query, without filters.
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	private function buildFindByIdentifierQuery(string|int $id): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('openregister_registers');

		$orConditions = $qb->expr()->orX(
			$qb->expr()->eq('uuid', $qb->createNamedParameter($id, IQueryBuilder::PARAM_STR)),
			$qb->expr()->eq(
				$qb->func()->lower('slug'),
				$qb->createNamedParameter(strtolower((string)$id), IQueryBuilder::PARAM_STR)
			)
		);

		if (is_numeric($id) === true) {
			$orConditions->add($qb->expr()->eq('id', $qb->createNamedParameter((int)$id, IQueryBuilder::PARAM_INT)));
		}

		$qb->where($orConditions);

		return $qb;
	}//end buildFindByIdentifierQuery()

	/**
	 * Whether the register exists before the organisation filter, for the
	 * not-found diagnostics. The probe is capped to one row in id order, so
	 * duplicate slugs cannot raise MultipleObjectsReturnedException out of it.
	 *
	 * @param IQueryBuilder $qb The unfiltered lookup query; it is cloned, not changed.
	 * @param int|string    $id The identifier, for the log.
	 *
	 * @return bool Whether a row matched.
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	private function probeRegisterBeforeFilters(IQueryBuilder $qb, string|int $id): bool {
		$qbBeforeFilter = clone $qb;
		$qbBeforeFilter->orderBy('id', 'ASC');
		$qbBeforeFilter->setMaxResults(1);
		try {
			$testResult = $this->findEntity(query: $qbBeforeFilter);
		} catch (\OCP\AppFramework\Db\DoesNotExistException|\OCP\AppFramework\Db\MultipleObjectsReturnedException $e) {
			// The exception class is recorded in `cause` rather than hedged in
			// prose: absence and duplication have different fixes (learniq#620).
			$this->logger->warning(
				message: '[RegisterMapper] Register not resolvable before filters',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'identifier' => $id,
					'cause' => $e::class,
				]
			);
			return false;
		}//end try

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
		return true;
	}//end probeRegisterBeforeFilters()

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
			} catch (\OCP\AppFramework\Db\DoesNotExistException|\OCP\AppFramework\Db\MultipleObjectsReturnedException $e) {
				// Ids that do not resolve to exactly one register are left out of the result.
				$this->logger->debug(
					message: '[RegisterMapper] findMultiple skips a register id that does not resolve',
					context: [
						'id' => $id,
						'exception' => $e->getMessage(),
					]
				);
			} catch (\OCP\DB\Exception $e) {
				// A database error drops this id from the result rather than failing the whole batch.
				$this->logger->warning(
					message: '[RegisterMapper] findMultiple skips a register after a database error',
					context: [
						'id' => $id,
						'exception' => $e->getMessage(),
					]
				);
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
		int|null $limit = null,
		int|null $offset = null,
		array|null $filters = [],
		array|null $searchConditions = [],
		array|null $searchParams = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array {
		// Verify RBAC permission to read registers if RBAC is enabled.
		if ($_rbac === true) {
			// $this->verifyRbacPermission('read', 'register');
		}

		$qb = $this->listedQuery(query: new ListQuery(limit: ($limit ?? ListQuery::UNBOUNDED), offset: ($offset ?? 0), filters: ($filters ?? [])));
		$this->applySearchConditions(qb: $qb, searchConditions: $searchConditions, searchParams: $searchParams);

		return $this->findInTenancy(qb: $qb, multitenancy: $_multitenancy);
	}//end findAll()

	/**
	 * The register list query for one page of column-filtered rows.
	 *
	 * @param ListQuery $query The limit (unbounded for every row), the offset and the column filters.
	 *
	 * @return IQueryBuilder The query, before search conditions and the organisation filter.
	 */
	private function listedQuery(ListQuery $query): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('openregister_registers')
			->setMaxResults($query->limitOrNull())
			->setFirstResult($query->offset ?? 0);

		$this->applyColumnFilters(qb: $qb, filters: $query->filters);

		return $qb;
	}//end listedQuery()

	/**
	 * The registers a query finds, limited to the caller's organisations when multitenancy applies.
	 *
	 * Registers without an organisation stay visible.
	 *
	 * @param IQueryBuilder $qb           The query.
	 * @param bool          $multitenancy Whether to apply the organisation filter.
	 *
	 * @return Register[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Register>
	 */
	private function findInTenancy(IQueryBuilder $qb, bool $multitenancy): array {
		$this->applyOrganisationFilter(
			qb: $qb,
			columnName: 'organisation',
			allowNullOrg: true,
			multiTenancyEnabled: $multitenancy
		);

		return $this->findEntities(query: $qb);
	}//end findInTenancy()

	/**
	 * OR the raw search conditions into the query and bind their parameters.
	 *
	 * Nothing is added, and no parameter is bound, when there are no conditions.
	 *
	 * @param IQueryBuilder $qb               The query to add the conditions to.
	 * @param array|null    $searchConditions SQL fragments, joined with OR.
	 * @param array|null    $searchParams     Parameter name to value for those fragments.
	 *
	 * @return void
	 */
	private function applySearchConditions(IQueryBuilder $qb, array|null $searchConditions, array|null $searchParams): void {
		if (empty($searchConditions) === true) {
			return;
		}

		$qb->andWhere('(' . implode(' OR ', $searchConditions) . ')');
		foreach ($searchParams ?? [] as $param => $value) {
			$qb->setParameter($param, $value);
		}
	}//end applySearchConditions()

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
			$register->setSlug($this->slugFromTitle(title: $register->getTitle()));
		}

		$this->applyVersionAndSourceDefaults(register: $register);

		$this->validateAuthorizationDeny(register: $register);
	}//end cleanObject()

	/**
	 * A slug from a register title: lowercased, anything but a-z, 0-9 and dashes
	 * turned into a dash, runs of dashes collapsed, and no dash at either end.
	 *
	 * @param string|null $title The title, or null for the word "register".
	 *
	 * @return string
	 */
	private function slugFromTitle(string|null $title): string {
		// Convert to lowercase and replace spaces with dashes.
		$slug = strtolower(trim($title ?? 'register'));
		// Assuming title is used for slug.
		// Remove special characters.
		$slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
		// Remove multiple dashes.
		$slug = preg_replace('/-+/', '-', $slug);
		// Remove leading/trailing dashes.
		return trim($slug, '-');
	}//end slugFromTitle()

	/**
	 * Give a register without a version 0.0.1, and one without a source 'internal'.
	 *
	 * @param Register $register The register.
	 *
	 * @return void
	 */
	private function applyVersionAndSourceDefaults(Register $register): void {
		// Ensure the object has a version.
		if ($register->getVersion() === null) {
			$register->setVersion('0.0.1');
		}

		// Ensure the object has a source set to 'internal' by default.
		if ($register->getSource() === null || $register->getSource() === '') {
			$register->setSource('internal');
		}
	}//end applyVersionAndSourceDefaults()

	/**
	 * Refuse an authorization block whose deny contradicts its own grants.
	 *
	 * The register is where `manage` lives, so this is the level the
	 * orphaned-administration refusal actually bites at: a register whose
	 * `manage` verb has been denied to the last principal holding it can no
	 * longer have its own access rules edited, and is recoverable only from the
	 * database.
	 *
	 * @param Register $register Register to validate.
	 *
	 * @return void
	 *
	 * @throws \OCA\OpenRegister\Exception\AuthorizationBlockException When the block contradicts itself.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function validateAuthorizationDeny(Register $register): void {
		$authorization = $register->getAuthorization();
		$roleDefinitions = $this->roleDefinitionsOf(register: $register);

		if ($this->isEmptyAuthorization(authorization: $authorization) === true && $roleDefinitions === null) {
			return;
		}

		$subject = $this->authorizationSubject(register: $register);

		// The catalogue check runs FIRST, and it is the only one that reads the
		// register's ROLE definitions: a role's `actions` array is where an
		// unknown verb used to survive longest. It matched nothing, so it
		// granted nothing, and the role read as correctly configured in every
		// screen that showed it.
		AccessDeclarationValidators::permissionCatalogue(eventDispatcher: $this->eventDispatcher)->assertGrantable(
			declaration: new GrantDeclaration(authorization: $authorization, roleDefinitions: $roleDefinitions, subject: $subject)
		);

		if ($this->isEmptyAuthorization(authorization: $authorization) === true) {
			return;
		}

		RbacChecks::metadataConditions(db: $this->db)->assertRegisterSavable(register: $register, subject: $subject);
		RbacChecks::unknownRuleKeys(db: $this->db)->assertRegisterSavable(register: $register, subject: $subject);

		AccessDeclarationValidators::denyValidator()->assertStorable(
			authorization: $authorization,
			subject: $subject
		);
	}//end validateAuthorizationDeny()

	/**
	 * The role definitions the register's configuration declares, or null when it declares none.
	 *
	 * @param Register $register The register.
	 *
	 * @return mixed The `roles` entry of the configuration, or null.
	 */
	private function roleDefinitionsOf(Register $register): mixed {
		$configuration = $register->getConfiguration();
		if (is_array($configuration) === true) {
			return ($configuration['roles'] ?? null);
		}

		return null;
	}//end roleDefinitionsOf()

	/**
	 * Whether an authorization block is missing: not an array, or an empty one.
	 *
	 * @param mixed $authorization The block as stored.
	 *
	 * @return bool
	 */
	private function isEmptyAuthorization(mixed $authorization): bool {
		return is_array($authorization) === false || $authorization === [];
	}//end isEmptyAuthorization()

	/**
	 * How a refusal names the register: by slug, else title, else empty.
	 *
	 * @param Register $register The register.
	 *
	 * @return string
	 */
	private function authorizationSubject(Register $register): string {
		return sprintf('the register "%s"', (string)($register->getSlug() ?? $register->getTitle() ?? ''));
	}//end authorizationSubject()

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
	 *
	 * @spec openspec/specs/activity-provider/spec.md#requirement-a-register-save-that-changes-nothing-publishes-nothing
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

		// Dispatch the update event only when the register actually changed. An
		// app re-importing its unchanged register (setup wizard, example data)
		// otherwise told every admin "Register was updated" while they edited an object.
		if ((new EntityChangeDetector())->changed(old: $oldSchema, new: $entity) === true) {
			$this->eventDispatcher->dispatchTyped(new RegisterUpdatedEvent(newRegister: $entity, oldRegister: $oldSchema));
		}

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
			$register->setVersion((new RegisterMapperHelper())->bumpPatchVersion(version: $currentVersion));
		}

		$register->hydrate(object: $object);

		// Clean the register object to ensure UUID, extend: slug, files: and version are set.
		$this->cleanObject(register: $register);

		$register = $this->update(entity: $register);

		return $register;
	}//end updateFromArray()

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

		$objectEntityMapper = $this->container->get(MagicMapperInterface::class);
		$stats = $objectEntityMapper->getStatistics(scope: new StatisticsScope(registerId: $registerId));
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

		return $this->findLinkedSchemas(schemaIds: $register->getSchemas(), _rbac: $_rbac);
	}//end getSchemasByRegisterId()

	/**
	 * Fetch each schema a register links, skipping the ones that no longer exist.
	 *
	 * Uses $_multitenancy=false to bypass the organisation filter: the register
	 * has already passed access checks, so schemas linked to an accessible
	 * register can always be found.
	 *
	 * @param array $schemaIds The register's schema ids.
	 * @param bool  $_rbac     Whether to apply RBAC filtering to each schema.
	 *
	 * @return Schema[] The schemas found, in link order.
	 */
	private function findLinkedSchemas(array $schemaIds, bool $_rbac): array {
		$schemas = [];
		foreach ($schemaIds as $schemaId) {
			try {
				$schemas[] = $this->schemaMapper->find(id: (int)$schemaId, _extend: [], _rbac: $_rbac, _multitenancy: false);
			} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
				// Schema not found, skip it (similar to RegistersController behavior).
				continue;
			}
		}

		return $schemas;
	}//end findLinkedSchemas()

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
		// PermissionHandlerInterface authorization resolver (getRegisterForSchema), whose
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
			$schemas = (new RegisterMapperHelper())->decodeSchemasField(raw: ($row['schemas'] ?? null));
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
}//end class
