<?php

declare(strict_types=1);

/*
 * ViewsController
 *
 * Controller for managing saved search views across multiple registers and schemas.
 * Provides CRUD operations for views that store search configurations.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version   1.0.0
 * @link      https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\ViewService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Controller for managing saved search views
 *
 * This controller handles operations for creating, reading, updating, and deleting
 * saved search views. Views allow users to save complex search configurations
 * including multiple registers, schemas, filters, and display settings.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class ViewsController extends Controller
{

    /**
     * The view service for managing views
     *
     * @var ViewService
     */
    private ViewService $viewService;

    /**
     * The user session for getting current user
     *
     * @var IUserSession
     */
    private IUserSession $userSession;

    /**
     * The logger interface
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;


    /**
     * Constructor for ViewsController
     *
     * @param string          $appName     The app name
     * @param IRequest        $request     The request object
     * @param ViewService     $viewService The view service
     * @param IUserSession    $userSession The user session
     * @param LoggerInterface $logger      The logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        ViewService $viewService,
        IUserSession $userSession,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        $this->viewService = $viewService;
        $this->userSession = $userSession;
        $this->logger      = $logger;

    }//end __construct()


    /**
     * Get all views for the current user
     *
     * This method retrieves all saved views that belong to the current user,
     * as well as any public views shared by other users.
     *
     * @return JSONResponse A JSON response containing the list of views
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): JSONResponse
    {
        try {
            $user   = $this->userSession->getUser();
            $userId = '';
            if ($user !== null) {
                $userId = $user->getUID();
            }

            if (empty($userId) === true) {
                return new JSONResponse(
                        [
                            'error' => 'User not authenticated',
                        ],
                        401
                        );
            }

            $params = $this->request->getParams();

            // Extract pagination and search parameters (for future use).
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

            $search = $params['_search'] ?? '';

            $views = $this->viewService->findAll($userId);

            // Apply client-side pagination if parameters are provided.
            $total = count($views);
            if ($limit !== null) {
                if ($page !== null) {
                    $offset = ($page - 1) * $limit;
                }

                if ($offset !== null) {
                    $views = array_slice($views, $offset, $limit);
                } else {
                    $views = array_slice($views, 0, $limit);
                }
            }

            return new JSONResponse(
                    [
                        'results' => array_map(fn($view) => $view->jsonSerialize(), $views),
                        'total'   => $total,
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    'Error fetching views',
                    [
                        'exception' => $e->getMessage(),
                    ]
                    );
            return new JSONResponse(
                    [
                        'error'   => 'Failed to fetch views',
                        'message' => $e->getMessage(),
                    ],
                    500
                    );
        }//end try

    }//end index()


    /**
     * Get a specific view by ID
     *
     * @param string $id The view ID (UUID or numeric ID)
     *
     * @return JSONResponse A JSON response containing the view
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function show(string $id): JSONResponse
    {
        try {
            $user   = $this->userSession->getUser();
            $userId = '';
            if ($user !== null) {
                $userId = $user->getUID();
            }

            if (empty($userId) === true) {
                return new JSONResponse(
                        [
                            'error' => 'User not authenticated',
                        ],
                        401
                        );
            }

            $view = $this->viewService->find($id, $userId);

            return new JSONResponse(
                    [
                        'view' => $view->jsonSerialize(),
                    ]
                    );
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                    [
                        'error' => 'View not found',
                    ],
                    404
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    'Error fetching view',
                    [
                        'id'        => $id,
                        'exception' => $e->getMessage(),
                    ]
                    );
            return new JSONResponse(
                    [
                        'error'   => 'Failed to fetch view',
                        'message' => $e->getMessage(),
                    ],
                    500
                    );
        }//end try

    }//end show()


    /**
     * Create a new view
     *
     * @return JSONResponse A JSON response containing the created view
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function create(): JSONResponse
    {
        try {
            $user   = $this->userSession->getUser();
            $userId = '';
            if ($user !== null) {
                $userId = $user->getUID();
            }

            if (empty($userId) === true) {
                return new JSONResponse(
                        [
                            'error' => 'User not authenticated',
                        ],
                        401
                        );
            }

            $data = $this->request->getParams();

            // Validate required fields.
            if (isset($data['name']) === false || empty($data['name']) === true) {
                return new JSONResponse(
                        [
                            'error' => 'View name is required',
                        ],
                        400
                        );
            }

            // Extract query parameters from configuration or query.
            $query = [];
            if (isset($data['configuration']) === true && is_array($data['configuration']) === true) {
                // Frontend still sends 'configuration', extract only query params.
                $config = $data['configuration'];
                $query  = [
                    'registers'     => $config['registers'] ?? [],
                    'schemas'       => $config['schemas'] ?? [],
                    'source'        => $config['source'] ?? 'auto',
                    'searchTerms'   => $config['searchTerms'] ?? [],
                    'facetFilters'  => $config['facetFilters'] ?? [],
                    'enabledFacets' => $config['enabledFacets'] ?? [],
                ];
            } else if (isset($data['query']) === true && is_array($data['query']) === true) {
                // Direct query parameter.
                $query = $data['query'];
            } else {
                return new JSONResponse(
                        [
                            'error' => 'View query or configuration is required',
                        ],
                        400
                        );
            }//end if

            $view = $this->viewService->create(
                name: $data['name'],
                description: $data['description'] ?? '',
                owner: $userId,
                isPublic: $data['isPublic'] ?? false,
                isDefault: $data['isDefault'] ?? false,
                query: $query
            );

            return new JSONResponse(
                    [
                        'view' => $view->jsonSerialize(),
                    ],
                    201
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    'Error creating view',
                    [
                        'exception' => $e->getMessage(),
                    ]
                    );
            return new JSONResponse(
                    [
                        'error'   => 'Failed to create view',
                        'message' => $e->getMessage(),
                    ],
                    500
                    );
        }//end try

    }//end create()


    /**
     * Update an existing view
     *
     * @param string $id The view ID
     *
     * @return JSONResponse A JSON response containing the updated view
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function update(string $id): JSONResponse
    {
        try {
            $user   = $this->userSession->getUser();
            $userId = '';
            if ($user !== null) {
                $userId = $user->getUID();
            }

            if (empty($userId) === true) {
                return new JSONResponse(
                        [
                            'error' => 'User not authenticated',
                        ],
                        401
                        );
            }

            $data = $this->request->getParams();

            // Validate required fields.
            if (isset($data['name']) === false || empty($data['name']) === true) {
                return new JSONResponse(
                        [
                            'error' => 'View name is required',
                        ],
                        400
                        );
            }

            // Extract query parameters from configuration or query.
            $query = [];
            if (isset($data['configuration']) === true && is_array($data['configuration']) === true) {
                // Frontend still sends 'configuration', extract only query params.
                $config = $data['configuration'];
                $query  = [
                    'registers'     => $config['registers'] ?? [],
                    'schemas'       => $config['schemas'] ?? [],
                    'source'        => $config['source'] ?? 'auto',
                    'searchTerms'   => $config['searchTerms'] ?? [],
                    'facetFilters'  => $config['facetFilters'] ?? [],
                    'enabledFacets' => $config['enabledFacets'] ?? [],
                ];
            } else if (isset($data['query']) === true && is_array($data['query']) === true) {
                // Direct query parameter.
                $query = $data['query'];
            } else {
                return new JSONResponse(
                        [
                            'error' => 'View query or configuration is required',
                        ],
                        400
                        );
            }//end if

            $view = $this->viewService->update(
                id: $id,
                name: $data['name'],
                description: $data['description'] ?? '',
                owner: $userId,
                isPublic: $data['isPublic'] ?? false,
                isDefault: $data['isDefault'] ?? false,
                query: $query
            );

            return new JSONResponse(
                    [
                        'view' => $view->jsonSerialize(),
                    ]
                    );
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                    [
                        'error' => 'View not found',
                    ],
                    404
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    'Error updating view',
                    [
                        'id'        => $id,
                        'exception' => $e->getMessage(),
                    ]
                    );
            return new JSONResponse(
                    [
                        'error'   => 'Failed to update view',
                        'message' => $e->getMessage(),
                    ],
                    500
                    );
        }//end try

    }//end update()


    /**
     * Patch view details (partial update)
     *
     * Updates only the fields provided in the request.
     * This is different from PUT (update) which requires all fields.
     *
     * @param string $id View ID.
     *
     * @return JSONResponse Updated view data.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function patch(string $id): JSONResponse
    {
        try {
            $user   = $this->userSession->getUser();
            $userId = '';
            if ($user !== null) {
                $userId = $user->getUID();
            }

            if (empty($userId) === true) {
                return new JSONResponse(
                        [
                            'error' => 'User not authenticated',
                        ],
                        401
                        );
            }

            // Get existing view.
            $view = $this->viewService->find($id, $userId);

            $data = $this->request->getParams();

            // Use existing values for fields not provided.
            $name        = $data['name'] ?? $view->getName();
            $description = $data['description'] ?? $view->getDescription();
            $isPublic    = $view->getIsPublic();
            if (isset($data['isPublic']) === true) {
                $isPublic = $data['isPublic'];
            }

            $isDefault = $view->getIsDefault();
            if (isset($data['isDefault']) === true) {
                $isDefault = $data['isDefault'];
            }

            $favoredBy = $data['favoredBy'] ?? $view->getFavoredBy();

            // Handle query parameter.
            $query = $view->getQuery() ?? [];
            if (isset($data['configuration']) === true && is_array($data['configuration']) === true) {
                $config = $data['configuration'];
                $query  = [
                    'registers'     => $config['registers'] ?? [],
                    'schemas'       => $config['schemas'] ?? [],
                    'source'        => $config['source'] ?? 'auto',
                    'searchTerms'   => $config['searchTerms'] ?? [],
                    'facetFilters'  => $config['facetFilters'] ?? [],
                    'enabledFacets' => $config['enabledFacets'] ?? [],
                ];
            } else if (isset($data['query']) === true && is_array($data['query']) === true) {
                $query = $data['query'];
            }

            // Update view.
            $updatedView = $this->viewService->update(
                id: $id,
                name: $name,
                description: $description,
                owner: $userId,
                isPublic: $isPublic,
                isDefault: $isDefault,
                query: $query,
                favoredBy: $favoredBy
            );

            return new JSONResponse(
                    [
                        'view' => $updatedView->jsonSerialize(),
                    ]
                    );
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                    [
                        'error' => 'View not found',
                    ],
                    404
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    'Error patching view',
                    [
                        'id'        => $id,
                        'exception' => $e->getMessage(),
                    ]
                    );
            return new JSONResponse(
                    [
                        'error'   => 'Failed to patch view',
                        'message' => $e->getMessage(),
                    ],
                    500
                    );
        }//end try

    }//end patch()


    /**
     * Delete a view
     *
     * @param string $id The view ID
     *
     * @return JSONResponse A JSON response confirming deletion
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function destroy(string $id): JSONResponse
    {
        try {
            $user   = $this->userSession->getUser();
            $userId = '';
            if ($user !== null) {
                $userId = $user->getUID();
            }

            if (empty($userId) === true) {
                return new JSONResponse(
                        [
                            'error' => 'User not authenticated',
                        ],
                        401
                        );
            }

            $this->viewService->delete($id, $userId);

            return new JSONResponse(
                    [
                        'message' => 'View deleted successfully',
                    ],
                    204
                    );
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                    [
                        'error' => 'View not found',
                    ],
                    404
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    'Error deleting view',
                    [
                        'id'        => $id,
                        'exception' => $e->getMessage(),
                    ]
                    );
            return new JSONResponse(
                    [
                        'error'   => 'Failed to delete view',
                        'message' => $e->getMessage(),
                    ],
                    500
                    );
        }//end try

    }//end destroy()


}//end class
