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

use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\RegisterService;
use OCA\OpenRegister\Service\UploadService;
use Exception;
use RuntimeException;
use DateTime;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\Configuration\GitHubHandler;
use OCA\OpenRegister\Service\OasService;
use OCA\OpenRegister\Service\Registers\RegisterCacheHandler;
use OCA\OpenRegister\Service\Serializer\RegisterSerializer;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\DB\Exception as DBException;
use OCP\IUserSession;
use OCA\OpenRegister\Exception\DatabaseConstraintException;
use OCA\OpenRegister\Service\AuthorizationAuditService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IGroupManager;
use OCP\IRequest;
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
 * @suppressWarnings(PHPMD.ExcessiveClassLength)     NC REST controller must expose all CRUD + subresource
 *   endpoints (registers, schemas, objects, statistics) in one class per NC AppFramework routing;
 *   splitting into multiple controllers would require additional routing registration.
 * @suppressWarnings(PHPMD.ExcessiveClassComplexity) Aggregate complexity from N independent REST actions;
 *   each action is individually simple — the class total is a routing artifact, not design debt.
 * @suppressWarnings(PHPMD.TooManyPublicMethods)     Each public method maps to one REST endpoint; NC AppFramework
 *   requires public methods for route dispatch — they cannot be made protected/private.
 * @suppressWarnings(PHPMD.CouplingBetweenObjects)   NC Controller DI injects AppFramework, RBAC, audit, domain
 *   services, and mappers — each dep is used and cannot be combined without violating SRP.
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)    The create/update actions include multi-step validation
 *   that is a single atomic write; extracting sub-steps would create misleading partial-update helpers.
 *
 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-registry-views/tasks.md#task-1
 */
class RegistersController extends Controller
{

    /**
     * Configuration service for handling import/export operations
     *
     * @var ConfigurationService
     */
    private readonly ConfigurationService $configurationService;

    /**
     * Audit trail mapper for fetching log statistics
     *
     * @var AuditTrailMapper
     */
    private readonly AuditTrailMapper $auditTrailMapper;

    /**
     * Export service for handling data exports
     *
     * @var ExportService
     */
    private readonly ExportService $exportService;

    /**
     * Import service for handling data imports
     *
     * @var ImportService
     */
    private readonly ImportService $importService;

    /**
     * Schema mapper for handling schema operations
     *
     * @var SchemaMapper
     */
    private readonly SchemaMapper $schemaMapper;

    /**
     * Register mapper for handling register operations
     *
     * @var RegisterMapper
     */
    private readonly RegisterMapper $registerMapper;

    /**
     * GitHub service for publishing to GitHub
     *
     * @var GitHubHandler
     */
    private readonly GitHubHandler $githubService;

    /**
     * App manager for getting app version
     *
     * @var IAppManager
     */
    private readonly IAppManager $appManager;

    /**
     * OAS service for generating OpenAPI specifications
     *
     * @var OasService
     */
    private readonly OasService $oasService;

