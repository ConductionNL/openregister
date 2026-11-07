<?php

/**
 * RegistersController handles REST API endpoints for register management
 *
 * Controller for managing register operations in the OpenRegister app.
 * Provides endpoints for CRUD operations, import/export, GitHub publishing,
 * and OpenAPI specification generation.
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
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Controller\Support\RegisterAccessAudit;
use OCA\OpenRegister\Controller\Support\RegisterDownloads;
use OCA\OpenRegister\Controller\Support\RegisterGitHubPublisher;
use OCA\OpenRegister\Controller\Support\RegistersControllerServices;
use OCA\OpenRegister\Controller\Support\RegisterStatistics;
use OCA\OpenRegister\Controller\Support\RegisterUpdate;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\AuthorizationBlockException;
use OCA\OpenRegister\Exception\DatabaseConstraintException;
use OCA\OpenRegister\Kernel\Contracts\GitHubClientInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectExporterInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectImporterInterface;
use OCA\OpenRegister\Service\AuthorizationAuditService;
use OCA\OpenRegister\Service\Configuration\RegisterBundleBinding;
use OCA\OpenRegister\Service\ConfigurationServiceInterface;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Rbac\ReadRuleTenancy;
use OCA\OpenRegister\Service\Rbac\DefinitionWriteRights;
use OCA\OpenRegister\Service\RegisterServiceInterface;
use OCA\OpenRegister\Service\Lifecycle\KeepLifecycleStatesScope;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\Exception as DBException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * RegistersController handles REST API endpoints for register management
 *
 * Provides REST API endpoints for managing registers including CRUD operations,
 * import/export functionality, GitHub publishing, and OpenAPI specification generation.
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
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     NC REST controller must expose all CRUD + subresource
 *   endpoints (registers, schemas, objects, statistics) in one class per NC AppFramework routing;
 *   splitting into multiple controllers would require additional routing registration.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Aggregate complexity from N independent REST actions;
 *   each action is individually simple — the class total is a routing artifact, not design debt.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     Each public method maps to one REST endpoint; NC AppFramework
 *   requires public methods for route dispatch — they cannot be made protected/private.
 * @SuppressWarnings(PHPMD.TooManyMethods)           The import action is split into one private method per step.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   NC Controller DI injects AppFramework, RBAC, audit, domain
 *   services, and mappers — each dep is used and cannot be combined without violating SRP.
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)    The create/update actions include multi-step validation
 *   that is a single atomic write; extracting sub-steps would create misleading partial-update helpers.
 *
 * @spec openspec/specs/openapi-generation/spec.md
 */
class RegistersController extends Controller {

	/**
	 * The boolean import flags and their defaults.
	 *
	 * @var array<string, bool>
	 */
	private const IMPORT_FLAG_DEFAULTS = [
		'includeObjects' => false,
		'validation' => false,
		'events' => false,
		'publish' => false,
		'enrich' => true,
		'dryRun' => false,
		'historic' => false,
	];

	/**
	 * Configuration service for handling import/export operations
	 *
	 * @var ConfigurationServiceInterface
	 */
	private readonly ConfigurationServiceInterface $configurationService;

	/**
	 * Audit trail mapper for fetching log statistics
	 *
	 * @var AuditTrailMapperInterface
	 */
	private readonly AuditTrailMapperInterface $auditTrailMapper;

	/**
	 * Import service for handling data imports
	 *
	 * @var ObjectImporterInterface
	 */
	private readonly ObjectImporterInterface $importService;

	/**
	 * Schema mapper for handling schema operations
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Register mapper for handling register operations
	 *
	 * @var RegisterMapperInterface
	 */
	private readonly RegisterMapperInterface $registerMapper;

	/**
	 * The register service.
	 *
	 * @var RegisterServiceInterface
	 */
	private readonly RegisterServiceInterface $registerService;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * The session bundle, which answers the admin check.
	 *
	 * @var ControllerSession
	 */
	private readonly ControllerSession $session;

	/**
	 * The user session.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * The container.
	 *
	 * @var ContainerInterface
	 */
	private readonly ContainerInterface $container;

	/**
	 * The group manager.
	 *
	 * @var IGroupManager
	 */
	private readonly IGroupManager $groupManager;

	/**
	 * The controller's injected services.
	 *
	 * @var RegistersControllerServices
	 */
	private readonly RegistersControllerServices $services;

	/**
	 * The exports and import templates this controller hands out.
	 *
	 * @var RegisterDownloads
	 */
	private readonly RegisterDownloads $downloads;

	/**
	 * The object counts this controller reports.
	 *
	 * @var RegisterStatistics
	 */
	private readonly RegisterStatistics $statistics;

	/**
	 * The publisher of a register's OpenAPI file.
	 *
	 * @var RegisterGitHubPublisher
	 */
	private readonly RegisterGitHubPublisher $gitHub;

	/**
	 * Constructor
	 *
	 * Initializes controller with required dependencies for register operations.
	 * Calls parent constructor to set up base controller functionality.
	 *
	 * @param string $appName Application name
	 * @param IRequest $request HTTP request object
	 * @param ControllerSession $session Session, group manager and logger.
	 * @param RegistersControllerServices $services The registers controller services.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		ControllerSession $session,
		RegistersControllerServices $services,
	) {
		$this->logger = $session->logger;
		$this->logger->debug(
			message: '[RegistersController] Constructor started.',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
		parent::__construct(appName: $appName, request: $request);
		$this->services = $services;
		$this->downloads = new RegisterDownloads(session: $session, services: $services);
		$this->statistics = new RegisterStatistics(services: $services);
		$this->gitHub = new RegisterGitHubPublisher(request: $request, session: $session, services: $services);
		$this->registerService = $services->registers->registerService;
		$this->userSession = $session->userSession;
		$this->session = $session;
		$this->container = $services->integrations->container;
		$this->groupManager = $session->groupManager;
		$this->logger->debug(
			message: '[RegistersController] Parent constructor called.',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
		$this->configurationService = $services->integrations->configurationService;
		$this->logger->debug(
			message: '[RegistersController] ConfigurationService assigned.',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
		$this->auditTrailMapper = $services->mappers->auditTrailMapper;
		$this->importService = $services->transfer->importService;
		$this->schemaMapper = $services->mappers->schemaMapper;
		$this->registerMapper = $services->mappers->registerMapper;
		$this->logger->debug(
			message: '[RegistersController] Constructor completed.',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end __construct()

	/**
	 * Retrieves a list of all registers
	 *
	 * This method returns a JSON response containing an array of all registers in the system.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @PublicPage
	 *
	 * @return JSONResponse The JSON response containing the list of registers
	 *
	 * @spec openspec/changes/register-schema-read-accessibility/tasks.md#task-1
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function index(): JSONResponse {
		// Get request parameters for filtering and searching.
		$params = $this->request->getParams();

		// Note: search parameter not currently used in this endpoint.
		$extend = $this->extendList(extend: ($params['_extend'] ?? []));
		$registers = $this->anonymousVisible(registers: $this->listedRegisters(params: $params));

		return new JSONResponse(data: ['results' => $this->serializedList(registers: $registers, extend: $extend)]);
	}//end index()

	/**
	 * An `_extend` value as a list.
	 *
	 * @param mixed $extend The value of the parameter.
	 *
	 * @return mixed A list for a single value; anything else as it came.
	 */
	private function extendList(mixed $extend): mixed {
		if (is_string($extend) === true) {
			return [$extend];
		}

		return $extend;
	}//end extendList()

	/**
	 * The page of registers a list request asks for.
	 *
	 * Entities, so the anonymous guard can filter them before serialization.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return array<int, Register> The registers.
	 */
	private function listedRegisters(array $params): array {
		$page = $this->listPage(params: $params);

		return $this->registerService->findAll(
			limit: $page['limit'],
			offset: $page['offset'],
			filters: ($params['filters'] ?? []),
			searchConditions: [],
			searchParams: [],
			_multitenancy: false
		);
	}//end listedRegisters()

