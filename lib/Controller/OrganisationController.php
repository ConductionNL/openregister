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
        parent::__construct($appName, $request);
        $this->organisationService = $organisationService;
        $this->organisationMapper  = $organisationMapper;
        $this->logger = $logger;

    }//end __construct()

    /**
	 * This returns the template of the main app's page
	 * It adds some data to the template (app version)
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return TemplateResponse
	 */
	public function page(): TemplateResponse
	{
        return new TemplateResponse(
            //Application::APP_ID,
            'openregister',
            'index',
            []
        );
	}

    /**
     * Get user's organisations and active organisation
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse User's organisations and statistics
     */
    public function index(): JSONResponse
    {
        try {
            $stats = $this->organisationService->getUserOrganisationStats();

            return new JSONResponse($stats, Http::STATUS_OK);
        } catch (Exception $e) {
            $this->logger->error(
                    'Failed to get user organisations',
                    [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );

            return new JSONResponse(
                    [
                        'error' => 'Failed to retrieve organisations',
                    ],
                    Http::STATUS_INTERNAL_SERVER_ERROR
                    );
        }

    }//end index()


    /**
     * Set the active organisation for the current user
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $uuid Organisation UUID to set as active
     *
     * @return JSONResponse Success or error response
     */
    public function setActive(string $uuid): JSONResponse
    {
        try {
            $success = $this->organisationService->setActiveOrganisation($uuid);

            if ($success) {
                $activeOrg = $this->organisationService->getActiveOrganisation();

                return new JSONResponse(
                        [
                            'message'            => 'Active organisation set successfully',
                            'activeOrganisation' => $activeOrg ? $activeOrg->jsonSerialize() : null,
                        ],
                        Http::STATUS_OK
                        );
            } else {
                return new JSONResponse(
                        [
                            'error' => 'Failed to set active organisation',
                        ],
                        Http::STATUS_BAD_REQUEST
                        );
            }
        } catch (Exception $e) {
            $this->logger->error(
                    'Failed to set active organisation',
                    [
                        'uuid'  => $uuid,
                        'error' => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    [
                        'error' => $e->getMessage(),
                    ],
                    Http::STATUS_BAD_REQUEST
                    );
        }//end try

    }//end setActive()


    /**
     * Get the current active organisation
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Active organisation data
     */
    public function getActive(): JSONResponse
    {
        try {
            $activeOrg = $this->organisationService->getActiveOrganisation();

            return new JSONResponse(
                    [
                        'activeOrganisation' => $activeOrg ? $activeOrg->jsonSerialize() : null,
                    ],
                    Http::STATUS_OK
                    );
        } catch (Exception $e) {
            $this->logger->error(
                    'Failed to get active organisation',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    [
                        'error' => 'Failed to retrieve active organisation',
                    ],
                    Http::STATUS_INTERNAL_SERVER_ERROR
                    );
        }//end try

    }//end getActive()


    /**
     * Create a new organisation
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $name        Organisation name
     * @param string $description Organisation description (optional)
     *
     * @return JSONResponse Created organisation data
     */
    public function create(string $name, string $description=''): JSONResponse
    {
        try {
            // Validate input
            if (empty(trim($name))) {
                return new JSONResponse(
                        [
                            'error' => 'Organisation name is required',
                        ],
                        Http::STATUS_BAD_REQUEST
                        );
            }

            // Get UUID from request body if provided
            $requestData = $this->request->getParams();
            $uuid        = $requestData['uuid'] ?? '';

            $organisation = $this->organisationService->createOrganisation($name, $description, true, $uuid);

            return new JSONResponse(
                    [
                        'message'      => 'Organisation created successfully',
                        'organisation' => $organisation->jsonSerialize(),
                    ],
                    Http::STATUS_CREATED
                    );
        } catch (Exception $e) {
            $this->logger->error(
                    'Failed to create organisation',
                    [
                        'name'  => $name,
                        'error' => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    [
                        'error' => $e->getMessage(),
                    ],
                    Http::STATUS_BAD_REQUEST
                    );
        }//end try

    }//end create()


    /**
     * Join an organisation by UUID
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $uuid Organisation UUID to join
     *
     * @return JSONResponse Success or error response
     */
    public function join(string $uuid): JSONResponse
    {
        try {
            // Get optional userId from request body
            $requestData = $this->request->getParams();
            $userId = $requestData['userId'] ?? null;

            // Join organisation with optional userId parameter
            $success = $this->organisationService->joinOrganisation($uuid, $userId);

            if ($success) {
                return new JSONResponse(
                        [
                            'message' => 'Successfully joined organisation',
                        ],
                        Http::STATUS_OK
                        );
            } else {
                return new JSONResponse(
                        [
                            'error' => 'Failed to join organisation',
                        ],
                        Http::STATUS_BAD_REQUEST
                        );
            }
        } catch (Exception $e) {
            $this->logger->error(
                    'Failed to join organisation',
                    [
                        'uuid'  => $uuid,
                        'userId' => $requestData['userId'] ?? 'current_user',
                        'error' => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    [
                        'error' => $e->getMessage(),
                    ],
                    Http::STATUS_BAD_REQUEST
                    );
        }//end try

    }//end join()


    /**
     * Leave an organisation by UUID
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $uuid Organisation UUID to leave
     *
     * @return JSONResponse Success or error response
     */
    public function leave(string $uuid): JSONResponse
    {
        try {
            $success = $this->organisationService->leaveOrganisation($uuid);

            if ($success) {
                return new JSONResponse(
                        [
                            'message' => 'Successfully left organisation',
                        ],
                        Http::STATUS_OK
                        );
            } else {
                return new JSONResponse(
                        [
                            'error' => 'Failed to leave organisation',
                        ],
                        Http::STATUS_BAD_REQUEST
                        );
            }
        } catch (Exception $e) {
            $this->logger->error(
                    'Failed to leave organisation',
                    [
                        'uuid'  => $uuid,
                        'error' => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    [
                        'error' => $e->getMessage(),
                    ],
                    Http::STATUS_BAD_REQUEST
                    );
        }//end try

    }//end leave()


    /**
     * Get organisation details by UUID
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $uuid Organisation UUID
     *
     * @return JSONResponse Organisation data
     */
    public function show(string $uuid): JSONResponse
    {
        try {
            // Check if user has access to this organisation
            if (!$this->organisationService->hasAccessToOrganisation($uuid)) {
                return new JSONResponse(
                        [
                            'error' => 'Access denied to this organisation',
                        ],
                        Http::STATUS_FORBIDDEN
                        );
            }

            $organisation = $this->organisationMapper->findByUuid($uuid);

            return new JSONResponse(
                    [
                        'organisation' => $organisation->jsonSerialize(),
                    ],
                    Http::STATUS_OK
                    );
        } catch (Exception $e) {
            $this->logger->error(
                    'Failed to get organisation',
                    [
                        'uuid'  => $uuid,
                        'error' => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    [
                        'error' => 'Organisation not found',
                    ],
                    Http::STATUS_NOT_FOUND
                    );
        }//end try

    }//end show()


    /**
     * Update organisation details
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $uuid        Organisation UUID
     * @param string $name        New organisation name (optional)
     * @param string $description New organisation description (optional)
     *
     * @return JSONResponse Updated organisation data
     */
    public function update(string $uuid, string $name='', string $description=''): JSONResponse
    {
        try {
            // Check if user has access to this organisation
            if (!$this->organisationService->hasAccessToOrganisation($uuid)) {
                return new JSONResponse(
                        [
                            'error' => 'Access denied to this organisation',
                        ],
                        Http::STATUS_FORBIDDEN
                        );
            }

            $organisation = $this->organisationMapper->findByUuid($uuid);

            // Update fields if provided
            if (!empty(trim($name))) {
                $organisation->setName(trim($name));
            }

            if (!empty(trim($description))) {
                $organisation->setDescription(trim($description));
            } else if ($description === '') {
                // Allow clearing description
                $organisation->setDescription('');
            }

            $updated = $this->organisationMapper->save($organisation);

            return new JSONResponse(
                    [
                        'message'      => 'Organisation updated successfully',
                        'organisation' => $updated->jsonSerialize(),
                    ],
                    Http::STATUS_OK
                    );
        } catch (Exception $e) {
            $this->logger->error(
                    'Failed to update organisation',
                    [
                        'uuid'  => $uuid,
                        'error' => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    [
                        'error' => 'Failed to update organisation',
                    ],
                    Http::STATUS_BAD_REQUEST
                    );
        }//end try

    }//end update()


    /**
     * Search organisations by name with pagination (for joining)
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $query Search query
     *
     * @return JSONResponse List of matching organisations with pagination info
     */
    public function search(string $query=''): JSONResponse
    {
        try {
            // Get pagination parameters from request
            $limit  = (int) $this->request->getParam('_limit', 50);
            $offset = (int) $this->request->getParam('_offset', 0);

            // Validate pagination parameters
            $limit  = max(1, min($limit, 100)); // Between 1 and 100
            $offset = max(0, $offset);

            // If query is empty, return all organisations
            // Otherwise search by name
            if (empty(trim($query))) {
                $organisations = $this->organisationMapper->findAll($limit, $offset);
            } else {
                $organisations = $this->organisationMapper->findByName(trim($query), $limit, $offset);
            }

            // Remove user information for privacy
            $publicData = array_map(
                    function ($org) {
                        $data = $org->jsonSerialize();
                        unset($data['users']);
                        // Don't expose user list
                        unset($data['owner']);
                        // Don't expose owner
                        return $data;
                    },
                    $organisations
                    );

            return new JSONResponse(
                    [
                        'organisations' => $publicData,
                        'limit'         => $limit,
                        'offset'        => $offset,
                        'count'         => count($publicData),
                    ],
                    Http::STATUS_OK
                    );
        } catch (Exception $e) {
            $this->logger->error(
                    'Failed to search organisations',
                    [
                        'query' => $query,
                        'error' => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    [
                        'error' => 'Search failed',
                    ],
                    Http::STATUS_INTERNAL_SERVER_ERROR
                    );
        }//end try

    }//end search()


    /**
     * Clear organisation cache for current user
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Success response
     */
    public function clearCache(): JSONResponse
    {
        try {
            $this->organisationService->clearCache();

            return new JSONResponse(
                    [
                        'message' => 'Cache cleared successfully',
                    ],
                    Http::STATUS_OK
                    );
        } catch (Exception $e) {
            $this->logger->error(
                    'Failed to clear cache',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    [
                        'error' => 'Failed to clear cache',
                    ],
                    Http::STATUS_INTERNAL_SERVER_ERROR
                    );
        }//end try

    }//end clearCache()


    /**
     * Get system statistics about organisations (admin only)
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Organisation statistics
     */
    public function stats(): JSONResponse
    {
        try {
            $stats = $this->organisationMapper->getStatistics();

            return new JSONResponse(
                    [
                        'statistics' => $stats,
                    ],
                    Http::STATUS_OK
                    );
        } catch (Exception $e) {
            $this->logger->error(
                    'Failed to get organisation statistics',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    [
                        'error' => 'Failed to retrieve statistics',
                    ],
                    Http::STATUS_INTERNAL_SERVER_ERROR
                    );
        }//end try

    }//end stats()


}//end class