    /**
     * Constructor
     *
     * Initializes controller with required dependencies for register operations.
     * Calls parent constructor to set up base controller functionality.
     *
     * @param string               $appName              Application name
     * @param IRequest             $request              HTTP request object
     * @param RegisterService      $registerService      Register service for business logic
     * @param MagicMapper          $objectEntityMapper   Object entity mapper for database operations
     * @param UploadService        $uploadService        Upload service for file uploads
     * @param LoggerInterface      $logger               Logger for error tracking
     * @param IUserSession         $userSession          User session service
     * @param ConfigurationService $configurationService Configuration service for import/export
     * @param AuditTrailMapper     $auditTrailMapper     Audit trail mapper for log statistics
     * @param ExportService        $exportService        Export service for data exports
     * @param ImportService        $importService        Import service for data imports
     * @param SchemaMapper         $schemaMapper         Schema mapper for schema operations
     * @param RegisterMapper       $registerMapper       Register mapper for database operations
     * @param GitHubHandler        $githubService        GitHub service for publishing
     * @param IAppManager          $appManager           App manager for app version
     * @param OasService           $oasService           OAS service for OpenAPI generation
     * @param ContainerInterface   $container            Container for lazy loading services
     * @param IGroupManager        $groupManager         Group manager for RBAC checks
     * @param RegisterCacheHandler $registerCacheHandler Register cache handler (runtime-schema-api)
     *
     * @return void
     *
     * @suppressWarnings(PHPMD.ExcessiveParameterList) Nextcloud DI requires constructor injection
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly RegisterService $registerService,
        private readonly MagicMapper $objectEntityMapper,
        private readonly UploadService $uploadService,
        private readonly LoggerInterface $logger,
        private readonly IUserSession $userSession,
        ConfigurationService $configurationService,
        AuditTrailMapper $auditTrailMapper,
        ExportService $exportService,
        ImportService $importService,
        SchemaMapper $schemaMapper,
        RegisterMapper $registerMapper,
        GitHubHandler $githubService,
        IAppManager $appManager,
        OasService $oasService,
        private readonly ContainerInterface $container,
        private readonly IGroupManager $groupManager,
        private readonly RegisterCacheHandler $registerCacheHandler,
        private readonly RegisterSerializer $registerSerializer
    ) {
        $this->logger->debug(
            message: '[RegistersController] Constructor started.',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );
        parent::__construct(appName: $appName, request: $request);
        $this->logger->debug(
            message: '[RegistersController] Parent constructor called.',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );
        $this->configurationService = $configurationService;
        $this->logger->debug(
            message: '[RegistersController] ConfigurationService assigned.',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );
        $this->auditTrailMapper = $auditTrailMapper;
        $this->exportService    = $exportService;
        $this->importService    = $importService;
        $this->schemaMapper     = $schemaMapper;
        $this->registerMapper   = $registerMapper;
        $this->githubService    = $githubService;
        $this->appManager       = $appManager;
        $this->oasService       = $oasService;
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
     * @suppressWarnings(PHPMD.NPathComplexity)      Complex request parameter handling for flexible API
     * @suppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @spec openspec/changes/register-schema-read-accessibility/tasks.md#task-1
     */
    public function index(): JSONResponse
    {
        // Get request parameters for filtering and searching.
        $params = $this->request->getParams();

        // Extract pagination and search parameters.
        $limit = null;
        if (isset($params['_limit']) === true) {
            $limit = (int) $params['_limit'];
        }

        $offset = null;
        if (isset($params['_offset']) === true) {
            $offset = (int) $params['_offset'];
        }

        $page = null;
        if (isset($params['_page']) === true) {
            $page = (int) $params['_page'];
        }

        // Note: search parameter not currently used in this endpoint.
        $extend = $params['_extend'] ?? [];
        if (is_string($extend) === true) {
            $extend = [$extend];
        }

        // Convert page to offset if provided.
        if ($page !== null && $limit !== null) {
            $offset = ($page - 1) * $limit;
        }

        // Extract filters.
        $filters = $params['filters'] ?? [];

        // Fetch entities so the anonymous-published guard can filter
        // them before serialization. The serialized output is produced
        // by RegisterService::findAllSerialized below (shared between
        // HTTP and DI consumers — see register-service-extensions spec).
        $registers = $this->registerService->findAll(
            limit: $limit,
            offset: $offset,
            filters: $filters,
            searchConditions: [],
            searchParams: [],
            _multitenancy: false
        );

        // Read-visibility guard: this endpoint is @PublicPage so it stays
        // reachable when OpenRegister is restricted to a user group. Anonymous
        // callers may only see PUBLISHED registers; authenticated users are
        // unaffected. Visibility is derived from server-side published/
        // depublished entity state, never from client-supplied parameters.
        if ($this->isAnonymousRequest() === true) {
            $registers = array_values(
                array_filter(
                    $registers,
                    fn($register) => $this->isPublishedEntity(
                        published: $register->getPublished(),
                        depublished: $register->getDepublished()
                    )
                )
            );
        }

        // Pre-compute per-register stats if both `schemas` + `@self.stats`
        // were requested, then hand the resulting entity list to the
        // serializer in a single call. RegisterSerializer owns the
        // schema-expansion + orphan-ID retention contract.
        $statsByRegisterId = null;
        if (in_array('schemas', $extend, true) === true
            && in_array('@self.stats', $extend, true) === true
        ) {
            $statsByRegisterId = [];
            foreach ($registers as $register) {
                $expandedSchemas = [];
                foreach (($register->getSchemas() ?? []) as $schemaId) {
                    try {
                        $expandedSchemas[] = $this->schemaMapper->find(id: $schemaId, _multitenancy: false);
                    } catch (DoesNotExistException $e) {
                        // Orphan IDs cannot contribute to stats; skip.
                        continue;
                    }
                }

                $statsByRegisterId[(int) $register->getId()] = $this->registerService->getSchemaObjectCounts(
                    registerId: (int) $register->getId(),
                    schemas: $expandedSchemas
                );
            }
        }

        $registersArr = $this->registerSerializer->serializeMany($registers, $extend, $statsByRegisterId);

        // If '@self.stats' is requested, attach register-level statistics
        // alongside the schema-level stats already produced by the
        // serializer. Register-level stats remain a controller concern
        // because they depend on multiple mappers (object-entity +
        // audit-trail) the serializer does not have access to.
        if (in_array('@self.stats', $extend, true) === true) {
            foreach ($registersArr as &$register) {
                $register['stats'] = [
                    'objects' => $this->objectEntityMapper->getStatistics(registerId: $register['id'], schemaId: null),
                    'logs'    => $this->auditTrailMapper->getStatistics(registerId: $register['id'], schemaId: null),
                    'files'   => [ 'total' => 0, 'size' => 0 ],
                ];
            }

            unset($register);
        }

        return new JSONResponse(data: ['results' => $registersArr]);
    }//end index()

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
    public function show($id): JSONResponse
    {
        $extend = $this->request->getParam(key: '_extend', default: []);
        if (is_string($extend) === true) {
            $extend = [$extend];
        }

        $register = $this->registerService->find(id: $id, _extend: [], _multitenancy: false);

        // Read-visibility guard (@PublicPage): an anonymous caller may only view
        // a PUBLISHED register. Derived from server-side published/depublished
        // entity state, never from client-supplied parameters.
        if ($this->isAnonymousRequest() === true
            && $this->isPublishedEntity(
                published: $register->getPublished(),
                depublished: $register->getDepublished()
            ) === false
        ) {
            return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
        }

        $registerArr = $register->jsonSerialize();
        // If '@self.stats' is requested, attach statistics to the register.
        if (in_array('@self.stats', $extend, true) === true) {
            $registerArr['stats'] = [
                'objects' => $this->objectEntityMapper->getStatistics(registerId: $registerArr['id'], schemaId: null),
                'logs'    => $this->auditTrailMapper->getStatistics(registerId: $registerArr['id'], schemaId: null),
                'files'   => [ 'total' => 0, 'size' => 0 ],
            ];
        }

        return new JSONResponse(data: $registerArr);
    }//end show()