	/**
	 * The registers the caller may list.
	 *
	 * The list is @PublicPage so it stays reachable when OpenRegister is restricted to
	 * a user group. Anonymous callers may only see registers whose RBAC authorization
	 * grants read access to the `public` group; authenticated users are unaffected.
	 * Visibility is derived from server-side authorization rules, never from
	 * client-supplied parameters.
	 *
	 * @param array<int, Register> $registers The registers.
	 *
	 * @return array<int, Register> The visible registers.
	 */
	private function anonymousVisible(array $registers): array {
		if ($this->isAnonymousRequest() === false) {
			return $registers;
		}

		return array_values(
			array_filter(
				$registers,
				fn ($register) => $this->isPublicReadable(authorization: $register->getAuthorization())
			)
		);
	}//end anonymousVisible()

	/**
	 * Serialize a list, with statistics when `@self.stats` is requested.
	 *
	 * With `schemas` as well, per-schema counts are computed once and handed to the
	 * serializer, which owns the schema expansion and orphan-id retention contract.
	 *
	 * @param array<int, Register> $registers The registers.
	 * @param mixed                $extend    The `_extend` list.
	 *
	 * @return array<int, array<string, mixed>> The serialized registers.
	 */
	private function serializedList(array $registers, mixed $extend): array {
		$wantsStats = in_array('@self.stats', $extend, true);
		$statsByRegisterId = null;
		if ($wantsStats === true && in_array('schemas', $extend, true) === true) {
			$statsByRegisterId = $this->statistics->schemaCounts(registers: $registers);
		}

		$serialized = $this->services->registers->registerSerializer->serializeMany($registers, $extend, $statsByRegisterId);
		if ($wantsStats === true) {
			return $this->withRegisterStats(registers: $serialized, statsByRegisterId: $statsByRegisterId);
		}

		return $serialized;
	}//end serializedList()

	/**
	 * The limit and offset a list request asks for; a page with a limit sets the offset.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return array{limit: int|null, offset: int|null}
	 */
	private function listPage(array $params): array {
		$limit = null;
		if (isset($params['_limit']) === true) {
			$limit = (int)$params['_limit'];
		}

		$offset = null;
		if (isset($params['_offset']) === true) {
			$offset = (int)$params['_offset'];
		}

		if (isset($params['_page']) === true && $limit !== null) {
			$offset = ((int)$params['_page'] - 1) * $limit;
		}

		return ['limit' => $limit, 'offset' => $offset];
	}//end listPage()

	/**
	 * Attach register-level statistics to serialized registers.
	 *
	 * They stay a controller concern because they depend on the object and audit-trail
	 * mappers the serializer does not have. A register's object stats are the sum of its
	 * schemas' counts already in hand; only a list without per-schema counts falls back
	 * to the mapper, whose per-register recount was ~4.7s of a ~7s request on a dev
	 * instance with 76 registers.
	 *
	 * @param array<int, array<string, mixed>> $registers         The serialized registers.
	 * @param array<int, array>|null           $statsByRegisterId The schema counts per register id, if computed.
	 *
	 * @return array<int, array<string, mixed>> The registers with a `stats` block.
	 */
	private function withRegisterStats(array $registers, ?array $statsByRegisterId): array {
		foreach ($registers as &$register) {
			$register['stats'] = [
				'objects' => $this->statistics->objectTotals(
					registerId: (int)$register['id'],
					schemaStats: ($statsByRegisterId[(int)$register['id']] ?? null)
				),
				'logs' => $this->auditLogStats(registerId: (int)$register['id']),
				'files' => [ 'total' => 0, 'size' => 0 ],
			];
		}

		unset($register);
		return $registers;
	}//end withRegisterStats()

	/**
	 * Retrieves a single register by ID
	 *
	 * @param int|string $id The ID of the register
	 *
	 * @return JSONResponse JSON response with register details
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @PublicPage
	 *
	 * @spec openspec/changes/register-schema-read-accessibility/tasks.md#task-2
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function show($id): JSONResponse {
		try {
			return $this->shownRegister(id: $id);
		} catch (DoesNotExistException $e) {
			// Unknown id, uuid or slug: a clean 404 JSON error, not an uncaught exception
			// falling through to Nextcloud's HTML error template.
			return new JSONResponse(data: ['error' => 'Register not found'], statusCode: 404);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[RegistersController] Failed to retrieve register',
				context: ['file' => __FILE__, 'line' => __LINE__, 'register_id' => $id, 'error_message' => $e->getMessage()]
			);

			return new JSONResponse(data: ['error' => 'Failed to retrieve register: ' . $e->getMessage()], statusCode: 500);
		}//end try
	}//end show()

	/**
	 * Answer with one register, when the caller may read it.
	 *
	 * Read-visibility guard (@PublicPage): an anonymous caller may only view a register
	 * whose RBAC authorization grants read access to the `public` group. Derived from
	 * server-side authorization rules, never from client-supplied parameters.
	 *
	 * @param int|string $id The ID of the register.
	 *
	 * @return JSONResponse The register, or 401.
	 *
	 * @throws DoesNotExistException When the register does not exist.
	 * @throws Exception             When reading fails; show() answers it.
	 */
	private function shownRegister(int|string $id): JSONResponse {
		$extend = $this->extendList(extend: $this->request->getParam(key: '_extend', default: []));
		$register = $this->registerService->find(id: $id, _extend: [], _multitenancy: false);

		if ($this->isAnonymousRequest() === true
			&& $this->isPublicReadable(authorization: $register->getAuthorization()) === false
		) {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
		}

		$registerArr = $register->jsonSerialize();
		if (in_array('@self.stats', $extend, true) === true) {
			$registerArr['stats'] = $this->shownStats(registerId: $registerArr['id']);
		}

		return new JSONResponse(data: $registerArr);
	}//end shownRegister()

	/**
	 * The statistics of one shown register.
	 *
	 * @param mixed $registerId The id in the serialized register.
	 *
	 * @return array<string, mixed> The object, log and file statistics.
	 */
	private function shownStats(mixed $registerId): array {
		return [
			'objects' => $this->services->mappers->objectEntityMapper->getStatistics(registerId: $registerId, schemaId: null),
			'logs' => $this->auditLogStats(registerId: (int)$registerId),
			'files' => [ 'total' => 0, 'size' => 0 ],
		];
	}//end shownStats()

	/**
	 * Creates a new register
	 *
	 * This method creates a new register based on POST data.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with created register or error
	 *
	 * @psalm-return JSONResponse<201, Register,
	 *     array<never, never>>|JSONResponse<403|409|500, array{error: string},
	 *     array<never, never>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	public function create(): JSONResponse {
		// Authorization: creating a register defines a new data model and is
		// restricted to administrators. Reading register metadata stays open so
		// frontends can build their UIs; only create/update/delete are gated.
		if ($this->isCurrentUserAdmin() === false) {
			return new JSONResponse(data: ['error' => 'Only administrators may create registers'], statusCode: 403);
		}

		$data = $this->createFields();

		try {
			return new JSONResponse(data: $this->createRegister(data: $data), statusCode: 201);
		} catch (AuthorizationBlockException | DBException | DatabaseConstraintException $e) {
			return $this->writeRefusal(error: $e);
		} catch (\Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
		}
	}//end create()

	/**
	 * The request's fields without internal parameters, those starting with '_'.
	 *
	 * @return array<string, mixed> The fields.
	 */
	private function requestFields(): array {
		$data = $this->request->getParams();
		foreach (array_keys($data) as $key) {
			if (str_starts_with($key, '_') === true) {
				unset($data[$key]);
			}
		}

		return $data;
	}//end requestFields()

