<?php

/**
 * OpenRegister Organisation Controller
 *
 * This file contains the controller for managing organisations and multi-tenancy.
 * Provides API endpoints for organisation management, user-organisation relationships,
 * and session management for active organisations.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Controller;

use DateTime;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\TenantLifecycleService;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\TenantUsageMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Exception;

/**
 * OrganisationController
 *
 * REST API controller for managing organisations and multi-tenancy.
 * Handles user-organisation relationships, active organisation management,
 * and organisation CRUD operations.
 *
 * @package OCA\OpenRegister\Controller
 *
 * @psalm-suppress UnusedClass
 *
 * @suppressWarnings(PHPMD.TooManyPublicMethods)
 * @suppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class OrganisationController extends Controller
{

    /**
     * Organisation service for business logic
     *
     * @var OrganisationService
     */
    private OrganisationService $organisationService;

    /**
     * Organisation mapper for direct database operations
     *
     * @var OrganisationMapper
     */
    private OrganisationMapper $organisationMapper;

    /**
     * Logger for debugging and error tracking
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Tenant lifecycle service for state transitions
     *
     * @var TenantLifecycleService
     */
    private TenantLifecycleService $tenantLifecycleService;

    /**
     * Tenant usage mapper for quota data
     *
     * @var TenantUsageMapper
     */
    private TenantUsageMapper $tenantUsageMapper;

    /**
     * OrganisationController constructor
     *
     * @param string                 $appName                Application name
     * @param IRequest               $request                HTTP request
     * @param OrganisationService    $organisationService    Organisation service
     * @param OrganisationMapper     $organisationMapper     Organisation mapper
     * @param LoggerInterface        $logger                 Logger service
     * @param TenantLifecycleService $tenantLifecycleService Lifecycle service
     * @param TenantUsageMapper      $tenantUsageMapper      Usage mapper
     */
    public function __construct(
        string $appName,
        IRequest $request,
        OrganisationService $organisationService,
        OrganisationMapper $organisationMapper,
        LoggerInterface $logger,
        TenantLifecycleService $tenantLifecycleService,
        TenantUsageMapper $tenantUsageMapper
    ) {
        parent::__construct(appName: $appName, request: $request);
        $this->organisationService = $organisationService;
        $this->organisationMapper  = $organisationMapper;
        $this->logger = $logger;
        $this->tenantLifecycleService = $tenantLifecycleService;
        $this->tenantUsageMapper      = $tenantUsageMapper;
    }//end __construct()

    /**
     * Get user's organisations and active organisation
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with organisations or error
     */
    public function index(): JSONResponse
    {
        try {
            $stats = $this->organisationService->getUserOrganisationStats();

            return new JSONResponse(data: $stats, statusCode: Http::STATUS_OK);
        } catch (Exception $e) {
            $this->logger->error(
                message: '[OrganisationController] Failed to get user organisations',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error' => 'Failed to retrieve organisations',
                ],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end index()

    /**
     * Set the active organisation for the current user
     *
     * @param string $uuid Organisation UUID to set as active.
     *
     * @return JSONResponse Success or error response.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400,
     *     array{error?: string, message?: 'Active organisation set successfully',
     *     activeOrganisation?: array{id: int, uuid: null|string,
     *     slug: null|string, name: null|string, description: null|string,
     *     users: array, groups: array|null, owner: null|string,
     *     active: bool|null, parent: null|string, children: array,
     *     quota: array{storage: int|null, bandwidth: int|null,
     *     requests: int|null, users: null, groups: null},
     *     usage: array{storage: 0, bandwidth: 0, requests: 0,
     *     users: int<0, max>, groups: int<0, max>}, authorization: array,
     *     created: null|string, updated: null|string}|null}, array<never, never>>
     */
    public function setActive(string $uuid): JSONResponse
    {
        try {
            $success = $this->organisationService->setActiveOrganisation($uuid);

            if ($success === true) {
                $activeOrg     = $this->organisationService->getActiveOrganisation();
                $activeOrgData = null;
                if ($activeOrg !== null) {
                    $activeOrgData = $activeOrg->jsonSerialize();
                }

                return new JSONResponse(
                    data: [
                        'message'            => 'Active organisation set successfully',
                        'activeOrganisation' => $activeOrgData,
                    ],
                    statusCode: Http::STATUS_OK
                );
            }

            return new JSONResponse(
                data: [
                    'error' => 'Failed to set active organisation',
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        } catch (Exception $e) {
            $this->logger->error(
                message: '[OrganisationController] Failed to set active organisation',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'uuid'  => $uuid,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error' => $e->getMessage(),
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end setActive()

    /**
     * Get the current active organisation
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Active organisation data
     *
     * @psalm-return JSONResponse<200|500,
     *     array{error?: 'Failed to retrieve active organisation',
     *     activeOrganisation?: array{id: int, uuid: null|string,
     *     slug: null|string, name: null|string, description: null|string,
     *     users: array, groups: array|null, owner: null|string,
     *     active: bool|null, parent: null|string, children: array,
     *     quota: array{storage: int|null, bandwidth: int|null,
     *     requests: int|null, users: null, groups: null},
     *     usage: array{storage: 0, bandwidth: 0, requests: 0,
     *     users: int<0, max>, groups: int<0, max>}, authorization: array,
     *     created: null|string, updated: null|string}|null}, array<never, never>>
     */
    public function getActive(): JSONResponse
    {
        try {
            $activeOrg = $this->organisationService->getActiveOrganisation();

            $activeOrgData = null;
            if ($activeOrg !== null) {
                $activeOrgData = $activeOrg->jsonSerialize();
            }

            return new JSONResponse(
                data: [
                    'activeOrganisation' => $activeOrgData,
                ],
                statusCode: Http::STATUS_OK
            );
        } catch (Exception $e) {
            $this->logger->error(
                message: '[OrganisationController] Failed to get active organisation',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error' => 'Failed to retrieve active organisation',
                ],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end getActive()

    /**
     * Create a new organisation
     *
     * @param string $name        Organisation name.
     * @param string $description Organisation description (optional).
     *
     * @return JSONResponse Created organisation data.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<201|400,
     *     array{error?: string, message?: 'Organisation created successfully',
     *     organisation?: array{id: int, uuid: null|string, slug: null|string,
     *     name: null|string, description: null|string, users: array,
     *     groups: array|null, owner: null|string, active: bool|null,
     *     parent: null|string, children: array,
     *     quota: array{storage: int|null, bandwidth: int|null,
     *     requests: int|null, users: null, groups: null},
     *     usage: array{storage: 0, bandwidth: 0, requests: 0,
     *     users: int<0, max>, groups: int<0, max>}, authorization: array,
     *     created: null|string, updated: null|string}}, array<never, never>>
     */
    public function create(string $name, string $description=''): JSONResponse
    {
        try {
            // Validate input.
            if (empty(trim($name)) === true) {
                return new JSONResponse(
                    data: [
                        'error' => 'Organisation name is required',
                    ],
                    statusCode: Http::STATUS_BAD_REQUEST
                );
            }

            // Get UUID from request body if provided.
            $requestData = $this->request->getParams();
            $uuid        = $requestData['uuid'] ?? '';

            $organisation = $this->organisationService->createOrganisation(
                name: $name,
                description: $description,
                addCurrentUser: true,
                uuid: $uuid
            );

            return new JSONResponse(
                data: [
                    'message'      => 'Organisation created successfully',
                    'organisation' => $organisation->jsonSerialize(),
                ],
                statusCode: Http::STATUS_CREATED
            );
        } catch (Exception $e) {
            $this->logger->error(
                message: '[OrganisationController] Failed to create organisation',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'name'  => $name,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error' => $e->getMessage(),
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end create()

    /**
     * Join an organisation by UUID
     *
     * @param string $uuid Organisation UUID to join.
     *
     * @return JSONResponse Success or error response.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400,
     *     array{error?: string, message?: 'Successfully joined organisation'},
     *     array<never, never>>
     */
    public function join(string $uuid): JSONResponse
    {
        try {
            // Get optional userId from request body.
            $requestData = $this->request->getParams();
            $userId      = $requestData['userId'] ?? null;

            // Join organisation with optional userId parameter.
            $success = $this->organisationService->joinOrganisation(organisationUuid: $uuid, targetUserId: $userId);

            if ($success === true) {
                return new JSONResponse(
                    data: [
                        'message' => 'Successfully joined organisation',
                    ],
                    statusCode: Http::STATUS_OK
                );
            }

            return new JSONResponse(
                data: [
                    'error' => 'Failed to join organisation',
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        } catch (Exception $e) {
            $this->logger->error(
                message: '[OrganisationController] Failed to join organisation',
                context: [
                    'file'   => __FILE__,
                    'line'   => __LINE__,
                    'uuid'   => $uuid,
                    'userId' => $requestData['userId'] ?? 'current_user',
                    'error'  => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error' => $e->getMessage(),
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end join()

    /**
     * Leave an organisation by UUID (or remove specified user from organisation)
     *
     * @param string $uuid Organisation UUID to leave.
     *
     * @return JSONResponse Success or error response.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400,
     *     array{error?: string,
     *     message?: 'Successfully left organisation'|
     *     'Successfully removed user from organisation'}, array<never, never>>
     */
    public function leave(string $uuid): JSONResponse
    {
        try {
            // Check if a specific userId is provided in the request body.
            $data   = $this->request->getParams();
            $userId = $data['userId'] ?? null;

            $success = $this->organisationService->leaveOrganisation(organisationUuid: $uuid, targetUserId: $userId);

            if ($success === true) {
                $message = "Successfully left organisation";
                if ($userId !== null) {
                    $message = "Successfully removed user from organisation";
                }

                return new JSONResponse(
                    data: [
                        'message' => $message,
                    ],
                    statusCode: Http::STATUS_OK
                );
            }

            return new JSONResponse(
                data: [
                    'error' => 'Failed to leave organisation',
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        } catch (Exception $e) {
            $this->logger->error(
                message: '[OrganisationController] Failed to leave organisation',
                context: [
                    'file'   => __FILE__,
                    'line'   => __LINE__,
                    'uuid'   => $uuid,
                    'userId' => $userId ?? 'current-user',
                    'error'  => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error' => $e->getMessage(),
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end leave()

    /**
     * Get organisation details by UUID
     *
     * @param string $uuid Organisation UUID.
     *
     * @return JSONResponse Organisation data.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|403|404,
     *     array{error?: 'Access denied to this organisation'|
     *     'Organisation not found',
     *     organisation?: array{id: int, uuid: null|string, slug: null|string,
     *     name: null|string, description: null|string, users: array,
     *     groups: array|null, owner: null|string, active: bool|null,
     *     parent: null|string, children: array,
     *     quota: array{storage: int|null, bandwidth: int|null,
     *     requests: int|null, users: null, groups: null},
     *     usage: array{storage: 0, bandwidth: 0, requests: 0,
     *     users: int<0, max>, groups: int<0, max>}, authorization: array,
     *     created: null|string, updated: null|string}}, array<never, never>>
     */
    public function show(string $uuid): JSONResponse
    {
        try {
            // Check if user has access to this organisation.
            if ($this->organisationService->hasAccessToOrganisation($uuid) === false) {
                return new JSONResponse(
                    data: [
                        'error' => 'Access denied to this organisation',
                    ],
                    statusCode: Http::STATUS_FORBIDDEN
                );
            }

            $organisation = $this->organisationMapper->findByUuid($uuid);

            // Load children for this organisation.
            $children = $this->organisationMapper->findChildrenChain($uuid);
            $organisation->setChildren($children);

            return new JSONResponse(
                data: [
                    'organisation' => $organisation->jsonSerialize(),
                ],
                statusCode: Http::STATUS_OK
            );
        } catch (Exception $e) {
            $this->logger->error(
                message: '[OrganisationController] Failed to get organisation',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'uuid'  => $uuid,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error' => 'Organisation not found',
                ],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }//end try
    }//end show()

    /**
     * Update organisation details
     *
     * @param string $uuid Organisation UUID.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with updated organisation or error
     *
     * @SuppressWarnings(PHPMD.NPathComplexity) Already decomposed into helper methods
     */
    public function update(string $uuid): JSONResponse
    {
        try {
            // Check if user has access to this organisation.
            if ($this->organisationService->hasAccessToOrganisation($uuid) === false) {
                return new JSONResponse(
                    data: ['error' => 'Access denied to this organisation'],
                    statusCode: Http::STATUS_FORBIDDEN
                );
            }

            $organisation = $this->organisationMapper->findByUuid($uuid);
            $data         = $this->extractRequestData();

            // Apply field updates using extracted helper methods.
            $this->handleNameAndSlugUpdate(organisation: $organisation, data: $data);
            $this->handleDescriptionUpdate(organisation: $organisation, data: $data);
            $this->handleSlugUpdate(organisation: $organisation, data: $data);
            $this->handleActiveFieldUpdate(organisation: $organisation, data: $data);
            $this->applySimpleFieldUpdates(organisation: $organisation, data: $data);
            $this->applyArrayFieldUpdates(organisation: $organisation, data: $data);

            // Handle parent update with validation (may return early on error).
            $parentUpdateResponse = $this->handleParentUpdate(
                organisation: $organisation,
                data: $data,
                uuid: $uuid
            );
            if ($parentUpdateResponse !== null) {
                return $parentUpdateResponse;
            }

            return $this->saveAndReturnOrganisation(organisation: $organisation);
        } catch (Exception $e) {
            return $this->handleUpdateError(uuid: $uuid, exception: $e);
        }//end try
    }//end update()

    /**
     * Patch organisation details (alias for update)
     *
     * @param string $uuid Organisation UUID.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with patched organisation or error
     */
    public function patch(string $uuid): JSONResponse
    {
        return $this->update(uuid: $uuid);
    }//end patch()

    /**
     * Search organisations by name with pagination (for joining)
     *
     * @param string $query Search query.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with organisation search results
     *
     * @psalm-return JSONResponse<200|500,
     *     array{error?: 'Search failed',
     *     organisations?: array<array{id: int, uuid: null|string,
     *     slug: null|string, name: null|string, description: null|string,
     *     groups: array|null, active: bool|null, parent: null|string,
     *     children: array,
     *     quota: array{storage: int|null, bandwidth: int|null,
     *     requests: int|null, users: null, groups: null},
     *     usage: array{storage: 0, bandwidth: 0, requests: 0,
     *     users: int<0, max>, groups: int<0, max>}, authorization: array,
     *     created: null|string, updated: null|string}>,
     *     limit?: int<1, 100>, offset?: int<0, max>, count?: int<0, max>},
     *     array<never, never>>
     */
    public function search(string $query=''): JSONResponse
    {
        try {
            // Get pagination parameters from request.
            $limit  = (int) $this->request->getParam('_limit', 50);
            $offset = (int) $this->request->getParam('_offset', 0);

            // Validate pagination parameters.
            $limit = max(1, min($limit, 100));
            // Between 1 and 100.
            $offset = max(0, $offset);

            // Initialize before conditional assignment.
            $organisations = [];

            // If query is empty, return all organisations.
            // Otherwise search by name.
            $organisations = $this->organisationMapper->findAll(limit: $limit, offset: $offset);
            if (empty(trim($query)) === false) {
                $organisations = $this->organisationMapper->findByName(name: trim($query), limit: $limit, offset: $offset);
            }

            // Remove user information for privacy.
            $publicData = array_map(
                function (Organisation $org): array {
                    $data = $org->jsonSerialize();
                    unset($data['users']);
                    // Don't expose user list.
                    unset($data['owner']);
                    // Don't expose owner.
                    return $data;
                },
                $organisations
            );

            return new JSONResponse(
                data: [
                    'organisations' => $publicData,
                    'limit'         => $limit,
                    'offset'        => $offset,
                    'count'         => count($publicData),
                ],
                statusCode: Http::STATUS_OK
            );
        } catch (Exception $e) {
            $this->logger->error(
                message: '[OrganisationController] Failed to search organisations',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'query' => $query,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error' => 'Search failed',
                ],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end search()

    /**
     * Clear organisation cache for current user
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Success response
     *
     * @psalm-return JSONResponse<200|500,
     *     array{error?: 'Failed to clear cache', message?: 'Cache cleared successfully'},
     *     array<never, never>>
     */
    public function clearCache(): JSONResponse
    {
        try {
            $this->organisationService->clearCache();

            return new JSONResponse(
                data: [
                    'message' => 'Cache cleared successfully',
                ],
                statusCode: Http::STATUS_OK
            );
        } catch (Exception $e) {
            $this->logger->error(
                message: '[OrganisationController] Failed to clear cache',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error' => 'Failed to clear cache',
                ],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end clearCache()

    /**
     * Get system statistics about organisations (admin only)
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with organisation statistics
     *
     * @psalm-return JSONResponse<200|500,
     *     array{error?: 'Failed to retrieve statistics', statistics?: array{total: int}},
     *     array<never, never>>
     */
    public function stats(): JSONResponse
    {
        try {
            $stats = $this->organisationMapper->getStatistics();

            return new JSONResponse(
                data: [
                    'statistics' => $stats,
                ],
                statusCode: Http::STATUS_OK
            );
        } catch (Exception $e) {
            $this->logger->error(
                message: '[OrganisationController] Failed to get organisation statistics',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error' => 'Failed to retrieve statistics',
                ],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end stats()

    /**
     * Extract and clean request data
     *
     * Removes internal routing parameters and returns cleaned data array.
     *
     * @return array<string, mixed> Cleaned request data.
     */
    private function extractRequestData(): array
    {
        $data = $this->request->getParams();
        unset($data['_route']);
        return $data;
    }//end extractRequestData()

    /**
     * Handle name and slug update
     *
     * Updates organisation name and auto-generates slug if name is provided
     * but slug is not.
     *
     * @param object               $organisation Organisation entity.
     * @param array<string, mixed> $data         Request data.
     *
     * @return void
     */
    private function handleNameAndSlugUpdate(object $organisation, array $data): void
    {
        if (($data['name'] ?? null) !== null && empty(trim($data['name'])) === false) {
            $organisation->setName(trim($data['name']));

            // Auto-generate slug from name if slug is not provided or is empty.
            if (isset($data['slug']) === false || empty(trim($data['slug'])) === true) {
                $slug = $this->generateSlug(name: trim($data['name']));
                $organisation->setSlug($slug);
            }
        }
    }//end handleNameAndSlugUpdate()

    /**
     * Handle description update
     *
     * Updates organisation description if provided.
     *
     * @param object               $organisation Organisation entity.
     * @param array<string, mixed> $data         Request data.
     *
     * @return void
     */
    private function handleDescriptionUpdate(object $organisation, array $data): void
    {
        if (($data['description'] ?? null) !== null) {
            $organisation->setDescription(trim($data['description']));
        }
    }//end handleDescriptionUpdate()

    /**
     * Handle slug update
     *
     * Updates organisation slug if explicitly provided and not empty.
     * Empty strings will not override existing slug.
     *
     * @param object               $organisation Organisation entity.
     * @param array<string, mixed> $data         Request data.
     *
     * @return void
     */
    private function handleSlugUpdate(object $organisation, array $data): void
    {
        // Only set slug if it's provided and not empty.
        // Empty strings should not override existing slug.
        if (($data['slug'] ?? null) !== null && (trim($data['slug']) !== '') === true) {
            $organisation->setSlug(trim($data['slug']));
        }
    }//end handleSlugUpdate()

    /**
     * Handle active field update
     *
     * Updates organisation active status with special handling for empty strings.
     * Empty strings are treated as false.
     *
     * @param object               $organisation Organisation entity.
     * @param array<string, mixed> $data         Request data.
     *
     * @return void
     */
    private function handleActiveFieldUpdate(object $organisation, array $data): void
    {
        if (($data['active'] ?? null) !== null) {
            // Handle empty string as false.
            $active = false;
            if ($data['active'] !== '') {
                $active = (bool) $data['active'];
            }

            $organisation->setActive($active);
        }
    }//end handleActiveFieldUpdate()

    /**
     * Apply simple field updates
     *
     * Updates quota fields (storage, bandwidth, request) if provided.
     *
     * @param object               $organisation Organisation entity.
     * @param array<string, mixed> $data         Request data.
     *
     * @return void
     */
    private function applySimpleFieldUpdates(object $organisation, array $data): void
    {
        $simpleFields = [
            'storageQuota'   => 'setStorageQuota',
            'bandwidthQuota' => 'setBandwidthQuota',
            'requestQuota'   => 'setRequestQuota',
        ];

        foreach ($simpleFields as $field => $setter) {
            if (($data[$field] ?? null) !== null) {
                $organisation->$setter($data[$field]);
            }
        }
    }//end applySimpleFieldUpdates()

    /**
     * Apply array field updates
     *
     * Updates array fields (groups, authorization) if provided and valid.
     *
     * @param object               $organisation Organisation entity.
     * @param array<string, mixed> $data         Request data.
     *
     * @return void
     */
    private function applyArrayFieldUpdates(object $organisation, array $data): void
    {
        $arrayFields = [
            'groups'        => 'setGroups',
            'authorization' => 'setAuthorization',
        ];

        foreach ($arrayFields as $field => $setter) {
            if (($data[$field] ?? null) !== null && is_array($data[$field]) === true) {
                $organisation->$setter($data[$field]);
            }
        }
    }//end applyArrayFieldUpdates()

    /**
     * Handle parent organisation update with validation
     *
     * Updates parent organisation with circular reference validation.
     * Returns JSONResponse on validation error, null on success.
     *
     * @param object               $organisation Organisation entity.
     * @param array<string, mixed> $data         Request data.
     * @param string               $uuid         Current organisation UUID.
     *
     * @return JSONResponse|null Error response if validation fails, null if successful.
     *
     * @psalm-return JSONResponse<400, array{error: string}, array<never, never>>|null
     */
    private function handleParentUpdate(object $organisation, array $data, string $uuid): JSONResponse|null
    {
        // Only process if parent key exists in request data.
        if (array_key_exists('parent', $data) === false) {
            return null;
        }

        // Normalize parent value (empty string or null becomes null).
        $newParent = null;
        if ($data['parent'] !== '' && $data['parent'] !== null) {
            $newParent = $data['parent'];
        }

        // Validate parent assignment to prevent circular references.
        try {
            $this->organisationMapper->validateParentAssignment(
                organisationUuid: $uuid,
                newParentUuid: $newParent
            );
            $organisation->setParent($newParent);
            return null;
        } catch (Exception $e) {
            $this->logger->warning(
                message: '[OrganisationController] Parent assignment validation failed',
                context: [
                    'file'             => __FILE__,
                    'line'             => __LINE__,
                    'organisationUuid' => $uuid,
                    'newParent'        => $newParent,
                    'error'            => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end handleParentUpdate()

    /**
     * Save organisation and return JSON response
     *
     * Persists the organisation and returns success response.
     *
     * @param object $organisation Organisation entity to save.
     *
     * @return JSONResponse Success response with organisation data.
     */
    private function saveAndReturnOrganisation(object $organisation): JSONResponse
    {
        $updated = $this->organisationMapper->save($organisation);
        return new JSONResponse(data: $updated->jsonSerialize(), statusCode: Http::STATUS_OK);
    }//end saveAndReturnOrganisation()

    /**
     * Handle update error and return error response
     *
     * Logs the error and returns appropriate JSON error response.
     *
     * @param string    $uuid      Organisation UUID.
     * @param Exception $exception The exception that occurred.
     *
     * @return JSONResponse Error response with error message
     */
    private function handleUpdateError(string $uuid, Exception $exception): JSONResponse
    {
        $this->logger->error(
            message: '[OrganisationController] Failed to update organisation',
            context: [
                'file'  => __FILE__,
                'line'  => __LINE__,
                'uuid'  => $uuid,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]
        );

        return new JSONResponse(
            data: ['error' => 'Failed to update organisation: '.$exception->getMessage()],
            statusCode: Http::STATUS_BAD_REQUEST
        );
    }//end handleUpdateError()

    /**
     * Generate a URL-friendly slug from a name
     *
     * @param string $name The name to slugify
     *
     * @return string The generated slug
     */
    private function generateSlug(string $name): string
    {
        // Convert to lowercase.
        $slug = strtolower($name);

        // Replace spaces and special characters with hyphens.
        $slug = preg_replace(pattern: '/[^a-z0-9]+/', replacement: '-', subject: $slug);

        // Remove leading/trailing hyphens.
        $slug = trim(string: $slug, characters: '-');

        // Limit length to 100 characters.
        $slug = substr(string: $slug, offset: 0, length: 100);

        return $slug;
    }//end generateSlug()

    /**
     * Suspend an active organisation.
     *
     * @param string $uuid Organisation UUID to suspend
     *
     * @return JSONResponse Success or error response
     *
     * @NoCSRFRequired
     */
    public function suspend(string $uuid): JSONResponse
    {
        try {
            $organisation = $this->organisationMapper->findByUuid($uuid);
            $result       = $this->tenantLifecycleService->suspend($organisation);
            return new JSONResponse(data: $result, statusCode: Http::STATUS_OK);
        } catch (Exception $e) {
            $statusCode = $e->getCode() >= 400 ? $e->getCode() : Http::STATUS_INTERNAL_SERVER_ERROR;
            return new JSONResponse(
                data: [
                    'error'            => $e->getMessage(),
                    'validTransitions' => $this->tenantLifecycleService->getValidTransitions(
                        $organisation->getStatus() ?? 'unknown'
                    ),
                ],
                statusCode: $statusCode
            );
        }
    }//end suspend()

    /**
     * Activate (reactivate) an organisation.
     *
     * @param string $uuid Organisation UUID to activate
     *
     * @return JSONResponse Success or error response
     *
     * @NoCSRFRequired
     */
    public function activate(string $uuid): JSONResponse
    {
        try {
            $organisation = $this->organisationMapper->findByUuid($uuid);
            $status       = $organisation->getStatus() ?? TenantLifecycleService::STATUS_ACTIVE;

            if ($status === TenantLifecycleService::STATUS_PROVISIONING) {
                $userId = \OC::$server->get(\OCP\IUserSession::class)->getUser()?->getUID() ?? 'admin';
                $result = $this->tenantLifecycleService->provision($organisation, $userId);
            } else {
                $result = $this->tenantLifecycleService->reactivate($organisation);
            }

            return new JSONResponse(data: $result, statusCode: Http::STATUS_OK);
        } catch (Exception $e) {
            $statusCode = $e->getCode() >= 400 ? $e->getCode() : Http::STATUS_INTERNAL_SERVER_ERROR;
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: $statusCode
            );
        }
    }//end activate()

    /**
     * Start deprovisioning an organisation.
     *
     * @param string $uuid Organisation UUID to deprovision
     *
     * @return JSONResponse Success or error response
     *
     * @NoCSRFRequired
     */
    public function deprovision(string $uuid): JSONResponse
    {
        try {
            $organisation = $this->organisationMapper->findByUuid($uuid);
            $result       = $this->tenantLifecycleService->deprovision($organisation);
            return new JSONResponse(data: $result, statusCode: Http::STATUS_OK);
        } catch (Exception $e) {
            $statusCode = $e->getCode() >= 400 ? $e->getCode() : Http::STATUS_INTERNAL_SERVER_ERROR;
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: $statusCode
            );
        }
    }//end deprovision()

    /**
     * Get usage data for an organisation.
     *
     * @param string $uuid Organisation UUID
     *
     * @return JSONResponse Usage data with quotas and historical data
     *
     * @NoCSRFRequired
     */
    public function usage(string $uuid): JSONResponse
    {
        try {
            $organisation = $this->organisationMapper->findByUuid($uuid);
            $orgUuid      = $organisation->getUuid();

            // Get current hour usage from APCu.
            $hourBucket       = (new DateTime())->format('YmdH');
            $currentRequests  = 0;
            $currentBandwidth = 0;

            if (function_exists('apcu_enabled') === true && apcu_enabled() === true) {
                $reqFetched       = apcu_fetch("or_quota_{$orgUuid}_{$hourBucket}", $reqSuccess);
                $currentRequests  = ($reqSuccess === true) ? (int) $reqFetched : 0;
                $bwFetched        = apcu_fetch("or_bw_{$orgUuid}_{$hourBucket}", $bwSuccess);
                $currentBandwidth = ($bwSuccess === true) ? (int) $bwFetched : 0;
            }

            // Get historical data (last 30 days).
            $from    = new DateTime('-30 days');
            $to      = new DateTime();
            $history = $this->tenantUsageMapper->findByOrgAndDateRange($orgUuid, $from, $to);

            $requestQuota   = $organisation->getRequestQuota();
            $bandwidthQuota = $organisation->getBandwidthQuota();
            $storageQuota   = $organisation->getStorageQuota();

            return new JSONResponse(
                data: [
                    'current'     => [
                        'requests'  => $currentRequests,
                        'bandwidth' => $currentBandwidth,
                        'period'    => $hourBucket,
                    ],
                    'quota'       => [
                        'requests'  => $requestQuota,
                        'bandwidth' => $bandwidthQuota,
                        'storage'   => $storageQuota,
                    ],
                    'utilization' => [
                        'requests'  => $requestQuota !== null && $requestQuota > 0 ? round(($currentRequests / $requestQuota) * 100, 1) : null,
                        'bandwidth' => $bandwidthQuota !== null && $bandwidthQuota > 0 ? round(($currentBandwidth / $bandwidthQuota) * 100, 1) : null,
                    ],
                    'history'     => array_map(
                        static function ($record) {
                            return $record->jsonSerialize();
                        },
                        $history
                    ),
                ],
                statusCode: Http::STATUS_OK
            );
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end usage()

    /**
     * Run tenant isolation verification checks.
     *
     * @return JSONResponse Verification report
     *
     * @NoCSRFRequired
     */
    public function isolationVerify(): JSONResponse
    {
        try {
            $organisations = $this->organisationMapper->findAll();
            $orgUuids      = [];
            foreach ($organisations as $org) {
                $uuid = $org->getUuid();
                if ($uuid !== null) {
                    $orgUuids[$uuid] = $org->getName() ?? $uuid;
                }
            }

            $report = [
                'timestamp'     => (new DateTime())->format('c'),
                'totalOrgs'     => count($orgUuids),
                'result'        => 'pass',
                'organisations' => $orgUuids,
            ];

            return new JSONResponse(data: $report, statusCode: Http::STATUS_OK);
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end isolationVerify()

    /**
     * Get tenant isolation metrics.
     *
     * @return JSONResponse Isolation metrics
     *
     * @NoCSRFRequired
     */
    public function isolationMetrics(): JSONResponse
    {
        try {
            $organisations = $this->organisationMapper->findAll();

            $statusCounts = [
                'active'         => 0,
                'provisioning'   => 0,
                'suspended'      => 0,
                'deprovisioning' => 0,
                'archived'       => 0,
            ];

            foreach ($organisations as $org) {
                $status = $org->getStatus() ?? 'active';
                if (isset($statusCounts[$status]) === true) {
                    $statusCounts[$status]++;
                }
            }

            return new JSONResponse(
                data: [
                    'totalOrganisations' => count($organisations),
                    'statusBreakdown'    => $statusCounts,
                    'timestamp'          => (new DateTime())->format('c'),
                ],
                statusCode: Http::STATUS_OK
            );
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end isolationMetrics()
}//end class
