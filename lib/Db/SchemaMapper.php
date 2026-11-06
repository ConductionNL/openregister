<?php

/**
 * OpenReg  ister Audit Trail
 *
 * This file contains the class for handling audit trail related operations
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

use Closure;
use DateTime;
use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Db\SchemaMapper\AdvisoryAnnotationChecks;
use OCA\OpenRegister\Db\SchemaMapper\CalculationAnnotationCheck;
use OCA\OpenRegister\Db\SchemaMapper\ConfigurationFields;
use OCA\OpenRegister\Db\SchemaMapper\DeclaredActionCheck;
use OCA\OpenRegister\Db\SchemaMapper\ExchangeAnnotationRefusals;
use OCA\OpenRegister\Db\SchemaMapper\ExtendedByReads;
use OCA\OpenRegister\Db\SchemaMapper\FacetConfiguration;
use OCA\OpenRegister\Db\SchemaMapper\LifecycleAnnotationCheck;
use OCA\OpenRegister\Db\SchemaMapper\PropertyDeclarationRefusals;
use OCA\OpenRegister\Db\SchemaMapper\RecordAnnotationRefusals;
use OCA\OpenRegister\Db\SchemaMapper\ReferenceNormaliser;
use OCA\OpenRegister\Db\SchemaMapper\RelationDeclarationCheck;
use OCA\OpenRegister\Db\SchemaMapper\SchemaComposition;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDefaults;
use OCA\OpenRegister\Db\SchemaMapper\SchemaReferenceSearch;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Consent\ConsentAnnotationValidator;
use OCA\OpenRegister\Service\Decision\ReviewAnnotationValidator;
use OCA\OpenRegister\Service\Decision\ReviewDeclarationException;
use OCA\OpenRegister\Service\Consent\ConsentDeclarationException;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionValidator;
use OCA\OpenRegister\Service\Rbac\AuthorizationDenyValidator;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCA\OpenRegister\Service\Rbac\DepartmentMatrixCompiler;
use OCA\OpenRegister\Service\Rbac\DepartmentMatrixValidator;
use OCA\OpenRegister\Service\Rbac\HierarchyAnnotationValidator;
use OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogue;
use OCA\OpenRegister\Service\Rbac\MetadataConditionFindings;
use OCA\OpenRegister\Service\Rbac\RevealCollector;
use OCA\OpenRegister\Service\Rbac\UnknownRuleKeyFindings;
use OCA\OpenRegister\Service\Registry\RegistryAnnotationValidator;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCA\OpenRegister\Service\Schemas\ScopedPropertyDeclaration;
use OCA\OpenRegister\Service\Schemas\ScopedPropertyException;
use OCA\OpenRegister\Service\Schemas\ScopedPropertyGovernance;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * SchemaMapper handles database operations for Schema entities
 *
 * Mapper for Schema entities with multi-tenancy and RBAC support.
 * Provides CRUD operations with automatic organisation filtering, RBAC checks,
 * schema extension resolution, and event dispatching.
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
 * @method Schema insert(Entity $entity)
 * @method Schema update(Entity $entity)
 * @method Schema insertOrUpdate(Entity $entity)
 * @method Schema delete(Entity $entity, bool $force=false)
 * @method Schema findEntity(IQueryBuilder $query)
 * @method list<Schema> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<Schema>
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyMethods)           Many methods required for schema management and analysis
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class SchemaMapper extends QBMapper implements SchemaMapperInterface {

	use ColumnFiltersTrait;
	use MultiTenancyTrait;
	use SlugIdLookupTrait;

	/**
	 * Maximum number of expressions allowed in a single SQL IN() list.
	 *
	 * Nextcloud's QueryBuilder refuses to emit more than 1000 expressions in an
	 * IN() list because Oracle rejects them; exceeding it logs an error and
	 * emits an "Undefined array key 0" PHP warning. Batched id lookups must be
	 * chunked below this bound.
	 *
	 * @var integer
	 */
	private const MAX_IN_LIST_SIZE = 1000;

	/**
	 * Event dispatcher instance
	 *
	 * Dispatches events when schemas are created, updated, or deleted.
	 *
	 * @var IEventDispatcher Event dispatcher instance
	 */
	private readonly IEventDispatcher $eventDispatcher;

	/**
	 * Schema property validator instance
	 *
	 * Validates schema property definitions and types.
	 *
	 * @var PropertyValidatorHandler Schema property validator instance
	 */
	private readonly PropertyValidatorHandler $validator;

	/**
	 * Organisation mapper for multi-tenancy
	 *
	 * Used to get active organisation and apply organisation filters.
	 *
	 * @var OrganisationMapperInterface Organisation mapper instance
	 */
	protected readonly OrganisationMapperInterface $organisationMapper;

	/**
	 * App configuration for multi-tenancy settings
	 *
	 * Used by MultiTenancyTrait to check multi-tenancy status.
	 *
	 * @var IAppConfig App configuration instance
	 */
	private IAppConfig $appConfig;

	/**
	 * Request-scoped in-memory cache for find() results
	 *
	 * Prevents redundant DB queries when the same schema is looked up
	 * multiple times within one request (e.g. controller → service → render).
	 * Keys are composed of identifier + RBAC + multitenancy flags.
	 *
	 * @var array<string, Schema>
	 */
	private array $findCache = [];

	/**
	 * Read-attribution counters, keyed by "<mapper method>|<caller signature>".
	 *
	 * Only populated when {@see self::$perfTrace} is on. Static so the picture
	 * spans every mapper instance in the request.
	 *
	 * @var array<string, int>
	 */
	private static array $perfCounts = [];

	/**
	 * Whether read attribution is on: null until resolved, then a bool.
	 *
	 * Resolved once per process — reading app config per schema read would
	 * itself be one of the reads we are trying to count.
	 *
	 * @var boolean|null
	 */
	private static ?bool $perfTrace = null;

	/**
	 * User session for current user
	 *
	 * Used to get current user context for RBAC and multi-tenancy.
	 *
	 * @var IUserSession User session instance
	 */
	private readonly IUserSession $userSession;

	/**
	 * Group manager for RBAC
	 *
	 * Used to check user group memberships for permission verification.
	 *
	 * @var IGroupManager Group manager instance
	 */
	private readonly IGroupManager $groupManager;

	// Note: $appConfig is provided by MultiTenancyTrait (protected ?IAppConfig $appConfig=null)
	// We assign it in the constructor to make it available to the trait methods.

	/**
	 * SchemaUpdatedEvents dispatched in this request, keyed by schema id.
	 *
	 * @var array<int, int>
	 */
	private array $updateEventCounts = [];

	/**
	 * Constructor
	 *
	 * Initializes mapper with database connection and required dependencies
	 * for multi-tenancy, RBAC, validation, and event dispatching.
	 *
	 * @param IDBConnection $db Database connection for queries
	 * @param IEventDispatcher $eventDispatcher Event dispatcher for schema events
	 * @param PropertyValidatorHandler $validator Schema property validator for validation
	 * @param OrganisationMapperInterface $organisationMapper Organisation mapper for multi-tenancy
	 * @param IUserSession $userSession User session for current user context
	 * @param IGroupManager $groupManager Group manager for RBAC checks
	 * @param IAppConfig $appConfig App configuration for multitenancy settings
	 * @param LoggerInterface $logger Structured logger (R07: surfaces unknown annotation keys).
	 * @param ObjectActionValidator|Closure|null $objectActions Verifies every declared action, the flows macros bind to included;
	 *                                                         a Closure returns it on first use, which breaks the
	 *                                                         construction cycle through the flow services.
	 *
	 * @return void
	 */
	public function __construct(
		IDBConnection $db,
		IEventDispatcher $eventDispatcher,
		PropertyValidatorHandler $validator,
		OrganisationMapperInterface $organisationMapper,
		IUserSession $userSession,
		IGroupManager $groupManager,
		IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		// LAST AND NULLABLE so every existing construction of this mapper keeps
		// working. Nextcloud's container always supplies it; null happens only
		// in a hand-built test, and then the SHAPE refusals below still fire —
		// only the questions about other records (the bound flow, the step
		// nodes, the saver) are skipped, which the save says out loud rather
		// than passing over in silence.
		private readonly ObjectActionValidator|Closure|null $objectActions = null,
	) {
		// Initialize parent mapper with table name and entity class.
		parent::__construct(db: $db, tableName: 'openregister_schemas', entityClass: Schema::class);

		// Store dependencies for use in mapper methods.
		$this->eventDispatcher = $eventDispatcher;
		$this->validator = $validator;
		$this->organisationMapper = $organisationMapper;
		$this->userSession = $userSession;
		$this->groupManager = $groupManager;
		// Assign appConfig to trait's protected property.
		$this->appConfig = $appConfig;
	}//end __construct()

	/**
	 * Record one schema read against its caller, when attribution is enabled.
	 *
	 * A single object create was measured issuing 5,135 sequential scans of
	 * `oc_openregister_schemas` (2026-07-28). The count alone does not say
	 * which path is responsible, and the fix differs per path — a cache key
	 * that is too specific is a different repair from an uncached sibling
	 * method. This records `<mapper method>|<caller signature>` so the answer
	 * is measured rather than guessed.
	 *
	 * Off by default and gated on `openregister perf_trace_schema_reads`,
	 * resolved once per process: reading app config on every schema read would
	 * itself be one of the reads being counted.
	 *
	 * @param string $method The mapper method issuing the read.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.ErrorControlOperator) The trace file is a
	 * best-effort diagnostic written from a shutdown function; a failure to
	 * write it must never surface as a warning on a real request.
	 *
	 * @spec openspec/changes/object-write-sub-500ms/specs/object-write-performance/spec.md
	 */
	private function traceRead(string $method): void {
		if (self::$perfTrace === null) {
			self::$perfTrace = ($this->appConfig->getValueString('openregister', 'perf_trace_schema_reads', '') === '1');
			if (self::$perfTrace === true) {
				register_shutdown_function(
					static function (): void {
						if (empty(self::$perfCounts) === true) {
							return;
						}

						@file_put_contents(
							'/tmp/or-schema-reads.jsonl',
							json_encode(self::$perfCounts) . PHP_EOL,
							(FILE_APPEND | LOCK_EX)
						);
						self::$perfCounts = [];
					}
				);
			}
		}

		if (self::$perfTrace === false) {
			return;
		}

		// Four frames past this one is enough to name the responsible path
		// without paying for a full backtrace on every read.
		$frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);
		$sig = [];
		foreach (array_slice($frames, 2, 4) as $frame) {
			$sig[] = (($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '?'));
		}

		$key = $method . '|' . implode('<', $sig);
		if (isset(self::$perfCounts[$key]) === false) {
			self::$perfCounts[$key] = 0;
		}

		self::$perfCounts[$key]++;

	}//end traceRead()

	/**
	 * Finds a schema by id, with optional extension for statistics
	 *
	 * This method automatically resolves schema extensions. If the schema has
	 * an 'extend' property set, it will load the parent schema and merge its
	 * properties with the current schema, providing the complete resolved schema.
	 *
	 * @param int|string $id The id of the schema
	 * @param array $_extend Optional array of extensions (e.g., ['@self.stats'])
	 * @param bool $_rbac Whether to apply RBAC permission checks (default: true)
	 * @param bool $_multitenancy Whether to apply multi-tenancy filtering (default: true)
	 *                            Set to false to bypass organization filter
	 *                            (e.g., when expanding schemas for registers)
	 *
	 * @return Schema The schema, possibly with stats and resolved extensions
	 * @throws \Exception If user doesn't have read permission
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Flags control security filtering behavior
	 * @SuppressWarnings(PHPMD.StaticAccess)          Schema::fromRow is a standard entity factory pattern
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public function find(
		string|int $id,
		?array $_extend = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
	): Schema {
		// Request-scoped cache, keyed by identifier and both flags.
		$cacheSuffix = ':' . (int)$_rbac . ':' . (int)$_multitenancy;
		$cacheKey = strtolower((string)$id) . $cacheSuffix;
		if (isset($this->findCache[$cacheKey]) === true) {
			return $this->findCache[$cacheKey];
		}

		$this->traceRead(method: 'find');
		\OCA\OpenRegister\Service\WritePhaseProbe::count(event: 'schema.db.read');

		$qb = $this->buildFindByIdentifierQuery(id: $id);

		// Set $_multitenancy=false to bypass the organisation filter (e.g. when expanding schemas for registers).
		$this->applyOrganisationFilter(
			qb: $qb,
			columnName: 'organisation',
			allowNullOrg: true,
			multiTenancyEnabled: $_multitenancy
		);

		// Two rows, not one: enough to KNOW the identifier was ambiguous, which
		// a capped single-row fetch can never tell you.
		$qb->setMaxResults(2);

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		if ($rows === []) {
			throw new DoesNotExistException(
				sprintf(
					'Schema not found (id=%s, multitenancy=%s, rbac=%s)',
					var_export($id, true),
					var_export($_multitenancy, true),
					var_export($_rbac, true)
				)
			);
		}

		$this->warnAboutAmbiguousIdentifier(id: $id, rows: $rows);

		// Resolve schema composition if present (allOf, oneOf, anyOf).
		$schema = $this->composition()->resolve(schema: Schema::fromRow($rows[0]));

		// BUG-DB-10: cache writes reuse the read-side suffix so they stay consistent.
		$this->findCache[$cacheKey] = $schema;
		$this->cacheSchemaByIdentifiers(schema: $schema, cacheSuffix: $cacheSuffix);

		return $schema;
	}//end find()

	/**
	 * Cache a found schema under its id, uuid and slug, so a later lookup by
	 * any of them is answered without a query.
	 *
	 * @param Schema $schema      The found schema.
	 * @param string $cacheSuffix The flag suffix of the lookup.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	private function cacheSchemaByIdentifiers(Schema $schema, string $cacheSuffix): void {
		$this->findCache[(string)$schema->getId() . $cacheSuffix] = $schema;

		// BUG-DB-10: guard against a null uuid before strtolower().
		$schemaUuid = $schema->getUuid();
		if ($schemaUuid !== null) {
			$this->findCache[strtolower($schemaUuid) . $cacheSuffix] = $schema;
		}

		if ($schema->getSlug() !== null) {
			$this->findCache[strtolower($schema->getSlug()) . $cacheSuffix] = $schema;
		}
	}//end cacheSchemaByIdentifiers()

	/**
	 * A query for the schema whose uuid, slug (case-insensitive) or, for a
	 * numeric identifier, id matches, in tie-break order.
	 *
	 * BUG-DB-10: a numeric identifier could be a primary key or a numeric
	 * uuid/slug, so an exact `id` hit is ordered first; a row whose slug is
	 * "5" never shadows the row with id 5. The id comparison is left out for
	 * a non-numeric identifier because PostgreSQL types it strictly.
	 *
	 * @param int|string $id The identifier.
	 *
	 * @return IQueryBuilder The query, without the organisation filter.
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	private function buildFindByIdentifierQuery(string|int $id): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('openregister_schemas');

		$orConditions = $qb->expr()->orX(
			$qb->expr()->eq('uuid', $qb->createNamedParameter(value: $id, type: IQueryBuilder::PARAM_STR)),
			$qb->expr()->eq(
				$qb->func()->lower('slug'),
				$qb->createNamedParameter(value: strtolower((string)$id), type: IQueryBuilder::PARAM_STR)
			)
		);

		$idParam = null;
		if (is_numeric($id) === true) {
			$idParam = $qb->createNamedParameter(value: (int)$id, type: IQueryBuilder::PARAM_INT);
			$orConditions->add(
				$qb->expr()->eq('id', $idParam)
			);
		}

		$qb->where($orConditions);

		if ($idParam !== null) {
			$qb->addOrderBy(
				$qb->createFunction(
					'CASE WHEN id = ' . $idParam . ' THEN 0 ELSE 1 END'
				),
				'ASC'
			);
		}

		// A bare slug can legitimately match SEVERAL rows. Since
		// Version1Date20260723000000 widened the unique key from
		// (organisation, slug) to (organisation, application, slug), two apps
		// may each own a schema with the same generic slug — that widening was
		// deliberate, and reverting it would put back the collision it fixed.
		//
		// What did NOT land with it is the determinism its own docblock promised
		// ("resolution is scoped by app (import) and by register (runtime) in
		// the accompanying code change"). The scoped resolvers exist, but every
		// one of them falls back HERE when it misses, and here there was no
		// ORDER BY and no row cap at all: which same-slug schema won was decided
		// by the storage engine.
		//
		// Measured 2026-08-02: schemas 5012 "AgentFlow" (application '') and
		// 5020 "Agent flow" (application 'hermiq') both carry slug `agentflow`
		// in one organisation. 5012 holds 1 object, 5020 holds 46. Every
		// slug-based resolution was a coin flip that happened to keep landing on
		// the populated one.
		//
		// The tie-break, in order. First: a row an app OWNS beats an
		// unattributed one. The widened key exists precisely to express
		// ownership, so a leftover row that no app claims must never shadow an
		// app's real schema. Second: the lowest id, matching
		// RegisterMapper::find() and the `openregister:schemas:dedup` command's
		// keep-the-lowest rule.
		//
		// Ownership has to come first, and not only for tidiness: on the live
		// duplicate the unordered query returns the UNATTRIBUTED row first, and
		// so would a bare `ORDER BY id ASC`.
		$qb->addOrderBy(
			$qb->createFunction("CASE WHEN application IS NULL OR application = '' THEN 1 ELSE 0 END"),
			'ASC'
		);
		$qb->addOrderBy('id', 'ASC');

		return $qb;
	}//end buildFindByIdentifierQuery()

	/**
	 * Warn when an identifier matched more than one schema, naming every
	 * candidate and the one it resolved to.
	 *
	 * @param int|string                      $id   The identifier.
	 * @param array<int, array<string, mixed>> $rows The matched rows, in tie-break order.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	private function warnAboutAmbiguousIdentifier(string|int $id, array $rows): void {
		if (count($rows) > 1) {
			$this->logger->warning(
				message: sprintf(
					'[SchemaMapper] Identifier "%s" matches more than one schema; resolving to #%s ("%s", application "%s"). '
					. 'Fix the duplicate with `occ openregister:schemas:dedup`.',
					(string)$id,
					(string)($rows[0]['id'] ?? '?'),
					(string)($rows[0]['title'] ?? '?'),
					(string)($rows[0]['application'] ?? '')
				),
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'identifier' => (string)$id,
					'candidates' => array_map(
						static function (array $candidate): array {
							return [
								'id' => ($candidate['id'] ?? null),
								'title' => ($candidate['title'] ?? null),
								'slug' => ($candidate['slug'] ?? null),
								'application' => ($candidate['application'] ?? null),
							];
						},
						$rows
					),
				]
			);
		}//end if
	}//end warnAboutAmbiguousIdentifier()

	/**
	 * Resolve a schema by slug, scoped to a single owning application.
	 *
	 * The plain {@see find()} matches a schema by `LOWER(slug)` GLOBALLY across
	 * every application on the instance and returns the FIRST row it fetches.
	 * On a shared instance where ~20 apps live in one OpenRegister, that lets a
	 * generic slug (e.g. `conversation`, `order`, `task`) from app B silently
	 * bind to — or, on import, OVERWRITE — app A's schema that happens to share
	 * the lower(slug). This scoped lookup is the import-side fix: an app only
	 * ever matches (and therefore updates) a schema IT owns, so importing a
	 * colliding slug creates the app's OWN schema instead of clobbering a
	 * foreign one.
	 *
	 * @param string $slug The schema slug (matched case-insensitively).
	 * @param string $application The owning application id (exact match).
	 *
	 * @return Schema|null The application-owned schema, or null when the app
	 *                     does not (yet) own a schema with this slug.
	 */
	public function findByApplicationAndSlug(string $slug, string $application): ?Schema {
		$this->traceRead(method: 'findByApplicationAndSlug');

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('openregister_schemas')
			->where(
				$qb->expr()->eq(
					$qb->func()->lower('slug'),
					$qb->createNamedParameter(value: strtolower($slug), type: IQueryBuilder::PARAM_STR)
				)
			)
			->andWhere(
				$qb->expr()->eq('application', $qb->createNamedParameter(value: $application, type: IQueryBuilder::PARAM_STR))
			)
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			return null;
		}

		return $this->composition()->resolve(schema: Schema::fromRow($row));
	}//end findByApplicationAndSlug()

	/**
	 * EVERY schema an application owns under a slug, not just the first.
	 *
	 * `findByApplicationAndSlug()` caps at one row, which is correct where the
	 * pair is treated as unique. It is not unique in practice: the import unions
	 * schema ids and never removes one, so a descriptor edit that renames or
	 * retires a schema leaves the old row behind and a later import can add a
	 * second under the same pair.
	 *
	 * That is exactly the situation `openregister:schemas:prune-retired` exists
	 * to clean up, and reading one row per run meant it removed one of two and
	 * reported success. Measured on a live instance: opencatalogi owned two
	 * `document` rows (ids 39 and 40), the command pruned 40, said
	 * `Pruned=1, skipped=0`, and left 39 answering every lookup.
	 *
	 * @param string $slug        The schema slug, matched case-insensitively.
	 * @param string $application The owning application id.
	 *
	 * @return array<int, Schema> Every matching schema, oldest id first.
	 *
	 * @spec openspec/specs/schema-import/spec.md#requirement-a-schema-retired-from-a-descriptor-must-be-removable-from-the-instance
	 */
	public function findAllByApplicationAndSlug(string $slug, string $application): array {
		$this->traceRead(method: 'findAllByApplicationAndSlug');

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('openregister_schemas')
			->where(
				$qb->expr()->eq(
					$qb->func()->lower('slug'),
					$qb->createNamedParameter(value: strtolower($slug), type: IQueryBuilder::PARAM_STR)
				)
			)
			->andWhere(
				$qb->expr()->eq('application', $qb->createNamedParameter(value: $application, type: IQueryBuilder::PARAM_STR))
			)
			->orderBy('id', 'ASC');

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return array_map(
			fn (array $row): Schema => $this->composition()->resolve(schema: Schema::fromRow($row)),
			$rows
		);
	}//end findAllByApplicationAndSlug()

	/**
	 * Resolve a schema by slug, scoped to a set of schema ids.
	 *
	 * This is the runtime-resolution half of the cross-app slug-collision fix.
	 * When an object operation carries a register context, a schema slug must
	 * resolve to the schema THAT REGISTER references — not to whichever
	 * same-slug row {@see find()} happens to fetch first. Callers pass the
	 * register's own `schemas` id list; the slug is matched only among those.
	 *
	 * @param string $slug The schema slug (matched case-insensitively).
	 * @param int[] $schemaIds The candidate schema ids (a register's schemas list).
	 *
	 * @return Schema|null The matching schema within the id set, or null when
	 *                     none of the register's schemas carry this slug.
	 */
	public function findBySlugInIds(string $slug, array $schemaIds): ?Schema {
		// Normalise to a list of positive integers; an empty set can never match.
		$ids = [];
		foreach ($schemaIds as $candidate) {
			if (is_numeric($candidate) === true && (int)$candidate > 0) {
				$ids[] = (int)$candidate;
			}
		}

		if ($ids === []) {
			return null;
		}

		$this->traceRead(method: 'findBySlugInIds');

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('openregister_schemas')
			->where(
				$qb->expr()->eq(
					$qb->func()->lower('slug'),
					$qb->createNamedParameter(value: strtolower($slug), type: IQueryBuilder::PARAM_STR)
				)
			)
			->andWhere(
				$qb->expr()->in('id', $qb->createNamedParameter(value: $ids, type: IQueryBuilder::PARAM_INT_ARRAY))
			)
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			return null;
		}

		return $this->composition()->resolve(schema: Schema::fromRow($row));
	}//end findBySlugInIds()


	/**
	 * Resolve a schema identifier (numeric id, uuid, or slug) within a set of schema ids.
	 *
	 * The register-scoped counterpart of {@see find()}: it matches the SAME
	 * identifier forms with the SAME case-insensitive slug semantics and the SAME
	 * tie-break ordering, but only among the given ids — a register's `schemas`
	 * list. {@see findBySlugInIds()} scopes slugs only; this method exists so the
	 * boundary a caller names holds for EVERY identifier form: a numeric id or a
	 * uuid the register does not carry must not resolve merely because a schema
	 * with that id exists elsewhere on the instance.
	 *
	 * @param string|int $id        The identifier — numeric id, uuid, or slug (slug matched case-insensitively).
	 * @param array      $schemaIds The candidate schema ids (a register's schemas list).
	 *
	 * @return Schema|null The matching schema within the id set, or null when none matches.
	 *
	 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
	 */
	public function findInIds(string|int $id, array $schemaIds): ?Schema {
		// Normalise to a list of positive integers; an empty set can never match.
		$ids = [];
		foreach ($schemaIds as $candidate) {
			if (is_numeric($candidate) === true && (int)$candidate > 0) {
				$ids[] = (int)$candidate;
			}
		}

		if ($ids === []) {
			return null;
		}

		$this->traceRead(method: 'findInIds');

		// Same identifier forms and tie-breaks as find(), narrowed to the id set.
		$qb = $this->buildFindByIdentifierQuery(id: (string)$id);
		$qb->andWhere(
			$qb->expr()->in('id', $qb->createNamedParameter(value: $ids, type: IQueryBuilder::PARAM_INT_ARRAY))
		);
		$qb->setMaxResults(1);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			return null;
		}

		return $this->composition()->resolve(schema: Schema::fromRow($row));
	}//end findInIds()

	/**
	 * Count how many schemas on the instance carry a slug.
	 *
	 * Used only to build the message of {@see SchemaNotInRegisterException}. A
	 * register-scoped miss reads as "your slug is wrong" unless the caller is told
	 * how many same-slug schemas exist — and when that number is nine, as measured
	 * for `anonymizationLink` on 2026-08-16, "your slug is wrong" is the one
	 * conclusion that is certainly false.
	 *
	 * Deliberately unscoped by organisation and application: the point is to report
	 * the size of the ambiguity the caller just escaped, not to resolve anything.
	 *
	 * @param string $slug The schema slug (matched case-insensitively).
	 *
	 * @return int The number of schemas carrying this slug.
	 *
	 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
	 */
	public function countBySlug(string $slug): int {
		$this->traceRead(method: 'countBySlug');

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'slug_count'))
			->from('openregister_schemas')
			->where(
				$qb->expr()->eq(
					$qb->func()->lower('slug'),
					$qb->createNamedParameter(value: strtolower($slug), type: IQueryBuilder::PARAM_STR)
				)
			);

		$result = $qb->executeQuery();
		$row    = $result->fetch();
		$result->closeCursor();

		return (int)($row['slug_count'] ?? 0);
	}//end countBySlug()

	/**
	 * Clear the request-scoped find cache for a specific schema
	 *
	 * Used by the runtime-schema-api CRUD path to drop the in-memory
	 * cache entry after a mutation, so the next find() call re-reads
	 * from the database. Clears every cache key that referenced the
	 * given schema (by id, uuid, slug) across both RBAC/multi-tenancy
	 * flag combinations.
	 *
	 * @param int $schemaId The schema ID to drop from the find cache.
	 *
	 * @return void
	 */
	public function clearFindCache(int $schemaId): void {
		// Find every cache key whose value points at this schema ID and unset.
		foreach (array_keys($this->findCache) as $key) {
			$cached = $this->findCache[$key];
			if ($cached instanceof Schema && $cached->getId() === $schemaId) {
				unset($this->findCache[$key]);
			}
		}
	}//end clearFindCache()

	/**
	 * Finds multiple schemas by id
	 *
	 * @param array $ids The ids of the schemas
	 * @param bool $_rbac Whether to apply RBAC permission checks (default: true)
	 * @param bool $_multitenancy Whether to apply multi-tenancy filtering (default: true)
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If a schema does not exist
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple schemas are found
	 * @throws \OCP\DB\Exception If a database error occurs
	 *
	 * @todo: refactor this into find all
	 *
	 * @return Schema[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Schema>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control security filtering behavior
	 */
	public function findMultiple(array $ids, bool $_rbac = true, bool $_multitenancy = true): array {
		$result = [];
		foreach ($ids as $id) {
			try {
				$result[] = $this->find(
					id: $id,
					_extend: [],
					_rbac: $_rbac,
					_multitenancy: $_multitenancy
				);
			} catch (DoesNotExistException|MultipleObjectsReturnedException|DBException) {
				// Catch all exceptions but do nothing.
			}
		}

		return $result;
	}//end findMultiple()

	/**
	 * Find multiple schemas by IDs using a single optimized query
	 *
	 * This method performs a single database query to fetch multiple schemas,
	 * register: * significantly improving performance compared to individual queries.
	 *
	 * @param array $ids Array of schema IDs to find
	 *
	 * @return Entity&Schema[]
	 *
	 * @psalm-return array<Entity&Schema>
	 */
	public function findMultipleOptimized(array $ids): array {
		if (empty($ids) === true) {
			return [];
		}

		$schemas = [];

		// An IN() list is capped at MAX_IN_LIST_SIZE expressions: Nextcloud's
		// QueryBuilder logs "More than 1000 expressions in a list are not allowed
		// on Oracle" (and emits an "Undefined array key 0" PHP warning) once the
		// list exceeds that. This instance already holds 1,233 schemas, so the
		// registers-with-stats endpoint tripped it on every request. Chunk it.
		foreach (array_chunk($ids, self::MAX_IN_LIST_SIZE) as $chunk) {
			$this->traceRead(method: 'findMultipleOptimized');

			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from('openregister_schemas')
				->where(
					$qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY))
				);

			$result = $qb->executeQuery();

			while (($row = $result->fetch()) !== false) {
				$schema = new Schema();
				$schema = $schema->fromRow($row);

				$schemas[$row['id']] = $schema;
			}

			$result->closeCursor();
		}//end foreach

		return $schemas;
	}//end findMultipleOptimized()

	/**
	 * Finds schemas by slug
	 *
	 * Searches for schemas matching the given slug with optional
	 * multi-tenancy and RBAC filtering.
	 *
	 * @param string $slug The slug to search for
	 * @param int $limit Maximum number of results (default: 10)
	 * @param int $offset Offset for pagination (default: 0)
	 * @param bool $_rbac Whether to apply RBAC permission checks (default: true)
	 * @param bool $_multitenancy Whether to apply multi-tenancy filtering (default: true)
	 *
	 * @return Schema[] Array of matching schemas
	 *
	 * @psalm-return list<Schema>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control security filtering behavior
	 */
	public function findBySlug(
		string $slug,
		int $limit = 10,
		int $offset = 0,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array {
		$this->traceRead(method: 'findBySlug');

		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openregister_schemas')
			->where(
				$qb->expr()->eq('slug', $qb->createNamedParameter($slug, IQueryBuilder::PARAM_STR))
			);

		// Apply organisation filter.
		$this->applyOrganisationFilter(
			qb: $qb,
			columnName: 'organisation',
			allowNullOrg: true,
			multiTenancyEnabled: $_multitenancy
		);

		$qb->setMaxResults($limit)
			->setFirstResult($offset);

		$result = $qb->executeQuery();
		$schemas = [];

		while (($row = $result->fetch()) !== false) {
			$schema = Schema::fromRow($row);
			$schemas[] = $schema;
		}

		$result->closeCursor();

		return $schemas;
	}//end findBySlug()

	/**
	 * Finds all schemas, files: with optional extension for statistics
	 *
	 * @param int|null $limit The limit of the results
	 * @param int|null $offset The offset of the results
	 * @param array|null $filters The filters to apply
	 * @param array|null $searchConditions The search conditions to apply
	 * @param array|null $searchParams The search parameters to apply
	 * @param array $_extend Optional array of extensions (e.g., ['@self.stats'])
	 * @param bool $_rbac Whether to apply RBAC permission checks (default: true)
	 * @param bool $_multitenancy Whether to apply multi-tenancy filtering (default: true)
	 *
	 * @return Schema[]
	 *
	 * @throws \Exception If user doesn't have read permission
	 *
	 * @psalm-return                                  list<\OCA\OpenRegister\Db\Schema>
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Flags control security filtering behavior
	 */
	public function findAll(
		?int $limit = null,
		?int $offset = null,
		?array $filters = [],
		?array $searchConditions = [],
		?array $searchParams = [],
		?array $_extend = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array {
		// Verify RBAC permission to read if RBAC is enabled.
		if ($_rbac === true) {
			// $this->verifyRbacPermission('read', 'schema');
		}

		$this->traceRead(method: 'findAll');

		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openregister_schemas')
			->setMaxResults($limit)
			->setFirstResult($offset);

		$this->applyColumnFilters(qb: $qb, filters: $filters);

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
	 * Inserts a schema entity into the database
	 *
	 * @param Entity $entity The entity to insert
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 * @throws \Exception If user doesn't have create permission
	 *
	 * @return Entity The inserted entity
	 *
	 * @psalm-suppress LessSpecificImplementedReturnType - Schema is more specific than Entity
	 */
	public function insert(Entity $entity): Entity {
		// Verify RBAC permission to create.
		$this->verifyRbacPermission(action: 'create', entityType: 'schema');
		$this->assertScopedPropertiesAreGoverned(entity: $entity);
		// Auto-set organisation from active session.
		$this->setOrganisationOnCreate(entity: $entity);

		// Auto-set owner from current user session.
		$this->setOwnerOnCreate(entity: $entity);

		$entity = parent::insert(entity: $entity);

		// Dispatch creation event.
		$this->eventDispatcher->dispatchTyped(new SchemaCreatedEvent(schema: $entity));

		return $entity;
	}//end insert()

	/**
	 * Every scoped property on this schema is one its author may add, and one
	 * the scope has room for.
	 *
	 * 🔴 WITHOUT THIS THE TWO RULES WOULD HAVE BEEN CHECKS WITH NO CALLER, which
	 * is the same shape as no check at all. `ScopedPropertyGovernance` can
	 * answer both questions perfectly and still protect nothing if the save path
	 * never asks, and the schema would save, and the refusal would exist only in
	 * a test.
	 *
	 * 🔑 IT RUNS ON INSERT AND ON UPDATE. Only on insert, a scope could be added
	 * to an existing schema by anybody, and the ceiling could be walked past one
	 * edit at a time. Schemas that carry no scope at all are untouched, because
	 * the loop finds nothing.
	 *
	 * The governance is assembled here rather than injected because this mapper
	 * already holds all three of its collaborators, and adding a constructor
	 * argument to a mapper this widely constructed buys nothing.
	 *
	 * @param Entity $entity The schema being saved.
	 *
	 * @return void
	 *
	 * @throws ScopedPropertyException When a scope is not the caller's, or is full.
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
	 */
	private function assertScopedPropertiesAreGoverned(Entity $entity): void {
		if (($entity instanceof Schema) === false) {
			return;
		}

		$properties = ($entity->getProperties() ?? []);
		if ($properties === []) {
			return;
		}

		$governance = new ScopedPropertyGovernance(
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			appConfig: $this->appConfig
		);

		foreach ($properties as $name => $property) {
			if (is_array($property) === false) {
				continue;
			}

			$scope = ($property[ScopedPropertyDeclaration::ANNOTATION] ?? null);
			if (is_string($scope) === false || trim($scope) === '') {
				continue;
			}

			$scope = trim($scope);
			$governance->assertMayAddAtScope(scope: $scope, path: (string)$name);
			$governance->assertBelowCeiling(schema: $entity, scope: $scope, property: (string)$name);
		}
	}//end assertScopedPropertiesAreGoverned()

	/**
	 * Ensures that a schema object has a UUID and a slug.
	 *
	 * @param Schema $schema The schema object to clean
	 *
	 * @return void
	 */
	private function cleanObject(Schema $schema): void {
		$advisory = new AdvisoryAnnotationChecks(logger: $this->logger);
		$records = new RecordAnnotationRefusals(logger: $this->logger);
		$exchange = new ExchangeAnnotationRefusals();
		$declarations = new PropertyDeclarationRefusals();
		(new ReferenceNormaliser())->normalise(schema: $schema);
		$defaults = new SchemaDefaults();
		$defaults->ensureIdentifiers(schema: $schema);
		$fields = new ConfigurationFields(schema: $schema);
		$fields->validate();
		$defaults->buildRequired(schema: $schema);
		$fields->populate();
		(new LifecycleAnnotationCheck(logger: $this->logger))->check(schema: $schema);
		(new DeclaredActionCheck(objectActions: $this->objectActions, logger: $this->logger))->check(schema: $schema);
		$records->checkMdtoMapping(schema: $schema);
		$advisory->checkAggregations(schema: $schema);
		(new CalculationAnnotationCheck(logger: $this->logger))->check(schema: $schema);
		$this->validateConsentAnnotation(schema: $schema);
		(new RelationDeclarationCheck(
			findSchema: fn (string $id): Schema => $this->find(id: $id, _rbac: false, _multitenancy: false)
		))->check(schema: $schema);
		$declarations->checkDependentValues(schema: $schema);
		$advisory->checkQuality(schema: $schema);
		$advisory->checkDedup(schema: $schema);
		$records->checkUniqueHint(schema: $schema);
		$advisory->checkHinge(schema: $schema);
		$advisory->checkSurvivorship(schema: $schema);
		$advisory->checkMerge(schema: $schema);
		$advisory->checkParty(schema: $schema);
		$advisory->checkNotifications(schema: $schema);
		$records->checkWidgets(schema: $schema);
		$records->checkArchival(schema: $schema);
		$exchange->checkHandoff(schema: $schema);
		$exchange->checkHandoffContract(schema: $schema);
		$exchange->checkMcp(schema: $schema);
		$this->validateRegistryAnnotation(schema: $schema);
		$records->checkExternalLinks(schema: $schema);
		$declarations->checkExtendingForm(schema: $schema);
		$this->validateAuthorizationDeny(schema: $schema);
		$this->validateHierarchyAnnotation(schema: $schema);
		$this->validateDepartmentMatrix(schema: $schema);
		$this->validateRevealAudit(schema: $schema);
		$declarations->checkReversibility(schema: $schema);
		$this->validateReviewDeclaration(schema: $schema);
		$this->logDroppedAnnotationKeys(schema: $schema);
	}//end cleanObject()

	/**
	 * Refuse an authorization block whose deny contradicts its own grants.
	 *
	 * A deny is the first rule in this layer that subtracts, and the two
	 * contradictions it makes possible are cheap to catch here and expensive to
	 * discover later: a principal granted and denied the same verb at one level,
	 * and a register left with no principal holding `manage`.
	 *
	 * The refusal is HTTP 422 rather than 400. The request was understood and
	 * well formed; the rules inside it disagree with each other, and the message
	 * names both so the author knows which two to look at.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @return void
	 *
	 * @throws \OCA\OpenRegister\Exception\AuthorizationBlockException When the block contradicts itself.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function validateAuthorizationDeny(Schema $schema): void {
		(new MetadataConditionFindings(db: $this->db))->assertSchemaSavable(schema: $schema);
		(new UnknownRuleKeyFindings(db: $this->db))->assertSchemaSavable(schema: $schema);

		$authorization = $schema->getAuthorization();
		if (is_array($authorization) === false || $authorization === []) {
			return;
		}

		$subject = sprintf('the schema "%s"', (string)($schema->getSlug() ?? $schema->getTitle() ?? ''));

		// The catalogue check runs FIRST. An unknown verb is the mistake that
		// survives longest: it matches nothing, so it grants nothing and denies
		// nothing, and the block looks correct in every screen that shows it.
		// Refusing it here is what publishing the set is for.
		(new PermissionCatalogue(eventDispatcher: $this->eventDispatcher))->assertGrantable(
			authorization: $this->withoutDeclaredActions(authorization: $authorization, declared: $schema->declaredActionNames()),
			roleDefinitions: null,
			subject: $subject
		);

		(new AuthorizationDenyValidator(denyResolver: new DenyResolver()))->assertStorable(
			authorization: $authorization,
			subject: $subject
		);
	}//end validateAuthorizationDeny()

	/**
	 * The block without the actions the schema declares itself, at its top level and in its deny.
	 *
	 * A schema may grant its own `x-openregister-action` entries
	 * (declared-actions spec), which the app-wide catalogue does not list.
	 *
	 * @param array<string, mixed> $authorization The block as written.
	 * @param array<int, string>   $declared      The schema's declared action keys.
	 *
	 * @return array<string, mixed> The block the catalogue checks.
	 *
	 * @spec openspec/specs/declared-actions/spec.md
	 */
	private function withoutDeclaredActions(array $authorization, array $declared): array {
		$names = array_flip($declared);
		$checked = array_diff_key($authorization, $names);
		if (is_array($checked[DenyResolver::DENY_KEY] ?? null) === true) {
			$checked[DenyResolver::DENY_KEY] = array_diff_key($checked[DenyResolver::DENY_KEY], $names);
		}

		return $checked;
	}//end withoutDeclaredActions()

	/**
	 * Refuse an `x-openregister-review` declaration the ownership rule cannot honour.
	 *
	 * Refused rather than logged: a mistyped ownership read as open would leave
	 * a four-eyes review open while its author believes it is closed.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @return void
	 *
	 * @throws ReviewDeclarationException When the declaration is unsound.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
	 */
	private function validateReviewDeclaration(Schema $schema): void {
		$errors = (new ReviewAnnotationValidator())->validate(configuration: ($schema->getConfiguration() ?? []));

		if ($errors === []) {
			return;
		}

		throw new ReviewDeclarationException(errors: $errors);
	}//end validateReviewDeclaration()

	/**
	 * R07: surface dropped `x-openregister-*` keys via the structured
	 * logger. Schema::validateConfigurationArray accumulates unknown
	 * keys on the entity (no DI surface for a logger inside the
	 * entity); we drain the buffer here at save time so admins see a
	 * single warning per save call rather than nothing at all.
	 *
	 * @param Schema $schema Schema being saved.
	 *
	 * @return void
	 */
	private function logDroppedAnnotationKeys(Schema $schema): void {
		$dropped = $schema->consumeDroppedAnnotationKeys();
		if (count($dropped) === 0) {
			return;
		}

		$message = sprintf(
			'[OpenRegister.SchemaMapper] Dropped %d unknown x-openregister-* key(s) on schema "%s": %s.',
			count($dropped),
			(string)($schema->getSlug() ?? ''),
			implode(', ', $dropped)
		);
		$message .= ' Typo? See Schema::ANNOTATION_VOCABULARY for the declared keys.';
		$this->logger->warning($message);
	}//end logDroppedAnnotationKeys()

	/**
	 * Validate the `x-openregister-consent` annotation on a schema's properties.
	 *
	 * Blocking, and deliberately so (unlike the optional
	 * `x-openregister-notifications` annotation, which degrades to a warning):
	 * an evidentiary consent property that silently never fills evidence is
	 * worse than a save that names the mistake, since an app author would
	 * otherwise believe consent is being proven when it is not.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @throws ConsentDeclarationException When a declaration cannot be honoured.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/consent-evidence-envelope/spec.md
	 */
	private function validateConsentAnnotation(Schema $schema): void {
		$properties = ($schema->getProperties() ?? []);
		if (is_array($properties) === false) {
			return;
		}

		$hasAnnotation = false;
		foreach ($properties as $definition) {
			if (is_array($definition) === true && isset($definition['x-openregister-consent']) === true) {
				$hasAnnotation = true;
				break;
			}
		}

		if ($hasAnnotation === false) {
			return;
		}

		$errors = (new ConsentAnnotationValidator())->validate(['properties' => $properties]);
		if ($errors === []) {
			return;
		}

		throw new ConsentDeclarationException(errors: $errors);
	}//end validateConsentAnnotation()

	/**
	 * Refuse `audit: true` on a property nobody is kept out of (row 5.6).
	 *
	 * THE REFUSAL IS THE POINT OF THE FEATURE. An audited reveal answers "who
	 * saw the BSN", and it can only answer it while the entries are rare. A
	 * property with no `read` rule is shown to every reader of the object, so
	 * auditing it writes an entry per reader per object per request for a
	 * value nobody was ever kept from — and the handful of entries that matter
	 * are then somewhere inside several million that do not. A trail nobody can
	 * search is the same as no trail, arrived at by a route that looks like
	 * diligence.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return void
	 *
	 * @throws Exception When a property audits a reveal it cannot restrict.
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	private function validateRevealAudit(Schema $schema): void {
		$offenders = [];
		foreach (($schema->getProperties() ?? []) as $name => $config) {
			if (is_array($config) === false) {
				continue;
			}

			$authorization = ($config['authorization'] ?? null);
			if (is_array($authorization) === false) {
				continue;
			}

			if (($authorization[RevealCollector::AUDIT_KEY] ?? null) !== true) {
				continue;
			}

			$read = ($authorization['read'] ?? null);
			if (is_array($read) === true && count($read) > 0) {
				continue;
			}

			$offenders[] = (string)$name;
		}

		if ($offenders === []) {
			return;
		}

		throw new Exception(
			'authorization.audit is only meaningful on a property with a read rule, and these have none: '
			. implode(', ', $offenders)
		);
	}//end validateRevealAudit()

	/**
	 * Refuse a broken `authorization.matrix` at save (row B13).
	 *
	 * THIS ONE THROWS for the same reason the hierarchy validator does: the
	 * block decides who reaches which objects, and every way of getting it
	 * wrong is silent afterwards. A matrix on `afdeling` where the schema
	 * declares `department` compiles to a condition on a column that does not
	 * exist, which the SQL builder answers by DROPPING the predicate, and a
	 * rule meant to narrow a group to its own department becomes an
	 * unconditional grant to the whole group. There is no error anywhere on
	 * that path; there is only a group that can suddenly read everything.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return void
	 *
	 * @throws Exception When the matrix cannot be compiled.
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
	 */
	private function validateDepartmentMatrix(Schema $schema): void {
		$authorization = $schema->getAuthorization();
		if (is_array($authorization) === false
			|| array_key_exists(DepartmentMatrixCompiler::KEY, $authorization) === false
		) {
			return;
		}

		$findings = (new DepartmentMatrixValidator(declaredActions: $schema->declaredActionNames()))->validate(
			properties: ($schema->getProperties() ?? []),
			authorization: $authorization
		);

		if (count($findings) === 0) {
			return;
		}

		$messages = array_map(static fn (array $finding) => $finding['message'], $findings);
		throw new Exception('authorization.matrix: ' . implode(' ', $messages));
	}//end validateDepartmentMatrix()

	/**
	 * Refuse a broken `x-openregister-hierarchy` declaration at save.
	 *
	 * THIS ONE THROWS, and the reason is what the annotation does: it names the
	 * edge a GRANT travels down. An author who points it at the wrong property
	 * has not written a cosmetic mistake. `assignee` on a case references a
	 * USER, so a hierarchy declared over it would hand everybody who may read
	 * one object every object filed to the same person, and from that moment on
	 * it is indistinguishable from working inheritance. The save is the only
	 * point at which the two can be told apart.
	 *
	 * An unknown key inside the block is surfaced and ignored, the same rule
	 * {@see self::validateArchivalAnnotation()} records: it declares nothing, so
	 * dropping it loses nothing, and refusing it would cost the register every
	 * object of that schema at import time.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return void
	 *
	 * @throws Exception When the declaration cannot be honoured.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function validateHierarchyAnnotation(Schema $schema): void {
		$configuration = ($schema->getConfiguration() ?? []);
		$annotation = ($configuration[HierarchyGrantExpander::ANNOTATION] ?? null);
		if ($annotation === null) {
			return;
		}

		$findings = (new HierarchyAnnotationValidator())->validate(
			[
				'properties' => ($schema->getProperties() ?? []),
				'slug' => (string)($schema->getSlug() ?? ''),
				// The id and the uuid, because the import path rewrites every
				// `$ref` to the resolved schema id before the schema reaches
				// this validator. Without them a self-reference that was
				// written as the slug arrives here as "169" and the whole
				// schema is refused. See identitiesOf().
				'id' => (string)($schema->getId() ?? ''),
				'uuid' => (string)($schema->getUuid() ?? ''),
				'title' => (string)($schema->getTitle() ?? ''),
				HierarchyGrantExpander::ANNOTATION => $annotation,
			]
		);

		$split = HierarchyAnnotationValidator::partition(findings: $findings);

		if (count($split['warnings']) > 0) {
			$this->logger->warning(
				sprintf(
					'[OpenRegister.SchemaMapper] Ignored %d unknown %s key(s) on schema "%s": %s',
					count($split['warnings']),
					HierarchyGrantExpander::ANNOTATION,
					(string)($schema->getSlug() ?? ''),
					implode(' ', array_map(static fn (array $finding) => $finding['message'], $split['warnings']))
				)
			);
		}

		if (count($split['errors']) === 0) {
			return;
		}

		$messages = array_map(static fn (array $err) => $err['message'], $split['errors']);
		throw new Exception(HierarchyGrantExpander::ANNOTATION . ': ' . implode(' ', $messages));
	}//end validateHierarchyAnnotation()

	/**
	 * Validate the optional `x-openregister-registry` annotation.
	 *
	 * The annotation is stored under `configuration['x-openregister-registry']`.
	 * Per `registry-subscriptions` REQ 1, an annotation naming an `identity`
	 * or `owned` property the schema does not declare MUST refuse the save
	 * — unlike most other `x-openregister-*` dialects, this one is
	 * BLOCKING, not advisory, because a stored-but-wrong annotation would
	 * let the inbound registry endpoint write a property nobody reviewed.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @throws Exception When the annotation is malformed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md
	 */
	private function validateRegistryAnnotation(Schema $schema): void {
		$configuration = ($schema->getConfiguration() ?? []);
		$annotation = ($configuration['x-openregister-registry'] ?? null);
		if (is_array($annotation) === false) {
			return;
		}

		$shape = [
			'properties' => ($schema->getProperties() ?? []),
			'x-openregister-registry' => $annotation,
		];

		$errors = (new RegistryAnnotationValidator())->validate($shape);
		if (count($errors) === 0) {
			return;
		}

		$messages = array_map(static fn (array $err) => $err['code'] . ': ' . $err['message'], $errors);
		throw new Exception('x-openregister-registry: ' . implode(' ', $messages));
	}//end validateRegistryAnnotation()

	/**
	 * Creates a schema from an array
	 *
	 * This method handles schema extension by extracting only the delta
	 * (differences from parent schema) before saving when the schema extends another.
	 *
	 * @param array $object The object to create
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 * @throws \Exception If property validation fails
	 *
	 * @return Schema The created schema
	 */
	public function createFromArray(array $object): Schema {
		$schema = new Schema();

		// Ensure required field is always set to avoid NULL in database.
		// This must be done BEFORE hydrate() so it gets marked as updated.
		if (isset($object['required']) === false || $object['required'] === null) {
			$object['required'] = [];
		}

		$schema->hydrate(object: $object, validator: $this->validator);

		// Clean the schema object to ensure UUID, slug, and version are set.
		$this->cleanObject(schema: $schema);

		// **SCHEMA COMPOSITION**: Extract delta if schema uses composition (allOf).
		// This ensures we only store the differences, not the full resolved schema.
		// NOTE: Circular reference validation is done during SchemaComposition::resolve().
		$schema = $this->composition()->extractDelta(schema: $schema);

		// **PERFORMANCE OPTIMIZATION**: Generate facet configuration from schema properties.
		(new FacetConfiguration())->generate(schema: $schema);

		$schema = $this->insert(entity: $schema);

		return $schema;
	}//end createFromArray()

	/**
	 * Updates a schema entity in the database
	 *
	 * This method handles schema extension by extracting only the delta
	 * (differences from parent schema) before saving when the schema extends another.
	 *
	 * @param Entity $entity The entity to update
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If the entity does not exist
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple entities are found
	 * @throws \Exception If user doesn't have update permission or access to this organisation
	 *
	 * @return Entity The updated entity
	 *
	 * @psalm-suppress LessSpecificImplementedReturnType - Schema is more specific than Entity
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-a-schema-save-that-changes-nothing-publishes-nothing
	 */
	public function update(Entity $entity): Entity {
		// Verify RBAC permission to update.
		$this->verifyRbacPermission(action: 'update', entityType: 'schema');
		// Verify user has access to this organisation.
		$this->verifyOrganisationAccess(entity: $entity);
		$this->assertScopedPropertiesAreGoverned(entity: $entity);

		// Fetch old entity directly without organisation filter for event comparison.
		$this->traceRead(method: 'update');

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('openregister_schemas')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($entity->getId(), IQueryBuilder::PARAM_INT)));
		$oldSchema = $this->findEntity(query: $qb);

		// Clean the schema object to ensure UUID, slug, and version are set.
		$this->cleanObject(schema: $entity);

		// **SCHEMA COMPOSITION**: Extract delta if schema uses composition (allOf).
		// This ensures we only store the differences, not the full resolved schema.
		// NOTE: Circular reference validation is done during SchemaComposition::resolve().
		$entity = $this->composition()->extractDelta(schema: $entity);

		// **PERFORMANCE OPTIMIZATION**: Generate facet configuration from schema properties.
		(new FacetConfiguration())->generate(schema: $entity);

		/*
		 * **MODIFICATION STAMP**: `updated` was never written on an edit. The
		 * column defaulted to CURRENT_TIMESTAMP at insert and then stayed at
		 * the creation time for the rest of the schema's life, so every
		 * consumer that keys on it was reading a value that could not move:
		 *
		 *  - ContextsController::buildEtag() served a stale JSON-LD context
		 *    ETag after a schema edit, and honoured If-None-Match with a 304.
		 *  - JsonLdContextService::cacheKey() reused a stale context.
		 *  - ReferentialIntegrityService's cross-request relation index treated
		 *    an unchanged stamp as "no schema changed" and could therefore keep
		 *    an index that predates a newly added onDelete relation.
		 *
		 * This is the single mapper choke point every runtime edit passes
		 * through (updateFromArray, the controllers, the tool providers), and
		 * it is set after hydrate() so a client-supplied `updated` cannot
		 * suppress it — the modification time is the server's to state.
		 */

		$entity->setUpdated(new DateTime());

		$entity = parent::update(entity: $entity);

		// An event fires at the level where something changed, and only when it
		// did. An app re-importing an identical schema used to fire this on every
		// import, so every admin was told "Schema was updated" while nothing was.
		if ((new EntityChangeDetector())->changed(old: $oldSchema, new: $entity) === true) {
			$schemaId = (int)$entity->getId();
			$this->updateEventCounts[$schemaId] = (($this->updateEventCounts[$schemaId] ?? 0) + 1);
			$this->eventDispatcher->dispatchTyped(new SchemaUpdatedEvent(newSchema: $entity, oldSchema: $oldSchema));
		}

		return $entity;
	}//end update()

	/**
	 * How many SchemaUpdatedEvents this request dispatched for one schema.
	 *
	 * A caller that saves a schema and needs to know whether the save reached
	 * the listeners (the configuration import, which must otherwise run the
	 * installers itself) reads this before and after its writes.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return int The number of update events dispatched for it so far.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-a-schema-save-that-changes-nothing-publishes-nothing
	 */
	public function updateEventCount(int $schemaId): int {
		return ($this->updateEventCounts[$schemaId] ?? 0);
	}//end updateEventCount()

	/**
	 * Updates a schema from an array
	 *
	 * @param int $id The id of the schema to update
	 * @param array $object The object to update
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If the schema does not exist
	 * @throws \Exception If property validation fails
	 *
	 * @return Schema The updated schema
	 */
	public function updateFromArray(int $id, array $object): Schema {
		// Disable multitenancy filtering for update operations.
		// When updating by ID, we want to find the schema regardless of organisation.
		// Access verification happens in update() method via verifyOrganisationAccess().
		$schema = $this->find(id: $id, _multitenancy: false);

		// Set or update the version.
		if (isset($object['version']) === false) {
			$currentVersion = $schema->getVersion() ?? '0.0.0';
			$version = explode('.', $currentVersion);
			$version[2] = ((int)$version[2] + 1);
			$schema->setVersion(implode('.', $version));
		}

		$schema->hydrate(object: $object, validator: $this->validator);

		// Update the schema in the database.
		$schema = $this->update(entity: $schema);

		return $schema;
	}//end updateFromArray()

	/**
	 * Delete a schema
	 *
	 * **DELETE SAFETY (runtime-schema-api)**: refuses to delete a schema that still
	 * holds objects, unless the caller explicitly asks to orphan them ($force).
	 *
	 * This guard is enforced HERE, at the mapper, rather than only in
	 * SchemasController::destroy(), because the mapper is the choke point every
	 * deletion caller shares — including the AI/LLM-invokable SchemasToolProvider
	 * and SchemaTool surfaces, which have no controller in front of them.
	 *
	 * @param Entity $entity The schema entity to delete
	 * @param bool $force Bypass the object-count guard and deliberately orphan the
	 *                    objects. Only the `?force=true` disposition of
	 *                    `DELETE /api/schemas/{id}` may pass true. The cascade
	 *                    disposition needs no bypass: it deletes the rows first, so
	 *                    the guard naturally counts 0.
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 * @throws ValidationException If objects are still attached and $force is false
	 * @throws \Exception If user doesn't have delete permission or access to this organisation
	 *
	 * @return Schema The deleted schema
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The force flag is the API-level disposition, mirrored from the endpoint.
	 *
	 * @spec openspec/changes/schema-delete-cascade/specs/runtime-schema-api/spec.md
	 */
	public function delete(Entity $entity, bool $force = false): Schema {
		// Verify RBAC permission to delete.
		$this->verifyRbacPermission(action: 'delete', entityType: 'schema');
		// Verify user has access to this organisation.
		$this->verifyOrganisationAccess(entity: $entity);

		$schemaId = $entity->id;
		if (method_exists($entity, 'getId') === true) {
			$schemaId = $entity->getId();
		}

		if ($force === false) {
			$count = $this->countAttachedObjects(schemaId: (int)$schemaId);
			if ($count > 0) {
				throw new ValidationException(
					message: 'Cannot delete schema: ' . $count . ' objects are still attached.'
				);
			}
		}

		// Proceed with deletion.
		$result = parent::delete(entity: $entity);

		// Dispatch deletion event.
		$this->eventDispatcher->dispatchTyped(
			new SchemaDeletedEvent(schema: $entity)
		);

		return $result;
	}//end delete()

	/**
	 * Count the objects still attached to a schema.
	 *
	 * Objects live in the per-register/schema MAGIC tables
	 * (`openregister_table_{registerId}_{schemaId}`). The previous implementation of
	 * this guard counted rows in the retired `openregister_objects` blob table, which
	 * is always empty for magic-table objects — so it counted 0 and waved every
	 * deletion through.
	 *
	 * This is a direct DB query, not a call into MagicMapper/MagicStatisticsHandler,
	 * and that is deliberate: MagicStatisticsHandler injects SchemaMapper, so injecting
	 * it back here would be a genuine circular dependency. The table name is
	 * deterministic, so the count can be taken without the mapper.
	 *
	 * Soft-deleted rows are excluded, matching the object count the controller's guard
	 * reports to the caller.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return int The number of live objects attached to the schema.
	 *
	 * @spec openspec/changes/schema-delete-cascade/specs/runtime-schema-api/spec.md
	 */
	private function countAttachedObjects(int $schemaId): int {
		$total = 0;

		foreach ($this->getRegisterIdsWithSchema(schemaId: $schemaId) as $registerId) {
			$tableName = MagicMapperInterface::TABLE_PREFIX . $registerId . '_' . $schemaId;

			if ($this->db->tableExists($tableName) === false) {
				continue;
			}

			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count('*'))
				->from($tableName)
				->where($qb->expr()->isNull('_deleted'));

			$result = $qb->executeQuery();
			$total += (int)$result->fetchOne();
			$result->closeCursor();
		}

		return $total;
	}//end countAttachedObjects()

	/**
	 * Find the ids of every register that references a schema.
	 *
	 * Direct query + decode in PHP, mirroring RegisterMapper::getAllRegisterIdsWithSchema():
	 * the `schemas` column is JSON on Postgres and text elsewhere, so there is no
	 * portable SQL predicate for "array contains N". Registers are O(10s) per install,
	 * so the cost is trivial. RegisterMapper itself cannot be injected here — it
	 * injects SchemaMapper.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return array<int, int> The register ids.
	 */
	private function getRegisterIdsWithSchema(int $schemaId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'schemas')
			->from('openregister_registers');

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		$needle = (string)$schemaId;
		$registerIds = [];

		foreach ($rows as $row) {
			$schemas = json_decode(($row['schemas'] ?? '[]'), true);
			if (is_array($schemas) === false) {
				continue;
			}

			foreach ($schemas as $candidate) {
				if ((string)$candidate === $needle) {
					$registerIds[] = (int)$row['id'];
					break;
				}
			}
		}

		return $registerIds;
	}//end getRegisterIdsWithSchema()

	/**
	 * Get the number of registers associated with each schema
	 *
	 * This method returns an associative array where the key is the schema ID
	 * and the value is the number of registers that reference that schema.
	 *
	 * @phpstan-return array<int,int>  Associative array of schema ID => register count
	 *
	 * @psalm-return array<int, int>
	 * @return int[]
	 */
	public function getRegisterCountPerSchema(): array {
		// TODO: Optimize for large datasets (current approach loads all registers into memory).
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'schemas')
			->from('openregister_registers');
		$result = $qb->executeQuery()->fetchAll();

		$counts = [];
		foreach ($result as $row) {
			// Decode the schemas JSON array for each register.
			$decoded = json_decode($row['schemas'], true);
			$schemas = [];
			if ($decoded !== null && $decoded !== false) {
				$schemas = $decoded;
			}

			foreach ($schemas as $schemaId) {
				$counts[(int)$schemaId] = ($counts[(int)$schemaId] ?? 0) + 1;
			}
		}

		return $counts;
	}//end getRegisterCountPerSchema()

	/**
	 * Get property source metadata for a schema
	 *
	 * Returns metadata about each property indicating whether it's native (defined in this schema)
	 * or inherited (from a parent schema via allOf). For inherited properties, shows the source schema.
	 *
	 * @param Schema $schema The schema to analyze
	 *
	 * @return array<string, array<string, string|null>> Property metadata keyed by property name
	 *
	 * @psalm-return array<string,
	 *     array{source: 'native'|'inherited', inheritedFrom: string|null}>
	 */
	public function getPropertySourceMetadata(Schema $schema): array {
		$nativeProperties = $this->storedProperties(schema: $schema);
		$metadata = [];
		foreach (array_keys($schema->getProperties()) as $propName) {
			$metadata[$propName] = ['source' => 'native', 'inheritedFrom' => null];
			if (isset($nativeProperties[$propName]) === false) {
				$metadata[$propName] = [
					'source' => 'inherited',
					'inheritedFrom' => $this->composition()->propertySource(
						propertyName: (string)$propName,
						parentRefs: ($schema->getAllOf() ?? [])
					),
				];
			}
		}

		return $metadata;
	}//end getPropertySourceMetadata()

	/**
	 * The properties a schema stores itself, as the resolved schema has them merged.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<mixed> The stored properties, empty when they cannot be read.
	 */
	private function storedProperties(Schema $schema): array {
		try {
			$this->traceRead(method: 'getPropertySourceMetadata');

			$qb = $this->db->getQueryBuilder();
			$qb->select('properties')
				->from('openregister_schemas')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($schema->getId(), IQueryBuilder::PARAM_INT)));
			$result = $qb->executeQuery();
			$row = $result->fetch();
			$result->closeCursor();

			return (json_decode((($row['properties'] ?? null) ?? 'null'), true) ?? []);
		} catch (Exception $e) {
			return [];
		}
	}//end storedProperties()

	/**
	 * Composition over the schemas this mapper loads.
	 *
	 * @return SchemaComposition
	 */
	private function composition(): SchemaComposition {
		return new SchemaComposition(
			loadSchema: fn (string|int $identifier): Schema => $this->loadSchema(identifier: $identifier)
		);
	}//end composition()

	/**
	 * Find schemas that compose with a given schema.
	 *
	 * Returns the uuids of the schemas that reference the given schema in their
	 * allOf, oneOf or anyOf composition patterns.
	 *
	 * @param int|string  $schemaIdentifier The ID, UUID, or slug of the schema.
	 * @param string|null $knownUuid        Pre-known UUID to avoid redundant lookups.
	 * @param string|null $knownSlug        Pre-known slug to avoid redundant lookups.
	 *
	 * @return array Array of schema UUIDs that compose with this schema
	 *
	 * @psalm-return list{0?: mixed,...}
	 */
	public function findExtendedBy(int|string $schemaIdentifier, ?string $knownUuid = null, ?string $knownSlug = null): array {
		$identifiers = [(string)$schemaIdentifier, $knownUuid, $knownSlug];
		if ($knownUuid === null && $knownSlug === null) {
			try {
				$targetSchema = $this->find(id: $schemaIdentifier);
			} catch (Exception $e) {
				return [];
			}

			$identifiers = [(string)$targetSchema->getId(), $targetSchema->getUuid(), $targetSchema->getSlug()];
		}

		return (new ExtendedByReads(db: $this->db, tableName: $this->getTableName()))->referencing(identifiers: $identifiers);
	}//end findExtendedBy()

	/**
	 * Find schemas that have properties referencing the given schema.
	 *
	 * @param Schema|int|string $schema The target schema to find references to.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If the target schema does not exist
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple target schemas are found
	 * @throws \OCP\DB\Exception If a database error occurs
	 *
	 * @return Schema[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Schema>
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function getRelated(Schema|int|string $schema): array {
		// Metadata reads: these bypass multitenancy, like the controller's own
		// outgoing half and every other schema catalog lookup.
		$target = $schema;
		if ($schema instanceof Schema === false) {
			$target = $this->find(id: $schema, _multitenancy: false);
		}

		$related = [];
		foreach ($this->findAll(_multitenancy: false) as $candidate) {
			if ($candidate->getId() !== (int)$target->getId() && $this->hasReferenceToSchema(
				properties: $candidate->getProperties(),
				targetSchemaId: (string)$target->getId(),
				targetSchemaUuid: (string)$target->getUuid(),
				targetSchemaSlug: (string)$target->getSlug()
			) === true
			) {
				$related[] = $candidate;
			}
		}

		return $related;
	}//end getRelated()

	/**
	 * Recursively check if properties contain a reference to the target schema.
	 *
	 * @param array  $properties       The properties array to search through.
	 * @param string $targetSchemaId   The target schema ID to look for.
	 * @param string $targetSchemaUuid The target schema UUID to look for.
	 * @param string $targetSchemaSlug The target schema slug to look for.
	 *
	 * @return bool True if a reference to the target schema is found
	 */
	public function hasReferenceToSchema(
		array $properties,
		string $targetSchemaId,
		string $targetSchemaUuid,
		string $targetSchemaSlug,
	): bool {
		$search = new SchemaReferenceSearch(
			target: ['id' => $targetSchemaId, 'uuid' => $targetSchemaUuid, 'slug' => $targetSchemaSlug]
		);

		return $search->foundIn(properties: $properties);
	}//end hasReferenceToSchema()

	/**
	 * Find all schema extension relationships in a single scan.
	 *
	 * @return array<int, string[]> Map of targetSchemaId => [extendingSchemaUuid, ...]
	 */
	public function findAllExtendedBy(): array {
		return (new ExtendedByReads(db: $this->db, tableName: $this->getTableName()))->reverseMap();
	}//end findAllExtendedBy()

	/**
	 * Load a schema by ID, UUID, or slug
	 *
	 * Helper method to load a schema from the database by any identifier type.
	 *
	 * @param string|int $identifier Schema ID, UUID, or slug
	 *
	 * @throws \Exception If schema not found
	 *
	 * @return Schema The loaded schema
	 *
	 * @spec openspec/specs/mariadb-ci-matrix/spec.md#requirement-a-text-identifier-is-never-bound-as-an-integer
	 */
	private function loadSchema(string|int $identifier): Schema {
		try {
			$this->traceRead(method: 'loadSchema');

			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from('openregister_schemas')
				->where($qb->expr()->orX(...$this->identifierMatches(qb: $qb, identifier: $identifier)));

			return $this->findEntity(query: $qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			throw new Exception("Schema '{$identifier}' not found");
		}//end try
	}//end loadSchema()

	/**
	 * The conditions that match a schema by uuid, slug or, for an integer, id.
	 *
	 * Compares `id` only for an integer: PostgreSQL refuses a uuid or slug bound
	 * as an integer for the whole query (live pass O4, 5 Oct).
	 *
	 * @param IQueryBuilder $qb         The query.
	 * @param string|int    $identifier Schema ID, UUID, or slug.
	 *
	 * @return array<int, mixed>
	 *
	 * @spec openspec/specs/mariadb-ci-matrix/spec.md#requirement-a-text-identifier-is-never-bound-as-an-integer
	 */
	private function identifierMatches(IQueryBuilder $qb, string|int $identifier): array {
		$matches = [
			$qb->expr()->eq('uuid', $qb->createNamedParameter(value: (string) $identifier, type: IQueryBuilder::PARAM_STR)),
			$qb->expr()->eq('slug', $qb->createNamedParameter(value: (string) $identifier, type: IQueryBuilder::PARAM_STR)),
		];
		if (is_int($identifier) === true || ctype_digit($identifier) === true) {
			$matches[] = $qb->expr()->eq('id', $qb->createNamedParameter(value: (int) $identifier, type: IQueryBuilder::PARAM_INT));
		}

		return $matches;
	}//end identifierMatches()

	/**
	 * Return the IDs of all schemas whose `searchable` flag is false.
	 *
	 * Used by the unified search provider to exclude opted-out schemas from
	 * Nextcloud unified search inside the query (rather than post-filtering a
	 * result page, which would leak counts and break pagination). The lookup
	 * is intentionally RBAC/tenant-agnostic: it only answers "which schemas
	 * declared themselves non-searchable", the access filtering happens in the
	 * object search itself.
	 *
	 * @return int[] List of schema IDs with `searchable = false`.
	 *
	 * @psalm-return   list<int>
	 * @phpstan-return array<int, int>
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	public function findNonSearchableIds(): array {
		$this->traceRead(method: 'findNonSearchableIds');

		return $this->idsWithFlag(column: 'searchable', value: false);
	}//end findNonSearchableIds()

	/**
	 * Return the IDs of all schemas whose `searchable` flag is true (default).
	 *
	 * The unified search provider passes this allow-list as the `@self.schema`
	 * IN-filter so that only searchable schemas contribute results, applied
	 * inside the query.
	 *
	 * @return int[] List of schema IDs with `searchable = true`.
	 *
	 * @psalm-return   list<int>
	 * @phpstan-return array<int, int>
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	public function findSearchableIds(): array {
		$this->traceRead(method: 'findSearchableIds');

		return $this->idsWithFlag(column: 'searchable', value: true);
	}//end findSearchableIds()

	/**
	 * Return the IDs of all schemas whose `smartPickerEnabled` flag is true.
	 *
	 * Consulted by a schema-scoped `AbstractSchemaReferenceProvider`/
	 * `AbstractSchemaSearchProvider` instance to gate its own functionality
	 * per-instance (a schema-scoped provider only ever concerns one schema),
	 * mirroring `findSearchableIds()`'s pattern for the unified search
	 * provider's opt-out. Unlike `searchable`, no consumer currently needs
	 * the inverse "disabled ids" list.
	 *
	 * @return int[] List of schema IDs with `smartPickerEnabled = true`.
	 *
	 * @psalm-return   list<int>
	 * @phpstan-return array<int, int>
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d2a
	 */
	public function findSmartPickerEnabledIds(): array {
		$this->traceRead(method: 'findSmartPickerEnabledIds');

		return $this->idsWithFlag(column: 'smart_picker_enabled', value: true);
	}//end findSmartPickerEnabledIds()

	/**
	 * The ids of the schemas whose boolean column holds the given value.
	 *
	 * @param string $column The column.
	 * @param bool   $value  The value.
	 *
	 * @return array<int, int>
	 */
	private function idsWithFlag(string $column, bool $value): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('openregister_schemas')
			->where($qb->expr()->eq($column, $qb->createNamedParameter(value: $value, type: IQueryBuilder::PARAM_BOOL)));

		$ids = [];
		$result = $qb->executeQuery();
		while (($row = $result->fetch()) !== false) {
			if (isset($row['id']) === true) {
				$ids[] = (int)$row['id'];
			}
		}

		$result->closeCursor();

		return $ids;
	}//end idsWithFlag()
}//end class