	/**
	 * The fields of a new register; a given id is dropped so a new record is created.
	 *
	 * @return array<string, mixed> The fields.
	 */
	private function createFields(): array {
		$data = $this->requestFields();
		if (($data['id'] ?? null) !== null) {
			unset($data['id']);
		}

		return $data;
	}//end createFields()

	/**
	 * Create a register and drop the request-scoped find cache for its id.
	 *
	 * A follow-up read in the same PHP worker then observes the new register.
	 *
	 * @param array<string, mixed> $data The fields of the register.
	 *
	 * @return Register The new register.
	 */
	private function createRegister(array $data): Register {
		$register = $this->registerService->createFromArray($data);
		$this->services->registers->registerCacheHandler->invalidate(registerId: $register->getId());

		return $register;
	}//end createRegister()

	/**
	 * The answer to a write the storage or the authorization rules refused.
	 *
	 * An authorization block is a deny that contradicts a grant beside it, or one
	 * that would leave nobody holding `manage`: the request was understood and the
	 * rules inside it disagree, which is 422 and not 400. A database error gets a
	 * user-friendly constraint message.
	 *
	 * @param AuthorizationBlockException|DBException|DatabaseConstraintException $error The refusal.
	 *
	 * @return JSONResponse The error and its status.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) DatabaseConstraintException factory method is standard pattern
	 */
	private function writeRefusal(AuthorizationBlockException|DBException|DatabaseConstraintException $error): JSONResponse {
		if ($error instanceof DBException) {
			$error = DatabaseConstraintException::fromDatabaseException(dbException: $error, entityType: 'register');
		}

		return new JSONResponse(data: ['error' => $error->getMessage()], statusCode: $error->getHttpStatusCode());
	}//end writeRefusal()

	/**
	 * Updates an existing register
	 *
	 * This method updates an existing register based on its ID.
	 *
	 * @param int $id The ID of the register to update
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with updated register or error
	 *
	 * @psalm-return JSONResponse<200, Register,
	 *     array<never, never>>|JSONResponse<403|404|409, array{error: string},
	 *     array<never, never>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	public function update(int $id): JSONResponse {
		$update = new RegisterUpdate(id: $id, data: $this->updateFields());

		// Authorization: modifying a register's definition requires manage
		// permission (manage-group membership, or administrator when no manage
		// rules are configured). This gates ALL updates, not just authorization
		// changes: reading register metadata stays open for frontends.
		try {
			$existingRegister = $this->registerMapper->find($id);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Register not found'], statusCode: 404);
		}

		if ($this->checkRegisterManagePermission(register: $existingRegister) === false) {
			return new JSONResponse(data: ['error' => 'User does not have permission to manage this register'], statusCode: 403);
		}

		return $this->updateResponse(update: $update, existing: $existingRegister);
	}//end update()

	/**
	 * Save an update the caller may make and answer with the result.
	 *
	 * @param RegisterUpdate $update   The update.
	 * @param Register       $existing The register as stored.
	 *
	 * @return JSONResponse The saved register, or the refusal.
	 */
	private function updateResponse(RegisterUpdate $update, Register $existing): JSONResponse {
		try {
			return new JSONResponse(data: $this->applyUpdate(update: $update, existing: $existing));
		} catch (AuthorizationBlockException | DBException | DatabaseConstraintException $e) {
			return $this->writeRefusal(error: $e);
		}
	}//end updateResponse()

	/**
	 * The fields an update writes, without the immutable ones, to prevent tampering.
	 *
	 * @return array<string, mixed> The fields.
	 */
	private function updateFields(): array {
		$data = $this->requestFields();
		unset($data['id'], $data['organisation'], $data['owner'], $data['created']);

		return $data;
	}//end updateFields()

	/**
	 * Save an update, drop the request-scoped find cache and log access changes.
	 *
	 * @param RegisterUpdate $update   The update.
	 * @param Register       $existing The register as stored.
	 *
	 * @return Register The saved register.
	 */
	private function applyUpdate(RegisterUpdate $update, Register $existing): Register {
		$audit   = new RegisterAccessAudit(update: $update, before: $existing);
		$updated = $this->registerService->updateFromArray(id: $update->id, data: $update->data);
		$this->services->registers->registerCacheHandler->invalidate(registerId: $updated->getId());
		$this->logAccessChanges(audit: $audit, updated: $updated);

		return $updated;
	}//end applyUpdate()

	/**
	 * Log authorization and role changes; a failing audit log does not break the update.
	 *
	 * @param RegisterAccessAudit $audit   The access settings before the update.
	 * @param Register            $updated The saved register.
	 *
	 * @return void
	 */
	private function logAccessChanges(RegisterAccessAudit $audit, Register $updated): void {
		try {
			$audit->log(service: $this->container->get(AuthorizationAuditService::class), after: $updated);
		} catch (\Throwable $e) {
			// Audit logging should not break the update operation.
		}
	}//end logAccessChanges()

	/**
	 * Patch (partially update) a register
	 *
	 * This method handles partial updates (PATCH requests) by updating only
	 * the fields provided in the request body. This is different from PUT
	 * which typically requires all fields to be provided.
	 *
	 * @param int $id The ID of the register to patch
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with patched register or error
	 *
	 * @psalm-return JSONResponse<200, Register,
	 *     array<never, never>>|JSONResponse<403|404|409, array{error: string},
	 *     array<never, never>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	public function patch(int $id): JSONResponse {
		// PATCH works the same as PUT for this resource.
		// The service layer handles partial updates automatically.
		return $this->update(id: $id);
	}//end patch()

	/**
	 * Deletes a register
	 *
	 * This method deletes a register based on its ID.
	 *
	 * @param int $id The ID of the register to delete
	 *
	 * @throws Exception If there is an error deleting the register
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response on success or error
	 *
	 * @psalm-return JSONResponse<200|403|404|409|500, array{error?: string, objectCount?: int},
	 *     array<never, never>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	public function destroy(int $id): JSONResponse {
		$force = $this->parseForceParam();

		try {
			// Find the register first (validates existence + access).
			return $this->destroyFound(register: $this->registerService->find($id), force: $force);
		} catch (DoesNotExistException $e) {
			// Return 404 Not Found when register doesn't exist or is not accessible.
			return new JSONResponse(data: ['error' => 'Register not found'], statusCode: 404);
		} catch (\OCA\OpenRegister\Exception\ValidationException $e) {
			// Return 409 Conflict for cascade protection (objects still attached).
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 409);
		} catch (Exception $e) {
			// Return 500 for other errors.
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
		}//end try
	}//end destroy()

	/**
	 * Delete a found register, once the caller's rights and the object count allow it.
	 *
	 * @param Register $register The register.
	 * @param bool     $force    Whether the force flag was set.
	 *
	 * @return JSONResponse Empty on success, or the refusal.
	 *
	 * @throws Exception When counting or deleting fails; destroy() answers it.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The force flag comes from the request.
	 */
	private function destroyFound(Register $register, bool $force): JSONResponse {
		// Authorization: deleting a register requires manage permission
		// (manage-group membership, or administrator). Reading stays open.
		if ($this->checkRegisterManagePermission(register: $register) === false) {
			return new JSONResponse(data: ['error' => 'User does not have permission to manage this register'], statusCode: 403);
		}

		$refusal = $this->objectCountRefusal(register: $register, force: $force);
		if ($refusal !== null) {
			return $refusal;
		}

		$this->registerService->delete($register);

		// Drop the request-scoped find cache for the deleted register.
		$this->services->registers->registerCacheHandler->invalidate(registerId: $register->getId());

		return new JSONResponse(data: []);
	}//end destroyFound()

	/**
	 * Parse the ?force query parameter into a boolean.
	 *
	 * Accepts 'true', '1', or boolean true.
	 *
	 * @return bool
	 */
	private function parseForceParam(): bool {
		$forceParam = $this->request->getParam(key: 'force', default: null);
		return (string)$forceParam === 'true' || $forceParam === true || $forceParam === '1';
	}//end parseForceParam()

