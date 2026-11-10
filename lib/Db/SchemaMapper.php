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
use OCA\OpenRegister\Db\SchemaMapper\AccessDeclarationChecks;
use OCA\OpenRegister\Db\SchemaMapper\AdvisoryAnnotationChecks;
use OCA\OpenRegister\Db\SchemaMapper\AuthorizationBlockCheck;
use OCA\OpenRegister\Db\SchemaMapper\CalculationAnnotationCheck;
use OCA\OpenRegister\Db\SchemaMapper\ConfigurationFields;
use OCA\OpenRegister\Db\SchemaMapper\DeclaredActionCheck;
use OCA\OpenRegister\Db\SchemaMapper\ExchangeAnnotationRefusals;
use OCA\OpenRegister\Db\SchemaMapper\ExtendedByReads;
use OCA\OpenRegister\Db\SchemaMapper\FacetConfiguration;
use OCA\OpenRegister\Db\SchemaMapper\LifecycleAnnotationCheck;
use OCA\OpenRegister\Db\SchemaMapper\NotificationAnnotationCheck;
use OCA\OpenRegister\Db\SchemaMapper\PropertyDeclarationRefusals;
use OCA\OpenRegister\Db\SchemaMapper\RecordAnnotationRefusals;
use OCA\OpenRegister\Db\SchemaMapper\ReferenceLoopCheck;
use OCA\OpenRegister\Db\SchemaMapper\ReferenceNormaliser;
use OCA\OpenRegister\Db\SchemaMapper\RelationDeclarationCheck;
use OCA\OpenRegister\Db\SchemaMapper\SchemaAnnotation;
use OCA\OpenRegister\Db\SchemaMapper\SchemaComposition;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDefaults;
use OCA\OpenRegister\Db\SchemaMapper\SchemaLookup;
use OCA\OpenRegister\Db\SchemaMapper\SchemaMapperServices;
use OCA\OpenRegister\Db\SchemaMapper\SchemaReadTrace;
use OCA\OpenRegister\Db\SchemaMapper\SchemaReferenceSearch;
use OCA\OpenRegister\Db\SchemaMapper\SchemaRegisterReads;
use OCA\OpenRegister\Db\SchemaMapper\SubjectDeclarationRefusals;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionValidatorInterface;
use OCA\OpenRegister\Service\Schemas\ScopedField;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandlerInterface;
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

	// Note: $appConfig is provided by MultiTenancyTrait (protected ?IAppConfig $appConfig=null)
	// We assign it in the constructor to make it available to the trait methods.

	/**
	 * SchemaUpdatedEvents dispatched in this request, keyed by schema id.
	 *
	 * @var array<int, int>
	 */
	private array $updateEventCounts = [];

	/**
	 * Whether a notification url action that cannot be linked to only warns on save.
	 *
	 * @var bool
	 */
	private bool $warnUnlinkable = false;

	/**
	 * Event dispatcher for schema events.
	 *
	 * @var IEventDispatcher
	 */
	private readonly IEventDispatcher $eventDispatcher;

	/**
	 * Schema property validator for validation.
	 *
	 * @var PropertyValidatorHandlerInterface
	 */
	private readonly PropertyValidatorHandlerInterface $validator;

	/**
	 * Organisation mapper for multi-tenancy.
	 *
	 * @var OrganisationMapperInterface
	 */
	protected readonly OrganisationMapperInterface $organisationMapper;

	/**
	 * User session for current user context.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Group manager for RBAC checks.
	 *
	 * @var IGroupManager
	 */
	private readonly IGroupManager $groupManager;

	/**
	 * Structured logger (R07: surfaces unknown annotation keys).
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Verifies every declared action; a Closure returns it on first use, which
	 * breaks the construction cycle through the flow services. Null only in a
	 * hand-built test: the shape refusals still fire, only the questions about
	 * other records are skipped.
	 *
	 * @var ObjectActionValidatorInterface|Closure|null
	 */
	private readonly ObjectActionValidatorInterface|Closure|null $objectActions;

	/**
	 * Constructor
	 *
	 * Initializes mapper with database connection and required dependencies
	 * for multi-tenancy, RBAC, validation, and event dispatching.
	 *
	 * @param IDBConnection        $db        Database connection for queries
	 * @param TenancyServices      $tenancy   The organisation mapper, user session and group manager the tenancy and RBAC checks read
	 * @param IAppConfig           $appConfig App configuration for multitenancy settings
	 * @param SchemaMapperServices $services  The event dispatcher, property validator, logger and action checks
	 *
	 * @return void
	 */
	public function __construct(
		IDBConnection $db,
		TenancyServices $tenancy,
		IAppConfig $appConfig,
		SchemaMapperServices $services,
	) {
		// Initialize parent mapper with table name and entity class.
		parent::__construct(db: $db, tableName: 'openregister_schemas', entityClass: Schema::class);

		$this->organisationMapper = $tenancy->organisationMapper;
		$this->userSession        = $tenancy->userSession;
		$this->groupManager       = $tenancy->groupManager;
		$this->eventDispatcher    = $services->eventDispatcher;
		$this->validator          = $services->validator;
		$this->logger             = $services->logger;
		$this->objectActions      = $services->objectActions;

		// Assign appConfig to trait's protected property.
		$this->appConfig = $appConfig;
	}//end __construct()

	/**
	 * The attribution of schema reads.
	 *
	 * @return SchemaReadTrace
	 */
	private function readTrace(): SchemaReadTrace {
		return new SchemaReadTrace(appConfig: $this->appConfig);
	}//end readTrace()

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
		array|null $_extend = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
	): Schema {
		$lookup = new SchemaLookup(id: $id, rbac: $_rbac, multitenancy: $_multitenancy);
		if (isset($this->findCache[$lookup->cacheKey()]) === true) {
			return $this->findCache[$lookup->cacheKey()];
		}

		$this->readTrace()->record(method: 'find');
		\OCA\OpenRegister\Service\WritePhase::count(event: 'schema.db.read');

		return $this->loadAndCache(lookup: $lookup);
	}//end find()

	/**
	 * Read a schema from the database and remember it for this request.
	 *
	 * @param SchemaLookup $lookup The lookup.
	 *
	 * @return Schema The schema with its composition resolved.
	 * @throws DoesNotExistException If no visible row matches.
	 */
	private function loadAndCache(SchemaLookup $lookup): Schema {
		$rows = $this->identifierRows(lookup: $lookup);
		if ($rows === []) {
			throw new DoesNotExistException($lookup->notFoundMessage());
		}

		$this->warnAboutAmbiguousIdentifier(id: $lookup->id, rows: $rows);

		// Resolve schema composition if present (allOf, oneOf, anyOf).
		$schema = $this->composition()->resolve(schema: Schema::fromRow($rows[0]));

		// BUG-DB-10: cache writes reuse the read-side suffix so they stay consistent.
		$this->findCache[$lookup->cacheKey()] = $schema;
		$this->cacheSchemaByIdentifiers(schema: $schema, cacheSuffix: $lookup->cacheSuffix());

		return $schema;
	}//end loadAndCache()

	/**
	 * The rows a lookup matches, at most two.
	 *
	 * Two rows, not one: enough to KNOW the identifier was ambiguous, which a
	 * capped single-row fetch can never tell you.
	 *
	 * @param SchemaLookup $lookup The lookup.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function identifierRows(SchemaLookup $lookup): array {
		$qb = $this->buildFindByIdentifierQuery(id: $lookup->id);
		$this->filterByOrganisation(qb: $qb, multitenancy: $lookup->multitenancy);
		$qb->setMaxResults(2);

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}//end identifierRows()

	/**
	 * Limit a schema query to the active organisation, schemas without one included.
	 *
	 * @param IQueryBuilder $qb           The query.
	 * @param bool          $multitenancy False skips the filter, for example when expanding schemas for registers.
	 *
	 * @return void
	 */
	private function filterByOrganisation(IQueryBuilder $qb, bool $multitenancy): void {
		$this->applyOrganisationFilter(
			qb: $qb,
			columnName: 'organisation',
			allowNullOrg: true,
			multiTenancyEnabled: $multitenancy
		);
	}//end filterByOrganisation()

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

		$this->whereIdentifier(qb: $qb, id: $id);
		$this->orderOwnedRowsFirst(qb: $qb);

		return $qb;
	}//end buildFindByIdentifierQuery()

	/**
	 * Match an identifier against uuid, slug and, for a numeric one, id, an exact id first.
	 *
	 * @param IQueryBuilder $qb The query.
	 * @param int|string    $id The identifier.
	 *
	 * @return void
	 */
	private function whereIdentifier(IQueryBuilder $qb, string|int $id): void {
		$conditions = $qb->expr()->orX(
			$qb->expr()->eq('uuid', $qb->createNamedParameter(value: $id, type: IQueryBuilder::PARAM_STR)),
			$this->slugEquals(qb: $qb, slug: (string)$id)
		);

		$idParam = null;
		if (is_numeric($id) === true) {
			$idParam = $qb->createNamedParameter(value: (int)$id, type: IQueryBuilder::PARAM_INT);
			$conditions->add($qb->expr()->eq('id', $idParam));
		}

		$qb->where($conditions);

		if ($idParam !== null) {
			$qb->addOrderBy($qb->createFunction('CASE WHEN id = ' . $idParam . ' THEN 0 ELSE 1 END'), 'ASC');
		}
	}//end whereIdentifier()

	/**
	 * Order rows an application owns before unattributed ones, then by lowest id.
	 *
	 * A bare slug can legitimately match SEVERAL rows. Since
	 * Version1Date20260723000000 widened the unique key from
	 * (organisation, slug) to (organisation, application, slug), two apps
	 * may each own a schema with the same generic slug — that widening was
	 * deliberate, and reverting it would put back the collision it fixed.
	 *
	 * What did NOT land with it is the determinism its own docblock promised
	 * ("resolution is scoped by app (import) and by register (runtime) in
	 * the accompanying code change"). The scoped resolvers exist, but every
	 * one of them falls back HERE when it misses, and here there was no
	 * ORDER BY and no row cap at all: which same-slug schema won was decided
	 * by the storage engine.
	 *
	 * Measured 2026-08-02: schemas 5012 "AgentFlow" (application '') and
	 * 5020 "Agent flow" (application 'hermiq') both carry slug `agentflow`
	 * in one organisation. 5012 holds 1 object, 5020 holds 46. Every
	 * slug-based resolution was a coin flip that happened to keep landing on
	 * the populated one.
	 *
	 * The tie-break, in order. First: a row an app OWNS beats an
	 * unattributed one. The widened key exists precisely to express
	 * ownership, so a leftover row that no app claims must never shadow an
	 * app's real schema. Second: the lowest id, matching
	 * RegisterMapper::find() and the `openregister:schemas:dedup` command's
	 * keep-the-lowest rule.
	 *
	 * Ownership has to come first, and not only for tidiness: on the live
	 * duplicate the unordered query returns the UNATTRIBUTED row first, and
	 * so would a bare `ORDER BY id ASC`.
	 *
	 * @param IQueryBuilder $qb The query.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	private function orderOwnedRowsFirst(IQueryBuilder $qb): void {
		$qb->addOrderBy(
			$qb->createFunction("CASE WHEN application IS NULL OR application = '' THEN 1 ELSE 0 END"),
			'ASC'
		);
		$qb->addOrderBy('id', 'ASC');
	}//end orderOwnedRowsFirst()

	/**
	 * The case-insensitive match of the slug column.
	 *
	 * @param IQueryBuilder $qb   The query.
	 * @param string        $slug The slug.
	 *
	 * @return string The comparison.
	 */
	private function slugEquals(IQueryBuilder $qb, string $slug): string {
		return $qb->expr()->eq(
			$qb->func()->lower('slug'),
			$qb->createNamedParameter(value: strtolower($slug), type: IQueryBuilder::PARAM_STR)
		);
	}//end slugEquals()

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
		if (count($rows) <= 1) {
			return;
		}

		$this->logger->warning(
			message: self::ambiguityMessage(id: (string)$id, chosen: $rows[0]),
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'identifier' => (string)$id,
				'candidates' => array_map(self::candidateSummary(...), $rows),
			]
		);
	}//end warnAboutAmbiguousIdentifier()

	/**
	 * The ambiguity warning for an identifier and the row it resolved to.
	 *
	 * @param string               $id     The identifier.
	 * @param array<string, mixed> $chosen The first row.
	 *
	 * @return string
	 */
	private static function ambiguityMessage(string $id, array $chosen): string {
		return sprintf(
			'[SchemaMapper] Identifier "%s" matches more than one schema; resolving to #%s ("%s", application "%s"). '
			. 'Fix the duplicate with `occ openregister:schemas:dedup`.',
			$id,
			(string)($chosen['id'] ?? '?'),
			(string)($chosen['title'] ?? '?'),
			(string)($chosen['application'] ?? '')
		);
	}//end ambiguityMessage()

	/**
	 * What the ambiguity warning names of a matched row.
	 *
	 * @param array<string, mixed> $candidate The row.
	 *
	 * @return array<string, mixed>
	 */
	private static function candidateSummary(array $candidate): array {
		return [
			'id' => ($candidate['id'] ?? null),
			'title' => ($candidate['title'] ?? null),
			'slug' => ($candidate['slug'] ?? null),
			'application' => ($candidate['application'] ?? null),
		];
	}//end candidateSummary()

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
		$this->readTrace()->record(method: 'findByApplicationAndSlug');

		$qb = $this->applicationSlugQuery(slug: $slug, application: $application);
		$qb->setMaxResults(1);

		return $this->firstResolved(qb: $qb);
	}//end findByApplicationAndSlug()

	/**
	 * A query for the schemas an application owns under a slug.
	 *
	 * @param string $slug        The slug, matched case-insensitively.
	 * @param string $application The owning application id.
	 *
	 * @return IQueryBuilder
	 */
	private function applicationSlugQuery(string $slug, string $application): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('openregister_schemas')
			->where($this->slugEquals(qb: $qb, slug: $slug))
			->andWhere(
				$qb->expr()->eq('application', $qb->createNamedParameter(value: $application, type: IQueryBuilder::PARAM_STR))
			);

		return $qb;
	}//end applicationSlugQuery()

	/**
	 * The first row of a query as a resolved schema.
	 *
	 * @param IQueryBuilder $qb The query.
	 *
	 * @return Schema|null The schema, null when the query matched nothing.
	 */
	private function firstResolved(IQueryBuilder $qb): ?Schema {
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			return null;
		}

		return $this->composition()->resolve(schema: Schema::fromRow($row));
	}//end firstResolved()

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
		$this->readTrace()->record(method: 'findAllByApplicationAndSlug');

		$qb = $this->applicationSlugQuery(slug: $slug, application: $application);
		$qb->orderBy('id', 'ASC');

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
		$ids = self::positiveIds(candidates: $schemaIds);
		if ($ids === []) {
			return null;
		}

		$this->readTrace()->record(method: 'findBySlugInIds');

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('openregister_schemas')
			->where($this->slugEquals(qb: $qb, slug: $slug))
			->andWhere($this->idIn(qb: $qb, ids: $ids))
			->setMaxResults(1);

		return $this->firstResolved(qb: $qb);
	}//end findBySlugInIds()

	/**
	 * The match of the id column against a list.
	 *
	 * @param IQueryBuilder   $qb  The query.
	 * @param array<int, int> $ids The ids.
	 *
	 * @return string The comparison.
	 */
	private function idIn(IQueryBuilder $qb, array $ids): string {
		return $qb->expr()->in('id', $qb->createNamedParameter(value: $ids, type: IQueryBuilder::PARAM_INT_ARRAY));
	}//end idIn()

	/**
	 * A candidate id list as positive integers; an empty result can never match.
	 *
	 * @param array<mixed> $candidates The candidates.
	 *
	 * @return array<int, int>
	 */
	private static function positiveIds(array $candidates): array {
		$ids = [];
		foreach ($candidates as $candidate) {
			if (is_numeric($candidate) === true && (int)$candidate > 0) {
				$ids[] = (int)$candidate;
			}
		}

		return $ids;
	}//end positiveIds()


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
		$ids = self::positiveIds(candidates: $schemaIds);
		if ($ids === []) {
			return null;
		}

		$this->readTrace()->record(method: 'findInIds');

		// Same identifier forms and tie-breaks as find(), narrowed to the id set.
		$qb = $this->buildFindByIdentifierQuery(id: (string)$id);
		$qb->andWhere($this->idIn(qb: $qb, ids: $ids));
		$qb->setMaxResults(1);

		return $this->firstResolved(qb: $qb);
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
		$this->readTrace()->record(method: 'countBySlug');

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'slug_count'))
			->from('openregister_schemas')
			->where($this->slugEquals(qb: $qb, slug: $slug));

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
			} catch (DoesNotExistException|MultipleObjectsReturnedException|DBException $e) {
				// Ids that cannot be loaded are left out of the result.
				$this->logger->debug(
					message: '[SchemaMapper] findMultiple skips a schema id that could not be loaded',
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
			$this->readTrace()->record(method: 'findMultipleOptimized');
			$schemas = array_replace($schemas, $this->schemasWithIds(ids: $chunk));
		}

		return $schemas;
	}//end findMultipleOptimized()

	/**
	 * The schemas with the given ids, keyed by id, unresolved.
	 *
	 * @param array<int, mixed> $ids The ids, at most MAX_IN_LIST_SIZE.
	 *
	 * @return array<int|string, Schema>
	 */
	private function schemasWithIds(array $ids): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('openregister_schemas')
			->where(
				$qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY))
			);

		return $this->fetchSchemas(qb: $qb);
	}//end schemasWithIds()

	/**
	 * Finds schemas by slug
	 *
	 * Searches for schemas matching the given slug with optional
	 * multi-tenancy and RBAC filtering.
	 *
	 * @param string $slug The slug to search for
	 * @param ListQuery $query The page: its limit and offset
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
		ListQuery $query,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array {
		$this->readTrace()->record(method: 'findBySlug');

		$qb = $this->exactSlugQuery(slug: $slug);
		$this->filterByOrganisation(qb: $qb, multitenancy: $_multitenancy);
		$qb->setMaxResults($query->limit)
			->setFirstResult($query->offset ?? 0);

		return array_values($this->fetchSchemas(qb: $qb));
	}//end findBySlug()

	/**
	 * The query for the rows with exactly this slug.
	 *
	 * @param string $slug The slug.
	 *
	 * @return IQueryBuilder
	 */
	private function exactSlugQuery(string $slug): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('openregister_schemas')
			->where(
				$qb->expr()->eq('slug', $qb->createNamedParameter($slug, IQueryBuilder::PARAM_STR))
			);

		return $qb;
	}//end exactSlugQuery()

	/**
	 * Run a schema query and keep its rows as entities, keyed by id.
	 *
	 * @param IQueryBuilder $qb The query.
	 *
	 * @return array<int|string, Schema>
	 */
	private function fetchSchemas(IQueryBuilder $qb): array {
		$result = $qb->executeQuery();
		$schemas = [];
		while (($row = $result->fetch()) !== false) {
			$schemas[$row['id']] = Schema::fromRow($row);
		}

		$result->closeCursor();

		return $schemas;
	}//end fetchSchemas()

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
		int|null $limit = null,
		int|null $offset = null,
		array|null $filters = [],
		array|null $searchConditions = [],
		array|null $searchParams = [],
		array|null $_extend = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array {
		$this->readTrace()->record(method: 'findAll');
		$qb = $this->listedQuery(query: new ListQuery(limit: ($limit ?? ListQuery::UNBOUNDED), offset: $offset, filters: ($filters ?? [])));
		if (empty($searchConditions) === false) {
			$qb->andWhere('(' . implode(' OR ', $searchConditions) . ')');
			$this->bindSearchParams(qb: $qb, params: $searchParams);
		}

		$this->filterByOrganisation(qb: $qb, multitenancy: $_multitenancy);

		return $this->findEntities(query: $qb);
	}//end findAll()

	/**
	 * The schema list query for one page of column-filtered rows.
	 *
	 * @param ListQuery $query The limit (unbounded for every row), the offset and the column filters.
	 *
	 * @return IQueryBuilder The query, before search conditions and the organisation filter.
	 */
	private function listedQuery(ListQuery $query): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('openregister_schemas')
			->setMaxResults($query->limitOrNull())
			->setFirstResult($query->offset);

		$this->applyColumnFilters(qb: $qb, filters: $query->filters);

		return $qb;
	}//end listedQuery()

	/**
	 * Bind the named parameters of the search conditions.
	 *
	 * @param IQueryBuilder $qb     The query.
	 * @param array|null    $params The parameters, by name.
	 *
	 * @return void
	 */
	private function bindSearchParams(IQueryBuilder $qb, array|null $params): void {
		foreach ($params ?? [] as $param => $value) {
			$qb->setParameter($param, $value);
		}
	}//end bindSearchParams()

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

		$governance = new ScopedPropertyGovernance(
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			appConfig: $this->appConfig
		);

		foreach ($this->scopedProperties(schema: $entity) as $name => $scope) {
			$governance->assertMayAddAtScope(scope: $scope, path: (string)$name);
			$governance->assertBelowCeiling(schema: $entity, field: new ScopedField(property: (string)$name, scope: $scope));
		}
	}//end assertScopedPropertiesAreGoverned()

	/**
	 * The scope each scoped property declares, trimmed, by property name.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return array<int|string, string>
	 */
	private function scopedProperties(Schema $schema): array {
		$scopes = [];
		foreach ($schema->getProperties() as $name => $property) {
			$scope = null;
			if (is_array($property) === true) {
				$scope = ($property[ScopedPropertyDeclaration::ANNOTATION] ?? null);
			}

			if (is_string($scope) === true && trim($scope) !== '') {
				$scopes[$name] = trim($scope);
			}
		}

		return $scopes;
	}//end scopedProperties()

	/**
	 * Ensures that a schema object has a UUID and a slug.
	 *
	 * @param Schema $schema The schema object to clean
	 *
	 * @return void
	 */
	private function cleanObject(Schema $schema): void {
		$this->prepareForSave(schema: $schema);
		$this->checkBehaviourDeclarations(schema: $schema);
		$this->checkDataDeclarations(schema: $schema);
		$this->checkExchangeDeclarations(schema: $schema);
		$this->checkAccessDeclarations(schema: $schema);
		$this->logDroppedAnnotationKeys(schema: $schema);
	}//end cleanObject()

	/**
	 * Normalise references, fill defaults and settle the name and description fields.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return void
	 */
	private function prepareForSave(Schema $schema): void {
		(new ReferenceNormaliser())->normalise(schema: $schema);
		$defaults = new SchemaDefaults();
		$defaults->ensureIdentifiers(schema: $schema);
		$fields = new ConfigurationFields(schema: $schema);
		$fields->validate();
		$defaults->buildRequired(schema: $schema);
		$fields->populate();
	}//end prepareForSave()

	/**
	 * What objects of this schema do: lifecycle, actions, calculations, relations and rules.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return void
	 */
	private function checkBehaviourDeclarations(Schema $schema): void {
		(new LifecycleAnnotationCheck(logger: $this->logger))->check(schema: $schema);
		(new DeclaredActionCheck(objectActions: $this->objectActions, logger: $this->logger))->check(schema: $schema);
		(new RecordAnnotationRefusals(logger: $this->logger))->checkMdtoMapping(schema: $schema);
		(new AdvisoryAnnotationChecks(logger: $this->logger))->checkAggregations(schema: $schema);
		(new CalculationAnnotationCheck(logger: $this->logger))->check(schema: $schema);
		(new SubjectDeclarationRefusals())->checkConsent(schema: $schema);
		(new RelationDeclarationCheck(
			findSchema: fn (string $id): Schema => $this->find(id: $id, _rbac: false, _multitenancy: false)
		))->check(schema: $schema);
		(new PropertyDeclarationRefusals())->checkDependentValues(schema: $schema);
		(new ReferenceLoopCheck())->check(schema: $schema);
	}//end checkBehaviourDeclarations()

	/**
	 * What the data of this schema means: quality, dedup, merge, party and archiving.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return void
	 */
	private function checkDataDeclarations(Schema $schema): void {
		$advisory = new AdvisoryAnnotationChecks(logger: $this->logger);
		$records = new RecordAnnotationRefusals(logger: $this->logger);
		$advisory->checkQuality(schema: $schema);
		$advisory->checkDedup(schema: $schema);
		$records->checkUniqueHint(schema: $schema);
		$advisory->checkHinge(schema: $schema);
		$advisory->checkSurvivorship(schema: $schema);
		$advisory->checkMerge(schema: $schema);
		$advisory->checkParty(schema: $schema);
		$this->checkNotifications(schema: $schema);
		$records->checkWidgets(schema: $schema);
		$records->checkArchival(schema: $schema);
	}//end checkDataDeclarations()

	/**
	 * Check the notification annotation; an unlinkable url action only warns while an import asked it.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return void
	 */
	private function checkNotifications(Schema $schema): void {
		$notifications = new NotificationAnnotationCheck(logger: $this->logger);
		if ($this->warnUnlinkable === true) {
			$notifications->warn(schema: $schema);
			return;
		}

		$notifications->check(schema: $schema);
	}//end checkNotifications()

	/**
	 * How objects leave or enter: handoff, MCP, registry, links and extending forms.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return void
	 */
	private function checkExchangeDeclarations(Schema $schema): void {
		$exchange = new ExchangeAnnotationRefusals();
		$exchange->checkHandoff(schema: $schema);
		$exchange->checkHandoffContract(schema: $schema);
		$exchange->checkMcp(schema: $schema);
		(new SubjectDeclarationRefusals())->checkRegistry(schema: $schema);
		(new RecordAnnotationRefusals(logger: $this->logger))->checkExternalLinks(schema: $schema);
		(new PropertyDeclarationRefusals())->checkExtendingForm(schema: $schema);
	}//end checkExchangeDeclarations()

	/**
	 * Who reaches what: authorization, hierarchy, matrix, reveal audit, reversibility and review.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return void
	 */
	private function checkAccessDeclarations(Schema $schema): void {
		$this->validateAuthorizationDeny(schema: $schema);
		$access = new AccessDeclarationChecks(logger: $this->logger);
		$access->checkHierarchy(schema: $schema);
		$access->checkDepartmentMatrix(schema: $schema);
		$access->checkRevealAudit(schema: $schema);
		(new PropertyDeclarationRefusals())->checkReversibility(schema: $schema);
		(new SubjectDeclarationRefusals())->checkReview(schema: $schema);
	}//end checkAccessDeclarations()

	/**
	 * Refuse an authorization block that cannot hold; RegisterMapper has its own of this name.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function validateAuthorizationDeny(Schema $schema): void {
		(new AuthorizationBlockCheck(db: $this->db, eventDispatcher: $this->eventDispatcher))->check(schema: $schema);
	}//end validateAuthorizationDeny()

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
		$schema = $this->extractSchemaDelta(schema: $schema);

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
		$this->readTrace()->record(method: 'update');
		$oldSchema = $this->storedRow(entity: $entity);

		$entity = parent::update(entity: $this->preparedOver(old: $oldSchema, schema: $entity));
		$this->announceUpdate(old: $oldSchema, new: $entity);

		return $entity;
	}//end update()

	/**
	 * The stored row of an entity, read without the organisation filter.
	 *
	 * @param Entity $entity The entity.
	 *
	 * @return Schema The row as it is in the database.
	 */
	private function storedRow(Entity $entity): Schema {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('openregister_schemas')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($entity->getId(), IQueryBuilder::PARAM_INT)));

		return $this->findEntity(query: $qb);
	}//end storedRow()

	/**
	 * Make a schema ready to be written over its stored row, warning about a url action it keeps as stored.
	 *
	 * A url action that cannot be linked to is refused when a save writes it. A save that
	 * leaves the notification block as stored (an authorization change, a revocation, a
	 * repair) only warns, so a rule stored before the check does not block other edits.
	 *
	 * @param Schema $old    The stored row.
	 * @param Entity $schema The schema to save.
	 *
	 * @return Schema The schema to write.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function preparedOver(Schema $old, Entity $schema): Schema {
		if (self::keepsNotifications(old: $old, new: $schema) === false) {
			return $this->preparedForUpdate(schema: $schema);
		}

		$wasWarning = $this->warnOnUnlinkableNotificationActions();
		try {
			return $this->preparedForUpdate(schema: $schema);
		} finally {
			$this->warnUnlinkable = $wasWarning;
		}
	}//end preparedOver()

	/**
	 * Whether a save leaves the notification block exactly as stored.
	 *
	 * @param Schema $old The stored row.
	 * @param Entity $new The schema to save.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private static function keepsNotifications(Schema $old, Entity $new): bool {
		$key = 'x-openregister-notifications';

		return $new instanceof Schema
			&& (new SchemaAnnotation(schema: $old, key: $key))->value() === (new SchemaAnnotation(schema: $new, key: $key))->value();
	}//end keepsNotifications()

	/**
	 * Make a schema ready to be written over its stored row.
	 *
	 * This is the single choke point every runtime edit passes through
	 * (updateFromArray, the controllers, the tool providers), so `updated` is
	 * stamped here, after hydrate(): a client-supplied `updated` cannot suppress
	 * it, the modification time is the server's to state. ContextsController's
	 * ETag, JsonLdContextService::cacheKey() and ReferentialIntegrityService's
	 * relation index all key on that stamp moving.
	 *
	 * @param Entity $schema The schema to save.
	 *
	 * @return Schema The schema to write.
	 */
	private function preparedForUpdate(Entity $schema): Schema {
		// Clean the schema object to ensure UUID, slug, and version are set.
		$this->cleanObject(schema: $schema);

		// Store only the differences from a composed parent, not the resolved schema.
		// Circular reference validation is done during SchemaComposition::resolve().
		$schema = $this->extractSchemaDelta(schema: $schema);

		// Generate facet configuration from schema properties.
		(new FacetConfiguration())->generate(schema: $schema);

		$schema->setUpdated(new DateTime());

		return $schema;
	}//end preparedForUpdate()

	/**
	 * Publish a schema update, but only when the save changed something.
	 *
	 * An app re-importing an identical schema must not tell every admin
	 * "Schema was updated".
	 *
	 * @param Schema $old The stored row before the save.
	 * @param Schema $new The saved schema.
	 *
	 * @return void
	 */
	private function announceUpdate(Schema $old, Schema $new): void {
		if ((new EntityChangeDetector())->changed(old: $old, new: $new) === false) {
			return;
		}

		$schemaId = (int)$new->getId();
		$this->updateEventCounts[$schemaId] = (($this->updateEventCounts[$schemaId] ?? 0) + 1);
		$this->eventDispatcher->dispatchTyped(new SchemaUpdatedEvent(newSchema: $new, oldSchema: $old));
	}//end announceUpdate()

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
	 * From now on a notification url action that cannot be linked to only warns on save.
	 *
	 * A configuration import sets it around its schema writes, so one such action
	 * does not abort the register; a save by hand still refuses it.
	 *
	 * @return bool Whether it already did.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function warnOnUnlinkableNotificationActions(): bool {
		$previous = $this->warnUnlinkable;
		$this->warnUnlinkable = true;

		return $previous;
	}//end warnOnUnlinkableNotificationActions()

	/**
	 * From now on a notification url action that cannot be linked to refuses the save, as it does by default.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function refuseUnlinkableNotificationActions(): void {
		$this->warnUnlinkable = false;
	}//end refuseUnlinkableNotificationActions()

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

		if ($force === false) {
			$this->refuseWhileObjectsAttached(schemaId: (int)$entity->getId());
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
	 * Refuse a delete that would leave objects without their schema.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return void
	 * @throws ValidationException If objects are still attached.
	 */
	private function refuseWhileObjectsAttached(int $schemaId): void {
		$count = $this->countAttachedObjects(schemaId: $schemaId);
		if ($count > 0) {
			throw new ValidationException(
				message: 'Cannot delete schema: ' . $count . ' objects are still attached.'
			);
		}
	}//end refuseWhileObjectsAttached()

	/**
	 * Count the objects still attached to a schema.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return int The number of live objects attached to the schema.
	 *
	 * @spec openspec/changes/schema-delete-cascade/specs/runtime-schema-api/spec.md
	 */
	private function countAttachedObjects(int $schemaId): int {
		return (new SchemaRegisterReads(db: $this->db))->attachedObjects(schemaId: $schemaId);
	}//end countAttachedObjects()

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
		return (new SchemaRegisterReads(db: $this->db))->registerCountPerSchema();
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
				$metadata[$propName] = ['source' => 'inherited', 'inheritedFrom' => $this->inheritedFrom(schema: $schema, propertyName: (string)$propName)];
			}
		}

		return $metadata;
	}//end getPropertySourceMetadata()

	/**
	 * The parent schema a property of a composed schema comes from.
	 *
	 * @param Schema $schema       The schema.
	 * @param string $propertyName The property.
	 *
	 * @return string|null
	 */
	private function inheritedFrom(Schema $schema, string $propertyName): ?string {
		return $this->composition()->propertySource(propertyName: $propertyName, parentRefs: ($schema->getAllOf() ?? []));
	}//end inheritedFrom()

	/**
	 * The properties a schema stores itself, as the resolved schema has them merged.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<mixed> The stored properties, empty when they cannot be read.
	 */
	private function storedProperties(Schema $schema): array {
		try {
			$this->readTrace()->record(method: 'getPropertySourceMetadata');

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
	 * A composed schema reduced to what it adds to its parents.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return Schema
	 */
	private function extractSchemaDelta(Schema $schema): Schema {
		return $this->composition()->extractDelta(schema: $schema);
	}//end extractSchemaDelta()

	/**
	 * Find schemas that compose with a given schema.
	 *
	 * Returns the uuids of the schemas that reference the given schema in their
	 * allOf, oneOf or anyOf composition patterns.
	 *
	 * @param Schema|int|string $schema The schema, or its ID, UUID or slug to look it up by.
	 *
	 * @return array Array of schema UUIDs that compose with this schema
	 *
	 * @psalm-return list{0?: mixed,...}
	 *
	 * @spec exclude schema composition lookup, no OpenSpec capability of its own yet
	 */
	public function findExtendedBy(Schema|int|string $schema): array {
		if ($schema instanceof Schema === false) {
			try {
				$schema = $this->find(id: $schema);
			} catch (Exception $e) {
				return [];
			}
		}

		$identifiers = [(string)$schema->getId(), $schema->getUuid(), $schema->getSlug()];
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

		$search = $this->referencesTo(target: $target);
		$related = [];
		foreach ($this->findAll(_multitenancy: false) as $candidate) {
			if ($candidate->getId() !== (int)$target->getId() && $search->foundIn(properties: $candidate->getProperties()) === true) {
				$related[] = $candidate;
			}
		}

		return $related;
	}//end getRelated()

	/**
	 * The search for references to a schema, by id, uuid or slug.
	 *
	 * @param Schema $target The schema.
	 *
	 * @return SchemaReferenceSearch
	 */
	private function referencesTo(Schema $target): SchemaReferenceSearch {
		return new SchemaReferenceSearch(
			target: ['id' => (string)$target->getId(), 'uuid' => (string)$target->getUuid(), 'slug' => (string)$target->getSlug()]
		);
	}//end referencesTo()

	/**
	 * Recursively check if properties contain a reference to the target schema.
	 *
	 * @param array  $properties The properties array to search through.
	 * @param Schema $target     The schema whose id, uuid or slug a reference names.
	 *
	 * @return bool True if a reference to the target schema is found
	 */
	public function hasReferenceToSchema(array $properties, Schema $target): bool {
		$search = new SchemaReferenceSearch(
			target: ['id' => (string)$target->getId(), 'uuid' => $target->getUuid() ?? '', 'slug' => $target->getSlug() ?? '']
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
			$this->readTrace()->record(method: 'loadSchema');

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
		$this->readTrace()->record(method: 'findNonSearchableIds');

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
		$this->readTrace()->record(method: 'findSearchableIds');

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
		$this->readTrace()->record(method: 'findSmartPickerEnabledIds');

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
