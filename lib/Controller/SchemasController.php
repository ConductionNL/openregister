<?php

/**
 * SchemasController handles REST API endpoints for schema management
 *
 * Controller for managing schema operations in the OpenRegister app.
 * Provides endpoints for CRUD operations, schema exploration, caching,
 * import/export, and statistics.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Controller;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenRegister\Controller\Support\SchemaRemoval;
use OCA\OpenRegister\Controller\Support\SchemasControllerServices;
use OCA\OpenRegister\Db\AuditTrail\AuditStatisticsScope;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\MagicMapper\StatisticsScope;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\InvalidAuthorizationRuleException;
use OCA\OpenRegister\Exception\ArchivalImmutableException;
use OCA\OpenRegister\Exception\AuthorizationBlockException;
use OCA\OpenRegister\Exception\BreakingSchemaChangeException;
use OCA\OpenRegister\Exception\DatabaseConstraintException;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Exception\SchemaImportException;
use OCA\OpenRegister\Exception\SchemaNotInRegisterException;
use OCA\OpenRegister\Service\Rbac\RbacRuleChecks;
use OCA\OpenRegister\Service\AuthorizationAuditService;
use OCA\OpenRegister\Service\AuthorizationChange;
use OCA\OpenRegister\Service\Rbac\RbacChecks;
use OCA\OpenRegister\Service\Rbac\ReadRuleTenancy;
use OCA\OpenRegister\Service\Calculation\CalculationDeclarationException;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationDeclarationException;
use OCA\OpenRegister\Service\Consent\ConsentDeclarationException;
use OCA\OpenRegister\Service\Form\FormDependentsCheckInterface;
use OCA\OpenRegister\Service\Hinge\ListPresentationResolverInterface;
use OCA\OpenRegister\Service\BulkJob\ReversibilityDeclarationException;
use OCA\OpenRegister\Service\Decision\ReviewDeclarationException;
use OCA\OpenRegister\Service\Relation\RelationDeclarationException;
use OCA\OpenRegister\Service\Rules\DependentValueDeclarationException;
use OCA\OpenRegister\Service\RegisterScopedSchemaResolverInterface;
use OCA\OpenRegister\Service\RegisterScopedSchemaResolvers;
use OCA\OpenRegister\Service\Schema\IncomingDefinition;
use OCA\OpenRegister\Service\Schema\SchemaChangeRecord;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;
use OCA\OpenRegister\Service\SchemaTeardownInterface;
use OCA\OpenRegister\Service\SchemaImport\GgmUpload;
use OCA\OpenRegister\Service\SchemaImport\ImportOptions;
use OCA\OpenRegister\Service\SchemaImport\SchemaImportServiceInterface;
use OCA\OpenRegister\Service\Schemas\FacetResultCacheClearerInterface;
use OCA\OpenRegister\Exception\UniqueHintException;
use OCA\OpenRegister\Service\Schemas\PropertyVocabularyException;
use OCA\OpenRegister\Service\Schemas\SchemaCatalogue;
use OCA\OpenRegister\Service\Schemas\ReferenceFilterException;
use OCA\OpenRegister\Service\Schemas\ReferenceFilterOperandGuard;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandlerInterface;
use OCA\OpenRegister\Service\Schemas\SemanticRoleHandlerInterface;
use OCA\OpenRegister\Service\SemanticTypeResolverInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\Exception as DBException;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * SchemasController handles REST API endpoints for schema management
 *
 * Provides REST API endpoints for managing schemas including CRUD operations,
 * schema exploration, caching, import/export, and statistics.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     REST controllers have many endpoints; extraction
 * into sub-controllers would break the route registration.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) REST controllers have many endpoints; extraction
 * into sub-controllers would break the route registration.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     REST controllers have many endpoints; extraction
 * into sub-controllers would break the route registration.
 * @SuppressWarnings(PHPMD.TooManyMethods)           REST controllers have many endpoints; extraction
 * into sub-controllers would break the route registration.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   NC AppFramework controller DI requires injecting
 * framework + RBAC + audit + domain services, each used in separate endpoint groups.
 *
 * @spec openspec/specs/openapi-generation/spec.md
 */
class SchemasController extends Controller {

	/**
	 * The exceptions that answer 422 with the errors of the declaration.
	 *
	 * @var array<int, class-string>
	 */
	private const DECLARATION_REFUSALS = [
		UniqueHintException::class,
		PropertyVocabularyException::class,
		ReversibilityDeclarationException::class,
		ReviewDeclarationException::class,
		RelationDeclarationException::class,
		CalculationDeclarationException::class,
		ConsentDeclarationException::class,
		DependentValueDeclarationException::class,
		NotificationDeclarationException::class,
	];
	use \OCA\OpenRegister\Controller\Trait\HandlesExceptionsTrait;

	/**
	 * Message fragments that mark a failed schema write as a validation error (400).
	 *
	 * @var array<int, string>
	 */
	private const VALIDATION_ERROR_MARKERS = [
		'Invalid',
		'must be',
		'required',
		'requires translatable',
		'format',
		'Property at',
		'authorization',
		'handoff',
	];

	/**
	 * Message fragments that mark a failed schema write as a conflict (409).
	 *
	 * @var array<int, string>
	 */
	private const CONFLICT_ERROR_MARKERS = ['constraint', 'duplicate', 'unique'];

	/**
	 * The `logs` statistics a caller who may not count the trail is given.
	 *
	 * @var array{total: int, size: int}
	 */
	private const NO_LOG_STATS = ['total' => 0, 'size' => 0];

	/**
	 * The shared register-scoped schema resolver.
	 *
	 * Built here rather than injected: it is a stateless collaborator over the
	 * `RegisterMapper` + `SchemaMapper` this class already holds, so constructing
	 * it directly keeps every existing unit test — all of which mock those two
	 * mappers — exercising the REAL resolution path instead of a mock of the very
	 * thing under test.
	 *
	 * @var RegisterScopedSchemaResolverInterface
	 */
	private readonly RegisterScopedSchemaResolverInterface $scopedSchemaResolver;

	/**
	 * The call site for the reference-filter operand check.
	 *
	 * Built here for the same reason as the resolver above: it is a stateless
	 * collaborator over the `SchemaMapper` this class already holds, so every
	 * existing unit test keeps exercising the real path instead of a mock of
	 * the thing under test.
	 *
	 * @var ReferenceFilterOperandGuard
	 */
	private readonly ReferenceFilterOperandGuard $referenceFilterOperands;

	/**
	 * The schema mapper.
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * The register mapper.
	 *
	 * @var RegisterMapperInterface
	 */
	private readonly RegisterMapperInterface $registerMapper;

	/**
	 * The object entity mapper.
	 *
	 * @var MagicMapperInterface
	 */
	private readonly MagicMapperInterface $objectEntityMapper;

	/**
	 * The audit trail mapper.
	 *
	 * @var AuditTrailMapperInterface
	 */
	private readonly AuditTrailMapperInterface $auditTrailMapper;

	/**
	 * The schema cache service.
	 *
	 * @var SchemaCacheHandlerInterface
	 */
	private readonly SchemaCacheHandlerInterface $schemaCacheService;

	/**
	 * The facet cache svc.
	 *
	 * @var FacetResultCacheClearerInterface
	 */
	private readonly FacetResultCacheClearerInterface $facetResults;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * The container.
	 *
	 * @var ContainerInterface
	 */
	private readonly ContainerInterface $container;

	/**
	 * The schema versioning service.
	 *
	 * @var SchemaVersioningService
	 */
	private readonly SchemaVersioningService $schemaVersioningService;

	/**
	 * The schema import service.
	 *
	 * @var ?SchemaImportServiceInterface
	 */
	private readonly ?SchemaImportServiceInterface $schemaImportService;

	/**
	 * The semantic type resolver.
	 *
	 * @var ?SemanticTypeResolverInterface
	 */
	private readonly ?SemanticTypeResolverInterface $semanticTypeResolver;

	/**
	 * The semantic roles.
	 *
	 * @var ?SemanticRoleHandlerInterface
	 */
	private readonly ?SemanticRoleHandlerInterface $semanticRoles;

	/**
	 * The controller's injected services.
	 *
	 * @var SchemasControllerServices
	 */
	private readonly SchemasControllerServices $services;