	/**
	 * Count objects attached to a register.
	 *
	 * Uses getStatistics() (single-axis registerId path) — countSearchObjects()
	 * only returns a real count when BOTH register AND schema are present in the
	 * $self filter, and silently returns 0 on single-axis queries.
	 *
	 * @param int $registerId Register id.
	 *
	 * @return int Total object count.
	 */
	private function countRegisterObjects(int $registerId): int {
		$stats = $this->services->mappers->objectEntityMapper->getStatistics(registerId: $registerId, schemaId: null);
		return (int)$stats['total'];
	}//end countRegisterObjects()

	/**
	 * Apply the delete-safety guard for the objects attached to a register.
	 *
	 * @param Register $register The register to be deleted.
	 * @param bool     $force    Whether the force flag was set.
	 *
	 * @return JSONResponse|null 409 when objects exist without force, or null to continue.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The force flag comes from the request.
	 */
	private function objectCountRefusal(Register $register, bool $force): ?JSONResponse {
		$objectCount = $this->countRegisterObjects(registerId: $register->getId());
		if ($objectCount === 0) {
			return null;
		}

		if ($force === false) {
			return new JSONResponse(data: ['error' => 'register-has-objects', 'objectCount' => $objectCount], statusCode: 409);
		}

		$this->logForcedDelete(register: $register, objectCount: $objectCount);

		return null;
	}//end objectCountRefusal()