    /**
     * Creates a new register
     *
     * This method creates a new register based on POST data.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @suppressWarnings(PHPMD.StaticAccess) DatabaseConstraintException factory method is standard pattern
     *
     * @return JSONResponse JSON response with created register or error
     *
     * @psalm-return JSONResponse<201, Register,
     *     array<never, never>>|JSONResponse<403|409|500, array{error: string},
     *     array<never, never>>
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
     */
    public function create(): JSONResponse
    {
        // Authorization: creating a register defines a new data model and is
        // restricted to administrators. Reading register metadata stays open so
        // frontends can build their UIs; only create/update/delete are gated.
        if ($this->isCurrentUserAdmin() === false) {
            return new JSONResponse(
                data: ['error' => 'Only administrators may create registers'],
                statusCode: 403
            );
        }

        // Get request parameters.
        $data = $this->request->getParams();

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

        try {
            // Create a new register from the data.
            $register = $this->registerService->createFromArray($data);

            // **CACHE INVALIDATION (runtime-schema-api)**: Drop the request-scoped
            // find cache for the freshly-created ID so any follow-up read in the
            // same PHP worker observes the new register.
            $this->registerCacheHandler->invalidate(registerId: $register->getId());

            return new JSONResponse(data: $register, statusCode: 201);
        } catch (DBException $e) {
            // Handle database constraint violations with user-friendly messages.
            $constraintException = DatabaseConstraintException::fromDatabaseException(
                dbException: $e,
                entityType: 'register'
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
        } catch (\Exception $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500
            );
        }//end try
    }//end create()

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
     * @SuppressWarnings(PHPMD.StaticAccess)         Uses \OCA\OpenRegister\Exception\ValidationException
     *   as a static reference; no injectable factory exists in NC AppFramework for exception types.
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) The update action must validate each editable
     *   field individually (authorization, schemas, config) — extracting branches into separate
     *   methods would scatter what is a single transactional write operation.
     *
     * @return JSONResponse JSON response with updated register or error
     *
     * @psalm-return JSONResponse<200, Register,
     *     array<never, never>>|JSONResponse<403|404|409, array{error: string},
     *     array<never, never>>
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
     */
    public function update(int $id): JSONResponse
    {
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

        // Authorization: modifying a register's definition requires manage
        // permission (manage-group membership, or administrator when no manage
        // rules are configured). This gates ALL updates, not just authorization
        // changes — reading register metadata stays open for frontends.
        try {
            $existingRegister = $this->registerMapper->find($id);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Register not found'], statusCode: 404);
        }

        if ($this->checkRegisterManagePermission(register: $existingRegister) === false) {
            return new JSONResponse(
                data: ['error' => 'User does not have permission to manage this register'],
                statusCode: 403
            );
        }

        // Capture prior authorization / roles so changes can be audit-logged below.
        $oldRegisterAuth  = $existingRegister->getAuthorization();
        $oldConfig        = $existingRegister->getConfiguration();
        $oldRegisterRoles = $oldConfig['roles'] ?? null;

        try {
            // Update the register with the provided data.
            $updatedRegister = $this->registerService->updateFromArray(id: $id, data: $data);

            // **CACHE INVALIDATION (runtime-schema-api)**: Drop the request-scoped
            // find cache for the updated ID so any follow-up read in the same PHP
            // worker observes the new register state (e.g. an added schema in schemas[]).
            $this->registerCacheHandler->invalidate(registerId: $updatedRegister->getId());

            // Log authorization and role changes.
            try {
                $auditService = $this->container->get(AuthorizationAuditService::class);

                if (isset($data['authorization']) === true) {
                    $auditService->logRegisterAuthorizationChange(
                        $id,
                        $updatedRegister->getTitle() ?? '',
                        $oldRegisterAuth,
                        $updatedRegister->getAuthorization()
                    );
                }

                if (isset($data['configuration']['roles']) === true) {
                    $configuration = $updatedRegister->getConfiguration();
                    $auditService->logRoleDefinitionChange(
                        $id,
                        $updatedRegister->getTitle() ?? '',
                        $oldRegisterRoles,
                        $configuration['roles'] ?? null
                    );
                }
            } catch (\Throwable $e) {
                // Audit logging should not break the update operation.
            }//end try

            return new JSONResponse(data: $updatedRegister);
        } catch (DBException $e) {
            // Handle database constraint violations with user-friendly messages.
            $constraintException = DatabaseConstraintException::fromDatabaseException(
                dbException: $e,
                entityType: 'register'
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
        }//end try
    }//end update()

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
    public function patch(int $id): JSONResponse
    {
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
    public function destroy(int $id): JSONResponse
    {
        $force = $this->parseForceParam();

        try {
            // Find the register first (validates existence + access).
            $register = $this->registerService->find($id);

            // Authorization: deleting a register requires manage permission
            // (manage-group membership, or administrator). Reading stays open.
            if ($this->checkRegisterManagePermission(register: $register) === false) {
                return new JSONResponse(
                    data: ['error' => 'User does not have permission to manage this register'],
                    statusCode: 403
                );
            }

            // Count objects and apply the delete-safety guard.
            $objectCount   = $this->countRegisterObjects(registerId: $register->getId());
            $guardResponse = $this->handleObjectCountGuard(
                register: $register,
                objectCount: $objectCount,
                force: $force
            );
            if ($guardResponse !== null) {
                return $guardResponse;
            }

            $this->registerService->delete($register);

            // **CACHE INVALIDATION (runtime-schema-api)**: Drop request-scoped
            // find cache for the deleted register.
            $this->registerCacheHandler->invalidate(registerId: $register->getId());

            // Return an empty response.
            return new JSONResponse(data: []);
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
     * Parse the ?force query parameter into a boolean.
     *
     * Accepts 'true', '1', or boolean true.
     *
     * @return bool
     */
    private function parseForceParam(): bool
    {
        $forceParam = $this->request->getParam(key: 'force', default: null);
        return (string) $forceParam === 'true' || $forceParam === true || $forceParam === '1';
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
    private function countRegisterObjects(int $registerId): int
    {
        $stats = $this->objectEntityMapper->getStatistics(registerId: $registerId, schemaId: null);
        return (int) $stats['total'];
    }//end countRegisterObjects()

    /**
     * Apply the delete-safety guard for object count.
     *
     * Returns a JSONResponse (409) when objects exist and force is false.
     * Logs a WARNING when force-deleting with objects present.
     * Returns null when the delete may proceed.
     *
     * @param Register $register    The register to be deleted.
     * @param int      $objectCount Number of objects attached to the register.
     * @param bool     $force       Whether the force flag was set.
     *
     * @return JSONResponse|null 409 response to abort, or null to continue.
     */
    private function handleObjectCountGuard(Register $register, int $objectCount, bool $force): ?JSONResponse
    {
        if ($objectCount === 0) {
            return null;
        }

        if ($force === false) {
            return new JSONResponse(
                data: [
                    'error'       => 'register-has-objects',
                    'objectCount' => $objectCount,
                ],
                statusCode: 409
            );
        }

        // Force-delete with audit at WARNING level so operators can review.
        $this->logger->warning(
            message: '[RegistersController] Force-deleting register with attached objects',
            context: [
                'file'         => __FILE__,
                'line'         => __LINE__,
                'registerId'   => $register->getId(),
                'registerSlug' => $register->getSlug(),
                'objectCount'  => $objectCount,
            ]
        );

        return null;
    }//end handleObjectCountGuard()

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
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-4
     */
    public function schemas(int|string $id): JSONResponse
    {
        try {
            // Find the register first to validate it exists and get its ID.
            $register   = $this->registerService->find($id);
            $registerId = $register->getId();

            // Get the schemas associated with this register.
            $schemas = $this->registerMapper->getSchemasByRegisterId($registerId);

            // Convert schemas to array format for JSON response.
            $schemasArray = array_map(fn($schema) => $schema->jsonSerialize(), $schemas);

            return new JSONResponse(
                data: [
                    'results' => $schemasArray,
                    'total'   => count($schemasArray),
                ]
            );
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            // Return a 404 error if the register doesn't exist.
            return new JSONResponse(data: ['error' => 'Register not found'], statusCode: 404);
        } catch (Exception $e) {
            // Return a 500 error for other exceptions.
            return new JSONResponse(data: ['error' => 'Internal server error: '.$e->getMessage()], statusCode: 500);
        }//end try
    }//end schemas()

    /**
     * Get objects
     *
     * Get all the objects for a register and schema
     *
     * @param int $register The ID of the register
     * @param int $schema   The ID of the schema
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with objects
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-4
     */
    public function objects(int $register, int $schema): JSONResponse
    {
        // Find objects by register and schema IDs.
        $query = [
            '@self' => [
                'register' => $register,
                'schema'   => $schema,
            ],
        ];
        return new JSONResponse(
            data: $this->objectEntityMapper->searchObjects(query: $query)
        );
    }//end objects()

    /**
     * Export a register and its related data
     *
     * This method exports a register, its schemas, and optionally its objects
     * in the specified format.
     *
     * @param int $id The ID of the register to export
     *
     * @return DataDownloadResponse|JSONResponse
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-10
     */
    public function export(int $id): JSONResponse|DataDownloadResponse
    {
        try {
            // Get export format from query parameter.
            $format          = $this->request->getParam(key: 'format', default: 'configuration');
            $includeObjParam = $this->request->getParam(key: 'includeObjects', default: false);
            $includeObjects  = filter_var($includeObjParam, FILTER_VALIDATE_BOOLEAN);
            $register        = $this->registerService->find($id);

            switch ($format) {
                case 'excel':
                    $spreadsheet = $this->exportService->exportToExcel(
                        register: $register,
                        schema: null,
                        filters: [],
                        currentUser: $this->userSession->getUser()
                    );
                    $writer      = new Xlsx($spreadsheet);
                    $slug        = $register->getSlug() ?? 'register';
                    $date        = (new DateTime())->format('Y-m-d_His');
                    $filename    = sprintf('%s_%s.xlsx', $slug, $date);
                    ob_start();
                    $writer->save('php://output');
                    $content = ob_get_clean();
                    $mime    = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
                    return new DataDownloadResponse($content, $filename, $mime);
                case 'csv':
                    // CSV exports require a specific schema.
                    $schemaId = $this->request->getParam('schema');

                    if ($schemaId === null || $schemaId === '') {
                        // If no schema specified, return error (CSV cannot handle multiple schemas).
                        $errMsg = 'CSV export requires a specific schema to be selected';
                        return new JSONResponse(data: ['error' => $errMsg], statusCode: 400);
                    }

                    $schema   = $this->schemaMapper->find($schemaId);
                    $csv      = $this->exportService->exportToCsv(
                        register: $register,
                        schema: $schema,
                        filters: [],
                        currentUser: $this->userSession->getUser()
                    );
                    $filename = sprintf(
                        '%s_%s_%s.csv',
                        $register->getSlug() ?? 'register',
                        $schema->getSlug() ?? 'schema',
                        (new DateTime())->format('Y-m-d_His')
                    );
                    return new DataDownloadResponse($csv, $filename, 'text/csv');
                case 'configuration':
                default:
                    $exportData  = $this->configurationService->exportConfig(
                        input: $register,
                        includeObjects: $includeObjects
                    );
                    $jsonContent = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    if ($jsonContent === false) {
                        throw new Exception('Failed to encode register data to JSON');
                    }

                    $slug     = $register->getSlug() ?? 'register';
                    $date     = (new DateTime())->format('Y-m-d_His');
                    $filename = sprintf('%s_%s.json', $slug, $date);
                    return new DataDownloadResponse($jsonContent, $filename, 'application/json');
            }//end switch
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => 'Failed to export register: '.$e->getMessage()], statusCode: 400);
        }//end try
    }//end export()

    /**
     * Download an empty import template for a schema
     *
     * Returns a spreadsheet (XLSX by default, CSV when `format=csv` is supplied)
     * containing only the schema's header row, so users can fill it in and
     * upload it via the existing import endpoint. The resulting file uses the
     * same column layout that `ExportService::exportToExcel()` would emit, so a
     * round-trip export/import keeps headers stable.
     *
     * @param int|string $id     The register slug or numeric id
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
    public function importTemplate(int|string $id, int|string $schema): JSONResponse|DataDownloadResponse
    {
        try {
            $format = strtolower((string) $this->request->getParam(key: 'format', default: 'xlsx'));
            if (in_array($format, ['xlsx', 'csv'], true) === false) {
                return new JSONResponse(
                    data: ['error' => 'Unsupported template format: '.$format.'. Supported formats: xlsx, csv.'],
                    statusCode: 400
                );
            }

            // RBAC is enforced inside both find() calls (default _rbac=true).
            $register     = $this->registerMapper->find($id);
            $schemaEntity = $this->schemaMapper->find($schema);

            $schemaSlug  = $schemaEntity->getSlug() ?? 'schema';
            $currentUser = $this->userSession->getUser();

            if ($format === 'csv') {
                $content  = $this->exportService->buildTemplateCsv(
                    register: $register,
                    schema: $schemaEntity,
                    currentUser: $currentUser
                );
                $filename = sprintf('%s_template.csv', $schemaSlug);
                return new DataDownloadResponse($content, $filename, 'text/csv');
            }

            $spreadsheet = $this->exportService->buildTemplateSpreadsheet(
                register: $register,
                schema: $schemaEntity,
                currentUser: $currentUser
            );
            $writer      = new Xlsx($spreadsheet);
            ob_start();
            $writer->save('php://output');
            $content  = ob_get_clean();
            $filename = sprintf('%s_template.xlsx', $schemaSlug);
            $mime     = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            return new DataDownloadResponse($content, $filename, $mime);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: ['error' => 'Register or schema not found: '.$e->getMessage()],
                statusCode: 404
            );
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['error' => 'Failed to generate import template: '.$e->getMessage()],
                statusCode: 400
            );
        }//end try
    }//end importTemplate()

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
     * @suppressWarnings(PHPMD.NPathComplexity)       GitHub publishing requires many conditional checks
     * @suppressWarnings(PHPMD.ExcessiveMethodLength)
     * @suppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-5
     */
    public function publishToGitHub(int $id): JSONResponse
    {
        // Authorization: publishToGitHub uses the shared app-level
        // `github_api_token` to push to any repo that token can write. Restrict
        // to administrators (mirror the wave-1 #1949 admin-gate pattern and
        // the existing importFromGitHub gate). Ideally callers would use a
        // per-user token, but until that lands the endpoint must be admin-only.
        if ($this->isCurrentUserAdmin() === false) {
            return new JSONResponse(
                data: ['error' => 'Only administrators may publish registers to GitHub'],
                statusCode: 403
            );
        }

        try {
            $register = $this->registerMapper->find($id);

            $data          = $this->request->getParams();
            $owner         = $data['owner'] ?? '';
            $repo          = $data['repo'] ?? '';
            $path          = $data['path'] ?? '';
            $branch        = $data['branch'] ?? 'main';
            $commitMessage = $data['commitMessage'] ?? "Update register OAS: {$register->getTitle()}";

            if (empty($owner) === true || empty($repo) === true) {
                return new JSONResponse(data: ['error' => 'Owner and repo parameters are required'], statusCode: 400);
            }

            // Strip leading slash from path.
            $path = ltrim($path, '/');

            // If path is empty, use a default filename based on register slug.
            if (empty($path) === true) {
                $slug = $register->getSlug() ?? 'register';
                $path = $slug.'_openregister.json';
            }

            $this->logger->info(
                message: '[RegistersController] Publishing register OAS to GitHub',
                context: [
                    'file'          => __FILE__,
                    'line'          => __LINE__,
                    'register_id'   => $id,
                    'register_slug' => $register->getSlug(),
                    'owner'         => $owner,
                    'repo'          => $repo,
                    'path'          => $path,
                    'branch'        => $branch,
                ]
            );

            // Generate real OAS (OpenAPI Specification) for the register.
            // Do NOT add x-openregister metadata - this is a pure OAS file, not a configuration file.
            $oasData = $this->oasService->createOas((string) $register->getId());

            $jsonContent = json_encode($oasData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            // Check if file already exists (for updates).
            $fileSha = null;
            try {
                $fileSha = $this->githubService->getFileSha(owner: $owner, repo: $repo, path: $path, branch: $branch);
            } catch (Exception $e) {
                // File doesn't exist, which is fine for new files.
                $this->logger->debug(
                    message: '[RegistersController] File does not exist, will create new file',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'path' => $path]
                );
            }

            // Publish to GitHub.
            $result = $this->githubService->publishConfiguration(
                owner: $owner,
                repo: $repo,
                path: $path,
                branch: $branch,
                content: $jsonContent,
                commitMessage: $commitMessage,
                fileSha: $fileSha
            );

            $this->logger->info(
                message: "[RegistersController] Successfully published register OAS {$register->getTitle()} to GitHub",
                context: [
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'owner'    => $owner,
                    'repo'     => $repo,
                    'branch'   => $branch,
                    'path'     => $path,
                    'file_url' => $result['file_url'] ?? null,
                ]
            );

            // Check if published to default branch (required for Code Search indexing).
            $defaultBranch = null;
            try {
                $repoInfo      = $this->githubService->getRepositoryInfo(owner: $owner, repo: $repo);
                $defaultBranch = $repoInfo['default_branch'] ?? 'main';
            } catch (Exception $e) {
                $this->logger->warning(
                    message: '[RegistersController] Could not fetch repository default branch',
                    context: [
                        'file'  => __FILE__,
                        'line'  => __LINE__,
                        'owner' => $owner,
                        'repo'  => $repo,
                        'error' => $e->getMessage(),
                    ]
                );
            }

            $message = 'Register OAS published successfully to GitHub';
            if (($defaultBranch !== null && $defaultBranch !== '') === true && $branch !== $defaultBranch) {
                $searchNote = 'GitHub Code Search primarily indexes the default branch.';
                $delayNote  = 'This may not appear in search results immediately.';
                $branchNote = "Note: Published to branch '{$branch}' (default is '{$defaultBranch}').";
                $message   .= ". {$branchNote} {$searchNote} {$delayNote}";
            }

            if (($defaultBranch === null || $defaultBranch === '') === true || $branch === $defaultBranch) {
                $message .= ". Note: GitHub Code Search may take a few minutes to index new files.";
            }

            // Determine indexing note.
            $indexingNote = "File published successfully. GitHub Code Search indexing may take a few minutes.";
            if (($defaultBranch !== null) === true && $branch !== $defaultBranch) {
                $indexingNote = "Published to non-default branch. For discovery, publish to '{$defaultBranch}' branch.";
            }

            return new JSONResponse(
                data: [
                    'success'        => true,
                    'message'        => $message,
                    'registerId'     => $register->getId(),
                    'commit_sha'     => $result['commit_sha'],
                    'commit_url'     => $result['commit_url'],
                    'file_url'       => $result['file_url'],
                    'branch'         => $branch,
                    'default_branch' => $defaultBranch,
                    'indexing_note'  => $indexingNote,
                ],
                statusCode: 200
            );
        } catch (DoesNotExistException $e) {
            $this->logger->error(
                message: '[RegistersController] Register not found for publishing',
                context: ['file' => __FILE__, 'line' => __LINE__, 'register_id' => $id]
            );
            return new JSONResponse(data: ['error' => 'Register not found'], statusCode: 404);
        } catch (Exception $e) {
            $this->logger->error(
                message: '[RegistersController] Failed to publish register OAS to GitHub: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );

            return new JSONResponse(data: ['error' => 'Failed to publish register OAS: '.$e->getMessage()], statusCode: 500);
        }//end try
    }//end publishToGitHub()

    /**
     * Import data into a register
     *
     * This method imports data into a register in the specified format and returns a detailed summary.
     *
     * @param int|string $id    The ID, UUID or slug of the register to import into
     * @param bool       $force Force import even if the same or newer version already exists
     *
     * @return JSONResponse JSON response with import result or error
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @suppressWarnings(PHPMD.BooleanArgumentFlag)   Force flag to override version checks
     * @suppressWarnings(PHPMD.ExcessiveMethodLength)
     * @suppressWarnings(PHPMD.CyclomaticComplexity)
     * @suppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-10
     */
    public function import(int|string $id, bool $force=false): JSONResponse
    {
        try {
            // Get the uploaded file.
            $uploadedFile = $this->request->getUploadedFile('file');
            if ($uploadedFile === null) {
                return new JSONResponse(data: ['error' => 'No file uploaded'], statusCode: 400);
            }

            // Authorization: importing into a register can create schemas/
            // registers/objects and calls `registerService->updateFromArray`
            // (a configurable data-model write). Gate on manage-permission for
            // the target register (default-SECURE: admin-only when no manage
            // rule exists). Closes the bypass of the wave-1 #1949 admin-only
            // create/update gate.
            try {
                $registerForAuth = $this->registerMapper->find($id);
            } catch (DoesNotExistException $e) {
                return new JSONResponse(data: ['error' => 'Register not found'], statusCode: 404);
            }

            if ($this->checkRegisterManagePermission(register: $registerForAuth) === false) {
                return new JSONResponse(
                    data: ['error' => 'User does not have permission to manage this register'],
                    statusCode: 403
                );
            }

            // Dynamically determine import type if not provided.
            $type = $this->request->getParam('type');
            if ($type === null || $type === '') {
                $filename  = $uploadedFile['name'] ?? '';
                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if (in_array($extension, ['xlsx', 'xls']) === true) {
                    $type = 'excel';
                } else if ($extension === 'csv') {
                    $type = 'csv';
                }

                if (in_array($extension, ['xlsx', 'xls', 'csv']) === false) {
                    $type = 'configuration';
                }
            }

            // Get import options for all types - support both boolean and string values.
            $includeObjects = $this->parseBooleanParam(paramName: 'includeObjects', default: false);
            $validation     = $this->parseBooleanParam(paramName: 'validation', default: false);
            $events         = $this->parseBooleanParam(paramName: 'events', default: false);
            $publish        = $this->parseBooleanParam(paramName: 'publish', default: false);
            $enrich         = $this->parseBooleanParam(paramName: 'enrich', default: true);

            // Log import parameters for debugging.
            $this->logger->debug(
                message: '[RegistersController] Import parameters received',
                context: [
                    'file'           => __FILE__,
                    'line'           => __LINE__,
                    'includeObjects' => $includeObjects,
                    'validation'     => $validation,
                    'events'         => $events,
                    'publish'        => $publish,
                    'registerId'     => $id,
                ]
            );
            // Find the register.
            $register = $this->registerService->find($id);
            // Handle different import types.
            switch ($type) {
                case 'excel':
                    // Import from Excel and get summary (now returns sheet-based format).
                    // SEC-CTRL-6: Do NOT read rbac/multi from the request — that would let a
                    // manager pass ?multi=false to write objects across organisation boundaries.
                    // Derive RBAC from admin status and always keep imports tenant-scoped.
                    $rbac  = ($this->isCurrentUserAdmin() === false);
                    $multi = true;
                    $summary = $this->importService->importFromExcel(
                        filePath: $uploadedFile['tmp_name'],
                        register: $register,
                        schema: null,
                        validation: $validation,
                        events: $events,
                        _rbac: $rbac,
                        _multitenancy: $multi,
                        publish: $publish,
                        currentUser: $this->userSession->getUser(),
                        enrich: $enrich
                    );
                    break;
                case 'csv':
                    // Import from CSV and get summary (now returns sheet-based format).
                    // For CSV, schema MUST be specified in the request.
                    $schemaId = $this->request->getParam('schema');

                    if ($schemaId === null || $schemaId === '') {
                        return new JSONResponse(
                            data: ['error' => 'Schema parameter is required for CSV imports.'],
                            statusCode: 400
                        );
                    }

                    $schema = $this->schemaMapper->find($schemaId);

                    // SEC-CTRL-6: Do NOT read rbac/multi from the request — that would let a
                    // manager pass ?multi=false to write objects across organisation boundaries.
                    // Derive RBAC from admin status and always keep imports tenant-scoped.
                    $rbac  = ($this->isCurrentUserAdmin() === false);
                    $multi = true;
                    $summary = $this->importService->importFromCsv(
                        filePath: $uploadedFile['tmp_name'],
                        register: $register,
                        schema: $schema,
                        validation: $validation,
                        events: $events,
                        _rbac: $rbac,
                        _multitenancy: $multi,
                        publish: $publish,
                        currentUser: $this->userSession->getUser(),
                        enrich: $enrich
                    );
                    break;
                case 'configuration':
                default:
                    // Initialize the uploaded files array.
                    $uploadedFiles = [$uploadedFile];
                    // Get the uploaded JSON data.
                    $jsonData = $this->configurationService->getUploadedJson(
                        data: $this->request->getParams(),
                        uploadedFiles: $uploadedFiles
                    );
                    if ($jsonData instanceof JSONResponse) {
                        return $jsonData;
                    }

                    // Import the data and get the result.
                    // ImportFromJson requires a Configuration entity as second parameter.
                    // For now, pass null and let the service handle it (will throw if required).
                    $configuration = null;
                    // TODO: Get or create Configuration entity if needed.
                    $result = $this->configurationService->importFromJson(
                        data: $jsonData,
                        configuration: $configuration,
                        owner: $this->request->getParam('owner'),
                        appId: $this->request->getParam('appId'),
                        version: $this->request->getParam('version'),
                        force: $force
                    );
                    // Build a summary for objects if present in sheet-based format.
                    $summary = [
                        'configuration' => [
                            'created'   => [],
                            'updated'   => [],
                            'unchanged' => [],
                            'errors'    => [],
                        ],
                    ];
                    if (($result['objects'] ?? null) !== null && is_array($result['objects']) === true) {
                        foreach ($result['objects'] as $object) {
                            // For now, treat all as 'created' (improve if possible).
                            $summary['configuration']['created'][] = [
                                'id'       => $object->getId(),
                                'uuid'     => $object->getUuid(),
                                'sheet'    => 'configuration',
                                'register' => [
                                    'id'   => $register->getId(),
                                    'name' => $register->getTitle(),
                                ],
                                'schema'   => null,
                                // Schema info not available in configuration import.
                            ];
                        }
                    }

                    // If no registers in oas, update the register given through query with created schemas.
                    if (empty($result['registers']) === true) {
                        // Get created schema ids.
                        $createdSchemas = [];
                        foreach ($result['schemas'] as $schema) {
                            $createdSchemas[] = $schema->getId();
                        }

                        // Get existing schemas.
                        $register        = $this->registerService->find($id);
                        $registerSchemas = $register->getSchemas();

                        // Merge new with existing.
                        $mergedSchemaArray = array_merge($registerSchemas ?? [], $createdSchemas);
                        $mergedSchemaArray = array_keys(array_flip($mergedSchemaArray));

                        $register->setSchemas($mergedSchemaArray);
                        // Update through service instead of direct mapper call.
                        $this->registerService->updateFromArray(id: $id, data: $register->jsonSerialize());
                    }
                    break;
            }//end switch

            // Attach a downloadable per-row error CSV when the summary
            // surfaces row-level failures. Base64 keeps the artefact in the
            // existing JSON envelope so the frontend can offer a download
            // without a second round-trip.
            $errorsCsv    = $this->importService->serializeErrorsToCsv(summary: $summary);
            $responseData = [
                'message' => 'Import successful',
                'summary' => $summary,
            ];
            if ($errorsCsv !== '') {
                $responseData['errors_csv']          = base64_encode($errorsCsv);
                $responseData['errors_csv_filename'] = 'import_errors.csv';
            }

            return new JSONResponse(data: $responseData);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
        }//end try
    }//end import()

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
    public function rollbackImport(): JSONResponse
    {
        $importJobId = $this->request->getParam('importJobId');
        if (is_string($importJobId) === false || $importJobId === '') {
            return new JSONResponse(
                data: ['error' => 'importJobId is required'],
                statusCode: 422
            );
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                data: ['error' => 'Authentication required'],
                statusCode: 401
            );
        }

        // SECURITY: rollback wipes every object created by an import job.
        // The only safety net was that `deleteObject` runs RBAC, which is
        // much weaker than it sounds — any user with broad delete rights
        // on the affected schemas could otherwise wipe a *different*
        // user's import (and across tenants, since the audit lookup
        // doesn't filter by organisation). Require the caller to be
        // either the original importer or a member of the admin group.
        $auditSample = $this->auditTrailMapper->findByImportJobId(
            importJobId: $importJobId,
            action: 'create'
        );
        if (count($auditSample) === 0) {
            return new JSONResponse(
                data: ['error' => 'Import job not found', 'importJobId' => $importJobId],
                statusCode: 404
            );
        }

        $importerUid = null;
        if (method_exists($auditSample[0], 'getUser') === true) {
            $importerUid = $auditSample[0]->getUser();
        }

        if ($this->canRollbackImport(user: $user, importerUid: $importerUid) === false) {
            return new JSONResponse(
                data: ['error' => 'Forbidden: only the user who initiated the import or an admin may roll it back'],
                statusCode: 403
            );
        }

        try {
            $report = $this->importService->softDeleteByImportJobId(importJobId: $importJobId);
            return new JSONResponse(data: $report, statusCode: 200);
        } catch (\Throwable $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500
            );
        }
    }//end rollbackImport()

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
     *
     * @return JSONResponse The JSON response containing register statistics
     *
     * @psalm-return JSONResponse<
     *     200|404|500,
     *     array{
     *         error?: string,
     *         register?: array{
     *             id: int,
     *             uuid: null|string,
     *             slug: null|string,
     *             title: null|string,
     *             version: null|string,
     *             description: null|string,
     *             schemas: array<int|string>,
     *             source: null|string,
     *             tablePrefix: null|string,
     *             folder: null|string,
     *             updated: null|string,
     *             created: null|string,
     *             owner: null|string,
     *             application: null|string,
     *             organisation: null|string,
     *             authorization: array|null,
     *             groups: array<string, list<string>>,
     *             configuration: array|null,
     *             quota: array{
     *                 storage: null,
     *                 bandwidth: null,
     *                 requests: null,
     *                 users: null,
     *                 groups: null
     *             },
     *             usage: array{
     *                 storage: 0,
     *                 bandwidth: 0,
     *                 requests: 0,
     *                 users: 0,
     *                 groups: int<0, max>
     *             },
     *             deleted: null|string,
     *             published: null|string,
     *             depublished: null|string
     *         },
     *         message?: 'Stats calculation not yet implemented'
     *     },
     *     array<never, never>
     * >
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-6
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function stats(int $id): JSONResponse
    {
        try {
            // Get the register with stats.
            $register = $this->registerService->find($id);

            // Calculate statistics for this register.
            // Note: calculateStats method doesn't exist, using getStats or similar if available.
            // For now, return basic register info.
            $stats = [
                'register' => $register->jsonSerialize(),
                'message'  => 'Stats calculation not yet implemented',
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
     * @param bool   $default   Default value if parameter is not present
     *
     * @return bool The parsed boolean value
     *
     * @suppressWarnings(PHPMD.BooleanArgumentFlag) Default value is needed for parameter parsing
     */
    private function parseBooleanParam(string $paramName, bool $default=false): bool
    {
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
            return (bool) $value;
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
    private function isAnonymousRequest(): bool
    {
        return $this->userSession->getUser() === null;

    }//end isAnonymousRequest()

    /**
     * Whether an entity is currently published.
     *
     * A resource is published when its `published` timestamp is set and it has
     * not since been depublished. Both values come from persisted entity state.
     *
     * @param \DateTime|null $published   The published timestamp, or null.
     * @param \DateTime|null $depublished The depublished timestamp, or null.
     *
     * @return bool True if the entity is published and not depublished.
     */
    private function isPublishedEntity(?\DateTime $published, ?\DateTime $depublished): bool
    {
        return $published !== null && $depublished === null;

    }//end isPublishedEntity()

    /**
     * Check whether the currently authenticated user is a Nextcloud administrator.
     *
     * Used to gate register creation, where there is no existing entity whose
     * manage-authorization block could be consulted.
     *
     * @return bool True if a user is signed in and belongs to the admin group.
     */
    private function isCurrentUserAdmin(): bool
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        return $this->groupManager->isAdmin($user->getUID());

    }//end isCurrentUserAdmin()

    /**
     * Check whether a user is authorized to roll back an import job.
     *
     * Returns true when the user is an admin or is the original importer.
     * Extracted to keep the public `rollbackImport` body free of low-level
     * isAdmin/uid comparisons (gate-9 false-positive avoidance).
     *
     * @param \OCP\IUser  $user        The authenticated user.
     * @param string|null $importerUid The UID of the user who initiated the import.
     *
     * @return bool True when the user may execute the rollback.
     */
    private function canRollbackImport(\OCP\IUser $user, ?string $importerUid): bool
    {
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
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Permission resolution must handle four distinct
     *   cases: no authorization config, admin bypass, group-string rules, and group-object rules —
     *   each is a real runtime path, not artificial branching.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Consequence of the four-case permission decision tree
     *   above; see CyclomaticComplexity note.
     */
    private function checkRegisterManagePermission(Register $register): bool
    {
        $authorization = $register->getAuthorization();

        // If no authorization configured, only admins can manage (default secure).
        // Check if manage action is defined.
        if (empty($authorization) === true || isset($authorization['manage']) === false) {
            // Fall back: only admin users can manage if no manage rules are defined.
            $user = $this->userSession->getUser();
            if ($user === null) {
                return false;
            }

            try {
                $groupManager = $this->container->get(\OCP\IGroupManager::class);
                $userGroups   = $groupManager->getUserGroupIds($user);
                return in_array('admin', $userGroups, true);
            } catch (\Throwable $e) {
                return false;
            }
        }

        // Use PermissionHandler logic via a schema-like check.
        // Create a minimal check using the register's authorization directly.
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        try {
            $userGroups = $this->groupManager->getUserGroupIds($user);

            // Admin bypass.
            if (in_array('admin', $userGroups, true) === true) {
                return true;
            }

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
    }//end checkRegisterManagePermission()
}//end class
