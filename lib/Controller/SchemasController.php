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
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\BreakingSchemaChangeException;
use OCA\OpenRegister\Exception\DatabaseConstraintException;
use OCA\OpenRegister\Exception\SchemaImportException;
use OCA\OpenRegister\Exception\SchemaNotInRegisterException;
use OCA\OpenRegister\Service\AuthorizationAuditService;
use OCA\OpenRegister\Service\JsonLd\JsonLdContextService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;
use OCA\OpenRegister\Service\SchemaDeletionService;
use OCA\OpenRegister\Service\SchemaImport\ImportOptions;
use OCA\OpenRegister\Service\SchemaImport\SchemaImportService;
use OCA\OpenRegister\Service\Schemas\FacetCacheHandler;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\SchemaService;
use OCA\OpenRegister\Service\SemanticTypeResolver;
use OCA\OpenRegister\Service\UploadService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\Exception as DBException;
use OCP\IAppConfig;
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
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   NC AppFramework controller DI requires injecting
 * framework + RBAC + audit + domain services, each used in separate endpoint groups.
 *
 * @spec openspec/specs/openapi-generation/spec.md
 */
class SchemasController extends Controller {
	use \OCA\OpenRegister\Controller\Trait\HandlesExceptionsTrait;

	/**
	 * Constructor
	 *
	 * Initializes controller with required dependencies for schema operations.
	 * Calls parent constructor to set up base controller functionality.
	 *
	 * @param string $appName Application name
	 * @param IRequest $request HTTP request object
	 * @param IAppConfig $config App configuration for settings
	 * @param SchemaMapper $schemaMapper Schema mapper for database operations
	 * @param MagicMapper $objectEntityMapper Object entity mapper for object queries
	 * @param UploadService $uploadService Upload service for file uploads
	 * @param AuditTrailMapper $auditTrailMapper Audit trail mapper for log statistics
	 * @param OrganisationService $organisationService Organisation service for multi-tenancy
	 * @param SchemaCacheHandler $schemaCacheService Schema cache handler for caching operations
	 * @param FacetCacheHandler $facetCacheSvc Schema facet cache service for facet caching
	 * @param SchemaService $schemaService Schema service for exploration operations
	 * @param LoggerInterface $logger Logger for error tracking
	 * @param ContainerInterface $container Container for lazy loading services
	 * @param SchemaVersioningService $schemaVersioningService Schema versioning service for version operations
	 * @param JsonLdContextService $jsonLdContextService JSON-LD context service
	 * @param SchemaImportService $schemaImportService Schema import service for importing schemas
	 * @param SemanticTypeResolver $semanticTypeResolver Semantic-type → schema resolver (cross-app references)
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Nextcloud AppFramework requires all services to be constructor-injected.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IAppConfig $config,
		private readonly SchemaMapper $schemaMapper,
		private readonly RegisterMapper $registerMapper,
		private readonly MagicMapper $objectEntityMapper,
		private readonly UploadService $uploadService,
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly OrganisationService $organisationService,
		private readonly SchemaCacheHandler $schemaCacheService,
		private readonly FacetCacheHandler $facetCacheSvc,
		private readonly SchemaService $schemaService,
		private readonly LoggerInterface $logger,
		private readonly ContainerInterface $container,
		private readonly SchemaVersioningService $schemaVersioningService,
		private readonly ?JsonLdContextService $jsonLdContextService = null,
		private readonly ?SchemaImportService $schemaImportService = null,
		private readonly ?SemanticTypeResolver $semanticTypeResolver = null,
	) {
		// Call parent constructor to initialize base controller.
		parent::__construct(appName: $appName, request: $request);
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
	 *     array{results: array<array{id: int, uuid: null|string,
	 *     uri: null|string, slug: null|string, title: null|string,
	 *     description: null|string, version: null|string,
	 *     summary: null|string, icon: null|string, required: array,
	 *     properties: array, archive: array|null, source: null|string,
	 *     hardValidation: bool, immutable: bool, searchable: bool,
	 *     updated: null|string, created: null|string, maxDepth: int,
	 *     owner: null|string, application: null|string,
	 *     organisation: null|string,
	 *     groups: array<string, list<string>>|null,
	 *     authorization: array|null, deleted: null|string,
	 *     published: null|string, depublished: null|string,
	 *     configuration: array|null|string, allOf: array|null,
	 *     oneOf: array|null, anyOf: array|null}>}, array<never, never>>
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Multiple optional extend/pagination/filter parameters each add one branch.
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple optional extend/pagination/filter parameters each add one branch.
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Handling pagination + filtering + extend + stats
	 * in one NC controller action is idiomatic; extracting helpers would obscure the flow.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function index(): JSONResponse {
		// Get request parameters for filtering and searching.
		$params = $this->request->getParams();

		// Extract pagination and search parameters.
		$limit = null;
		if (isset($params['_limit']) === true) {
			$limit = (int)$params['_limit'];
		}

		$offset = null;
		if (isset($params['_offset']) === true) {
			$offset = (int)$params['_offset'];
		}

		$page = null;
		if (isset($params['_page']) === true) {
			$page = (int)$params['_page'];
		}

		// Note: search parameter not currently used in this endpoint.
		// Extract extend parameter for additional properties.
		$extend = $params['_extend'] ?? [];

		// Normalize extend to array if string.
		if (is_string($extend) === true) {
			$extend = [$extend];
		}

		// Convert page to offset if provided (page-based pagination).
		if ($page !== null && $limit !== null) {
			$offset = ($page - 1) * $limit;
		}

		// Extract filters from request parameters.
		$filters = $params['filters'] ?? [];

		// Retrieve schemas using mapper with pagination and filters.
		$schemas = $this->schemaMapper->findAll(
			limit: $limit,
			offset: $offset,
			filters: $filters,
			searchConditions: [],
			searchParams: [],
			_extend: [],
			_multitenancy: false
		);

		// Read-visibility guard: this endpoint is @PublicPage so it stays
		// reachable when OpenRegister is restricted to a user group. Anonymous
		// callers may only see schemas whose RBAC authorization grants read
		// access to the `public` group; authenticated users are unaffected.
		// Visibility is derived from server-side authorization rules, never
		// from client-supplied parameters.
		if ($this->isAnonymousRequest() === true) {
			$schemas = array_values(
				array_filter(
					$schemas,
					fn ($schema) => $this->isPublicReadable(authorization: $schema->getAuthorization())
				)
			);
		}

		// Serialize schemas to arrays.
		$schemasArr = array_map(
			function ($schema) {
				return $schema->jsonSerialize();
			},
			$schemas
		);

		// Batch-load all extendedBy relationships in a single query instead of N queries.
		$allExtendedBy = $this->schemaMapper->findAllExtendedBy();
		foreach ($schemasArr as &$schema) {
			// @psalm-suppress InvalidArrayOffset
			$schema['@self'] = $schema['@self'] ?? [];
			$schema['@self']['extendedBy'] = $allExtendedBy[$schema['id']] ?? [];
		}

		unset($schema);
		// Break the reference.
		// If '@self.stats' is requested, attach statistics to each schema.
		if (in_array('@self.stats', $extend, true) === true) {
			// Collect all schema IDs for batch queries.
			$schemaIds = array_map(fn ($schema) => $schema['id'], $schemasArr);

			// Batch-load all statistics in 3 queries instead of N*2 queries.
			$registerCounts = $this->schemaMapper->getRegisterCountPerSchema();
			$objectStats = $this->objectEntityMapper->getStatisticsGroupedBySchema(schemaIds: $schemaIds);
			$logStats = $this->auditTrailMapper->getStatisticsGroupedBySchema(schemaIds: $schemaIds);

			foreach ($schemasArr as &$schema) {
				$schema['stats'] = [
					'objects' => $objectStats[$schema['id']] ?? [
						'total' => 0,
						'size' => 0,
						'invalid' => 0,
						'deleted' => 0,
						'locked' => 0,
					],
					'logs' => $logStats[$schema['id']] ?? ['total' => 0, 'size' => 0],
					'files' => [ 'total' => 0, 'size' => 0 ],
					// Add the number of registers referencing this schema.
					'registers' => $registerCounts[$schema['id']] ?? 0,
				];
			}
		}//end if

		return new JSONResponse(data: ['results' => $schemasArr]);
	}//end index()

	/**
	 * Resolve the {id} route parameter to a Schema, register-scoped when possible.
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
	 */
	private function resolveSchema(int|string $id): Schema {
		$registerParam = $this->request->getParam(key: 'register', default: null);

		// Register-scoped resolution. A numeric id is resolved globally below;
		// scoping applies to slugs only.
		//
		// The two failures below are deliberately NOT handled together, and the
		// separation is load-bearing. An *unresolvable register parameter* is not a
		// reason to fail the schema read — the caller gets what they would have got
		// without the parameter. But a register that resolves and does not carry the
		// slug is a refusal, because naming a register makes it a boundary.
		//
		// Both used to sit inside one try/catch. Throwing the refusal from in there
		// would have been caught by that same catch and logged as "scope could not
		// be applied", restoring the exact fallback this change removes — a silent
		// no-op that looks like a fix.
		if ($registerParam !== null && $registerParam !== '' && is_string($id) === true && is_numeric($id) === false) {
			$register = null;
			try {
				$register = $this->registerMapper->find(id: $registerParam, _rbac: false, _multitenancy: false);
			} catch (Exception $e) {
				$this->logger->debug(
					'[SchemasController] register scope could not be applied: ' . $e->getMessage(),
					['register' => $registerParam, 'schema' => $id]
				);
			}

			if ($register !== null) {
				$registerSchemaIds = ($register->getSchemas() ?? []);
				$scoped            = $this->schemaMapper->findBySlugInIds(
					slug: $id,
					schemaIds: $registerSchemaIds
				);
				if ($scoped !== null) {
					return $scoped;
				}

				throw new SchemaNotInRegisterException(
					schemaSlug: $id,
					registerId: $register->getId(),
					registerSlug: $register->getSlug(),
					candidatesElsewhere: $this->schemaMapper->countBySlug(slug: $id),
					registerListEmpty: ($registerSchemaIds === [])
				);
			}
		}

		$schema = $this->schemaMapper->find(id: $id, _extend: [], _multitenancy: false);

		// Leave evidence when a slug was ambiguous and nobody said which register they
		// meant. Silence here is what made the `agent` collision cost a workaround
		// instead of a bug report.
		if (is_string($id) === true && is_numeric($id) === false && $registerParam === null) {
			$this->logAmbiguousSlug(slug: $id, resolved: $schema);
		}

		return $schema;
	}//end resolveSchema()

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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Handles _extend, _count, RBAC, 404, and several
	 * response-shaping branches; each is a required rendering path that cannot be extracted without
	 * splitting the HTTP contract.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function show($id): JSONResponse {
		try {
			$extend = $this->request->getParam(key: '_extend', default: []);
			if (is_string($extend) === true) {
				$extend = [$extend];
			}

			$schema = $this->resolveSchema(id: $id);

			// Read-visibility guard (@PublicPage): an anonymous caller may only
			// view a schema whose RBAC authorization grants read access to the
			// `public` group. Derived from server-side authorization rules,
			// never from client-supplied parameters.
			if ($this->isAnonymousRequest() === true
				&& $this->isPublicReadable(authorization: $schema->getAuthorization()) === false
			) {
				return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
			}

			$schemaArr = $schema->jsonSerialize();

			// Add extendedBy property showing UUIDs of schemas that extend this schema.
			// Note: @psalm-suppress InvalidArrayOffset used here for dynamic array access.
			$schemaArr['@self'] = $schemaArr['@self'] ?? [];
			$schemaArr['@self']['extendedBy'] = $this->schemaMapper->findExtendedBy($id);

			// Add property source metadata to distinguish native vs inherited properties.
			// This is especially useful for schemas using allOf composition.
			if (($schema->getAllOf() ?? null) !== null && count($schema->getAllOf()) > 0) {
				$schemaArr['@self']['propertyMetadata'] = $this->schemaMapper->getPropertySourceMetadata($schema);
			}

			// If '@self.stats' is requested, attach statistics to the schema.
			if (in_array('@self.stats', $extend, true) === true) {
				// Get register counts for all schemas in one call.
				$registerCounts = $this->schemaMapper->getRegisterCountPerSchema();
				$schemaArr['stats'] = [
					'objects' => $this->objectEntityMapper->getStatistics(registerId: null, schemaId: $schemaArr['id']),
					'logs' => $this->auditTrailMapper->getStatistics(registerId: null, schemaId: $schemaArr['id']),
					'files' => [ 'total' => 0, 'size' => 0 ],
					// Add the number of registers referencing this schema.
					'registers' => $registerCounts[$schemaArr['id']] ?? 0,
				];
			}

			return new JSONResponse(data: $schemaArr);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Schema not found'], statusCode: 404);
		} catch (\OCA\OpenRegister\Exception\ValidationException $e) {
			// ValidationException is thrown when schema is not found (includes debugging info).
			return new JSONResponse(data: ['error' => 'Schema not found'], statusCode: 404);
		} catch (Exception $e) {
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
		}//end try
	}//end show()

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
	private function validateJsonLdMapping(array $data): ?JSONResponse {
		if ($this->jsonLdContextService === null) {
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

		$errors = $this->jsonLdContextService->validateMapping(jsonld: $jsonld);
		if (empty($errors) === true) {
			return null;
		}

		return new JSONResponse(
			data: [
				'error' => 'Invalid jsonld mapping in schema configuration',
				'errors' => $errors,
			],
			statusCode: 400
		);
	}//end validateJsonLdMapping()

	/**
	 * Creates a new schema
	 *
	 * This method creates a new schema based on POST data.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)          DatabaseConstraintException::fromDatabaseException is a named constructor — no DI alternative.
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Multiple message-substring checks for error classification; each adds one branch.
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple message-substring checks for error classification; each adds one branch.
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Error-classification block at the end is
	 * repetitive but intentional; extracting it would not reduce cognitive load.
	 *
	 * @return JSONResponse JSON response with created schema or error
	 *
	 * @psalm-return JSONResponse<201, Schema,
	 *     array<never, never>>|JSONResponse<400|403|409|500, array{error: string},
	 *     array<never, never>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	public function create(): JSONResponse {
		// Authorization: creating a schema defines a new data model and is
		// restricted to administrators. Reading schema metadata stays open so
		// frontends can build their UIs; only create/update/delete are gated.
		if ($this->isCurrentUserAdmin() === false) {
			return new JSONResponse(
				data: ['error' => 'Only administrators may create schemas'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		// Get request parameters.
		$data = $this->request->getParams();

		// DEBUG: Log incoming request to track duplicate creation.
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

		// Remove internal parameters (starting with '_').
		foreach (array_keys($data) as $key) {
			if (str_starts_with($key, '_') === true) {
				unset($data[$key]);
			}
		}

		// Remove ID if present to ensure a new record is created.
		if (($data['id'] ?? null) !== null) {
			unset($data['id']);
		}

		// Validate the optional JSON-LD vocabulary-mapping block (json-ld-output).
		$jsonLdError = $this->validateJsonLdMapping(data: $data);
		if ($jsonLdError !== null) {
			return $jsonLdError;
		}

		try {
			// Create a new schema from the data.
			$schema = $this->schemaMapper->createFromArray(object: $data);

			/*
			 * NOTE: Organization should already be set from the request data.
			 * The update() call below was causing duplicate schema creation with different timestamps.
			 * Since createFromArray() already handles organization assignment, this is commented out.
				// Set organisation from active organisation for multi-tenancy (if not already set).
				if ($schema->getOrganisation() === null || $schema->getOrganisation() === '') {
				$organisationUuid = $this->organisationService->getOrganisationForNewEntity();
				$schema->setOrganisation($organisationUuid);
				$schema = $this->schemaMapper->update($schema);
				}
			 */

			// **CACHE INVALIDATION (runtime-schema-api)**: Drop in-memory + persistent
			// schema cache for the freshly-created ID so any follow-up read in the same
			// PHP worker observes the new schema. This is the create-side counterpart
			// of the invalidations already wired into update() and destroy().
			$this->schemaCacheService->invalidate(schemaId: $schema->getId());

			return new JSONResponse(data: $schema, statusCode: 201);
		} catch (DBException $e) {
			// Handle database constraint violations with user-friendly messages.
			$constraintException = DatabaseConstraintException::fromDatabaseException(dbException: $e, entityType: 'schema');
			return new JSONResponse(
				data: ['error' => $constraintException->getMessage()],
				statusCode: $constraintException->getHttpStatusCode()
			);
		} catch (DatabaseConstraintException $e) {
			// Handle our custom database constraint exceptions.
			return new JSONResponse(
				data: ['error' => $e->getMessage()],
				statusCode: $e->getHttpStatusCode()
			);
		} catch (Exception $e) {
			// Log the actual error for debugging.
			$this->logger->error(
				message: '[SchemasController] Schema creation failed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error_message' => $e->getMessage(),
					'error_code' => $e->getCode(),
					'trace' => $e->getTraceAsString(),
				]
			);