	/**
	 * Log a force-delete at WARNING level so operators can review it.
	 *
	 * @param Register $register    The register to be deleted.
	 * @param int      $objectCount Number of objects attached to the register.
	 *
	 * @return void
	 */
	private function logForcedDelete(Register $register, int $objectCount): void {
		$this->logger->warning(
			message: '[RegistersController] Force-deleting register with attached objects',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'registerId' => $register->getId(),
				'registerSlug' => $register->getSlug(),
				'objectCount' => $objectCount,
			]
		);
	}//end logForcedDelete()

	/**
	 * Get schemas associated with a register
	 *
	 * This method returns all schemas that are associated with the specified register.
	 *
	 * @param int|string $id The ID, UUID, or slug of the register
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with schemas or error
	 *
	 * @spec openspec/specs/openapi-generation/spec.md#requirement-schema-authoring-sub-resources-and-meta-entity-operational-endpoints
	 */
	public function schemas(int|string $id): JSONResponse {
		try {
			// Find the register first to validate it exists and get its ID.
			$register = $this->registerService->find($id);
			$schemas  = $this->registerMapper->getSchemasByRegisterId($register->getId());
			$results  = array_map(fn ($schema) => $schema->jsonSerialize(), $schemas);
			return new JSONResponse(data: ['results' => $results, 'total' => count($results)]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Register not found'], statusCode: 404);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => 'Internal server error: ' . $e->getMessage()], statusCode: 500);
		}//end try
	}//end schemas()

	/**
	 * Export a register and its related data
	 *
	 * This method exports a register, its schemas, and optionally its objects
	 * in the specified format.
	 *
	 * @param int $id The ID of the register to export
	 *
	 * @return DataDownloadResponse|JSONResponse Download for `excel`/`csv`/`pdf`/`configuration` formats,
	 *                                           or a 400 JSON error for a missing schema (`csv`/`pdf`) or an over-cap `pdf` row count.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-10
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function export(int $id): JSONResponse|DataDownloadResponse {
		try {
			$format   = $this->request->getParam(key: 'format', default: 'configuration');
			$register = $this->registerService->find($id);
			return $this->exportAs(register: $register, format: $format);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => 'Failed to export register: ' . $e->getMessage()], statusCode: 400);
		}//end try
	}//end export()

	/**
	 * A register as a download in the asked format.
	 *
	 * @param Register $register The register.
	 * @param mixed    $format   The format the request asks for.
	 *
	 * @return JSONResponse|DataDownloadResponse The download, or the refusal.
	 *
	 * @throws Exception When the export fails; export() answers 400.
	 */
	private function exportAs(Register $register, mixed $format): JSONResponse|DataDownloadResponse {
		switch ($format) {
			case 'excel':
				return $this->downloads->excel(register: $register);
			case 'csv':
			case 'pdf':
				return $this->exportRegisterSchemaTable(register: $register, format: $format);
			case 'configuration':
			default:
				$includeObjects = filter_var($this->request->getParam(key: 'includeObjects', default: false), FILTER_VALIDATE_BOOLEAN);
				return $this->downloads->configuration(register: $register, includeObjects: $includeObjects);
		}
	}//end exportAs()

	/**
	 * One schema of a register as a CSV or PDF download.
	 *
	 * Both formats hold a single table, so they need one column set: a schema is required.
	 *
	 * @param Register $register The register.
	 * @param string   $format   'csv' or 'pdf'.
	 *
	 * @return JSONResponse|DataDownloadResponse The download, or 400 without a schema.
	 *
	 * @throws Exception When the schema cannot be found or the export fails; export() answers 400.
	 */
	private function exportRegisterSchemaTable(Register $register, string $format): JSONResponse|DataDownloadResponse {
		$schemaId = $this->request->getParam('schema');
		if ($schemaId === null || $schemaId === '') {
			$errMsg = strtoupper($format) . ' export requires a specific schema to be selected';
			return new JSONResponse(data: ['error' => $errMsg], statusCode: 400);
		}

		$schema = $this->schemaMapper->find($schemaId);
		if ($format === 'pdf') {
			return $this->downloads->pdf(register: $register, schema: $schema);
		}

		return $this->downloads->csv(register: $register, schema: $schema);
	}//end exportRegisterSchemaTable()

	/**
	 * Download an empty import template for a schema
	 *
	 * Returns a spreadsheet (XLSX by default, CSV when `format=csv` is supplied)
	 * containing only the schema's header row, so users can fill it in and
	 * upload it via the existing import endpoint. The resulting file uses the
	 * same column layout that `ExportService::exportToExcel()` would emit, so a
	 * round-trip export/import keeps headers stable.
	 *
	 * @param int|string $id The register slug or numeric id
	 * @param int|string $schema The schema slug or numeric id
	 *
	 * @return DataDownloadResponse|JSONResponse Spreadsheet on success, JSON error otherwise
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-10
	 */
	public function importTemplate(int|string $id, int|string $schema): JSONResponse|DataDownloadResponse {
		try {
			return $this->templateDownload(id: $id, schema: $schema);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Register or schema not found: ' . $e->getMessage()], statusCode: 404);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => 'Failed to generate import template: ' . $e->getMessage()], statusCode: 400);
		}//end try
	}//end importTemplate()

	/**
	 * The import template the request asks for.
	 *
	 * @param int|string $id     The register slug or numeric id.
	 * @param int|string $schema The schema slug or numeric id.
	 *
	 * @return JSONResponse|DataDownloadResponse The template, or 400 on an unsupported format.
	 *
	 * @throws DoesNotExistException When the register or schema does not exist.
	 * @throws Exception             When the template cannot be built.
	 */
	private function templateDownload(int|string $id, int|string $schema): JSONResponse|DataDownloadResponse {
		$format = strtolower((string)$this->request->getParam(key: 'format', default: 'xlsx'));
		if (in_array($format, ['xlsx', 'csv'], true) === false) {
			return new JSONResponse(data: ['error' => "Unsupported template format: {$format}. Supported formats: xlsx, csv."], statusCode: 400);
		}

		// RBAC is enforced inside both find() calls (default _rbac=true).
		$register = $this->registerMapper->find($id);
		$schemaEntity = $this->schemaMapper->find($schema);

		if ($format === 'csv') {
			return $this->downloads->templateCsv(register: $register, schema: $schemaEntity);
		}

		return $this->downloads->templateXlsx(register: $register, schema: $schemaEntity);
	}//end templateDownload()

	/**
	 * Publish register OAS specification to GitHub
	 *
	 * Exports the register as OpenAPI Specification and publishes it to a GitHub repository.
	 *
	 * @param int $id The ID of the register to publish
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with publish result or error
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-5
	 */
	public function publishToGitHub(int $id): JSONResponse {
		// Authorization: publishToGitHub uses the shared app-level
		// `github_api_token` to push to any repo that token can write. Restrict
		// to administrators (mirror the wave-1 #1949 admin-gate pattern and
		// the existing importFromGitHub gate). Ideally callers would use a
		// per-user token, but until that lands the endpoint must be admin-only.
		if ($this->isCurrentUserAdmin() === false) {
			return new JSONResponse(data: ['error' => 'Only administrators may publish registers to GitHub'], statusCode: 403);
		}

		try {
			return $this->gitHub->publish(register: $this->registerMapper->find($id));
		} catch (DoesNotExistException $e) {
			return $this->gitHub->registerMissing(id: $id);
		} catch (Exception $e) {
			return $this->gitHub->failure(error: $e);
		}
	}//end publishToGitHub()

	/**
	 * Import data into a register
	 *
	 * This method imports data into a register in the specified format and returns a detailed summary.
	 *
	 * @param int|string $id The ID, UUID or slug of the register to import into
	 * @param bool $force Force import even if the same or newer version already exists
	 *
	 * @return JSONResponse JSON response with import result or error
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Force flag to override version checks
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-10
	 */
	public function import(int|string $id, bool $force = false): JSONResponse {
		try {
			$uploadedFile = $this->request->getUploadedFile('file');
			if ($uploadedFile === null) {
				return new JSONResponse(data: ['error' => 'No file uploaded'], statusCode: 400);
			}

			return $this->importUpload(target: ['id' => $id, 'force' => $force, 'file' => $uploadedFile]);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
		}//end try
	}//end import()

	/**
	 * Hold an upload to the target register's rights, then import it.
	 *
	 * Importing can create schemas, registers and objects, so it is gated on manage
	 * permission for the target register (admin-only without a manage rule). A
	 * missing register defers its 404: an admin's JSON register bundle may
	 * auto-create it (REQ-IMP-AC-01).
	 *
	 * @param array{id: int|string, force: bool, file: array} $target The register asked for, the force flag and the upload.
	 *
	 * @return JSONResponse The import result or a refusal.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function importUpload(array $target): JSONResponse {
		$register = $this->findImportRegister(id: $target['id']);
		if ($register !== null && $this->checkRegisterManagePermission(register: $register) === false) {
			return new JSONResponse(data: ['error' => 'User does not have permission to manage this register'], statusCode: 403);
		}

		$type = $this->resolveImportType(uploadedFile: $target['file']);
		if ($this->isImportRegisterMissing(register: $register, type: $type) === true) {
			return new JSONResponse(data: ['error' => 'Register not found'], statusCode: 404);
		}

		return $this->importAllowedUpload(target: $target + ['type' => $type, 'register' => $register]);
	}//end importUpload()

	/**
	 * Read the import options and run an import the caller may make.
	 *
	 * @param array $target The upload, the force flag, the type and the register.
	 *
	 * @return JSONResponse The import result or a refusal.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function importAllowedUpload(array $target): JSONResponse {
		$options = $this->readImportOptions();
		if ($options instanceof JSONResponse) {
			return $options;
		}

		$options += $target;
		$this->logImportOptions(options: $options);

		$summary = $this->runImport(options: $options);
		if ($summary instanceof JSONResponse) {
			return $summary;
		}

		return $this->importSuccessResponse(summary: $summary);
	}//end importAllowedUpload()

	/**
	 * Find the register an import targets, or null when it does not exist yet.
	 *
	 * @param int|string $id The ID, UUID or slug of the register
	 *
	 * @return Register|null The register, or null when none matches
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function findImportRegister(int|string $id): ?Register {
		try {
			return $this->registerMapper->find($id);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end findImportRegister()

	/**
	 * Whether a missing register blocks the import.
	 *
	 * Only an admin's JSON import may run without a register, because a register
	 * bundle can create it; every other import needs an existing register.
	 *
	 * @param Register|null $register The target register, or null when missing
	 * @param mixed         $type     The resolved import type
	 *
	 * @return bool True when the import must answer 404
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function isImportRegisterMissing(?Register $register, mixed $type): bool {
		if ($register !== null) {
			return false;
		}

		return $type !== 'json' || $this->isCurrentUserAdmin() === false;
	}//end isImportRegisterMissing()

	/**
	 * Determine the import type from the `type` parameter or the uploaded file.
	 *
	 * Without a `type` parameter the extension decides: xlsx/xls is excel, csv is
	 * csv, and json is either an object import or a configuration bundle (see
	 * jsonImportType()). Anything else is a configuration import.
	 *
	 * @param array $uploadedFile The uploaded file entry
	 *
	 * @return mixed One of excel, csv, json or configuration, or the given type
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function resolveImportType(array $uploadedFile): mixed {
		$type = $this->request->getParam('type');
		if ($type !== null && $type !== '') {
			return $type;
		}

		$extension = strtolower(pathinfo($uploadedFile['name'] ?? '', PATHINFO_EXTENSION));
		$byExtension = ['xlsx' => 'excel', 'xls' => 'excel', 'csv' => 'csv'];
		if (isset($byExtension[$extension]) === true) {
			return $byExtension[$extension];
		}

		if ($extension === 'json') {
			return $this->jsonImportType(uploadedFile: $uploadedFile);
		}

		return 'configuration';
	}//end resolveImportType()

	/**
	 * Decide whether a .json upload is an object import or a configuration bundle.
	 *
	 * An object export from ExportService::exportToJson (a JSON list, or a
	 * `{ "results": [...] }` envelope) and a register bundle (`components.registers`,
	 * REQ-IMP-AC-01) go to the object importer, which auto-creates a bundle's
	 * register and schemas. Everything else stays on the configuration path.
	 *
	 * @param array $uploadedFile The uploaded file entry
	 *
	 * @return string Either json or configuration
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function jsonImportType(array $uploadedFile): string {
		$peek = json_decode($this->readableUpload(uploadedFile: $uploadedFile), true);
		if (is_array($peek) === true && $this->holdsObjectsOrRegisters(peek: $peek) === true) {
			return 'json';
		}

		return 'configuration';
	}//end jsonImportType()

	/**
	 * The content of an upload, empty when it cannot be read.
	 *
	 * @param array $uploadedFile The uploaded file entry
	 *
	 * @return string The content
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function readableUpload(array $uploadedFile): string {
		if (is_readable((string)($uploadedFile['tmp_name'] ?? '')) === false) {
			return '';
		}

		return (string)file_get_contents($uploadedFile['tmp_name']);
	}//end readableUpload()

	/**
	 * Whether decoded JSON is an object list, a results envelope or a register bundle.
	 *
	 * @param array $peek The decoded upload
	 *
	 * @return bool True when the object importer takes it
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function holdsObjectsOrRegisters(array $peek): bool {
		return array_is_list($peek) === true
			|| is_array($peek['results'] ?? null) === true
			|| is_array($peek['components']['registers'] ?? null) === true;
	}//end holdsObjectsOrRegisters()

	/**
	 * Read the import flags and the optional migration pack from the request.
	 *
	 * A `packId` resolves a stored MigrationPack by its slug; ImportService maps
	 * every row through it. `dryRun` maps and validates every row and saves nothing.
	 *
	 * @return array|JSONResponse The options, or a 400 response for an unknown pack
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function readImportOptions(): array|JSONResponse {
		$options = $this->importFlags() + ['pack' => null];

		$packId = $this->request->getParam('packId');
		if ($this->isBlank(value: $packId) === true) {
			return $options;
		}

		try {
			$options['pack'] = $this->services->registers->migrationPackService->findByPackSlug(packSlug: (string)$packId)->getDefinitionArray();
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Migration pack "' . $packId . '" not found'], statusCode: 400);
		}

		return $options;
	}//end readImportOptions()

	/**
	 * The import flags the request sets, each with its default.
	 *
	 * @return array<string, bool> The flags
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function importFlags(): array {
		$flags = [];
		foreach (self::IMPORT_FLAG_DEFAULTS as $name => $default) {
			$flags[$name] = $this->parseBooleanParam(paramName: $name, default: $default);
		}

		return $flags;
	}//end importFlags()

	/**
	 * Whether a request parameter is absent or empty.
	 *
	 * @param mixed $value The parameter
	 *
	 * @return bool True for null and the empty string
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function isBlank(mixed $value): bool {
		return $value === null || $value === '';
	}//end isBlank()

	/**
	 * Log the import options at debug level.
	 *
	 * @param array $options The import options
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function logImportOptions(array $options): void {
		$this->logger->debug(
			message: '[RegistersController] Import parameters received',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'includeObjects' => $options['includeObjects'],
				'validation' => $options['validation'],
				'events' => $options['events'],
				'publish' => $options['publish'],
				'registerId' => $options['id'],
			]
		);
	}//end logImportOptions()

	/**
	 * Run the import; when an administrator marked it historic, rows keep their own lifecycle state.
	 *
	 * Anybody but an administrator gets 403 for a historic import before any row
	 * is read. The import is not elevated: it runs as the administrator, with
	 * only the initial-state check on creates lifted.
	 *
	 * @param array $options The import options, including type, register, file and historic
	 *
	 * @return array|JSONResponse The import summary, or an error response
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) KeepLifecycleStatesScope is an ambient marker; a static call is the whole point of it.
	 *
	 * @spec openspec/changes/lifecycle-guards-see-the-stored-object/specs/object-lifecycle/spec.md#requirement-an-administrator-can-import-historic-data-in-its-own-states
	 */
	private function runImport(array $options): array|JSONResponse {
		if ($options['historic'] !== true) {
			return $this->runImporter(options: $options);
		}

		if ($this->isCurrentUserAdmin() === false) {
			return new JSONResponse(data: ['error' => 'Only an administrator can import historic data.'], statusCode: 403);
		}

		return KeepLifecycleStatesScope::run(fn (): array|JSONResponse => $this->runImporter(options: $options));
	}//end runImport()

	/**
	 * Run the importer that matches the import type.
	 *
	 * @param array $options The import options, including type, register and file
	 *
	 * @return array|JSONResponse The import summary, or an error response
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function runImporter(array $options): array|JSONResponse {
		switch ($options['type']) {
			case 'excel':
				return $this->importExcel(options: $options);
			case 'csv':
				return $this->importCsv(options: $options);
			case 'json':
				return $this->importJsonObjects(options: $options);
			default:
				return $this->importConfigurationBundle(options: $options);
		}
	}//end runImporter()

	/**
	 * The arguments every row importer (excel, csv, json) receives.
	 *
	 * SEC-CTRL-6: RBAC is derived from admin status, never from the request, and
	 * imports always stay tenant-scoped, so a manager cannot pass ?multi=false to
	 * write objects across organisation boundaries.
	 *
	 * @param array $options The import options
	 *
	 * @return array Named arguments for ImportService
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function rowImportArguments(array $options): array {
		return [
			'filePath' => $options['file']['tmp_name'],
			'register' => $options['register'],
			'validation' => $options['validation'],
			'events' => $options['events'],
			'_rbac' => ($this->isCurrentUserAdmin() === false),
			'_multitenancy' => true,
			'publish' => $options['publish'],
			'currentUser' => $this->userSession->getUser(),
			'enrich' => $options['enrich'],
		];
	}//end rowImportArguments()

	/**
	 * Import an Excel workbook; migration packs are not supported here.
	 *
	 * @param array $options The import options
	 *
	 * @return array|JSONResponse The sheet-based summary, or a 400 response
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function importExcel(array $options): array|JSONResponse {
		if ($options['pack'] !== null) {
			return new JSONResponse(
				data: ['error' => 'packId is not yet supported for Excel imports; use CSV or JSON with a migration pack instead.'],
				statusCode: 400
			);
		}

		return $this->importService->importFromExcel(...$this->rowImportArguments(options: $options), schema: null);
	}//end importExcel()

	/**
	 * Import a CSV file into the schema named by the required `schema` parameter.
	 *
	 * @param array $options The import options
	 *
	 * @return array|JSONResponse The sheet-based summary, or a 400 response
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If the schema does not exist
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If several schemas match
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function importCsv(array $options): array|JSONResponse {
		$schemaId = $this->request->getParam('schema');
		if ($this->isBlank(value: $schemaId) === true) {
			return new JSONResponse(data: ['error' => 'Schema parameter is required for CSV imports.'], statusCode: 400);
		}

		$schema = $this->schemaMapper->find($schemaId);

		return $this->importService->importFromCsv(
			...$this->rowImportArguments(options: $options),
			schema: $schema,
			pack: $options['pack'],
			dryRun: $options['dryRun']
		);
	}//end importCsv()

	/**
	 * Import a JSON object export (the inverse of ExportService::exportToJson).
	 *
	 * The schema comes from the `schema` parameter or from the first exported
	 * object's `@self.schema`. A register bundle (REQ-IMP-AC-01) carries its own
	 * schemas and needs neither.
	 *
	 * @param array $options The import options
	 *
	 * @return array|JSONResponse The summary, or a 400 response without a schema
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If the schema does not exist
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If several schemas match
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function importJsonObjects(array $options): array|JSONResponse {
		$peek = json_decode((string)file_get_contents($options['file']['tmp_name']), true);
		$schemaId = $this->jsonImportSchemaId(peek: $peek);
		if ($this->isBlank(value: $schemaId) === true && $this->isRegisterBundle(peek: $peek) === false) {
			return new JSONResponse(data: ['error' => 'Could not determine schema for JSON import; pass a schema parameter.'], statusCode: 400);
		}

		$schema = $this->jsonImportSchema(schemaId: $schemaId);

		return $this->importService->importFromJson(
			...$this->rowImportArguments(options: $options),
			schema: $schema,
			pack: $options['pack'],
			dryRun: $options['dryRun'],
			registerSlug: (string)$options['id']
		);
	}//end importJsonObjects()

	/**
	 * The schema a JSON import names: the parameter, else the first object's `@self.schema`.
	 *
	 * A register bundle carries its own schemas, so only the parameter counts for it.
	 *
	 * @param mixed $peek The decoded upload
	 *
	 * @return mixed The schema id, blank when there is none
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function jsonImportSchemaId(mixed $peek): mixed {
		$schemaId = $this->request->getParam('schema');
		if ($this->isBlank(value: $schemaId) === false || $this->isRegisterBundle(peek: $peek) === true) {
			return $schemaId;
		}

		$list = ($peek['results'] ?? $peek);

		return ($list[0]['@self']['schema'] ?? null);
	}//end jsonImportSchemaId()

	/**
	 * The schema entity of a JSON import, if it names one.
	 *
	 * @param mixed $schemaId The schema id, or blank
	 *
	 * @return Schema|null The schema
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If the schema does not exist
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If several schemas match
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function jsonImportSchema(mixed $schemaId): ?Schema {
		if ($this->isBlank(value: $schemaId) === true) {
			return null;
		}

		return $this->schemaMapper->find($schemaId);
	}//end jsonImportSchema()

	/**
	 * Whether decoded JSON is a register bundle with at least one register.
	 *
	 * @param mixed $peek The decoded upload
	 *
	 * @return bool True for a non-empty `components.registers` map
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function isRegisterBundle(mixed $peek): bool {
		return is_array($peek) === true
			&& array_is_list($peek) === false
			&& isset($peek['components']['registers']) === true
			&& is_array($peek['components']['registers']) === true
			&& count($peek['components']['registers']) > 0;
	}//end isRegisterBundle()

	/**
	 * Import a configuration bundle and summarise the objects it created.
	 *
	 * @param array $options The import options
	 *
	 * @return array|JSONResponse The summary, or an error response
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function importConfigurationBundle(array $options): array|JSONResponse {
		if ($options['pack'] !== null) {
			return new JSONResponse(data: ['error' => 'packId only applies to CSV/JSON row imports, not configuration bundle imports.'], statusCode: 400);
		}

		$bundle = $this->allowedBundle(options: $options);
		if ($bundle instanceof JSONResponse) {
			return $bundle;
		}

		// The importer falls back to the app id a bundle names; here the register decides.
		unset($bundle['appId']);

		return $this->storeBundle(options: $options, bundle: $bundle);
	}//end importConfigurationBundle()

	/**
	 * The uploaded bundle, when the caller may import it into this register.
	 *
	 * @param array $options The import options
	 *
	 * @return array|JSONResponse The bundle, or an error response
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function allowedBundle(array $options): array|JSONResponse {
		$bundle = $this->configurationService->getUploadedJson(
			data: $this->request->getParams(),
			uploadedFiles: [$options['file']]
		);
		if ($bundle instanceof JSONResponse) {
			return $bundle;
		}

		return ($this->bundleRefusal(register: $options['register'], bundle: $bundle) ?? $bundle);
	}//end allowedBundle()

	/**
	 * Import a bundle and summarise it; schemas it created join the register.
	 *
	 * @param array                $options The import options
	 * @param array<string, mixed> $bundle  The bundle
	 *
	 * @return array The summary
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function storeBundle(array $options, array $bundle): array {
		// ImportFromJson takes a Configuration entity; this endpoint has none to pass.
		$result = $this->configurationService->importFromJson(
			data: $bundle,
			configuration: null,
			owner: $this->userSession->getUser()?->getUID(),
			appId: $options['register']->getApplication(),
			version: $this->request->getParam('version'),
			force: $options['force']
		);

		$summary = $this->configurationImportSummary(result: $result, register: $options['register']);

		if (empty($result['registers']) === true) {
			$this->addCreatedSchemasToRegister(id: $options['id'], schemas: $result['schemas']);
		}

		return $summary;
	}//end storeBundle()

	/**
	 * Refuse a bundle that reaches outside the register for a caller who is not an administrator.
	 *
	 * @param Register             $register The register the bundle was uploaded to
	 * @param array<string, mixed> $bundle   The bundle
	 *
	 * @return JSONResponse|null A 403, or null when the import may go ahead
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-a-bundle-uploaded-by-a-register-manager-stays-inside-that-register
	 */
	private function bundleRefusal(Register $register, array $bundle): ?JSONResponse {
		if ($this->isCurrentUserAdmin() === true) {
			return null;
		}

		$reason = (new RegisterBundleBinding(schemaMapper: $this->schemaMapper))->refusalFor(register: $register, bundle: $bundle);
		if ($reason === null) {
			return null;
		}

		return new JSONResponse(data: ['error' => $reason], statusCode: 403);
	}//end bundleRefusal()

	/**
	 * Build the sheet-based summary of a configuration import.
	 *
	 * Every imported object is listed as created.
	 *
	 * @param array    $result   The configuration import result
	 * @param Register $register The register the import targeted
	 *
	 * @return array The summary under the `configuration` sheet
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function configurationImportSummary(array $result, Register $register): array {
		$created = [];
		if (is_array($result['objects'] ?? null) === true) {
			foreach ($result['objects'] as $object) {
				$created[] = $this->createdImportEntry(object: $object, register: $register);
			}
		}

		return ['configuration' => ['created' => $created, 'updated' => [], 'unchanged' => [], 'errors' => []]];
	}//end configurationImportSummary()

	/**
	 * The summary entry of an object a configuration import created.
	 *
	 * @param object   $object   The object
	 * @param Register $register The register the import targeted
	 *
	 * @return array The entry
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function createdImportEntry(object $object, Register $register): array {
		return [
			'id' => $object->getId(),
			'uuid' => $object->getUuid(),
			'sheet' => 'configuration',
			'register' => [
				'id' => $register->getId(),
				'name' => $register->getTitle(),
			],
			'schema' => null,
		];
	}//end createdImportEntry()

	/**
	 * Add the schemas a configuration import created to the target register.
	 *
	 * Used when the imported bundle defines no registers of its own.
	 *
	 * @param int|string $id      The ID, UUID or slug of the register
	 * @param array      $schemas The created schema entities
	 *
	 * @return void
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If the register does not exist
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If several registers match
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function addCreatedSchemasToRegister(int|string $id, array $schemas): void {
		$createdSchemas = [];
		foreach ($schemas as $schema) {
			$createdSchemas[] = $schema->getId();
		}

		$register = $this->registerService->find($id);
		$mergedSchemaArray = array_merge($register->getSchemas() ?? [], $createdSchemas);
		$register->setSchemas(array_keys(array_flip($mergedSchemaArray)));
		$this->registerService->updateFromArray(id: $id, data: $register->jsonSerialize());
	}//end addCreatedSchemasToRegister()

	/**
	 * Wrap an import summary in the success response.
	 *
	 * Row-level failures are attached as a base64 CSV so the frontend can offer a
	 * download without a second request.
	 *
	 * @param array $summary The import summary
	 *
	 * @return JSONResponse The success response
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function importSuccessResponse(array $summary): JSONResponse {
		$errorsCsv = $this->importService->serializeErrorsToCsv(summary: $summary);
		$responseData = [
			'message' => 'Import successful',
			'summary' => $summary,
		];
		if ($errorsCsv !== '') {
			$responseData['message'] = 'Import finished with errors';
			$responseData['errors_csv'] = base64_encode($errorsCsv);
			$responseData['errors_csv_filename'] = 'import_errors.csv';
		}

		return new JSONResponse(data: $responseData);
	}//end importSuccessResponse()

	/**
	 * Roll back an import by soft-deleting every object whose `create`
	 * audit row carries the given `importJobId`. Implements the
	 * rollback contract on the `data-import-export` change.
	 *
	 * @return JSONResponse Report with counts and per-object outcomes.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-10
	 */
	public function rollbackImport(): JSONResponse {
		$importJobId = $this->request->getParam('importJobId');
		if (is_string($importJobId) === false || $importJobId === '') {
			return new JSONResponse(data: ['error' => 'importJobId is required'], statusCode: 422);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
		}

		$refusal = ($this->appImportRefusal(importJobId: $importJobId) ?? $this->rollbackRightsRefusal(importJobId: $importJobId, user: $user));
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->softDeleteImport(importJobId: $importJobId);
	}//end rollbackImport()

	/**
	 * Refuse a job that loaded an app's example data.
	 *
	 * That data leaves through the app or through occ, never through this route:
	 * it spans archival and append-only schemas, and archival schemas refuse HTTP
	 * deletes.
	 *
	 * @param string $importJobId The import job.
	 *
	 * @return JSONResponse|null The refusal, or null when no app owns the job.
	 */
	private function appImportRefusal(string $importJobId): ?JSONResponse {
		$owningApp = $this->container->get(\OCA\OpenRegister\Service\Configuration\AppImportJobRecorder::class)
			->appForJob(importJobId: $importJobId);
		if ($owningApp === null) {
			return null;
		}

		$error = 'This import job loaded data for app %s. Remove it through that app, or with occ openregister:objects:purge --import-job %s.';
		return new JSONResponse(
			data: ['error' => sprintf($error, $owningApp, $importJobId), 'importJobId' => $importJobId, 'app' => $owningApp],
			statusCode: 409
		);
	}//end appImportRefusal()

	/**
	 * Refuse a caller who may not roll this job back, or a job that does not exist.
	 *
	 * SECURITY: rollback wipes every object created by an import job. The only
	 * safety net was that `deleteObject` runs RBAC, which is much weaker than it
	 * sounds — any user with broad delete rights on the affected schemas could
	 * otherwise wipe a *different* user's import (and across tenants, since the
	 * audit lookup doesn't filter by organisation). Require the caller to be
	 * either the original importer or a member of the admin group.
	 *
	 * @param string     $importJobId The import job.
	 * @param \OCP\IUser $user        The authenticated user.
	 *
	 * @return JSONResponse|null The refusal, or null when the caller may go on.
	 */
	private function rollbackRightsRefusal(string $importJobId, \OCP\IUser $user): ?JSONResponse {
		$auditSample = $this->auditTrailMapper->findByImportJobId(importJobId: $importJobId, action: 'create');
		if (count($auditSample) === 0) {
			return new JSONResponse(data: ['error' => 'Import job not found', 'importJobId' => $importJobId], statusCode: 404);
		}

		$importerUid = null;
		if (method_exists($auditSample[0], 'getUser') === true) {
			$importerUid = $auditSample[0]->getUser();
		}

		if ($this->canRollbackImport(user: $user, importerUid: $importerUid) === true) {
			return null;
		}

		$error = 'Forbidden: only the user who initiated the import or an admin may roll it back';
		return new JSONResponse(data: ['error' => $error], statusCode: 403);
	}//end rollbackRightsRefusal()

	/**
	 * Soft-delete what the import job created and report it.
	 *
	 * @param string $importJobId The import job.
	 *
	 * @return JSONResponse The report, or the error.
	 */
	private function softDeleteImport(string $importJobId): JSONResponse {
		try {
			$report = $this->importService->softDeleteByImportJobId(importJobId: $importJobId);
			return new JSONResponse(data: $report, statusCode: 200);
		} catch (\Throwable $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
		}
	}//end softDeleteImport()

	/**
	 * Get statistics for a specific register
	 *
	 * @param int $id The register ID
	 *
	 * @throws DoesNotExistException When the register is not found
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt Guarded downstream: RegisterServiceInterface::find loads the register via RegisterMapper::find(_multitenancy: true);
	 *   register metadata reads are open by design (only create/update/delete are admin-gated) and the response carries no
	 *   cross-object PII.
	 *
	 * @return JSONResponse The JSON response containing register statistics
	 *
	 * @psalm-return JSONResponse<
	 *     200|404|500,
	 *     array{
	 *         error?: string,
	 *         register?: array<string, mixed>,
	 *         message?: 'Stats calculation not yet implemented'
	 *     },
	 *     array<never, never>
	 * >
	 *
	 * `register` is deliberately `array<string, mixed>` and not a spelled-out
	 * shape. The previous annotation duplicated Register::jsonSerialize() field
	 * by field and had rotted into nonsense: it pinned `quota` to all-null and
	 * `usage` to all-literal-zero, because it was inferred from a register that
	 * had neither. The entity's serializer owns that contract; restating it here
	 * only guarantees the copy drifts again.
	 *
	 * @spec openspec/specs/production-observability/spec.md#requirement-per-entity-statistics-and-endpoint-delivery-log-api
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function stats(int $id): JSONResponse {
		try {
			// Get the register with stats.
			$register = $this->registerService->find($id);

			// Calculate statistics for this register.
			// Note: calculateStats method doesn't exist, using getStats or similar if available.
			// For now, return basic register info.
			$stats = [
				'register' => $register->jsonSerialize(),
				'message' => 'Stats calculation not yet implemented',
			];

			return new JSONResponse(data: $stats);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Register not found'], statusCode: 404);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
		}
	}//end stats()

	/**
	 * Parse boolean parameter from request with enhanced support for string values
	 *
	 * Supports both actual booleans and string representations:
	 * - true, "true", "1", "on", "yes" -> true
	 * - false, "false", "0", "off", "no", "" -> false
	 *
	 * @param string $paramName The parameter name to retrieve
	 * @param bool $default Default value if parameter is not present
	 *
	 * @return bool The parsed boolean value
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Default value is needed for parameter parsing
	 */
	private function parseBooleanParam(string $paramName, bool $default = false): bool {
		$value = $this->request->getParam(key: $paramName, default: $default);

		// If already boolean, return as-is.
		if (is_bool($value) === true) {
			return $value;
		}

		// Handle string values.
		if (is_string($value) === true) {
			$value = strtolower(trim($value));
			return in_array($value, ['true', '1', 'on', 'yes'], true);
		}

		// Handle numeric values.
		if (is_numeric($value) === true) {
			return (bool)$value;
		}

		// Fallback to default.
		return $default;
	}//end parseBooleanParam()

	/**
	 * Whether the current request has no resolved Nextcloud user (anonymous).
	 *
	 * Read endpoints are @PublicPage so they survive an app-group restriction;
	 * this lets the read-visibility guard distinguish anonymous callers (who may
	 * only see published resources) from authenticated users (unaffected).
	 *
	 * @return bool True if no user is signed in.
	 */
	private function isAnonymousRequest(): bool {
		return $this->userSession->getUser() === null;
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
	 * The audit-trail statistics of one register, for an administrator only.
	 *
	 * The count covers every entry of the register, whichever organisation the
	 * object belongs to, so a non-admin or anonymous caller is given zeros.
	 *
	 * @param int $registerId The register id.
	 *
	 * @return array The statistics, or zeros for a non-admin.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md#requirement-the-instance-wide-audit-reads-are-admin-only
	 */
	private function auditLogStats(int $registerId): array {
		if ($this->isCurrentUserAdmin() === false) {
			return ['total' => 0, 'size' => 0];
		}

		return $this->auditTrailMapper->getStatistics(registerId: $registerId, schemaId: null);
	}//end auditLogStats()

	/**
	 * Check whether the currently authenticated user is a Nextcloud administrator.
	 *
	 * Used to gate register creation, where there is no existing entity whose
	 * manage-authorization block could be consulted.
	 *
	 * @return bool True if a user is signed in and belongs to the admin group.
	 */
	private function isCurrentUserAdmin(): bool {
		return $this->session->isAdmin();
	}//end isCurrentUserAdmin()

	/**
	 * Check whether a user is authorized to roll back an import job.
	 *
	 * Returns true when the user is an admin or is the original importer.
	 * Extracted to keep the public `rollbackImport` body free of low-level
	 * isAdmin/uid comparisons (gate-9 false-positive avoidance).
	 *
	 * @param \OCP\IUser $user The authenticated user.
	 * @param string|null $importerUid The UID of the user who initiated the import.
	 *
	 * @return bool True when the user may execute the rollback.
	 */
	private function canRollbackImport(\OCP\IUser $user, ?string $importerUid): bool {
		if ($this->groupManager->isAdmin($user->getUID()) === true) {
			return true;
		}

		return $importerUid === $user->getUID();
	}//end canRollbackImport()

	/**
	 * Check if the current user has 'manage' permission on a register.
	 *
	 * Uses the register's own authorization block to check for the 'manage' action.
	 * Admin users always pass this check.
	 *
	 * @param Register $register The register to check manage permission for.
	 *
	 * @return bool True if user has manage permission.
	 */
	private function checkRegisterManagePermission(Register $register): bool {
		try {
			$rights = new DefinitionWriteRights(
				userSession: $this->userSession,
				groupManager: $this->container->get(\OCP\IGroupManager::class)
			);

			return $rights->mayManage(authorization: $register->getAuthorization());
		} catch (\Throwable $e) {
			return false;
		}
	}//end checkRegisterManagePermission()
}//end class
