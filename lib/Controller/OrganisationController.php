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
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Db\OrganisationMapper;
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
     * OrganisationController constructor
     *
     * @param string              $appName             Application name
     * @param IRequest            $request             HTTP request
     * @param OrganisationService $organisationService Organisation service
     * @param OrganisationMapper  $organisationMapper  Organisation mapper
     * @param LoggerInterface     $logger              Logger service
     */
    public function __construct(
        string $appName,
        IRequest $request,
        OrganisationService $organisationService,
        OrganisationMapper $organisationMapper,
        LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);
        $this->organisationService = $organisationService;
        $this->organisationMapper  = $organisationMapper;
        $this->logger = $logger;

    }//end __construct()

    /**
     * Get user's organisations and active organisation
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with list of organisations
     *
     * @psalm-return JSONResponse<200|500, array{error?: 'Failed to retrieve organisations', total?: int<0, max>, active?: array{id: int, uuid: null|string, slug: null|string, name: null|string, description: null|string, users: array, groups: array|null, owner: null|string, active: bool|null, parent: null|string, children: array, quota: array{storage: int|null, bandwidth: int|null, requests: int|null, users: null, groups: null}, usage: array{storage: 0, bandwidth: 0, requests: 0, users: int<0, max>, groups: int<0, max>}, authorization: array, created: null|string, updated: null|string}|null, results?: array}, array<never, never>>
     */
    public function index(): JSONResponse
    {
        try {
            $stats = $this->organisationService->getUserOrganisationStats();

            return new JSONResponse(data: $stats, statusCode: Http::STATUS_OK);
        } catch (Exception $e) {
            $this->logger->error(
                    message: 'Failed to get user organisations',
                    context: [
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
        }

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
     * @psalm-return JSONResponse<200|400, array{error?: string, message?: 'Active organisation set successfully', activeOrganisation?: array{id: int, uuid: null|string, slug: null|string, name: null|string, description: null|string, users: array, groups: array|null, owner: null|string, active: bool|null, parent: null|string, children: array, quota: array{storage: int|null, bandwidth: int|null, requests: int|null, users: null, groups: null}, usage: array{storage: 0, bandwidth: 0, requests: 0, users: int<0, max>, groups: int<0, max>}, authorization: array, created: null|string, updated: null|string}|null}, array<never, never>>
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
            } else {
                return new JSONResponse(
                        data: [
                            'error' => 'Failed to set active organisation',
                        ],
                        statusCode: Http::STATUS_BAD_REQUEST
                        );
            }//end if
        } catch (Exception $e) {
            $this->logger->error(
                    message: 'Failed to set active organisation',
                    context: [
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
     * @psalm-return JSONResponse<200|500, array{error?: 'Failed to retrieve active organisation', activeOrganisation?: array{id: int, uuid: null|string, slug: null|string, name: null|string, description: null|string, users: array, groups: array|null, owner: null|string, active: bool|null, parent: null|string, children: array, quota: array{storage: int|null, bandwidth: int|null, requests: int|null, users: null, groups: null}, usage: array{storage: 0, bandwidth: 0, requests: 0, users: int<0, max>, groups: int<0, max>}, authorization: array, created: null|string, updated: null|string}|null}, array<never, never>>
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
                    message: 'Failed to get active organisation',
                    context: [
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
     * @psalm-return JSONResponse<201|400, array{error?: string, message?: 'Organisation created successfully', organisation?: array{id: int, uuid: null|string, slug: null|string, name: null|string, description: null|string, users: array, groups: array|null, owner: null|string, active: bool|null, parent: null|string, children: array, quota: array{storage: int|null, bandwidth: int|null, requests: int|null, users: null, groups: null}, usage: array{storage: 0, bandwidth: 0, requests: 0, users: int<0, max>, groups: int<0, max>}, authorization: array, created: null|string, updated: null|string}}, array<never, never>>
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

            $organisation = $this->organisationService->createOrganisation(name: $name, description: $description, addCurrentUser: true, uuid: $uuid);

            return new JSONResponse(
                    data: [
                        'message'      => 'Organisation created successfully',
                        'organisation' => $organisation->jsonSerialize(),
                    ],
                    statusCode: Http::STATUS_CREATED
                    );
        } catch (Exception $e) {
            $this->logger->error(
                    message: 'Failed to create organisation',
                    context: [
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
     * @psalm-return JSONResponse<200|400, array{error?: string, message?: 'Successfully joined organisation'}, array<never, never>>
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
            } else {
                return new JSONResponse(
                        data: [
                            'error' => 'Failed to join organisation',
                        ],
                        statusCode: Http::STATUS_BAD_REQUEST
                        );
            }
        } catch (Exception $e) {
            $this->logger->error(
                    message: 'Failed to join organisation',
                    context: [
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
     * @psalm-return JSONResponse<200|400, array{error?: string, message?: 'Successfully left organisation'|'Successfully removed user from organisation'}, array<never, never>>
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
            } else {
                return new JSONResponse(
                        data: [
                            'error' => 'Failed to leave organisation',
                        ],
                        statusCode: Http::STATUS_BAD_REQUEST
                        );
            }
        } catch (Exception $e) {
            $this->logger->error(
                    message: 'Failed to leave organisation',
                    context: [
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
     * @psalm-return JSONResponse<200|403|404, array{error?: 'Access denied to this organisation'|'Organisation not found', organisation?: array{id: int, uuid: null|string, slug: null|string, name: null|string, description: null|string, users: array, groups: array|null, owner: null|string, active: bool|null, parent: null|string, children: array, quota: array{storage: int|null, bandwidth: int|null, requests: int|null, users: null, groups: null}, usage: array{storage: 0, bandwidth: 0, requests: 0, users: int<0, max>, groups: int<0, max>}, authorization: array, created: null|string, updated: null|string}}, array<never, never>>
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
                    message: 'Failed to get organisation',
                    context: [
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
     * @return JSONResponse Updated organisation data.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400|403, array{error?: string, id?: int, uuid?: null|string, slug?: null|string, name?: null|string, description?: null|string, users?: array, groups?: array|null, owner?: null|string, active?: bool|null, parent?: null|string, children?: array, quota?: array{storage: int|null, bandwidth: int|null, requests: int|null, users: null, groups: null}, usage?: array{storage: 0, bandwidth: 0, requests: 0, users: int<0, max>, groups: int<0, max>}, authorization?: array, created?: null|string, updated?: null|string}, array<never, never>>
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
     * @return JSONResponse Updated organisation data.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400|403, array{error?: string, id?: int, uuid?: null|string, slug?: null|string, name?: null|string, description?: null|string, users?: array, groups?: array|null, owner?: null|string, active?: bool|null, parent?: null|string, children?: array, quota?: array{storage: int|null, bandwidth: int|null, requests: int|null, users: null, groups: null}, usage?: array{storage: 0, bandwidth: 0, requests: 0, users: int<0, max>, groups: int<0, max>}, authorization?: array, created?: null|string, updated?: null|string}, array<never, never>>
     */
    public function patch(string $uuid): JSONResponse
    {
        return $this->update($uuid);

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
     * @psalm-return JSONResponse<200|500, array{error?: 'Search failed', organisations?: array<array{id: int, uuid: null|string, slug: null|string, name: null|string, description: null|string, groups: array|null, active: bool|null, parent: null|string, children: array, quota: array{storage: int|null, bandwidth: int|null, requests: int|null, users: null, groups: null}, usage: array{storage: 0, bandwidth: 0, requests: 0, users: int<0, max>, groups: int<0, max>}, authorization: array, created: null|string, updated: null|string}>, limit?: int<1, 100>, offset?: int<0, max>, count?: int<0, max>}, array<never, never>>
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

            // If query is empty, return all organisations.
            // Otherwise search by name.
            if (empty(trim($query)) === true) {
                $organisations = $this->organisationMapper->findAll(limit: $limit, offset: $offset);
            } else {
                $organisations = $this->organisationMapper->findByName(name: trim($query), limit: $limit, offset: $offset);
            }

            // Remove user information for privacy.
            $publicData = array_map(
                    function ($org) {
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
                    message: 'Failed to search organisations',
                    context: [
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
     * @psalm-return JSONResponse<200|500, array{error?: 'Failed to clear cache', message?: 'Cache cleared successfully'}, array<never, never>>
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
                    message: 'Failed to clear cache',
                    context: [
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
     * @psalm-return JSONResponse<200|500, array{error?: 'Failed to retrieve statistics', statistics?: array{total: int}}, array<never, never>>
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
                    message: 'Failed to get organisation statistics',
                    context: [
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
                $slug = $this->generateSlug(trim($data['name']));
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
     */
    private function handleParentUpdate(object $organisation, array $data, string $uuid): ?JSONResponse
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
                message: 'Parent assignment validation failed',
                context: [
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
     * @return JSONResponse Error response.
     */
    private function handleUpdateError(string $uuid, Exception $exception): JSONResponse
    {
        $this->logger->error(
            message: 'Failed to update organisation',
            context: [
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
}//end class