			// Check if this is a validation error by examining the message.
			if (str_contains($e->getMessage(), 'Invalid') === true
				|| str_contains($e->getMessage(), 'must be') === true
				|| str_contains($e->getMessage(), 'required') === true
				|| str_contains($e->getMessage(), 'requires translatable') === true
				|| str_contains($e->getMessage(), 'format') === true
				|| str_contains($e->getMessage(), 'Property at') === true
				|| str_contains($e->getMessage(), 'authorization') === true
				|| str_contains($e->getMessage(), 'handoff') === true
			) {
				// Return 400 Bad Request for validation errors with actual error message.
				return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
			}

			// For database constraint violations, return 409 Conflict.
			if (str_contains($e->getMessage(), 'constraint') === true
				|| str_contains($e->getMessage(), 'duplicate') === true
				|| str_contains($e->getMessage(), 'unique') === true
			) {
				return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 409);
			}

			// Return 500 for other unexpected errors with actual error message.
			return $this->errorResponse(e: $e);
		}//end try
	}//end create()

	/**
	 * Updates an existing schema
	 *
	 * This method updates an existing schema based on its ID.
	 *
	 * @param int $id The ID of the schema to update
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
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
	 *     array<never, never>>|JSONResponse<400|403|404|409|500, array{error: string},
	 *     array<never, never>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	public function update(int $id): JSONResponse {
		// Get request parameters.
		$data = $this->request->getParams();

		// Remove internal parameters (starting with '_').
		foreach (array_keys($data) as $key) {
			if (str_starts_with($key, '_') === true) {
				unset($data[$key]);
			}
		}

		// Remove immutable fields to prevent tampering.
		unset($data['id']);
		unset($data['organisation']);
		unset($data['owner']);
		unset($data['created']);

		// Authorization: modifying a schema's definition requires manage
		// permission. This gates ALL updates, not just authorization changes —
		// reading schema metadata stays open for frontends.
		try {
			$existingSchema = $this->schemaMapper->find($id);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Schema not found'], statusCode: 404);
		}

		if ($this->checkSchemaManagePermission(schema: $existingSchema) === false) {
			return new JSONResponse(
				data: ['error' => 'User does not have permission to manage this schema'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		// Validate the optional JSON-LD vocabulary-mapping block (json-ld-output).
		// Runs after the manage-permission check so an unauthorized caller can
		// never probe mapping validation; an invalid mapping leaves the stored
		// configuration unchanged (no save happens).
		$jsonLdError = $this->validateJsonLdMapping(data: $data);
		if ($jsonLdError !== null) {
			return $jsonLdError;
		}

		// Capture prior authorization so a change can be audit-logged below.
		$oldSchemaAuth = $existingSchema->getAuthorization();

		// Schema versioning gate (schema-versioning-and-object-migration):
		// classify the incoming definition against the stored one and refuse
		// an unacknowledged breaking change with the structured HTTP 409
		// contract. Only runs when the update actually carries a definition.
		$changeSet = null;
		$acknowledged = ($this->request->getParam('acknowledgeBreaking') === true
			|| $this->request->getParam('acknowledgeBreaking') === 'true');
		if (isset($data['properties']) === true || isset($data['required']) === true) {
			$proposedDefinition = [
				'properties' => ($data['properties'] ?? $existingSchema->getProperties() ?? []),
				'required' => ($data['required'] ?? $existingSchema->getRequired() ?? []),
			];

			$renames = [];
			if (is_array($this->request->getParam('renames')) === true) {
				$renames = $this->request->getParam('renames');
			}

			$changeSet = $this->schemaVersioningService->classify(
				existing: $existingSchema,
				newDefinition: $proposedDefinition,
				renames: $renames
			);

			try {
				$this->schemaVersioningService->enforceGate(
					changeSet: $changeSet,
					acknowledged: $acknowledged,
					schemaId: $id
				);
			} catch (BreakingSchemaChangeException $e) {
				return new JSONResponse(data: $e->toResponse(), statusCode: 409);
			}

			// Apply the classification-driven semantic version bump unless the
			// caller pinned an explicit version.
			if (isset($data['version']) === false && $changeSet->hasChanges() === true) {
				$data['version'] = $this->schemaVersioningService->nextVersion(
					existing: $existingSchema,
					changeSet: $changeSet
				);
			}
		}//end if

		try {
			// Update the schema with the provided data.
			$updatedSchema = $this->schemaMapper->updateFromArray(id: $id, object: $data);

			// Record the classified changelog entry once the update applied.
			if ($changeSet !== null) {
				$this->schemaVersioningService->recordChangelog(
					schemaId: $updatedSchema->getId(),
					version: $updatedSchema->getVersion(),
					changeSet: $changeSet,
					acknowledged: $acknowledged
				);
			}

			// Log authorization change if authorization was modified.
			if (isset($data['authorization']) === true) {
				try {
					$auditService = $this->container->get(AuthorizationAuditService::class);
					$auditService->logSchemaAuthorizationChange(
						$updatedSchema->getId(),
						$updatedSchema->getTitle() ?? '',
						$oldSchemaAuth,
						$updatedSchema->getAuthorization()
					);
				} catch (\Throwable $e) {
					// Audit logging should not break the update operation.
				}
			}

			// **CACHE INVALIDATION (runtime-schema-api)**: Clear all schema-related
			// caches when a schema is updated. `invalidate()` is the runtime-schema-api
			// entry point — it covers the legacy `invalidateForSchemaChange` cleanup AND
			// drops the request-scoped find cache on the mapper itself so reads in the
			// same worker observe the new state.
			$this->schemaCacheService->invalidate(schemaId: $updatedSchema->getId());
			$this->facetCacheSvc->invalidateForSchemaChange(
				schemaId: $updatedSchema->getId(),
				operation: 'update'
			);

			return new JSONResponse(data: $updatedSchema);
		} catch (DBException $e) {
			// Handle database constraint violations with user-friendly messages.
			$constraintException = DatabaseConstraintException::fromDatabaseException(
				dbException: $e,
				entityType: 'schema'
			);
			return new JSONResponse(
				data: ['error' => $constraintException->getMessage()],
				statusCode: $constraintException->getHttpStatusCode()
			);
		} catch (DatabaseConstraintException $e) {
			// Handle our custom database constraint exceptions.
			return new JSONResponse(
				data: ['error' => $e->getMessage()],
				statusCode: $e->getHttpStatusCode()
			);
		} catch (Exception $e) {
			// Log the actual error for debugging.
			$this->logger->error(
				message: '[SchemasController] Schema update failed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'schema_id' => $id,
					'error_message' => $e->getMessage(),
					'error_code' => $e->getCode(),
					'trace' => $e->getTraceAsString(),
				]
			);

			// Check if this is a validation error by examining the message.
			if (str_contains($e->getMessage(), 'Invalid') === true
				|| str_contains($e->getMessage(), 'must be') === true
				|| str_contains($e->getMessage(), 'required') === true
				|| str_contains($e->getMessage(), 'requires translatable') === true
				|| str_contains($e->getMessage(), 'format') === true
				|| str_contains($e->getMessage(), 'Property at') === true
				|| str_contains($e->getMessage(), 'authorization') === true
				|| str_contains($e->getMessage(), 'handoff') === true
			) {
				// Return 400 Bad Request for validation errors with actual error message.
				return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
			}

			// For database constraint violations, return 409 Conflict.
			if (str_contains($e->getMessage(), 'constraint') === true
				|| str_contains($e->getMessage(), 'duplicate') === true
				|| str_contains($e->getMessage(), 'unique') === true
			) {
				return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 409);
			}

			// Return 500 for other unexpected errors with actual error message.
			return $this->errorResponse(e: $e);
		}//end try
	}//end update()

	/**
	 * Patch (partially update) a schema
	 *
	 * @param int $id The ID of the schema to patch
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with patched schema or error
	 *
	 * @no-admin-idor-exempt Pure delegation to update(), which performs the checkSchemaManagePermission() guard; this method has no body of its own.
	 *
	 * @psalm-return JSONResponse<200, Schema,
	 *     array<never, never>>|JSONResponse<400|403|404|409|500, array{error: string},
	 *     array<never, never>>
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
	 * @NoCSRFRequired
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
			$objectStats = $this->objectEntityMapper->getStatistics(registerId: null, schemaId: $schemaToDelete->getId());
			$objectCount = (int)($objectStats['total'] ?? 0);

			if ($deleteObjects === true) {
				return $this->cascadeDeleteSchema(schema: $schemaToDelete);
			}

			return $this->deleteSchemaKeepingObjects(
				schema: $schemaToDelete,
				objectCount: $objectCount,
				force: $force
			);
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
	 * @param Schema $schema The schema to delete (already authorized).
	 * @param int $objectCount The number of live objects referencing the schema.
	 * @param bool $force Whether ?force=true was passed.
	 *
	 * @throws Exception If the delete fails.
	 *
	 * @return JSONResponse Empty body on success, or the structured 409 refusal.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The force flag is the API-level disposition, mirrored from the endpoint.
	 *
	 * @spec openspec/changes/schema-delete-cascade/specs/runtime-schema-api/spec.md
	 */
	private function deleteSchemaKeepingObjects(Schema $schema, int $objectCount, bool $force): JSONResponse {
		if ($objectCount > 0 && $force === false) {
			// Refuse: structured 409 with the orphan count for the caller.
			return new JSONResponse(
				data: [
					'error' => 'schema-has-objects',
					'objectCount' => $objectCount,
				],
				statusCode: 409
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
		$this->schemaMapper->delete(entity: $schema, force: $force);

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
		$result = $this->getSchemaDeletionService()->cascadeDeleteSchema(schema: $schema);

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
		$this->facetCacheSvc->invalidateForSchemaChange(
			schemaId: $schemaId,
			operation: 'delete'
		);
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
	 * @return SchemaDeletionService The service.
	 */
	private function getSchemaDeletionService(): SchemaDeletionService {
		$service = $this->container->get(SchemaDeletionService::class);

		if (($service instanceof SchemaDeletionService) === false) {
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
	 * @NoCSRFRequired
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
	 * @NoCSRFRequired
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)          Uuid::v4 is a named constructor and
	 * DatabaseConstraintException::fromDatabaseException is a factory — no DI alternatives.
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) JSON-upload path merges insert + update branches;
	 * splitting would duplicate error classification.
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Multiple message-substring checks for error classification; each adds one branch.
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple message-substring checks for error classification; each adds one branch.
	 * @SuppressWarnings(PHPMD.ShortVariable)         $id matches the {id} URL route parameter; renaming breaks route binding.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md#requirement-schema-authoring-sub-resources-and-meta-entity-operational-endpoints
	 */
	public function upload(?int $id = null): JSONResponse {
		// Default: create a new schema.
		$schema = new Schema();
		$schema->setUuid(Uuid::v4()->toRfc4122());
		if ($id !== null) {
			// If ID is provided, find the existing schema.
			$schema = $this->schemaMapper->find($id);
		}

		// SECURITY (H3): gate schema uploads on appropriate permissions.
		// Updating an existing schema requires manage-permission (same as update/destroy).
		// Creating a new schema requires admin (same as create).
		if ($id !== null && $this->checkSchemaManagePermission(schema: $schema) === false) {
			return new JSONResponse(
				data: ['error' => 'You do not have permission to update this schema'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		if ($id === null && $this->isCurrentUserAdmin() === false) {
			return new JSONResponse(
				data: ['error' => 'Admin privileges required to upload new schemas'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		// Get the uploaded JSON data.
		$phpArray = $this->uploadService->getUploadedJson($this->request->getParams());
		if ($phpArray instanceof JSONResponse) {
			// Return any error response from the upload service.
			return $phpArray;
		}

		// Dialect resolution: explicit `dialect` parameter wins, otherwise the
		// document is sniffed. Schema.org / GGM inputs are mapped through the
		// standards importers; json-schema / openapi flow on unchanged.
		// Undetectable input fails with HTTP 422 instead of being mis-ingested.
		$dialectResult = $this->applyDialect(document: $phpArray);
		if ($dialectResult instanceof JSONResponse) {
			return $dialectResult;
		}

		$phpArray = $dialectResult;

		// Set default title if not provided or empty.
		if (empty($phpArray['title']) === true) {
			$phpArray['title'] = 'New Schema';
		}

		try {
			// Update the schema with the data from the uploaded JSON.
			$schema->hydrate($phpArray);

			// Track whether this is a new schema before potential insert.
			$isNewSchema = ($schema->getId() === null);

			if ($isNewSchema === true) {
				// Insert a new schema if no ID is set.
				$schema = $this->schemaMapper->insert($schema);

				// Set organisation from active organisation for multi-tenancy (if not already set).
				if ($schema->getOrganisation() === null || $schema->getOrganisation() === '') {
					$organisationUuid = $this->organisationService->getOrganisationForNewEntity();
					$schema->setOrganisation($organisationUuid);
					$schema = $this->schemaMapper->update($schema);
				}

				// **CACHE INVALIDATION**: Clear all schema-related caches when schema is created.
				$this->schemaCacheService->invalidateForSchemaChange(schemaId: $schema->getId(), operation: 'create');
				$this->facetCacheSvc->invalidateForSchemaChange(schemaId: $schema->getId(), operation: 'create');
			}

			if ($isNewSchema === false) {
				// Update the existing schema.
				$schema = $this->schemaMapper->update($schema);

				// **CACHE INVALIDATION**: Clear all schema-related caches when schema is updated.
				$this->schemaCacheService->invalidateForSchemaChange(schemaId: $schema->getId(), operation: 'update');
				$this->facetCacheSvc->invalidateForSchemaChange(
					schemaId: $schema->getId(),
					operation: 'update'
				);
			}

			return new JSONResponse(data: $schema);
		} catch (DBException $e) {
			// Handle database constraint violations with user-friendly messages.
			$constraintException = DatabaseConstraintException::fromDatabaseException(
				dbException: $e,
				entityType: 'schema'
			);
			return new JSONResponse(
				data: ['error' => $constraintException->getMessage()],
				statusCode: $constraintException->getHttpStatusCode()
			);
		} catch (DatabaseConstraintException $e) {
			// Handle our custom database constraint exceptions.
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: $e->getHttpStatusCode());
		} catch (Exception $e) {
			// Log the actual error for debugging.
			$this->logger->error(
				message: '[SchemasController] Schema upload failed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'schema_id' => $id,
					'error_message' => $e->getMessage(),
					'error_code' => $e->getCode(),
					'trace' => $e->getTraceAsString(),
				]
			);

			// Check if this is a validation error by examining the message.
			if (str_contains($e->getMessage(), 'Invalid') === true
				|| str_contains($e->getMessage(), 'must be') === true
				|| str_contains($e->getMessage(), 'required') === true
				|| str_contains($e->getMessage(), 'requires translatable') === true
				|| str_contains($e->getMessage(), 'format') === true
				|| str_contains($e->getMessage(), 'Property at') === true
				|| str_contains($e->getMessage(), 'authorization') === true
				|| str_contains($e->getMessage(), 'handoff') === true
			) {
				// Return 400 Bad Request for validation errors with actual error message.
				return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
			}

			// For database constraint violations, return 409 Conflict.
			if (str_contains($e->getMessage(), 'constraint') === true
				|| str_contains($e->getMessage(), 'duplicate') === true
				|| str_contains($e->getMessage(), 'unique') === true
			) {
				return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 409);
			}

			// Return 500 for other unexpected errors with actual error message.
			return $this->errorResponse(e: $e);
		}//end try
	}//end upload()

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
			// Find related schemas using the SchemaMapper (incoming references).
			$incomingSchemas = $this->schemaMapper->getRelated($id);
			$incomingSchemasArray = array_map(fn ($schema) => $schema->jsonSerialize(), $incomingSchemas);

			// Find outgoing references: schemas that this schema refers to.
			// Metadata-read bypass per auth-system "Schema and register
			// METADATA-READ lookups MUST bypass multi-tenancy" — related-schema
			// resolution is a catalog read (no object data exposed).
			$targetSchema = $this->schemaMapper->find($id, _multitenancy: false);
			$properties = $targetSchema->getProperties() ?? [];
			$allSchemas = $this->schemaMapper->findAll(_multitenancy: false);
			$outgoingSchemas = [];
			foreach ($allSchemas as $schema) {
				// Skip self.
				if ($schema->getId() === $targetSchema->getId()) {
					continue;
				}

				// Use the same reference logic as getRelated, but reversed.
				if ($this->schemaMapper->hasReferenceToSchema(
					properties: $properties,
					targetSchemaId: (string)$schema->getId(),
					targetSchemaUuid: $schema->getUuid() ?? '',
					targetSchemaSlug: $schema->getSlug() ?? ''
				) === true
				) {
					$outgoingSchemas[$schema->getId()] = $schema;
				}
			}

			$outgoingSchemasArray = array_map(fn ($schema) => $schema->jsonSerialize(), array_values($outgoingSchemas));

			return new JSONResponse(
				data: [
					'incoming' => $incomingSchemasArray,
					'outgoing' => $outgoingSchemasArray,
					'total' => count($incomingSchemasArray) + count($outgoingSchemasArray),
				]
			);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			// Return a 404 error if the target schema doesn't exist.
			return new JSONResponse(data: ['error' => 'Schema not found'], statusCode: 404);
		} catch (Exception $e) {
			// Return a 500 error for other exceptions.
			return $this->errorResponse(e: $e);
		}//end try
	}//end related()

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
			$objectStats = $this->objectEntityMapper->getStatistics(registerId: null, schemaId: $id);
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
				'logs' => $this->auditTrailMapper->getStatistics(registerId: null, schemaId: $id),
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
			try {
				$existingSchema = $this->schemaMapper->find($id);
			} catch (DoesNotExistException $e) {
				return new JSONResponse(data: ['error' => 'Schema not found'], statusCode: 404);
			}

			if ($this->checkSchemaManagePermission(schema: $existingSchema) === false) {
				return new JSONResponse(
					data: ['error' => 'User does not have permission to manage this schema'],
					statusCode: Http::STATUS_FORBIDDEN
				);
			}

			$this->logger->info(
				message: '[SchemasController] Starting schema exploration for schema ID: ' . $id,
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			$explorationResults = $this->schemaService->exploreSchemaProperties($id);

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
	 * @NoCSRFRequired
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
			try {
				$existingSchema = $this->schemaMapper->find($id);
			} catch (DoesNotExistException $e) {
				return new JSONResponse(data: ['error' => 'Schema not found'], statusCode: 404);
			}

			if ($this->checkSchemaManagePermission(schema: $existingSchema) === false) {
				return new JSONResponse(
					data: ['error' => 'User does not have permission to manage this schema'],
					statusCode: Http::STATUS_FORBIDDEN
				);
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

			$updatedSchema = $this->schemaService->updateSchemaFromExploration(
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

		// Optional consuming-register hint biases the tie-break; never required.
		$consumingRegisterId = null;
		$rawRegister = $this->request->getParam('register');
		if ($rawRegister !== null && is_numeric($rawRegister) === true) {
			$consumingRegisterId = (int)$rawRegister;
		}

		try {
			$schema = $this->semanticTypeResolver->resolveSchemaByImplements(
				uri: $uri,
				consumingRegisterId: $consumingRegisterId
			);
		} catch (\Throwable $e) {
			// Resolution is contractually null-safe; log and degrade, never 500.
			$this->logger->warning(
				message: '[SchemasController] resolveByImplements failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'uri' => $uri]
			);
			return new JSONResponse(['resolved' => false]);
		}

		if ($schema === null) {
			return new JSONResponse(['resolved' => false]);
		}

		$register = $this->semanticTypeResolver->findRegisterForSchema(schema: $schema);

		$payload = [
			'resolved' => true,
			'schema' => $schema->getId(),
			'schemaSlug' => $schema->getSlug(),
		];

		return new JSONResponse(array_merge($payload, $this->registerPayload(register: $register)));
	}//end resolveByImplements()

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
	 * grants `read` access to the `public` group. This replaces the former
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

		$readGroups = ($authorization['read'] ?? []);
		return is_array($readGroups) === true && in_array('public', $readGroups, true) === true;
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
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One branch per supported dialect.
	 */
	private function applyDialect(array $document): array|JSONResponse {
		if ($this->schemaImportService === null) {
			return $document;
		}

		$explicit = $this->request->getParam('dialect');
		if (is_string($explicit) === false || $explicit === '') {
			$explicit = null;
		}

		try {
			$dialect = $this->schemaImportService->resolveUploadDialect(document: $document, explicitDialect: $explicit);
		} catch (SchemaImportException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: $e->getHttpStatus());
		}

		// Json-schema and openapi documents are ingested as-is (unchanged).
		if ($dialect === 'json-schema' || $dialect === 'openapi') {
			return $document;
		}

		// Standards dialects are mapped via the importer. The reference is the
		// explicit `reference` parameter, else the document's own type marker.
		$reference = $this->resolveDialectReference(dialect: $dialect, document: $document);
		if ($reference === null) {
			return new JSONResponse(
				data: ['error' => 'A "reference" identifying the ' . $dialect . ' type/objecttype to import is required.'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			if ($dialect === 'ggm' && isset($document['objecttypen']) === true) {
				// Treat the uploaded body as a normalised GGM intermediate.
				$imported = $this->schemaImportService->importGgmUpload(
					normalised: $document,
					reference: $reference,
					options: ImportOptions::fromArray($this->request->getParams()),
					sourceLabel: 'upload'
				);
			} else {
				$imported = $this->schemaImportService->import(
					dialect: $dialect,
					reference: $reference,
					options: ImportOptions::fromArray($this->request->getParams())
				);
			}
		} catch (SchemaImportException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: $e->getHttpStatus());
		}

		return $imported->toSchemaArray();
	}//end applyDialect()

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

		if ($dialect === 'schema.org' && isset($document['@type']) === true && is_string($document['@type']) === true) {
			return $document['@type'];
		}

		return null;
	}//end resolveDialectReference()

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
	 * deliberately NOT PermissionHandler::hasPermission(), which is default-OPEN
	 * for object data RBAC and therefore unsuitable for gating schema-definition
	 * writes. Reading schema metadata is unaffected and stays open.
	 *
	 * @param Schema $schema The schema to check manage permission for.
	 *
	 * @return bool True if the current user may manage this schema.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 */
	private function checkSchemaManagePermission(Schema $schema): bool {
		try {
			$user = $this->container->get(\OCP\IUserSession::class)->getUser();
			if ($user === null) {
				return false;
			}

			$groupManager = $this->container->get(\OCP\IGroupManager::class);

			// Admins always pass.
			if ($groupManager->isAdmin($user->getUID()) === true) {
				return true;
			}

			$authorization = $schema->getAuthorization();

			// Default-secure: no manage rule defined → admin-only (already failed above).
			if (empty($authorization) === true || isset($authorization['manage']) === false) {
				return false;
			}

			$userGroups = $groupManager->getUserGroupIds($user);
			$manageRules = $authorization['manage'];
			foreach ($userGroups as $groupId) {
				foreach ($manageRules as $entry) {
					if (is_string($entry) === true && $entry === $groupId) {
						return true;
					}

					if (is_array($entry) === true && isset($entry['group']) === true && $entry['group'] === $groupId) {
						return true;
					}
				}
			}
		} catch (\Throwable $e) {
			return false;
		}//end try

		return false;
	}//end checkSchemaManagePermission()
}//end class