	/**
	 * Constructor
	 *
	 * Initializes controller with required dependencies for schema operations.
	 * Calls parent constructor to set up base controller functionality.
	 *
	 * @param string $appName Application name
	 * @param IRequest $request HTTP request object
	 * @param SchemasControllerServices $services The schemas controller services.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		SchemasControllerServices $services,
	) {
		// Call parent constructor to initialize base controller.
		parent::__construct(appName: $appName, request: $request);
		$this->services = $services;
		$this->schemaMapper = $services->mappers->schemaMapper;
		$this->registerMapper = $services->mappers->registerMapper;
		$this->objectEntityMapper = $services->mappers->objectEntityMapper;
		$this->auditTrailMapper = $services->mappers->auditTrailMapper;
		$this->schemaCacheService = $services->schemas->schemaCacheService;
		$this->facetResults = $services->schemas->facetResults;
		$this->logger = $services->platform->logger;
		$this->container = $services->platform->container;
		$this->schemaVersioningService = $services->schemas->versioning;
		$this->schemaImportService = $services->semantics->schemaImportService;
		$this->semanticTypeResolver = $services->semantics->semanticTypeResolver;
		$this->semanticRoles = $services->semantics->semanticRoles;
		$this->scopedSchemaResolver = RegisterScopedSchemaResolvers::over(
			registerMapper: $this->registerMapper,
			schemaMapper: $this->schemaMapper
		);
		$this->referenceFilterOperands = new ReferenceFilterOperandGuard(schemaMapper: $this->schemaMapper);
	}//end __construct()

	/**
	 * Retrieves a list of all schemas
	 *
	 * Returns a JSON response containing an array of all schemas in the system.
	 * Supports pagination, filtering, and extended properties (stats, extendedBy).
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @PublicPage
	 *
	 * @return JSONResponse JSON response with array of schemas
	 *
	 * @psalm-return JSONResponse<200,
	 *     array{results: list<array<string, mixed>>}, array<never, never>>
	 *
	 * Each result is deliberately `array<string, mixed>` and not a spelled-out
	 * shape. The previous annotation restated Schema::jsonSerialize() field by
	 * field and was already wrong: it omitted the four keys (`objects`, `logs`,
	 * `files`, `registers`) that the `_stats` block appends to every entry. The
	 * entity's serializer owns that contract; copying it here only guarantees
	 * the copy drifts.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function index(): JSONResponse {
		// Get request parameters for filtering and searching.
		$params = $this->withClassificationFilter(params: $this->request->getParams());
		if ($params instanceof JSONResponse) {
			return $params;
		}

		$extend = $this->extendList(extend: ($params['_extend'] ?? []));

		$schemas    = $this->visibleSchemas(schemas: $this->listSchemas(params: $params));
		$schemasArr = $this->withExtendedBy(schemasArr: array_map(fn ($schema) => $this->forCaller(schema: $schema->jsonSerialize()), $schemas));

		// If '@self.stats' is requested, attach statistics to each schema.
		if (in_array('@self.stats', $extend, true) === true) {
			$schemasArr = $this->withSchemaStats(schemasArr: $schemasArr);
		}

		return new JSONResponse(data: ['results' => $schemasArr]);
	}//end index()

	/**
	 * Pass a `classification` filter to the list only when it is one of the vocabulary values.
	 *
	 * Read from `?classification=` or `?filters[classification]=`. An unknown
	 * value is a 400 before it reaches the query; a known one is only a WHERE
	 * clause on the same RBAC- and tenant-scoped list.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return array<string, mixed>|JSONResponse The parameters, or a 400 naming the field.
	 *
	 * @spec openspec/changes/modelling-type-catalogue-metadata/specs/schema-catalogue-metadata/spec.md#requirement-schemas-can-be-listed-by-classification
	 */
	private function withClassificationFilter(array $params): array|JSONResponse {
		$filters = ($params['filters'] ?? []);
		if (is_array($filters) === false) {
			$filters = [];
		}

		$wanted = ($params['classification'] ?? $filters['classification'] ?? null);
		unset($params['classification'], $filters['classification']);
		if ($wanted !== null && SchemaCatalogue::isClassification(value: $wanted) === false) {
			return new JSONResponse(
				data: ['error' => 'classification must be one of: ' . implode(', ', SchemaCatalogue::CLASSIFICATIONS) . '.', 'field' => 'classification'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		if ($wanted !== null) {
			$filters['classification'] = $wanted;
		}

		if ($filters !== [] || array_key_exists('filters', $params) === true) {
			$params['filters'] = $filters;
		}

		return $params;
	}//end withClassificationFilter()

	/**
	 * A serialised schema as this caller may see it: an anonymous caller does not see the catalogue's contact person.
	 *
	 * @param array<string, mixed> $schema The serialised schema.
	 *
	 * @return array<string, mixed> The schema.
	 *
	 * @spec openspec/changes/modelling-type-catalogue-metadata/specs/schema-catalogue-metadata/spec.md#requirement-a-schema-carries-catalogue-metadata
	 */
	private function forCaller(array $schema): array {
		if ($this->isAnonymousRequest() === false) {
			return $schema;
		}

		return SchemaCatalogue::withoutContact(schema: $schema);
	}//end forCaller()

	/**
	 * An `_extend` value as a list: a single string becomes a one-entry list.
	 *
	 * @param mixed $extend The requested `_extend` value.
	 *
	 * @return mixed The list of extend entries.
	 */
	private function extendList(mixed $extend): mixed {
		if (is_string($extend) === true) {
			return [$extend];
		}

		return $extend;
	}//end extendList()

	/**
	 * The page of schemas a listing asks for, with its filters, unscoped by tenancy.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return array<int, Schema> The schemas.
	 */
	private function listSchemas(array $params): array {
		return $this->schemaMapper->findAll(
			limit: $this->intParam(params: $params, key: '_limit'),
			offset: $this->listOffset(params: $params),
			filters: ($params['filters'] ?? []),
			searchConditions: [],
			searchParams: [],
			_extend: [],
			_multitenancy: false
		);
	}//end listSchemas()

	/**
	 * A request parameter as an integer, or null when it is not set.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param string               $key    The parameter name.
	 *
	 * @return int|null The value.
	 */
	private function intParam(array $params, string $key): ?int {
		if (isset($params[$key]) === false) {
			return null;
		}

		return (int)$params[$key];
	}//end intParam()

	/**
	 * The listing offset: derived from `_page` when a page and a limit are given, else `_offset`.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return int|null The offset.
	 */
	private function listOffset(array $params): ?int {
		$limit = $this->intParam(params: $params, key: '_limit');
		$page  = $this->intParam(params: $params, key: '_page');
		if ($page !== null && $limit !== null) {
			return ($page - 1) * $limit;
		}

		return $this->intParam(params: $params, key: '_offset');
	}//end listOffset()

	/**
	 * The schemas the caller may see in a listing.
	 *
	 * Read-visibility guard: this endpoint is @PublicPage so it stays
	 * reachable when OpenRegister is restricted to a user group. Anonymous
	 * callers may only see schemas whose RBAC authorization grants read
	 * access to the `public` group; authenticated users are unaffected.
	 * Visibility is derived from server-side authorization rules, never
	 * from client-supplied parameters.
	 *
	 * @param array<int, Schema> $schemas The listed schemas.
	 *
	 * @return array<int, Schema> The visible schemas.
	 */
	private function visibleSchemas(array $schemas): array {
		if ($this->isAnonymousRequest() === false) {
			return $schemas;
		}

		return array_values(
			array_filter(
				$schemas,
				fn ($schema) => $this->isPublicReadable(authorization: $schema->getAuthorization())
			)
		);
	}//end visibleSchemas()

	/**
	 * Add `@self.extendedBy` to each serialized schema.
	 *
	 * Batch-loads all extendedBy relationships in a single query instead of N queries.
	 *
	 * @param array<int, array<string, mixed>> $schemasArr The serialized schemas.
	 *
	 * @return array<int, array<string, mixed>> The schemas with their extendedBy lists.
	 */
	private function withExtendedBy(array $schemasArr): array {
		$allExtendedBy = $this->schemaMapper->findAllExtendedBy();
		foreach ($schemasArr as &$schema) {
			// @psalm-suppress InvalidArrayOffset
			$schema['@self'] = $schema['@self'] ?? [];
			$schema['@self']['extendedBy'] = $allExtendedBy[$schema['id']] ?? [];
		}

		// Break the reference.
		unset($schema);
		return $schemasArr;
	}//end withExtendedBy()

	/**
	 * Attach the `@self.stats` block to each serialized schema of a list.
	 *
	 * Batch-loads the statistics in 3 queries instead of N*2. The audit-trail
	 * counts are given to an administrator only.
	 *
	 * @param array<int, array<string, mixed>> $schemasArr The serialized schemas.
	 *
	 * @return array<int, array<string, mixed>> The schemas with a `stats` block.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md#requirement-the-instance-wide-audit-reads-are-admin-only
	 */
	private function withSchemaStats(array $schemasArr): array {
		$schemaIds = array_map(fn ($schema) => $schema['id'], $schemasArr);

		$registerCounts = $this->schemaMapper->getRegisterCountPerSchema();
		$objectStats = $this->objectEntityMapper->getStatisticsGroupedBySchema(schemaIds: $schemaIds);
		$logStats = [];
		if ($this->isCurrentUserAdmin() === true) {
			$logStats = $this->auditTrailMapper->getStatisticsGroupedBySchema(schemaIds: $schemaIds);
		}

		foreach ($schemasArr as &$schema) {
			$schema['stats'] = [
				'objects' => $objectStats[$schema['id']] ?? [
					'total' => 0,
					'size' => 0,
					'invalid' => 0,
					'deleted' => 0,
					'locked' => 0,
				],
				'logs' => $logStats[$schema['id']] ?? self::NO_LOG_STATS,
				'files' => [ 'total' => 0, 'size' => 0 ],
				// Add the number of registers referencing this schema.
				'registers' => $registerCounts[$schema['id']] ?? 0,
			];
		}

		unset($schema);
		return $schemasArr;
	}//end withSchemaStats()

	/**
	 * Resolve the {id} route parameter to a Schema, register-scoped when the caller names one.
	 *
	 * WHY this exists. Schema slugs are unique WITHIN a register, never across the
	 * instance, but `SchemaMapper::find()` matches `LOWER(slug)` globally and returns
	 * the first row it fetches. Two apps that both own an `agent`, `conversation`,
	 * `order` or `task` schema therefore fight over the name and the loser silently
	 * reads the winner's schema. Measured on the shared dev instance 2026-08-13:
	 * hermiq and openbuild both own slug `agent`, and `GET /api/schemas/agent`
	 * returned openbuild's 6-property schema instead of hermiq's 36-property one —
	 * a bug hermiq carries a permanent layout workaround for.
	 *
	 * `?register=` is OPTIONAL and absence keeps the previous behaviour exactly.
	 * This is a foundation repository consumed by 18 apps; turning a currently-working
	 * (if silently wrong) request into a 409 for every existing consumer at once is
	 * disproportionate to fixing the lookup. The ambiguity that remains without the
	 * parameter is now LOGGED with its candidates instead of being invisible, so the
	 * next investigation starts from evidence.
	 *
	 * @param int|string $id The {id} route parameter — a numeric id, a uuid, or a slug.
	 *
	 * @return Schema The resolved schema.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When nothing matches.
	 * @throws RegisterNotFoundException When a named register does not resolve.
	 *
	 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
	 */
	private function resolveSchema(int|string $id): Schema {
		$registerParam = $this->request->getParam(key: 'register', default: null);

		if (is_scalar($registerParam) === true && (string)$registerParam !== '') {
			return $this->resolveSchemaInRegister(id: $id, registerParam: (string)$registerParam);
		}

		$schema = $this->schemaMapper->find(id: $id, _extend: [], _multitenancy: false);

		// Leave evidence when a slug was ambiguous and nobody said which register they
		// meant. Silence here is what made the `agent` collision cost a workaround
		// instead of a bug report.
		if ($this->isUnscopedSlug(id: $id, registerParam: $registerParam) === true) {
			$this->logAmbiguousSlug(slug: $id, resolved: $schema);
		}

		return $schema;
	}//end resolveSchema()

	/**
	 * Whether the {id} is a slug and the caller named no register at all.
	 *
	 * @param int|string $id            The {id} route parameter.
	 * @param mixed      $registerParam The raw `?register=` parameter.
	 *
	 * @return bool True when the global slug lookup may have been ambiguous.
	 *
	 * @phpstan-assert-if-true string $id
	 */
	private function isUnscopedSlug(int|string $id, mixed $registerParam): bool {
		return is_string($id) === true && is_numeric($id) === false && $registerParam === null;
	}//end isUnscopedSlug()


	/**
	 * Resolve the {id} route parameter inside the register the caller named.
	 *
	 * Naming a register makes it a BOUNDARY, and the boundary holds for every
	 * identifier form — numeric id, uuid, and slug alike. Earlier this scoping
	 * applied to slugs only and an unresolvable `?register=` fell back to global
	 * resolution "so the caller gets what they would have got without the
	 * parameter". Both softenings put the silent cross-app read back: measured on
	 * the shared dev instance 2026-08-21, three schemas carried the slug
	 * `timeEntry` and hrmq's form dialog was served ANOTHER app's schema (id 161
	 * instead of hrmq's 9466) — precisely the read a caller passes `?register=`
	 * to rule out. A mistyped register name that silently widens the scope back
	 * to the whole instance is indistinguishable, from the caller's side, from a
	 * correct scoped hit; refusing it loudly is the only observable behaviour.
	 *
	 * The register lookup runs with `_rbac: false, _multitenancy: false`,
	 * matching the schema metadata-read it scopes: this resolves WHICH schema is
	 * meant, it grants nothing — the read-visibility guard in {@see show()}
	 * still runs on the result.
	 *
	 * @param int|string $id            The {id} route parameter — a numeric id, a uuid, or a slug.
	 * @param string     $registerParam The `?register=` parameter — a register id, uuid, or slug.
	 *
	 * @return Schema The schema, resolved among the register's carried schemas only.
	 *
	 * @throws RegisterNotFoundException    When the named register does not resolve (→ 404).
	 * @throws SchemaNotInRegisterException When the register does not carry the identifier (→ 404).
	 *
	 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
	 */
	private function resolveSchemaInRegister(int|string $id, string $registerParam): Schema {
		// Delegated to the shared resolver rather than kept inline. This method was
		// the FIRST implementation of the boundary and, being private, could not be
		// reused — so the aggregation endpoints, which carry a `{register}` path
		// segment, went on resolving globally and served another app's rows
		// (measured 2026-08-21: `TimeEntry` → planix schema 161 instead of hrmq's
		// 9466). One implementation is the only way the wording, the identifier
		// forms and the refusal stay identical across every surface.
		return $this->scopedSchemaResolver->resolvePair(
			registerRef: $registerParam,
			schemaRef: $id
		)['schema'];
	}//end resolveSchemaInRegister()




	/**
	 * Log a debug line naming every schema a slug could have resolved to.
	 *
	 * Only emits when the slug genuinely matches more than one schema, so a normal
	 * unambiguous read stays silent.
	 *
	 * @param string $slug     The slug that was resolved.
	 * @param Schema $resolved The schema the global lookup returned.
	 *
	 * @return void
	 */
	private function logAmbiguousSlug(string $slug, Schema $resolved): void {
		try {
			$candidates = $this->schemaMapper->findAll(
				filters: ['slug' => $slug],
				_rbac: false,
				_multitenancy: false
			);

			if (count($candidates) < 2) {
				return;
			}

			$this->logger->debug(
				sprintf(
					'[SchemasController] slug "%s" is ambiguous across %d schemas and no ?register= was supplied; '
					. 'returned id %s. Pass ?register=<slug> to resolve deterministically.',
					$slug,
					count($candidates),
					(string)$resolved->getId()
				),
				['candidateIds' => array_map(static fn (Schema $s): int => (int)$s->getId(), $candidates)]
			);
		} catch (Exception $e) {
			// Diagnostics must never break the read they are diagnosing.
			$this->logger->debug('[SchemasController] ambiguity check failed: ' . $e->getMessage());
		}
	}//end logAmbiguousSlug()

	/**
	 * Retrieves a single schema by ID
	 *
	 * @param int|string $id The ID of the schema
	 *
	 * @return JSONResponse JSON response with schema data
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @PublicPage
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable)        $id matches the {id} URL route parameter; renaming breaks route binding.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function show($id): JSONResponse {
		try {
			return $this->showSchema(id: $id, extend: $this->extendList(extend: $this->request->getParam(key: '_extend', default: [])));
		} catch (SchemaNotInRegisterException | RegisterNotFoundException $e) {
			// Register-scoped refusals carry a diagnosis — which register, how many
			// same-slug schemas exist elsewhere, the repair command. Flattening them
			// to a bare "Schema not found" reads as "your slug is wrong", which is
			// the one conclusion that is certainly false when duplicates exist.
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 404);
		} catch (DoesNotExistException | \OCA\OpenRegister\Exception\ValidationException $e) {
			// ValidationException is thrown when schema is not found (includes debugging info).
			return new JSONResponse(data: ['error' => 'Schema not found'], statusCode: 404);
		} catch (Exception $e) {
			return $this->showFailure(e: $e, id: $id);
		}//end try
	}//end show()

	/**
	 * Log a failed schema read and answer with the generic error response.
	 *
	 * @param Exception  $e  The failure.
	 * @param int|string $id The {id} route parameter.
	 *
	 * @return JSONResponse The error response.
	 */
	private function showFailure(Exception $e, mixed $id): JSONResponse {
		$this->logger->error(
			message: '[SchemasController] Failed to retrieve schema',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'schema_id' => $id,
				'error_message' => $e->getMessage(),
			]
		);
		return $this->errorResponse(e: $e);
	}//end showFailure()

	/**
	 * The schema read of {@see show()}: resolve, guard anonymous reads, and shape the response.
	 *
	 * @param int|string $id     The {id} route parameter — a numeric id, a uuid, or a slug.
	 * @param mixed      $extend The requested `_extend` entries.
	 *
	 * @return JSONResponse The serialized schema, or a 401 for an anonymous caller who may not read it.
	 */
	private function showSchema(mixed $id, mixed $extend): JSONResponse {
		$schema = $this->resolveSchema(id: $id);

		// Read-visibility guard (@PublicPage): an anonymous caller may only
		// view a schema whose RBAC authorization grants read access to the
		// `public` group. Derived from server-side authorization rules,
		// never from client-supplied parameters.
		if ($this->deniesAnonymousRead(authorization: $schema->getAuthorization()) === true) {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
		}

		$schemaArr = $this->forCaller(schema: $schema->jsonSerialize());

		// Add extendedBy property showing UUIDs of schemas that extend this schema.
		$schemaArr['@self'] = $schemaArr['@self'] ?? [];
		$schemaArr['@self']['extendedBy'] = $this->schemaMapper->findExtendedBy($id);
		$schemaArr['@self'] = $this->withPropertyMetadata(annotations: $schemaArr['@self'], schema: $schema);
		$schemaArr['@self'] = $this->withSemanticRoles(annotations: $schemaArr['@self'], schema: $schema);

		// If '@self.stats' is requested, attach statistics to the schema.
		if (in_array('@self.stats', $extend, true) === true) {
			$schemaArr['stats'] = $this->singleSchemaStats(schemaArr: $schemaArr);
		}

		return new JSONResponse(data: $schemaArr);
	}//end showSchema()

	/**
	 * Whether the caller is anonymous and the authorization grants the `public` group no read.
	 *
	 * @param array|null $authorization The entity's authorization block, or null.
	 *
	 * @return bool True when the read must be refused.
	 */
	private function deniesAnonymousRead(?array $authorization): bool {
		return $this->isAnonymousRequest() === true && $this->isPublicReadable(authorization: $authorization) === false;
	}//end deniesAnonymousRead()

	/**
	 * Add property source metadata to distinguish native vs inherited properties.
	 *
	 * This is especially useful for schemas using allOf composition.
	 *
	 * @param array<string, mixed> $annotations The `@self` block.
	 * @param Schema               $schema      The schema.
	 *
	 * @return array<string, mixed> The block, with `propertyMetadata` when the schema composes others.
	 */
	private function withPropertyMetadata(array $annotations, Schema $schema): array {
		if (($schema->getAllOf() ?? null) !== null && count($schema->getAllOf()) > 0) {
			$annotations['propertyMetadata'] = $this->schemaMapper->getPropertySourceMetadata($schema);
		}

		return $annotations;
	}//end withPropertyMetadata()

	/**
	 * Add the semantic roles and the administered help text, resolved in the caller's language.
	 *
	 * A list component reads the roles and can then render a schema it has
	 * never seen (REQ-CLH-003).
	 *
	 * @param array<string, mixed> $annotations The `@self` block.
	 * @param Schema               $schema      The schema.
	 *
	 * @return array<string, mixed> The block, with `roles`, `help` and `language` when roles are wired.
	 */
	private function withSemanticRoles(array $annotations, Schema $schema): array {
		if ($this->semanticRoles === null) {
			return $annotations;
		}

		$properties = ($schema->getProperties() ?? []);
		$language   = $this->negotiatedLanguage();
		$annotations['roles'] = $this->semanticRoles->roles(properties: $properties);
		$annotations['help']  = $this->semanticRoles->helpTexts(properties: $properties, language: $language);
		$annotations['language'] = $language;
		return $annotations;
	}//end withSemanticRoles()

	/**
	 * The `stats` block of one serialized schema.
	 *
	 * @param array<string, mixed> $schemaArr The serialized schema.
	 *
	 * @return array<string, mixed> The object, log, file and register statistics.
	 */
	private function singleSchemaStats(array $schemaArr): array {
		// Get register counts for all schemas in one call.
		$registerCounts = $this->schemaMapper->getRegisterCountPerSchema();
		return [
			'objects' => $this->objectEntityMapper->getStatistics(scope: new StatisticsScope(schemaId: $schemaArr['id'])),
			'logs' => $this->auditLogStats(schemaId: (int)$schemaArr['id']),
			'files' => [ 'total' => 0, 'size' => 0 ],
			// Add the number of registers referencing this schema.
			'registers' => $registerCounts[$schemaArr['id']] ?? 0,
		];
	}//end singleSchemaStats()

	/**
	 * Validate the optional `configuration.jsonld` vocabulary-mapping block.
	 *
	 * Term values must be absolute IRIs or compact terms resolvable against a
	 * declared `@vocab`. Returns a 400 JSONResponse describing the problem when
	 * the block is invalid, or null when valid / absent (json-ld-output).
	 *
	 * @param array $data The incoming schema request data.
	 *
	 * @return JSONResponse|null A 400 response when invalid, else null.
	 *
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	/**
	 * Refuse a schema whose semantic roles or help text cannot be honoured.
	 *
	 * Two properties claiming one role is the refusal the spec names, and the
	 * message names both: a list component that asks "which property is the
	 * title" cannot be answered with two, and answering with the first would
	 * make the choice depend on property order.
	 *
	 * @param array $data The incoming schema request data.
	 *
	 * @return JSONResponse|null A 422 response when invalid, else null.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	private function validateSemanticRoles(array $data): ?JSONResponse {
		if ($this->semanticRoles === null) {
			return null;
		}

		$properties = ($data['properties'] ?? null);
		if (is_array($properties) === false) {
			return null;
		}

		$errors = $this->semanticRoles->violations(properties: $properties);
		if ($errors === []) {
			return null;
		}

		return new JSONResponse(
			data: [
				'error' => 'Invalid semantic role declaration in schema properties',
				'errors' => $errors,
			],
			statusCode: 422
		);
	}//end validateSemanticRoles()

	/**
	 * Refuse a reference filter that reads a property nobody declares.
	 *
	 * 🔴 THIS IS THE CALL SITE `assertOperandsExist()` SHIPPED WITHOUT. The
	 * comment beside `PropertyValidatorHandler::validateProperty()` said the
	 * operands were checked "in SchemasController", and this class did not
	 * mention the declaration at all, so the check was dead code with a full
	 * set of messages nobody could ever read. `validateProperty()` sees one
	 * property and cannot answer the question: it needs both schemas.
	 *
	 * The refusal is a 422 in the same family as the semantic-role and
	 * generated-identifier refusals, and it names the property and which of
	 * the two schemas is missing the operand.
	 *
	 * @param array<string, mixed> $data The incoming schema payload.
	 *
	 * @return JSONResponse|null A 422 naming the operand, or null when there is nothing to refuse.
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md#requirement-a-reference-property-may-narrow-its-choices-with-a-query-over-the-record-req-fuc-003
	 */
	private function validateReferenceFilterOperands(array $data): ?JSONResponse {
		$properties = ($data['properties'] ?? null);
		if (is_array($properties) === false) {
			return null;
		}

		try {
			$this->referenceFilterOperands->assertProperties(properties: $properties);
		} catch (ReferenceFilterException $e) {
			return new JSONResponse(
				data: [
					'error' => $e->getMessage(),
					'errors' => $e->getErrors(),
				],
				statusCode: 422
			);
		}

		return null;
	}//end validateReferenceFilterOperands()

	/**
	 * The language the caller asked for, defaulting to Dutch.
	 *
	 * @return string The BCP-47 tag.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	private function negotiatedLanguage(): string {
		$explicit = trim((string)$this->request->getParam('language', ''));
		if ($explicit !== '') {
			return $explicit;
		}

		$header = trim((string)$this->request->getHeader('Accept-Language'));
		if ($header === '') {
			return 'nl';
		}

		$first = trim((string)(explode(',', $header)[0] ?? ''));
		$first = trim((string)(explode(';', $first)[0] ?? ''));

		if ($first === '') {
			return 'nl';
		}

		return $first;
	}//end negotiatedLanguage()

	/**
	 * Check a schema payload's JSON-LD context mapping before it is saved.
	 *
	 * @param array<string,mixed> $data The incoming schema payload.
	 *
	 * @return JSONResponse|null A 400 naming the bad mapping, or null when there is nothing to refuse.
	 */
	private function validateJsonLdMapping(array $data): ?JSONResponse {
		$jsonld = $this->declaredJsonLdMapping(data: $data);
		if ($jsonld === null) {
			return null;
		}

		$errors = $this->services->semantics->jsonLdContextService->validateMapping(jsonld: $jsonld);
		if (empty($errors) === true) {
			return null;
		}

		return $this->invalidJsonLdMapping(errors: $errors);
	}//end validateJsonLdMapping()

	/**
	 * The `configuration.jsonld` block to check, or null when there is no checker or no block.
	 *
	 * @param array<string,mixed> $data The incoming schema payload.
	 *
	 * @return array<string,mixed>|null The mapping block.
	 */
	private function declaredJsonLdMapping(array $data): ?array {
		if ($this->services->semantics->jsonLdContextService === null) {
			return null;
		}

		$configuration = ($data['configuration'] ?? null);
		if (is_array($configuration) === false) {
			return null;
		}

		$jsonld = ($configuration['jsonld'] ?? null);
		if (is_array($jsonld) === false) {
			return null;
		}

		return $jsonld;
	}//end declaredJsonLdMapping()

	/**
	 * The 400 naming the problems of a JSON-LD mapping.
	 *
	 * @param array<mixed> $errors The mapping errors.
	 *
	 * @return JSONResponse
	 */
	private function invalidJsonLdMapping(array $errors): JSONResponse {
		return new JSONResponse(
			data: [
				'error' => 'Invalid jsonld mapping in schema configuration',
				'errors' => $errors,
			],
			statusCode: 400
		);
	}//end invalidJsonLdMapping()

	/**
	 * Creates a new schema
	 *
	 * This method creates a new schema based on POST data.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response with created schema or error
	 *
	 * @psalm-return JSONResponse<201, Schema,
	 *     array<never, never>>|JSONResponse<400|403|409|500, array{error: string},
	 *     array<never, never>>|JSONResponse<422, array{error: string,
	 *     errors: array<int, array{code: string, message: string}>}, array<never, never>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	public function create(): JSONResponse {
		// Creating a schema defines a new data model and is restricted to
		// administrators. Reading schema metadata stays open for frontends.
		if ($this->isCurrentUserAdmin() === false) {
			return new JSONResponse(
				data: ['error' => 'Only administrators may create schemas'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		$data = $this->request->getParams();

		// `?register=<id|uuid|slug>` names the register the new schema belongs to.
		// It is consumed here, never hydrated: the linkage lives in the register's
		// `schemas` list, not on the schema row.
		$registerParam = ($data['register'] ?? null);
		unset($data['register']);

		$this->logSchemaCreation(data: $data);

		return $this->createSchema(data: $this->newSchemaBody(data: $data), registerParam: $registerParam);
	}//end create()

	/**
	 * The body a new schema is created from: internal parameters and any client `id` removed.
	 *
	 * @param array<string, mixed> $data The request parameters, without `register`.
	 *
	 * @return array<string, mixed> The schema body.
	 */
	private function newSchemaBody(array $data): array {
		$data = $this->withoutInternalParams(data: $data);
		if (($data['id'] ?? null) !== null) {
			unset($data['id']);
		}

		return $data;
	}//end newSchemaBody()

	/**
	 * Validate a new schema body, resolve its register, and insert it.
	 *
	 * @param array<string, mixed> $data          The schema body.
	 * @param mixed                $registerParam The `?register=` parameter, or null.
	 *
	 * @return JSONResponse The created schema (201), or the refusal.
	 */
	private function createSchema(array $data, mixed $registerParam): JSONResponse {
		$invalid = $this->validateSchemaBody(data: $data);
		if ($invalid !== null) {
			return $invalid;
		}

		$register = $this->newSchemaRegister(registerParam: $registerParam);
		if ($register instanceof JSONResponse) {
			return $register;
		}

		return $this->insertSchema(data: $data, register: $register);
	}//end createSchema()

	/**
	 * The register a new schema is linked to: none without a `?register=`, else the one it names.
	 *
	 * @param mixed $registerParam The `?register=` parameter, or null.
	 *
	 * @return Register|JSONResponse|null The register, the refusal when it does not resolve, or null.
	 */
	private function newSchemaRegister(mixed $registerParam): Register|JSONResponse|null {
		if ($registerParam === null || $registerParam === '') {
			return null;
		}

		return $this->findRegisterForNewSchema(registerParam: $registerParam);
	}//end newSchemaRegister()

	/**
	 * Insert a validated new schema and link it to its register.
	 *
	 * @param array<string, mixed> $data     The schema body.
	 * @param Register|null        $register The register, or null for a register-less schema.
	 *
	 * @return JSONResponse The created schema (201), or the write error.
	 */
	private function insertSchema(array $data, ?Register $register): JSONResponse {
		try {
			$schema = $this->schemaMapper->createFromArray(object: $data);
			$this->linkNewSchemaToRegister(schema: $schema, register: $register);

			// Drop the cached copy so a follow-up read in this worker sees the new schema.
			$this->schemaCacheService->invalidate(schemaId: $schema->getId());

			return new JSONResponse(data: $schema, statusCode: 201);
		} catch (Exception $e) {
			return $this->schemaWriteErrorResponse(
				e: $e,
				failure: ['message' => '[SchemasController] Schema creation failed', 'context' => []]
			);
		}
	}//end insertSchema()

	/**
	 * Log the start of a schema creation with its title and organisation.
	 *
	 * @param array<string, mixed> $data The request parameters
	 *
	 * @return void
	 */
	private function logSchemaCreation(array $data): void {
		$this->logger->info(
			message: '[SchemasController::create] Starting schema creation',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'title' => $data['title'] ?? 'no title',
				'has_organisation' => isset($data['organisation']),
				'organisation' => $data['organisation'] ?? 'not set',
			]
		);
	}//end logSchemaCreation()

	/**
	 * Remove internal request parameters (those starting with `_`).
	 *
	 * @param array<string, mixed> $data The request parameters
	 *
	 * @return array<string, mixed> The parameters without internal ones
	 */
	private function withoutInternalParams(array $data): array {
		foreach (array_keys($data) as $key) {
			if (str_starts_with($key, '_') === true) {
				unset($data[$key]);
			}
		}

		return $data;
	}//end withoutInternalParams()

	/**
	 * Run the checks a schema body must pass before it is written.
	 *
	 * The JSON-LD vocabulary mapping, duplicated semantic roles (REQ-CLH-003) and
	 * reference filters reading a property neither schema declares are refused
	 * before the write, while the author is still waiting.
	 *
	 * @param array<string, mixed> $data The schema body
	 *
	 * @return JSONResponse|null The first refusal, or null when the body passes
	 */
	private function validateSchemaBody(array $data): ?JSONResponse {
		return $this->validateJsonLdMapping(data: $data)
			?? $this->validateSemanticRoles(data: $data)
			?? $this->validateReferenceFilterOperands(data: $data)
			?? self::validateCatalogue(data: $data);
	}//end validateSchemaBody()

	/**
	 * Refuse an unknown classification or update frequency, or a malformed contact e-mail, naming the field.
	 *
	 * @param array<string, mixed> $data The schema body.
	 *
	 * @return JSONResponse|null A 400 naming the field, or null when the body is fine.
	 *
	 * @spec openspec/changes/modelling-type-catalogue-metadata/specs/schema-catalogue-metadata/spec.md#requirement-a-schema-carries-a-data-classification
	 */
	private static function validateCatalogue(array $data): ?JSONResponse {
		$violation = SchemaCatalogue::violation(data: $data);
		if ($violation === null) {
			return null;
		}

		return new JSONResponse(
			data: ['error' => $violation['message'], 'field' => $violation['field']],
			statusCode: Http::STATUS_BAD_REQUEST
		);
	}//end validateCatalogue()

	/**
	 * Resolve the register a new schema is created in.
	 *
	 * An unknown register is refused before the schema is written: creating a
	 * free-floating schema and answering 201 would hide that it is in no register.
	 * The lookup skips multitenancy, as RegisterMapper::updateFromArray() does;
	 * the register update that links the schema carries the access check.
	 *
	 * @param mixed $registerParam The register id, uuid or slug
	 *
	 * @return Register|JSONResponse The register, or a 400 response
	 */
	private function findRegisterForNewSchema(mixed $registerParam): Register|JSONResponse {
		try {
			return $this->registerMapper->find(id: $registerParam, _multitenancy: false);
		} catch (Exception $e) {
			return new JSONResponse(
				data: [
					'error' => sprintf(
						'The register "%s" named by ?register= does not exist or is not accessible, '
						. 'so the schema was not created. Omit the parameter to create a '
						. 'register-less schema.',
						(string)$registerParam
					),
				],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}
	}//end findRegisterForNewSchema()

	/**
	 * Add a newly created schema to its register's `schemas` list.
	 *
	 * Without the link every register-scoped slug read of the schema is refused
	 * (SchemaNotInRegisterException). A refused link (cross-tenant register, RBAC)
	 * is logged, not fatal: the schema itself was created, and
	 * `occ openregister:registers:relink-schemas` repairs the link.
	 *
	 * @param Schema        $schema   The created schema
	 * @param Register|null $register The register, or null for a register-less schema
	 *
	 * @return void
	 */
	private function linkNewSchemaToRegister(Schema $schema, ?Register $register): void {
		if ($register === null || $register->addSchemaId(schemaId: $schema->getId()) !== true) {
			return;
		}

		try {
			$this->registerMapper->update(entity: $register);

			// Register lookups are cached per request; drop the pre-update copy.
			$this->registerMapper->clearFindCache(registerId: $register->getId());
		} catch (Exception $linkError) {
			$this->logger->error(
				message: '[SchemasController] Schema created but could not be linked to its register',
				context: [
					'file'          => __FILE__,
					'line'          => __LINE__,
					'schemaId'      => $schema->getId(),
					'registerId'    => $register->getId(),
					'error_message' => $linkError->getMessage(),
				]
			);
		}
	}//end linkNewSchemaToRegister()

	/**
	 * Map an exception from a schema write to its response.
	 *
	 * Declaration refusals name what refused (422, ADR-005), rule conflicts and
	 * constraint violations keep their own status, and anything else is logged
	 * and classified by its message.
	 *
	 * @param Exception                                               $e       The exception
	 * @param array{message: string, context: array<string, mixed>} $failure The log message and extra context
	 *
	 * @return JSONResponse The error response
	 */
	private function schemaWriteErrorResponse(Exception $e, array $failure): JSONResponse {
		$refusal = $this->declarationRefusal(e: $e);
		if ($refusal !== null) {
			return $refusal;
		}

		$ruleConflict = $this->ruleConflictResponse(e: $e);
		if ($ruleConflict !== null) {
			return $ruleConflict;
		}

		if ($e instanceof DBException) {
			return $this->databaseConstraintResponse(e: $e);
		}

		return $this->unexpectedSchemaWriteError(e: $e, failure: $failure);
	}//end schemaWriteErrorResponse()

	/**
	 * The response of a write error that carries its own status: an authorization
	 * block, a constraint violation or an invalid authorization rule.
	 *
	 * @param Exception $e The exception.
	 *
	 * @return JSONResponse|null The response, or null for any other exception.
	 */
	private function ruleConflictResponse(Exception $e): ?JSONResponse {
		if ($e instanceof AuthorizationBlockException || $e instanceof DatabaseConstraintException) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: $e->getHttpStatusCode());
		}

		if ($e instanceof InvalidAuthorizationRuleException) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: InvalidAuthorizationRuleException::HTTP_STATUS);
		}

		return null;
	}//end ruleConflictResponse()

	/**
	 * The response of a database error, classified as a schema constraint violation.
	 *
	 * @param DBException $e The database exception.
	 *
	 * @return JSONResponse The response with the constraint's status.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) DatabaseConstraintException::fromDatabaseException is a named constructor.
	 */
	private function databaseConstraintResponse(DBException $e): JSONResponse {
		$constraintException = DatabaseConstraintException::fromDatabaseException(dbException: $e, entityType: 'schema');
		return new JSONResponse(
			data: ['error' => $constraintException->getMessage()],
			statusCode: $constraintException->getHttpStatusCode()
		);
	}//end databaseConstraintResponse()

	/**
	 * The 422 refusal for a declaration the caller wrote and a person is waiting on.
	 *
	 * @param Exception $e The exception
	 *
	 * @return JSONResponse|null The refusal with its errors, or null for any other exception
	 */
	private function declarationRefusal(Exception $e): ?JSONResponse {
		foreach (self::DECLARATION_REFUSALS as $refusal) {
			if ($e instanceof $refusal) {
				return new JSONResponse(
					data: ['error' => $e->getMessage(), 'errors' => $e->getErrors()],
					statusCode: 422
				);
			}
		}

		return null;
	}//end declarationRefusal()

	/**
	 * Log an unexpected schema write failure and classify it by its message.
	 *
	 * Validation wording answers 400, constraint wording 409, anything else 500.
	 *
	 * @param Exception                                               $e       The exception
	 * @param array{message: string, context: array<string, mixed>} $failure The log message and extra context
	 *
	 * @return JSONResponse The error response
	 */
	private function unexpectedSchemaWriteError(Exception $e, array $failure): JSONResponse {
		$this->logger->error(
			message: $failure['message'],
			context: array_merge(
				['file' => __FILE__, 'line' => __LINE__],
				$failure['context'],
				[
					'error_message' => $e->getMessage(),
					'error_code' => $e->getCode(),
					'trace' => $e->getTraceAsString(),
				]
			)
		);

		if ($this->messageContainsAny(message: $e->getMessage(), markers: self::VALIDATION_ERROR_MARKERS) === true) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
		}

		if ($this->messageContainsAny(message: $e->getMessage(), markers: self::CONFLICT_ERROR_MARKERS) === true) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 409);
		}

		return $this->errorResponse(e: $e);
	}//end unexpectedSchemaWriteError()

	/**
	 * Whether a message contains any of the markers (case-sensitive).
	 *
	 * @param string             $message The message
	 * @param array<int, string> $markers The substrings to look for
	 *
	 * @return bool True when one marker occurs in the message
	 */
	private function messageContainsAny(string $message, array $markers): bool {
		foreach ($markers as $marker) {
			if (str_contains($message, $marker) === true) {
				return true;
			}
		}

		return false;
	}//end messageContainsAny()

	/**
	 * Updates an existing schema
	 *
	 * This method updates an existing schema based on its ID.
	 *
	 * @param int $id The ID of the schema to update
	 *
	 * @NoAdminRequired
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)          DatabaseConstraintException::fromDatabaseException is a named constructor — no DI alternative.
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Multiple message-substring checks for error classification; each adds one branch.
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple message-substring checks for error classification; each adds one branch.
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Error-classification block is repetitive but
	 * intentional; extracting it would not reduce cognitive load.
	 * @SuppressWarnings(PHPMD.ShortVariable)         $id matches the {id} URL route parameter; renaming breaks route binding.
	 *
	 * @return JSONResponse JSON response with updated schema or error
	 *
	 * @psalm-return JSONResponse<200, Schema,
	 *     array<never, never>>|JSONResponse<200, array<string, mixed>,
	 *     array<never, never>>|JSONResponse<400|403|404|409|500, array{error: string},
	 *     array<never, never>>|JSONResponse<422, array{error: string,
	 *     errors: array<int, array{code: string, message: string}>}, array<never, never>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 * @spec openspec/changes/modelling-schema-draft/specs/runtime-schema-api/spec.md#requirement-req-sdraft-001-a-schema-edit-can-be-held-as-a-draft-until-it-is-published
	 */
	public function update(int $id): JSONResponse {
		$data = $this->withoutInternalParams(data: $this->request->getParams());

		// `?draft=true` holds the edit beside the published definition instead
		// of applying it (modelling-schema-draft).
		$asDraft = $this->isTrue(value: ($data['draft'] ?? null));
		unset($data['draft'], $data['acknowledgeBreaking'], $data['renames']);
		if ($asDraft === true) {
			return $this->saveDraft(id: $id, data: $data);
		}

		return $this->applyUpdate(id: $id, data: $data);
	}//end update()

	/**
	 * The dependent-form assessment of an update that changes properties or required.
	 *
	 * @param Schema               $existing The stored schema.
	 * @param array<string, mixed> $data     The update body.
	 *
	 * @return array<string, mixed>|null The assessment (refused, schema, affected), or null when the definition is unchanged.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-saving-a-schema-must-re-check-the-forms-that-submit-into-it
	 */
	private function assessDependentForms(Schema $existing, array $data): ?array {
		if (isset($data['properties']) === false && isset($data['required']) === false) {
			return null;
		}

		$check = $this->formDependentsCheck();
		if ($check === null) {
			return null;
		}

		return $check->assess(schema: $this->proposedSchema(existing: $existing, data: $data));
	}//end assessDependentForms()

	/**
	 * A copy of the stored schema carrying the update's properties and required list.
	 *
	 * @param Schema               $existing The stored schema.
	 * @param array<string, mixed> $data     The update body.
	 *
	 * @return Schema The proposed schema; the stored one is not changed.
	 */
	private function proposedSchema(Schema $existing, array $data): Schema {
		$proposed = clone $existing;
		if (is_array($data['properties'] ?? null) === true) {
			$proposed->setProperties($data['properties']);
		}

		if (is_array($data['required'] ?? null) === true) {
			$proposed->setRequired($data['required']);
		}

		return $proposed;
	}//end proposedSchema()

	/**
	 * The dependent-form check, resolved lazily like the audit service.
	 *
	 * A check that cannot be built is logged at warning level: the save then
	 * goes ahead without judging the published forms, and that must leave a trace
	 * (an owning app's `refuse` setting is skipped with it).
	 *
	 * @return FormDependentsCheckInterface|null The check, or null when it cannot be built.
	 */
	private function formDependentsCheck(): ?FormDependentsCheckInterface {
		try {
			$check = $this->container->get(FormDependentsCheckInterface::class);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[SchemasController] The dependent-form check could not be built; the schema save did not re-check the published forms',
				context: ['error' => $e->getMessage()]
			);

			return null;
		}

		if ($check instanceof FormDependentsCheckInterface) {
			return $check;
		}

		$this->logger->warning(
			message: '[SchemasController] The dependent-form check could not be built; the schema save did not re-check the published forms',
			context: ['error' => 'the container did not return a FormDependentsCheck']
		);

		return null;
	}//end formDependentsCheck()

	/**
	 * Apply an update body to a schema: permission, validation, versioning gate, write, changelog.
	 *
	 * Shared by a direct update and by publishing a draft, so a published draft
	 * gets the same gate, the same version bump and one changelog entry.
	 *
	 * @param int                  $id   The ID of the schema to update
	 * @param array<string, mixed> $data The update body, internal parameters removed
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable)         $id matches the {id} URL route parameter.
	 *
	 * @return JSONResponse JSON response with updated schema or error
	 *
	 * @psalm-return JSONResponse<200, Schema,
	 *     array<never, never>>|JSONResponse<200, array<string, mixed>,
	 *     array<never, never>>|JSONResponse<400|403|404|409|500, array{error: string},
	 *     array<never, never>>|JSONResponse<422, array{error: string,
	 *     errors: array<int, array{code: string, message: string}>}, array<never, never>>
	 *
	 * @spec openspec/changes/modelling-schema-draft/specs/runtime-schema-api/spec.md#requirement-req-sdraft-001-a-schema-edit-can-be-held-as-a-draft-until-it-is-published
	 */
	private function applyUpdate(int $id, array $data): JSONResponse {
		// Immutable fields are dropped to prevent tampering.
		unset($data['id'], $data['organisation'], $data['owner'], $data['created']);

		// Every update needs manage permission, not just authorization changes;
		// reading schema metadata stays open for frontends.
		$existingSchema = $this->manageableSchema(id: $id);
		if ($existingSchema instanceof JSONResponse) {
			return $existingSchema;
		}

		$data = $this->withAuthorizationAsStored(document: $data);

		// Validated after the permission check, so an unauthorized caller cannot probe it.
		$invalid = $this->updateBodyRefusal(existing: $existingSchema, data: $data);
		if ($invalid !== null) {
			return $invalid;
		}

		$update = ['id' => $id, 'data' => $data, 'oldAuthorization' => $existingSchema->getAuthorization()];
		return $this->classifyAndWriteUpdate(existing: $existingSchema, update: $update);
	}//end applyUpdate()

	/**
	 * The stored schema an update or a draft publish writes, when the caller may manage it.
	 *
	 * @param int $id The ID of the schema.
	 *
	 * @return Schema|JSONResponse The schema, a 404 when it does not exist, or a 403 without manage permission.
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter.
	 */
	private function manageableSchema(int $id): Schema|JSONResponse {
		try {
			$schema = $this->schemaMapper->find($id);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Schema not found'], statusCode: 404);
		}

		if ($this->checkSchemaManagePermission(schema: $schema) === false) {
			return new JSONResponse(
				data: ['error' => 'User does not have permission to manage this schema'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		return $schema;
	}//end manageableSchema()

	/**
	 * Why an update body is refused: orphan cleanup, the body itself, or a cross-organisation grant, in that order.
	 *
	 * @param Schema               $existing The stored schema.
	 * @param array<string, mixed> $data     The update body, authorization as stored.
	 *
	 * @return JSONResponse|null The first refusal, or null when the body may be classified.
	 */
	private function updateBodyRefusal(Schema $existing, array $data): ?JSONResponse {
		return ($this->orphanCleanupRefusal(schema: $existing, document: $data)
			?? $this->validateSchemaBody(data: $data)
			?? $this->crossOrganisationRefusal(old: $existing->getAuthorization(), document: $data));
	}//end updateBodyRefusal()

	/**
	 * Classify the definition change, refuse an unbounded external grant, and write the update.
	 *
	 * @param Schema               $existing The stored schema.
	 * @param array<string, mixed> $update   The update: id, data and the old authorization.
	 *
	 * @return JSONResponse The updated schema, or the refusal.
	 */
	private function classifyAndWriteUpdate(Schema $existing, array $update): JSONResponse {
		$update = $this->classifySchemaUpdate(existing: $existing, update: $update);
		if ($update instanceof JSONResponse) {
			return $update;
		}

		$refusal = $this->externalGrantRefusal(data: $update['data']);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->writeUpdateWithForms(existing: $existing, update: $update);
	}//end classifyAndWriteUpdate()

	/**
	 * Re-check the published forms that submit into the schema, then write the update.
	 *
	 * @param Schema               $existing The stored schema.
	 * @param array<string, mixed> $update   The classified update.
	 *
	 * @return JSONResponse The updated schema, the forms refusal, or the write error.
	 */
	private function writeUpdateWithForms(Schema $existing, array $update): JSONResponse {
		$update['forms'] = $this->assessAndClaimDependentForms(existing: $existing, update: $update);
		$formsRefusal = $this->dependentFormsRefusal(assessment: $update['forms']);
		if ($formsRefusal !== null) {
			return $formsRefusal;
		}

		try {
			return $this->writeSchemaUpdate(update: $update);
		} catch (Exception $e) {
			return $this->schemaWriteErrorResponse(
				e: $e,
				failure: ['message' => '[SchemasController] Schema update failed', 'context' => ['schema_id' => $update['id']]]
			);
		}
	}//end writeUpdateWithForms()

	/**
	 * Classify a definition change and apply the schema versioning gate.
	 *
	 * Only runs when the update carries properties or required. An unacknowledged
	 * breaking change is refused with the structured 409; otherwise the version is
	 * bumped from the classification unless the caller pinned one.
	 *
	 * @param Schema               $existing The stored schema
	 * @param array<string, mixed> $update   The update: id, data and oldAuthorization
	 *
	 * @return array<string, mixed>|JSONResponse The update with changeSet and acknowledged, or a 409
	 */
	private function classifySchemaUpdate(Schema $existing, array $update): array|JSONResponse {
		$update['changeSet'] = null;
		$update['acknowledged'] = ($this->request->getParam('acknowledgeBreaking') === true
			|| $this->request->getParam('acknowledgeBreaking') === 'true');
		$data = $update['data'];
		if (isset($data['properties']) === false && isset($data['required']) === false) {
			return $update;
		}

		$renames = [];
		if (is_array($this->request->getParam('renames')) === true) {
			$renames = $this->request->getParam('renames');
		}

		$changeSet = $this->schemaVersioningService->classify(
			existing: $existing,
			incoming: new IncomingDefinition(
				definition: [
					'properties' => ($data['properties'] ?? $existing->getProperties() ?? []),
					'required' => ($data['required'] ?? $existing->getRequired() ?? []),
				],
				renames: $renames
			)
		);

		try {
			$this->schemaVersioningService->gateChange(
				schemaId: $update['id'],
				record: new SchemaChangeRecord(changeSet: $changeSet, acknowledged: $update['acknowledged'])
			);
		} catch (BreakingSchemaChangeException $e) {
			return new JSONResponse(data: $e->toResponse(), statusCode: 409);
		}

		if (isset($data['version']) === false && $changeSet->hasChanges() === true) {
			$update['data']['version'] = $this->schemaVersioningService->nextVersion(existing: $existing, changeSet: $changeSet);
		}

		$update['changeSet'] = $changeSet;
		return $update;
	}//end classifySchemaUpdate()

	/**
	 * Refuse an authorization block granting an external principal without an end date (D-6).
	 *
	 * Asked before the write: a refusal after it would refuse something already saved.
	 *
	 * @param array<string, mixed> $data The update body
	 *
	 * @return JSONResponse|null A 400 naming the grants, or null when there is nothing to refuse
	 */
	private function externalGrantRefusal(array $data): ?JSONResponse {
		if (isset($data['authorization']) === false || is_array($data['authorization']) === false) {
			return null;
		}

		$externalRefusal = RbacChecks::externalGrants()->refusalFor($data['authorization']);
		if ($externalRefusal === null) {
			return null;
		}

		return new JSONResponse(data: $externalRefusal, statusCode: 400);
	}//end externalGrantRefusal()

	/**
	 * Judge the published forms that submit into this schema (decision 179, ADR-117 D2).
	 *
	 * When this request judges the forms itself it claims the schema, so the
	 * listener that covers every other schema writer does not judge them again.
	 *
	 * @param Schema               $existing The stored schema
	 * @param array<string, mixed> $update   The update: id and data
	 *
	 * @return array<string, mixed>|null The assessment (refused, affected), or null when not judged
	 */
	private function assessAndClaimDependentForms(Schema $existing, array $update): ?array {
		$assessment = $this->assessDependentForms(existing: $existing, data: $update['data']);
		if ($assessment !== null) {
			$this->formDependentsCheck()?->claim(schemaId: $update['id']);
		}

		return $assessment;
	}//end assessAndClaimDependentForms()

	/**
	 * The 409 for a change whose dependent forms' owning app refuses it.
	 *
	 * @param array<string, mixed>|null $assessment The dependent-form assessment
	 *
	 * @return JSONResponse|null The refusal listing the affected forms, or null when the change may go ahead
	 */
	private function dependentFormsRefusal(?array $assessment): ?JSONResponse {
		if ($assessment === null || $assessment['refused'] !== true) {
			return null;
		}

		return new JSONResponse(
			data: [
				'error' => 'This change would break published forms that submit into this schema. Their owning app refuses the change.',
				'affectedForms' => $this->formDependentsCheck()?->apply(assessment: $assessment) ?? [],
			],
			statusCode: 409
		);
	}//end dependentFormsRefusal()

	/**
	 * Write a classified schema update, record its changelog and audit, and clear caches.
	 *
	 * Forms broken by the change are unpublished after the write and listed in the response.
	 *
	 * @param array<string, mixed> $update The update: id, data, changeSet, acknowledged, oldAuthorization, forms
	 *
	 * @return JSONResponse The updated schema
	 *
	 * @throws Exception When the write fails
	 */
	private function writeSchemaUpdate(array $update): JSONResponse {
		$updatedSchema = $this->schemaMapper->updateFromArray(id: $update['id'], object: $update['data']);

		if ($update['changeSet'] !== null) {
			$this->schemaVersioningService->recordChange(
				schemaId: $updatedSchema->getId(),
				record: new SchemaChangeRecord(changeSet: $update['changeSet'], acknowledged: $update['acknowledged'], version: $updatedSchema->getVersion())
			);
		}

		if (isset($update['data']['authorization']) === true) {
			$this->auditSchemaAuthorizationChange(schema: $updatedSchema, oldAuthorization: $update['oldAuthorization']);
		}

		// `invalidate()` also drops the mapper's request-scoped find cache, so reads
		// in this worker observe the new state.
		$this->schemaCacheService->invalidate(schemaId: $updatedSchema->getId());
		$this->facetResults->clearAll();

		if ($update['forms'] !== null && $update['forms']['affected'] !== []) {
			return new JSONResponse(
				data: array_merge(
					$updatedSchema->jsonSerialize(),
					['affectedForms' => $this->formDependentsCheck()?->apply(assessment: $update['forms']) ?? []]
				)
			);
		}

		return new JSONResponse(data: $updatedSchema);
	}//end writeSchemaUpdate()

	/**
	 * Audit-log a change to a schema's authorization block; a failing audit never breaks the update.
	 *
	 * @param Schema $schema           The updated schema
	 * @param mixed  $oldAuthorization The authorization before the update
	 *
	 * @return void
	 */
	private function auditSchemaAuthorizationChange(Schema $schema, mixed $oldAuthorization): void {
		try {
			$auditService = $this->container->get(AuthorizationAuditService::class);
			$auditService->recordSchemaChange(
				change: new AuthorizationChange(
					entityId: $schema->getId(),
					entityTitle: $schema->getTitle() ?? '',
					before: $oldAuthorization,
					after: $schema->getAuthorization()
				)
			);
		} catch (\Throwable $e) {
			// Audit logging should not break the update operation.
			$this->logger->warning(
				message: '[SchemasController] Schema authorization change not written to the audit trail',
				context: [
					'schemaId' => $schema->getId(),
					'exception' => $e->getMessage(),
				]
			);
		}
	}//end auditSchemaAuthorizationChange()

	/**
	 * Whether a request value means true (`true` or the string `'true'`).
	 *
	 * @param mixed $value The request value
	 *
	 * @return bool
	 */
	private function isTrue(mixed $value): bool {
		return ($value === true || $value === 'true' || $value === '1' || $value === 1);
	}//end isTrue()

	/**
	 * Hold an edit as the schema's draft, leaving the published definition untouched.
	 *
	 * @param int                  $id   The ID of the schema
	 * @param array<string, mixed> $data The edit body
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter.
	 *
	 * @return JSONResponse The schema with its draft, or an error
	 *
	 * @spec openspec/changes/modelling-schema-draft/specs/runtime-schema-api/spec.md#requirement-req-sdraft-001-a-schema-edit-can-be-held-as-a-draft-until-it-is-published
	 */
	private function saveDraft(int $id, array $data): JSONResponse {
		$schema = $this->manageableSchema(id: $id);
		if ($schema instanceof JSONResponse) {
			return $schema;
		}

		unset($data['id'], $data['organisation'], $data['owner'], $data['created']);
		$schema->setDraft($data);

		return new JSONResponse(data: $this->schemaMapper->update(entity: $schema));
	}//end saveDraft()

	/**
	 * Publish a schema's draft through the normal update, then remove it
	 *
	 * The draft goes through the same permission check, versioning gate
	 * (`acknowledgeBreaking`, `renames`), version bump and changelog as a direct
	 * update. A refused publish keeps the draft.
	 *
	 * @param int $id The ID of the schema
	 *
	 * @NoAdminRequired
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter.
	 *
	 * @return JSONResponse The updated schema, 409 when there is no draft, or the update's error
	 *
	 * @spec openspec/changes/modelling-schema-draft/specs/runtime-schema-api/spec.md#requirement-req-sdraft-001-a-schema-edit-can-be-held-as-a-draft-until-it-is-published
	 */
	public function publishDraft(int $id): JSONResponse {
		$schema = $this->manageableSchema(id: $id);
		if ($schema instanceof JSONResponse) {
			return $schema;
		}

		$draft = $schema->getDraft();
		if ($draft === null) {
			return new JSONResponse(data: ['error' => 'This schema has no draft to publish'], statusCode: 409);
		}

		return $this->withDraftCleared(response: $this->applyUpdate(id: $id, data: $draft));
	}//end publishDraft()

	/**
	 * Clear the draft of a schema the publish wrote; any other response passes through.
	 *
	 * @param JSONResponse $response The response of applying the draft.
	 *
	 * @return JSONResponse The stored schema without its draft, or the response as given.
	 */
	private function withDraftCleared(JSONResponse $response): JSONResponse {
		$published = $response->getData();
		if ($response->getStatus() !== Http::STATUS_OK || $published instanceof Schema === false) {
			return $response;
		}

		$published->setDraft(null);

		return new JSONResponse(data: $this->schemaMapper->update(entity: $published));
	}//end withDraftCleared()

	/**
	 * Discard a schema's draft, leaving the published definition as it is
	 *
	 * @param int $id The ID of the schema
	 *
	 * @NoAdminRequired
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter.
	 *
	 * @return JSONResponse The schema without a draft, or an error
	 *
	 * @spec openspec/changes/modelling-schema-draft/specs/runtime-schema-api/spec.md#requirement-req-sdraft-001-a-schema-edit-can-be-held-as-a-draft-until-it-is-published
	 */
	public function discardDraft(int $id): JSONResponse {
		$schema = $this->manageableSchema(id: $id);
		if ($schema instanceof JSONResponse) {
			return $schema;
		}

		$schema->setDraft(null);

		return new JSONResponse(data: $this->schemaMapper->update(entity: $schema));
	}//end discardDraft()

	/**
	 * Patch (partially update) a schema
	 *
	 * @param int $id The ID of the schema to patch
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response with patched schema or error
	 *
	 * @no-admin-idor-exempt Pure delegation to update(), which performs the checkSchemaManagePermission() guard; this method has no body of its own.
	 *
	 * @psalm-return JSONResponse<200, Schema,
	 *     array<never, never>>|JSONResponse<200, array<string, mixed>,
	 *     array<never, never>>|JSONResponse<400|403|404|409|500, array{error: string},
	 *     array<never, never>>|JSONResponse<422, array{error: string,
	 *     errors: array<int, array{code: string, message: string}>}, array<never, never>>
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter; renaming breaks route binding.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	public function patch(int $id): JSONResponse {
		return $this->update(id: $id);
	}//end patch()

	/**
	 * Deletes a schema
	 *
	 * This method deletes a schema based on its ID. Three dispositions exist for a
	 * schema that still holds objects, and they are mutually exclusive:
	 *
	 * - no flag              → HTTP 409 `{error: schema-has-objects, objectCount: N}`
	 * - `?deleteObjects=true` → CASCADE: audit + hard-delete every object, drop the
	 *                          magic table, then delete the schema. No orphans.
	 * - `?force=true`         → legacy: delete the schema and ORPHAN its objects.
	 *                          Retained for API back-compat; never exposed in the UI.
	 *
	 * Passing both flags is HTTP 400 — an ambiguous destructive intent is refused
	 * rather than silently resolved.
	 *
	 * @param int $id The ID of the schema to delete
	 *
	 * @throws Exception If there is an error deleting the schema
	 *
	 * @return JSONResponse An empty JSON response, or the cascade result
	 *
	 * @NoAdminRequired
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable)        $id matches the {id} URL route parameter; renaming breaks route binding.
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Force/cascade dispositions and the orphan-count branch are inherent to a safe delete endpoint.
	 *
	 * @spec openspec/changes/schema-delete-cascade/specs/runtime-schema-api/spec.md
	 */
	public function destroy(int $id): JSONResponse {
		// **DELETE SAFETY (runtime-schema-api)**: Count attached objects FIRST.
		// If N > 0 and no disposition flag is set, refuse with HTTP 409 so the caller
		// gets a structured error containing the orphan count and can decide
		// whether to escalate. A bare DELETE on a schema with objects is the
		// canonical foot-gun that this guard closes.
		$force = $this->isFlagEnabled(key: 'force');
		$deleteObjects = $this->isFlagEnabled(key: 'deleteObjects');

		if ($force === true && $deleteObjects === true) {
			return new JSONResponse(
				data: [
					'error' => 'conflicting-delete-dispositions',
					'message' => 'force and deleteObjects are mutually exclusive: force orphans the objects, '
						. 'deleteObjects removes them. Pick one.',
				],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		// The cascade removes the rows of every organisation in the table, which a `manage`
		// rule does not answer for.
		if ($deleteObjects === true && $this->isCurrentUserAdmin() === false) {
			return new JSONResponse(
				data: ['error' => 'Deleting a schema with its objects is for administrators.'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		try {
			// Find the schema first (also validates existence and access).
			$schemaToDelete = $this->schemaMapper->find(id: $id);

			// Authorization: deleting a schema requires manage permission.
			// Reading schema metadata stays open for frontends.
			if ($this->checkSchemaManagePermission(schema: $schemaToDelete) === false) {
				return new JSONResponse(
					data: ['error' => 'User does not have permission to manage this schema'],
					statusCode: Http::STATUS_FORBIDDEN
				);
			}

			// Count objects still referencing this schema across all registers.
			// Use getStatistics() (single-axis schemaId path) — countSearchObjects()
			// only returns a real count when BOTH register AND schema are present
			// in the @self filter, and silently returns 0 on single-axis queries,
			// which would let DELETE silently succeed on schemas with objects.
			$objectStats = $this->objectEntityMapper->getStatistics(scope: new StatisticsScope(schemaId: $schemaToDelete->getId()));
			$objectCount = (int)($objectStats['total'] ?? 0);

			if ($deleteObjects === true) {
				return $this->cascadeDeleteSchema(schema: $schemaToDelete);
			}

			return $this->deleteSchemaKeepingObjects(
				removal: new SchemaRemoval(schema: $schemaToDelete, objectCount: $objectCount, force: $force)
			);
		} catch (ArchivalImmutableException $e) {
			// The cascade refused: this schema holds legally retained records. An HTTP
			// caller has no way to override that — `occ openregister:schemas:prune-retired
			// --force-archival` is the sanctioned path, because shell access is an
			// authorization boundary a request cannot cross. Caught ahead of the generic
			// handler, which would have reported a deliberate refusal as a 500.
			return new JSONResponse(data: $e->toResponseBody(), statusCode: Http::STATUS_FORBIDDEN);
		} catch (\OCA\OpenRegister\Exception\ValidationException $e) {
			// Return 409 Conflict for cascade protection (objects still attached).
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 409);
		} catch (\Exception $e) {
			// Return 500 for other errors.
			return $this->errorResponse(e: $e);
		}//end try
	}//end destroy()

	/**
	 * Delete a schema WITHOUT deleting its objects — the no-flag and ?force=true paths.
	 *
	 * With objects attached and no force flag this refuses (HTTP 409). With ?force=true
	 * it deletes the schema and orphans the objects, which is unchanged legacy behaviour
	 * retained for API back-compat and never surfaced in the UI.
	 *
	 * @param SchemaRemoval $removal The schema to delete (already authorized), the number of live
	 *                               objects referencing it, and whether ?force=true was passed.
	 *
	 * @throws Exception If the delete fails.
	 *
	 * @return JSONResponse Empty body on success, or the structured 409 refusal.
	 *
	 * @spec openspec/changes/schema-delete-cascade/specs/runtime-schema-api/spec.md
	 */
	private function deleteSchemaKeepingObjects(SchemaRemoval $removal): JSONResponse {
		$schema      = $removal->schema;
		$objectCount = $removal->objectCount;
		if ($objectCount > 0 && $removal->force === false) {
			// Refuse: structured 409 with the orphan count for the caller.
			return new JSONResponse(
				data: [
					'error' => 'schema-has-objects',
					'objectCount' => $objectCount,
				],
				statusCode: 409
			);
		}

		// Orphaning the rows of every organisation in the table is not what a
		// `manage` rule answers for, so it is for administrators, like the cascade.
		if ($objectCount > 0 && $this->isCurrentUserAdmin() === false) {
			return new JSONResponse(
				data: ['error' => 'Deleting a schema and keeping its objects is for administrators.'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		if ($objectCount > 0) {
			// Force-delete with audit trail at WARNING level: a misused force flag
			// orphans every object referencing this schema, so log who did it.
			$this->logger->warning(
				message: '[SchemasController] Force-deleting schema with attached objects',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'schemaId' => $schema->getId(),
					'schemaSlug' => $schema->getSlug(),
					'objectCount' => $objectCount,
				]
			);
		}

		// Only a deliberate ?force=true may bypass the mapper-level guard and orphan
		// the objects. The magic table is deliberately LEFT IN PLACE on that path —
		// its rows are the orphans, and dropping it would destroy them unaudited.
		$this->schemaMapper->delete(entity: $schema, force: $removal->force);

		if ($objectCount === 0) {
			// Nothing referenced this schema, so reclaim its (empty) magic table
			// instead of leaving an orphan table behind forever. Best effort.
			$this->getSchemaDeletionService()->dropEmptyTablesForSchema(schema: $schema);
		}

		$this->invalidateSchemaCaches(schemaId: (int)$schema->getId());

		// Return an empty response.
		return new JSONResponse(data: []);
	}//end deleteSchemaKeepingObjects()

	/**
	 * Run the cascade disposition of DELETE /api/schemas/{id}.
	 *
	 * Phase 1 (transactional) audits and hard-deletes every object and then the schema
	 * itself; phase 2 drops the magic table post-commit. A phase-2 failure does NOT
	 * fail the request — the caller's intent is already satisfied — it is reported as
	 * `tableDropped: false`. See SchemaDeletionService for the full rationale.
	 *
	 * @param Schema $schema The schema to tear down (already authorized).
	 *
	 * @throws Exception If phase 1 fails; the caller maps it to HTTP 500 and nothing is deleted.
	 *
	 * @return JSONResponse The cascade result.
	 *
	 * @spec openspec/changes/schema-delete-cascade/specs/runtime-schema-api/spec.md
	 */
	private function cascadeDeleteSchema(Schema $schema): JSONResponse {
		$schemaId = (int)$schema->getId();

		try {
			$result = $this->getSchemaDeletionService()->cascadeDeleteSchema(schema: $schema);
		} catch (ObjectStateWriteException $e) {
			return new JSONResponse(data: $e->toResponseBody(), statusCode: ObjectStateWriteException::HTTP_STATUS);
		} catch (LockedException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: LockedException::HTTP_STATUS);
		}

		$this->invalidateSchemaCaches(schemaId: $schemaId);

		return new JSONResponse(
			data: [
				'success' => true,
				'schemaId' => $schemaId,
				'deletedCount' => $result['deletedCount'],
				'deletedUuids' => $result['deletedUuids'],
				'tableDropped' => $result['tableDropped'],
			]
		);
	}//end cascadeDeleteSchema()

	/**
	 * Invalidate every cache that holds a copy of a now-deleted schema.
	 *
	 * **CACHE INVALIDATION (runtime-schema-api)**: invalidate() is the canonical entry
	 * point — it covers the in-memory cache, the persistent cache table, AND the
	 * request-scoped find cache on the mapper. Runs on every disposition.
	 *
	 * @param int $schemaId The id of the deleted schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schema-delete-cascade/specs/runtime-schema-api/spec.md
	 */
	private function invalidateSchemaCaches(int $schemaId): void {
		$this->schemaCacheService->invalidate(schemaId: $schemaId);
		$this->facetResults->clearAll();
	}//end invalidateSchemaCaches()

	/**
	 * Read a boolean query flag from the request.
	 *
	 * Accepts `true`, `'true'` and `'1'` — the shapes a query string can deliver.
	 *
	 * @param string $key The query parameter name.
	 *
	 * @return bool True when the flag is explicitly enabled.
	 */
	private function isFlagEnabled(string $key): bool {
		$value = $this->request->getParam(key: $key, default: null);

		return ((string)$value === 'true' || $value === true || $value === '1');
	}//end isFlagEnabled()

	/**
	 * Resolve the schema deletion service.
	 *
	 * Lazily resolved from the container: it is only needed by the delete path, and
	 * SchemasController is constructed on every schema read.
	 *
	 * @throws \RuntimeException If the service cannot be resolved.
	 *
	 * @return SchemaTeardownInterface The service.
	 */
	private function getSchemaDeletionService(): SchemaTeardownInterface {
		$service = $this->container->get(SchemaTeardownInterface::class);

		if (($service instanceof SchemaTeardownInterface) === false) {
			throw new RuntimeException('SchemaDeletionService could not be resolved');
		}

		return $service;
	}//end getSchemaDeletionService()

	/**
	 * Updates an existing Schema object using a json text/string as input
	 *
	 * Uses 'file', 'url' or else 'json' from POST body.
	 *
	 * @param int|null $id The ID of the schema to update, or null for a new schema
	 *
	 * @throws Exception If there is a database error
	 *
	 * @throws GuzzleException If there is an HTTP request error
	 *
	 * @return JSONResponse The JSON response with the updated schema
	 *
	 * @NoAdminRequired
	 *
	 * @no-admin-idor-exempt Pure delegation to upload(), which performs the checkSchemaManagePermission()
	 * guard for update paths; this method has no body of its own.
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter; renaming breaks route binding.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md#requirement-schema-authoring-sub-resources-and-meta-entity-operational-endpoints
	 */
	public function uploadUpdate(?int $id = null): JSONResponse {
		return $this->upload(id: $id);
	}//end uploadUpdate()

	/**
	 * Creates a new Schema object or updates an existing one
	 *
	 * Uses 'file', 'url' or else 'json' from POST body.
	 *
	 * @param int|null $id The ID of the schema to update, or null for a new schema
	 *
	 * @throws Exception If there is a database error
	 *
	 * @throws GuzzleException If there is an HTTP request error
	 *
	 * @return JSONResponse The JSON response with the created or updated schema
	 *
	 * @NoAdminRequired
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter; renaming breaks route binding.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md#requirement-schema-authoring-sub-resources-and-meta-entity-operational-endpoints
	 */
	public function upload(?int $id = null): JSONResponse {
		$schema = $this->authorizedUploadTarget(id: $id);
		if ($schema instanceof JSONResponse) {
			return $schema;
		}

		$phpArray = $this->checkedUploadDocument(schema: $schema);
		if ($phpArray instanceof JSONResponse) {
			return $phpArray;
		}

		try {
			return new JSONResponse(data: $this->saveUploadedSchema(schema: $schema, document: $this->uploadDocument(document: $phpArray, id: $id)));
		} catch (Exception $e) {
			return $this->schemaWriteErrorResponse(e: $e, failure: $this->uploadFailure(id: $id));
		}
	}//end upload()

	/**
	 * The schema an upload writes, when the caller may make it.
	 *
	 * A new schema gets a fresh uuid; an update writes the stored schema.
	 *
	 * @param int|null $id The ID of the schema to update, or null for a new schema.
	 *
	 * @return Schema|JSONResponse The schema, or the 403 of {@see uploadPermissionError()}.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)  Uuid::v4 is a named constructor.
	 * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter.
	 */
	private function authorizedUploadTarget(?int $id): Schema|JSONResponse {
		$schema = new Schema();
		$schema->setUuid(Uuid::v4()->toRfc4122());
		if ($id !== null) {
			$schema = $this->schemaMapper->find($id);
		}

		$denied = $this->uploadPermissionError(schema: $schema, id: $id);
		if ($denied !== null) {
			return $denied;
		}

		return $schema;
	}//end authorizedUploadTarget()

	/**
	 * The uploaded document, mapped from its dialect and with authorization as stored, unless it is refused.
	 *
	 * @param Schema $schema The schema the upload writes.
	 *
	 * @return array<string, mixed>|JSONResponse The document, or the refusal.
	 */
	private function checkedUploadDocument(Schema $schema): array|JSONResponse {
		$phpArray = $this->uploadedDocument();
		if ($phpArray instanceof JSONResponse) {
			return $phpArray;
		}

		$refusal = $this->uploadRefusal(schema: $schema, document: $phpArray);
		if ($refusal !== null) {
			return $refusal;
		}

		return $phpArray;
	}//end checkedUploadDocument()

	/**
	 * The uploaded body as a schema document: decoded, mapped from its dialect, authorization as stored.
	 *
	 * @return array<string, mixed>|JSONResponse The document, or the error of reading or mapping it.
	 */
	private function uploadedDocument(): array|JSONResponse {
		$phpArray = $this->services->platform->uploadService->getUploadedJson($this->request->getParams());
		if ($phpArray instanceof JSONResponse) {
			return $phpArray;
		}

		// An explicit `dialect` parameter wins, otherwise the document is sniffed;
		// undetectable input fails with 422 instead of being mis-ingested.
		$dialectResult = $this->applyDialect(document: $phpArray);
		if ($dialectResult instanceof JSONResponse) {
			return $dialectResult;
		}

		return $this->withAuthorizationAsStored(document: $dialectResult);
	}//end uploadedDocument()

	/**
	 * Why an uploaded document is refused: a cross-organisation grant, an unbounded external grant, or orphan cleanup.
	 *
	 * @param Schema               $schema   The schema the upload writes.
	 * @param array<string, mixed> $document The uploaded document, authorization as stored.
	 *
	 * @return JSONResponse|null The first refusal, or null when the upload may be saved.
	 */
	private function uploadRefusal(Schema $schema, array $document): ?JSONResponse {
		$old = $schema->getAuthorization();
		return ($this->crossOrganisationRefusal(old: $old, document: $document)
			?? $this->externalGrantRefusal(data: $document)
			?? $this->orphanCleanupRefusal(schema: $schema, document: $document));
	}//end uploadRefusal()

	/**
	 * The log message and context of a failed upload.
	 *
	 * @param int|null $id The ID of the schema the upload updates, or null for a new schema.
	 *
	 * @return array{message: string, context: array<string, mixed>}
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter.
	 */
	private function uploadFailure(?int $id): array {
		return ['message' => '[SchemasController] Schema upload failed', 'context' => ['schema_id' => $id]];
	}//end uploadFailure()

	/**
	 * The uploaded body as it is saved: an update keeps the stored identity and
	 * ownership, and a body without a title gets a default one.
	 *
	 * @param array<string, mixed> $document The uploaded body.
	 * @param int|null             $id       The schema being updated, or null for a new one.
	 *
	 * @return array<string, mixed> The body to save.
	 */
	private function uploadDocument(array $document, ?int $id): array {
		if ($id !== null) {
			unset($document['id'], $document['organisation'], $document['owner'], $document['created']);
		}

		if (empty($document['title']) === true) {
			$document['title'] = 'New Schema';
		}

		return $document;
	}//end uploadDocument()

	/**
	 * The body with its authorization in the form the schema will store, so
	 * every check on it judges that form.
	 *
	 * @param array<string, mixed> $document The schema body being written.
	 *
	 * @return array<string, mixed> The body with a JSON text block decoded; any other text stays for the write to judge.
	 */
	private function withAuthorizationAsStored(array $document): array {
		$stored = RbacRuleChecks::crossOrganisationMarkerChange()->asStored(authorization: ($document['authorization'] ?? null));
		if (is_array($stored) === true) {
			$document['authorization'] = $stored;
		}

		return $document;
	}//end withAuthorizationAsStored()

	/**
	 * Refuse a write by a non-administrator that lets a rule cross
	 * organisations it did not cross before. A schema manager may change the
	 * rest of the authorization, and remove a marker, but adding or widening
	 * `crossOrganisation: true` gives every holder of that rule other
	 * organisations' rows, so it is for administrators only.
	 *
	 * @param mixed                $old      The authorization before the write.
	 * @param array<string, mixed> $document The schema body being written.
	 *
	 * @return JSONResponse|null A 403, or null when the write may go ahead.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	private function crossOrganisationRefusal(mixed $old, array $document): ?JSONResponse {
		if (array_key_exists('authorization', $document) === false
			|| RbacRuleChecks::crossOrganisationMarkerChange()->widens(old: $old, new: $document['authorization']) === false
			|| $this->isCurrentUserAdmin() === true
		) {
			return null;
		}

		return new JSONResponse(
			data: ['error' => 'Only an administrator may let a rule read across organisations (crossOrganisation: true)'],
			statusCode: Http::STATUS_FORBIDDEN
		);
	}//end crossOrganisationRefusal()

	/**
	 * Refuse a schema write by a non-administrator that sets or changes `orphanCleanup`.
	 *
	 * The flag makes saves soft-delete rows, so a schema's `manage` rule does not cover it.
	 *
	 * @param Schema               $schema   The schema as stored.
	 * @param array<string, mixed> $document The incoming schema.
	 *
	 * @return JSONResponse|null A 403, or null when the write may go on.
	 *
	 * @spec openspec/specs/referential-integrity/spec.md
	 */
	private function orphanCleanupRefusal(Schema $schema, array $document): ?JSONResponse {
		if (RbacRuleChecks::orphanCleanupFlag()->changedBy(schema: $schema, document: $document) === false || $this->isCurrentUserAdmin() === true) {
			return null;
		}

		return new JSONResponse(
			data: ['error' => 'Only an administrator can change orphanCleanup on a schema'],
			statusCode: Http::STATUS_FORBIDDEN
		);
	}//end orphanCleanupRefusal()

	/**
	 * Refuse an upload the caller may not make (H3).
	 *
	 * Updating an existing schema needs manage permission, as update and destroy
	 * do; uploading a new schema needs admin, as create does.
	 *
	 * @param Schema   $schema The schema the upload writes
	 * @param int|null $id     The ID of the schema to update, or null for a new schema
	 *
	 * @return JSONResponse|null A 403, or null when the upload is allowed
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter.
	 */
	private function uploadPermissionError(Schema $schema, ?int $id): ?JSONResponse {
		if ($id === null) {
			return $this->newSchemaUploadError();
		}

		return $this->schemaUpdateUploadError(schema: $schema);
	}//end uploadPermissionError()

	/**
	 * Refuse an upload that updates a schema the caller may not manage.
	 *
	 * @param Schema $schema The stored schema the upload writes.
	 *
	 * @return JSONResponse|null A 403, or null when the caller may manage the schema.
	 */
	private function schemaUpdateUploadError(Schema $schema): ?JSONResponse {
		if ($this->checkSchemaManagePermission(schema: $schema) === false) {
			return new JSONResponse(
				data: ['error' => 'You do not have permission to update this schema'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end schemaUpdateUploadError()

	/**
	 * Refuse an upload of a new schema by a caller who is not an administrator.
	 *
	 * @return JSONResponse|null A 403, or null for an administrator.
	 */
	private function newSchemaUploadError(): ?JSONResponse {
		if ($this->isCurrentUserAdmin() === false) {
			return new JSONResponse(
				data: ['error' => 'Admin privileges required to upload new schemas'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end newSchemaUploadError()

	/**
	 * Hydrate a schema from an uploaded document and insert or update it.
	 *
	 * A new schema without an organisation gets the active one (multitenancy).
	 * Either way the schema and facet caches are cleared.
	 *
	 * @param Schema               $schema   The new or existing schema
	 * @param array<string, mixed> $document The uploaded schema document
	 *
	 * @return Schema The saved schema
	 *
	 * @throws Exception When the write fails
	 */
	private function saveUploadedSchema(Schema $schema, array $document): Schema {
		$schema->hydrate($document);

		if ($schema->getId() !== null) {
			$schema = $this->schemaMapper->update($schema);
			$this->schemaCacheService->invalidateForSchemaChange(schemaId: $schema->getId(), operation: 'update');
			$this->facetResults->clearAll();
			return $schema;
		}

		$schema = $this->schemaMapper->insert($schema);
		if ($schema->getOrganisation() === null || $schema->getOrganisation() === '') {
			$schema->setOrganisation($this->services->platform->organisationService->getOrganisationForNewEntity());
			$schema = $this->schemaMapper->update($schema);
		}

		$this->schemaCacheService->invalidateForSchemaChange(schemaId: $schema->getId(), operation: 'create');
		$this->facetResults->clearAll();
		return $schema;
	}//end saveUploadedSchema()

	/**
	 * Creates and return a json file for a Schema
	 *
	 * @param int $id The ID of the schema to return json file for
	 *
	 * @throws Exception If there is an error retrieving the schema
	 *
	 * @return JSONResponse A JSON response containing the schema as JSON
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @no-admin-idor-exempt Read-only export of a shared schema *definition* (not a user-owned object);
	 * schema metadata is public-readable by design, matching the @PublicPage index/show endpoints.
	 * No per-user data, no IDOR vector.
	 *
	 * @psalm-return JSONResponse<200, Schema,
	 *     array<never, never>>|JSONResponse<404,
	 *     array{error: 'Schema not found'}, array<never, never>>
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter; renaming breaks route binding.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md#requirement-schema-authoring-sub-resources-and-meta-entity-operational-endpoints
	 */
	public function download(int $id): JSONResponse {
		// Note: Accept header not currently used - always returns JSON.
		try {
			// Metadata-read bypass per auth-system "Schema and register
			// METADATA-READ lookups MUST bypass multi-tenancy" — download
			// serves the schema definition for export/consumer use.
			$schema = $this->schemaMapper->find($id, _multitenancy: false);
		} catch (Exception $e) {
			// Return a 404 error if the schema doesn't exist.
			return new JSONResponse(data: ['error' => 'Schema not found'], statusCode: 404);
		}

		// Return the schema as JSON.
		return new JSONResponse(data: $schema);
	}//end download()

	/**
	 * Get schemas that have properties referencing the given schema
	 *
	 * This method finds schemas that contain properties with $ref values pointing
	 * to the specified schema, indicating a relationship between schemas.
	 *
	 * @param int|string $id The ID, UUID, or slug of the schema to find relationships for
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @no-admin-idor-exempt Read-only discovery of which shared schema *definitions* reference this one;
	 * returns schema metadata only (public-readable by design, like @PublicPage index/show).
	 * No per-user data, no IDOR vector.
	 *
	 * @return JSONResponse JSON response with related schemas
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter; renaming breaks route binding.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md#requirement-schema-authoring-sub-resources-and-meta-entity-operational-endpoints
	 */
	public function related(int|string $id): JSONResponse {
		try {
			return $this->relatedSchemas(id: $id);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			// Return a 404 error if the target schema doesn't exist.
			return new JSONResponse(data: ['error' => 'Schema not found'], statusCode: 404);
		} catch (Exception $e) {
			// Return a 500 error for other exceptions.
			return $this->errorResponse(e: $e);
		}//end try
	}//end related()

	/**
	 * The schemas that refer to a schema (incoming) and the schemas it refers to (outgoing).
	 *
	 * @param int|string $id The schema ID.
	 *
	 * @return JSONResponse The incoming and outgoing schemas and their total.
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter.
	 */
	private function relatedSchemas(int|string $id): JSONResponse {
		// Find related schemas using the SchemaMapper (incoming references).
		$incomingSchemas = $this->schemaMapper->getRelated($id);
		$incomingSchemasArray = array_map(fn ($schema) => $schema->jsonSerialize(), $incomingSchemas);

		$outgoingSchemasArray = array_map(fn ($schema) => $schema->jsonSerialize(), $this->outgoingSchemas(id: $id));

		return new JSONResponse(
			data: [
				'incoming' => $incomingSchemasArray,
				'outgoing' => $outgoingSchemasArray,
				'total' => count($incomingSchemasArray) + count($outgoingSchemasArray),
			]
		);
	}//end relatedSchemas()

	/**
	 * Outgoing references: the schemas a schema refers to.
	 *
	 * Metadata-read bypass per auth-system "Schema and register
	 * METADATA-READ lookups MUST bypass multi-tenancy" — related-schema
	 * resolution is a catalog read (no object data exposed).
	 *
	 * @param int|string $id The schema ID.
	 *
	 * @return array<int, Schema> The referenced schemas, once each.
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter.
	 */
	private function outgoingSchemas(int|string $id): array {
		$targetSchema = $this->schemaMapper->find($id, _multitenancy: false);
		$properties = $targetSchema->getProperties() ?? [];

		return array_values($this->schemasReferencedBy(target: $targetSchema, properties: $properties));
	}//end outgoingSchemas()

	/**
	 * Every other schema the target's properties refer to, keyed by id.
	 *
	 * @param Schema               $target     The schema whose references are followed.
	 * @param array<string, mixed> $properties The target's properties.
	 *
	 * @return array<int, Schema> The referenced schemas by id.
	 */
	private function schemasReferencedBy(Schema $target, array $properties): array {
		$allSchemas = $this->schemaMapper->findAll(_multitenancy: false);
		$outgoingSchemas = [];
		foreach ($allSchemas as $schema) {
			// Skip self.
			if ($schema->getId() === $target->getId()) {
				continue;
			}

			// Use the same reference logic as getRelated, but reversed.
			if ($this->schemaMapper->hasReferenceToSchema(properties: $properties, target: $schema) === true
			) {
				$outgoingSchemas[$schema->getId()] = $schema;
			}
		}

		return $outgoingSchemas;
	}//end schemasReferencedBy()

	/**
	 * Get statistics for a specific schema
	 *
	 * @param int $id The schema ID
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When the schema is not found
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @no-admin-idor-exempt Read-only aggregate counts for a shared schema *definition* (object totals,
	 * not object contents); exposes no per-object or per-user data, matching the @PublicPage read posture.
	 * No IDOR vector.
	 *
	 * @return JSONResponse JSON response with schema statistics
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter; renaming breaks route binding.
	 *
	 * @spec openspec/specs/production-observability/spec.md#requirement-per-entity-statistics-and-endpoint-delivery-log-api
	 */
	public function stats(int $id): JSONResponse {
		try {
			// Metadata-read bypass per auth-system "Schema and register
			// METADATA-READ lookups MUST bypass multi-tenancy" — stats over
			// a schema is a catalog read; the underlying object-row
			// statistics remain tenant-scoped via MultiTenancyTrait.
			$schema = $this->schemaMapper->find($id, _multitenancy: false);

			if ($schema === null) {
				return new JSONResponse(data: ['error' => 'Schema not found'], statusCode: 404);
			}

			// Get detailed object statistics for this schema using the existing method.
			// Default every key: mapper variants (and mocked test doubles) may
			// return partial maps, which otherwise emits "Undefined array key"
			// PHP warnings for empty schemas.
			$objectStats = $this->objectEntityMapper->getStatistics(scope: new StatisticsScope(schemaId: $id));
			$objectStats = array_merge(
				['total' => 0, 'invalid' => 0, 'deleted' => 0, 'locked' => 0, 'size' => 0],
				$objectStats
			);

			// Calculate comprehensive statistics for this schema.
			$stats = [
				'objectCount' => ($objectStats['total'] ?? 0),
				// Keep for backward compatibility.
				'objects_count' => ($objectStats['total'] ?? 0),
				// Alternative field name for compatibility.
				'objects' => [
					'total' => ($objectStats['total'] ?? 0),
					'invalid' => ($objectStats['invalid'] ?? 0),
					'deleted' => ($objectStats['deleted'] ?? 0),
					'locked' => ($objectStats['locked'] ?? 0),
					'size' => ($objectStats['size'] ?? 0),
				],
				'logs' => $this->auditLogStats(schemaId: $id),
				'files' => ['total' => 0, 'size' => 0],
				// Placeholder for future file statistics.
				'registers' => $this->schemaMapper->getRegisterCountPerSchema()[$id] ?? 0,
			];

			return new JSONResponse(data: $stats);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Schema not found'], statusCode: 404);
		} catch (Exception $e) {
			return $this->errorResponse(e: $e);
		}//end try
	}//end stats()

	/**
	 * Explore schema properties to discover new properties in objects
	 *
	 * Analyzes all objects belonging to a schema to discover properties that exist
	 * in the object data but are not defined in the schema. This is useful for
	 * identifying properties that were added during imports or when validation
	 * was disabled.
	 *
	 * @param int $id The ID of the schema to explore
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with exploration results
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter; renaming breaks route binding.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md#requirement-schema-authoring-sub-resources-and-meta-entity-operational-endpoints
	 */
	public function explore(int $id): JSONResponse {
		try {
			// Authorization: exploration scans the schema's object population to
			// surface undefined properties — a schema-introspection operation that
			// MUST require manage permission (same authority as editing the schema),
			// so an arbitrary user cannot probe another schema's data shape (IDOR).
			$existingSchema = $this->manageableSchema(id: $id);
			if ($existingSchema instanceof JSONResponse) {
				return $existingSchema;
			}

			$this->logger->info(
				message: '[SchemasController] Starting schema exploration for schema ID: ' . $id,
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			$explorationResults = $this->services->schemas->schemaService->exploreSchemaProperties($id);

			$this->logger->info(
				message: '[SchemasController] Schema exploration completed successfully',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			return new JSONResponse(data: $explorationResults);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[SchemasController] Schema exploration failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return $this->errorResponse(e: $e);
		}//end try
	}//end explore()

	/**
	 * Update schema properties based on exploration results
	 *
	 * Applies user-confirmed property updates to a schema based on exploration
	 * results. This allows schemas to be updated with newly discovered properties.
	 *
	 * @param int $id The ID of the schema to update
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response with updated schema
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter; renaming breaks route binding.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md#requirement-schema-authoring-sub-resources-and-meta-entity-operational-endpoints
	 */
	public function updateFromExploration(int $id): JSONResponse {
		try {
			// Authorization: writing exploration results back into a schema's
			// definition is a schema mutation and MUST require manage permission,
			// exactly like update()/upload(). Without this guard any authenticated
			// user could rewrite an arbitrary schema's properties (IDOR).
			$existingSchema = $this->manageableSchema(id: $id);
			if ($existingSchema instanceof JSONResponse) {
				return $existingSchema;
			}

			// Get property updates from request.
			$propertyUpdates = $this->request->getParam(key: 'properties', default: []);

			if (empty($propertyUpdates) === true) {
				return new JSONResponse(data: ['error' => 'No property updates provided'], statusCode: 400);
			}

			$updateCount = count($propertyUpdates);
			$this->logger->info(
				message: "[SchemasController] Updating schema {$id} with {$updateCount} property updates",
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			$updatedSchema = $this->services->schemas->schemaService->updateSchemaFromExploration(
				schemaId: $id,
				propertyUpdates: $propertyUpdates
			);

			// Clear schema cache to ensure fresh data.
			$this->schemaCacheService->clearSchemaCache($id);

			$this->logger->info(
				message: '[SchemasController] Schema ' . $id . ' successfully updated with exploration results',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			return new JSONResponse(
				data: [
					'success' => true,
					'schema' => $updatedSchema->jsonSerialize(),
					'message' => 'Schema updated successfully with ' . count($propertyUpdates) . ' properties',
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[SchemasController] Failed to update schema from exploration: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return $this->errorResponse(e: $e);
		}//end try
	}//end updateFromExploration()

	/**
	 * Resolve a canonical semantic-type URI to the installed provider schema.
	 *
	 * Discovery endpoint for cross-app semantic references (ADR-048). Given a
	 * `uri` query parameter (an absolute semantic-type IRI such as
	 * `https://schema.org/Organization`), returns the register + schema slugs of
	 * the installed schema that implements it, so the frontend can build an
	 * object picker over the provider schema. When no installed schema provides
	 * the type, returns `{ resolved: false }` with HTTP 200 — never 500 — so a
	 * consuming form degrades gracefully to a disabled field.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @PublicPage
	 *
	 * @return JSONResponse `{ resolved: bool, register, registerSlug, schema, schemaSlug, appId? }`
	 *                      or `{ resolved: false }`.
	 *
	 * @spec openspec/changes/cross-app-semantic-references/specs/semantic-schema-references/spec.md
	 *   (Requirement: Resolution is null-safe across installed schemas)
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function resolveByImplements(): JSONResponse {
		$uri = (string)($this->request->getParam('uri', ''));
		if ($uri === '' || $this->semanticTypeResolver === null) {
			return new JSONResponse(['resolved' => false]);
		}

		$schema = $this->implementingSchema(resolver: $this->semanticTypeResolver, uri: $uri);
		if ($schema === null) {
			return new JSONResponse(['resolved' => false]);
		}

		return new JSONResponse($this->resolvedSchemaPayload(resolver: $this->semanticTypeResolver, schema: $schema));
	}//end resolveByImplements()

	/**
	 * The installed schema implementing a semantic type, or null when none does or resolution fails.
	 *
	 * @param SemanticTypeResolverInterface $resolver The semantic type resolver.
	 * @param string                        $uri      The semantic-type IRI.
	 *
	 * @return Schema|null The provider schema.
	 */
	private function implementingSchema(SemanticTypeResolverInterface $resolver, string $uri): ?Schema {
		$consumingRegisterId = $this->consumingRegisterId();

		try {
			return $resolver->resolveSchemaByImplements(
				uri: $uri,
				consumingRegisterId: $consumingRegisterId
			);
		} catch (\Throwable $e) {
			// Resolution is contractually null-safe; log and degrade, never 500.
			$this->logger->warning(
				message: '[SchemasController] resolveByImplements failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'uri' => $uri]
			);
			return null;
		}
	}//end implementingSchema()

	/**
	 * The optional consuming-register hint: it biases the tie-break and is never required.
	 *
	 * @return int|null The register id, or null when absent or not numeric.
	 */
	private function consumingRegisterId(): ?int {
		$rawRegister = $this->request->getParam('register');
		if ($rawRegister === null || is_numeric($rawRegister) === false) {
			return null;
		}

		return (int)$rawRegister;
	}//end consumingRegisterId()

	/**
	 * The payload of a resolved semantic type: the schema, and its owning register.
	 *
	 * @param SemanticTypeResolverInterface $resolver The semantic type resolver.
	 * @param Schema                        $schema   The provider schema.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function resolvedSchemaPayload(SemanticTypeResolverInterface $resolver, Schema $schema): array {
		$register = $resolver->findRegisterForSchema(schema: $schema);

		$payload = [
			'resolved' => true,
			'schema' => $schema->getId(),
			'schemaSlug' => $schema->getSlug(),
		];

		return array_merge($payload, $this->registerPayload(register: $register));
	}//end resolvedSchemaPayload()

	/**
	 * Build the register portion of a {@see self::resolveByImplements()} payload.
	 *
	 * Extracted so the discovery endpoint stays within complexity limits;
	 * behaviour is identical. When the schema has no owning register the
	 * register/slug keys are explicitly null; otherwise the register id + slug
	 * are returned, plus `appId` when the register names an owning app.
	 *
	 * @param Register|null $register The owning register, or null when none.
	 *
	 * @return array<string, mixed> The register keys to merge into the payload.
	 */
	private function registerPayload(?Register $register): array {
		if ($register === null) {
			return ['register' => null, 'registerSlug' => null];
		}

		$payload = [
			'register' => $register->getId(),
			'registerSlug' => $register->getSlug(),
		];

		$appId = $register->getApplication();
		if (is_string($appId) === true && $appId !== '') {
			$payload['appId'] = $appId;
		}

		return $payload;
	}//end registerPayload()

	/**
	 * Whether the current request has no resolved Nextcloud user (anonymous).
	 *
	 * Read endpoints are @PublicPage so they survive an app-group restriction;
	 * this lets the read-visibility guard distinguish anonymous callers (who may
	 * only see published resources) from authenticated users (unaffected). The
	 * user session is resolved lazily from the container.
	 *
	 * @return bool True if no user is signed in.
	 */
	private function isAnonymousRequest(): bool {
		try {
			return $this->container->get(\OCP\IUserSession::class)->getUser() === null;
		} catch (\Throwable $e) {
			// If the session service is unavailable, treat the caller as anonymous (fail closed).
			return true;
		}

	}//end isAnonymousRequest()

	/**
	 * Whether an entity is anonymously readable via RBAC authorization.
	 *
	 * A resource is visible to anonymous callers when its authorization block
	 * grants `read` access to the `public` group, as `"public"` or as
	 * `{"group": "public"}` with no match. This replaces the former
	 * published/depublished column gate: anonymous publication is an RBAC
	 * concern, expressed as a `public`-group read rule on the entity.
	 *
	 * @param array|null $authorization The entity's authorization block, or null.
	 *
	 * @return bool True if the `public` group has read access.
	 */
	private function isPublicReadable(?array $authorization): bool {
		if (is_array($authorization) === false) {
			return false;
		}

		return (new ReadRuleTenancy())->readsPublicUnconditionally(readRules: ($authorization['read'] ?? []));
	}//end isPublicReadable()

	/**
	 * Resolve the dialect of an uploaded schema document and map it.
	 *
	 * Explicit `dialect` parameter wins; otherwise the document is sniffed.
	 * `json-schema` and `openapi` documents flow through unchanged (existing
	 * behaviour, now labelled). `schema.org` and `ggm` documents are mapped
	 * through the standards importers into a register-schema array. Input that
	 * matches no dialect (and carries no explicit one) fails with HTTP 422.
	 *
	 * When the standards import service is unavailable (DI not wired), the
	 * document is returned unchanged so the legacy JSON-Schema path is
	 * preserved.
	 *
	 * @param array<string, mixed> $document The decoded upload document.
	 *
	 * @return array<string, mixed>|JSONResponse The (possibly mapped) schema array, or an error response.
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	private function applyDialect(array $document): array|JSONResponse {
		if ($this->schemaImportService === null) {
			return $document;
		}

		$dialect = $this->uploadDialect(document: $document);
		if ($dialect instanceof JSONResponse) {
			return $dialect;
		}

		// Json-schema and openapi documents are ingested as-is (unchanged).
		if ($dialect === 'json-schema' || $dialect === 'openapi') {
			return $document;
		}

		return $this->mapStandardsDialect(dialect: $dialect, document: $document);
	}//end applyDialect()

	/**
	 * The dialect of an uploaded document: the explicit `dialect` parameter, else sniffed.
	 *
	 * @param array<string, mixed> $document The decoded upload document.
	 *
	 * @return string|JSONResponse The dialect, or the error response when none applies.
	 */
	private function uploadDialect(array $document): string|JSONResponse {
		try {
			return $this->schemaImportService->resolveUploadDialect(
				document: $document,
				explicitDialect: $this->explicitDialect()
			);
		} catch (SchemaImportException $e) {
			return $this->schemaImportErrorResponse(e: $e);
		}
	}//end uploadDialect()

	/**
	 * The `dialect` request parameter, or null when it is absent, empty or not a string.
	 *
	 * @return string|null The explicitly requested dialect.
	 */
	private function explicitDialect(): ?string {
		$explicit = $this->request->getParam('dialect');
		if (is_string($explicit) === false || $explicit === '') {
			return null;
		}

		return $explicit;
	}//end explicitDialect()

	/**
	 * Map a standards-dialect document (schema.org, ggm) through the importers.
	 *
	 * The reference is the explicit `reference` parameter, else the document's
	 * own type marker. A ggm document carrying `objecttypen` is a normalised GGM
	 * intermediate and is imported as an upload; every other document is
	 * imported by reference.
	 *
	 * @param string               $dialect  The resolved dialect.
	 * @param array<string, mixed> $document The decoded upload document.
	 *
	 * @return array<string, mixed>|JSONResponse The mapped schema array, or an error response.
	 */
	private function mapStandardsDialect(string $dialect, array $document): array|JSONResponse {
		$reference = $this->resolveDialectReference(dialect: $dialect, document: $document);
		if ($reference === null) {
			return new JSONResponse(
				data: ['error' => 'A "reference" identifying the ' . $dialect . ' type/objecttype to import is required.'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		if ($dialect === 'ggm' && isset($document['objecttypen']) === true) {
			return $this->importGgmIntermediate(document: $document, reference: $reference);
		}

		return $this->importDialectReference(dialect: $dialect, reference: $reference);
	}//end mapStandardsDialect()

	/**
	 * Import an uploaded body that is a normalised GGM intermediate.
	 *
	 * @param array<string, mixed> $document  The normalised GGM document.
	 * @param string               $reference The objecttype to import.
	 *
	 * @return array<string, mixed>|JSONResponse The mapped schema array, or an error response.
	 */
	private function importGgmIntermediate(array $document, string $reference): array|JSONResponse {
		try {
			$imported = $this->schemaImportService->importGgmUpload(
				upload: new GgmUpload(normalised: $document, reference: $reference, sourceLabel: 'upload'),
				options: ImportOptions::fromArray($this->request->getParams())
			);
		} catch (SchemaImportException $e) {
			return $this->schemaImportErrorResponse(e: $e);
		}

		return $imported->toSchemaArray();
	}//end importGgmIntermediate()

	/**
	 * Import a standards type by its reference through the dialect's importer.
	 *
	 * @param string $dialect   The resolved dialect.
	 * @param string $reference The type or objecttype to import.
	 *
	 * @return array<string, mixed>|JSONResponse The mapped schema array, or an error response.
	 */
	private function importDialectReference(string $dialect, string $reference): array|JSONResponse {
		try {
			$imported = $this->schemaImportService->import(
				dialect: $dialect,
				reference: $reference,
				options: ImportOptions::fromArray($this->request->getParams())
			);
		} catch (SchemaImportException $e) {
			return $this->schemaImportErrorResponse(e: $e);
		}

		return $imported->toSchemaArray();
	}//end importDialectReference()

	/**
	 * The error response for a failed dialect resolution or import.
	 *
	 * @param SchemaImportException $e The failure.
	 *
	 * @return JSONResponse The message with the exception's HTTP status.
	 */
	private function schemaImportErrorResponse(SchemaImportException $e): JSONResponse {
		return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: $e->getHttpStatus());
	}//end schemaImportErrorResponse()

	/**
	 * Resolve the type reference for a standards-dialect upload.
	 *
	 * @param string $dialect The resolved dialect.
	 * @param array<string, mixed> $document The decoded document.
	 *
	 * @return string|null The reference, or null when none can be determined.
	 */
	private function resolveDialectReference(string $dialect, array $document): ?string {
		$reference = $this->request->getParam('reference');
		if (is_string($reference) === true && $reference !== '') {
			return $reference;
		}

		return $this->schemaOrgTypeReference(dialect: $dialect, document: $document);
	}//end resolveDialectReference()

	/**
	 * The `@type` marker of a schema.org document, used as its reference.
	 *
	 * @param string               $dialect  The resolved dialect.
	 * @param array<string, mixed> $document The decoded document.
	 *
	 * @return string|null The type, or null for another dialect or a missing or non-string marker.
	 */
	private function schemaOrgTypeReference(string $dialect, array $document): ?string {
		if ($dialect !== 'schema.org' || isset($document['@type']) === false) {
			return null;
		}

		if (is_string($document['@type']) === true) {
			return $document['@type'];
		}

		return null;
	}//end schemaOrgTypeReference()

	/**
	 * The audit-trail statistics of one schema, for an administrator only.
	 *
	 * The count covers every entry of the schema, whichever organisation the
	 * object belongs to, so a non-admin or anonymous caller is given zeros.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return array The statistics, or zeros for a non-admin.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md#requirement-the-instance-wide-audit-reads-are-admin-only
	 */
	private function auditLogStats(int $schemaId): array {
		if ($this->isCurrentUserAdmin() === false) {
			return self::NO_LOG_STATS;
		}

		return $this->auditTrailMapper->statisticsTotals(scope: new AuditStatisticsScope(schemaId: $schemaId));
	}//end auditLogStats()

	/**
	 * Check if the current user is a Nextcloud administrator.
	 *
	 * @return bool True when the current user is an admin.
	 */
	private function isCurrentUserAdmin(): bool {
		try {
			$user = $this->container->get(\OCP\IUserSession::class)->getUser();
			if ($user === null) {
				return false;
			}

			return $this->container->get(\OCP\IGroupManager::class)->isAdmin($user->getUID());
		} catch (\Throwable $e) {
			return false;
		}

	}//end isCurrentUserAdmin()

	/**
	 * Check if the current user has 'manage' permission on a schema.
	 *
	 * Default-SECURE: a schema with no `manage` authorization rule can only be
	 * managed by administrators. When manage rules are present, membership of
	 * one of the listed groups grants permission (admins always pass). This is
	 * deliberately NOT PermissionHandlerInterface::hasPermission(), which is default-OPEN
	 * for object data RBAC and therefore unsuitable for gating schema-definition
	 * writes. Reading schema metadata is unaffected and stays open.
	 *
	 * @param Schema $schema The schema to check manage permission for.
	 *
	 * @return bool True if the current user may manage this schema.
	 */
	private function checkSchemaManagePermission(Schema $schema): bool {
		try {
			$rights = RbacRuleChecks::definitionWriteRights(
				userSession: $this->container->get(\OCP\IUserSession::class),
				groupManager: $this->container->get(\OCP\IGroupManager::class)
			);

			return $rights->mayManage(authorization: $schema->getAuthorization());
		} catch (\Throwable $e) {
			return false;
		}
	}//end checkSchemaManagePermission()


	/**
	 * Read the list surface a schema declares: its columns and its search fields.
	 *
	 * A generic list surface asks this once per schema and renders whatever comes
	 * back, so an object type is as usable as a case list without a page written
	 * for it. A schema that declares nothing answers with the columns every list
	 * already shows and `declared: false`, so a surface with its own defaults can
	 * keep them.
	 *
	 * @param int|string                $id       The schema id, uuid or slug.
	 * @param ListPresentationResolverInterface $resolver The resolver.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @no-admin-idor-exempt Read-only catalogue read of a schema's own declaration.
	 * Returns schema metadata only, no object data, like the sibling `related` and
	 * `explore` endpoints.
	 *
	 * @return JSONResponse The declared columns and search fields, or 404.
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function listPresentation(int|string $id, ListPresentationResolverInterface $resolver): JSONResponse {
		try {
			// Metadata read: a schema's own declaration is catalogue data, scoped
			// the same way the sibling `related` endpoint scopes it.
			$schema = $this->schemaMapper->find($id, _multitenancy: false);
		} catch (\Throwable $e) {
			return new JSONResponse(data: ['error' => 'Schema not found'], statusCode: 404);
		}

		$presentation = $resolver->resolve(schema: $schema);
		$presentation['schema'] = [
			'id' => (string)$schema->getId(),
			'slug' => $schema->getSlug(),
			'title' => $schema->getTitle(),
		];

		return new JSONResponse(data: $presentation);
	}//end listPresentation()
}//end class
