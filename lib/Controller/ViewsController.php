<?php

/**
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
 * @version   GIT: <git_id>
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
 *
 * @psalm-suppress UnusedClass
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
        parent::__construct(appName: $appName, request: $request);
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
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with views or error
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
                    statusCode: 401
                );
            }

            $params = $this->request->getParams();

            // Extract pagination and search parameters (for future use).
            $limit = null;
            if (($params['_limit'] ?? null) !== null) {
                $limit = (int) $params['_limit'];
            }

            $offset = null;
            if (($params['_offset'] ?? null) !== null) {
                $offset = (int) $params['_offset'];
            }

            $page = null;
            if (($params['_page'] ?? null) !== null) {
                $page = (int) $params['_page'];
            }

            // Note: search parameter not currently used in this endpoint.
            $views = $this->viewService->findAll($userId);

            // Apply client-side pagination if parameters are provided.
            $total = count($views);
            if ($limit !== null) {
                if ($page !== null) {
                    $offset = ($page - 1) * $limit;
                }

                $sliceOffset = 0;
                if ($offset !== null) {
                    $sliceOffset = $offset;
                }

                $views = array_slice(array: $views, offset: $sliceOffset, length: $limit);
            }

            return new JSONResponse(
                data: [
                    'results' => array_map(fn($view) => $view->jsonSerialize(), $views),
                    'total'   => $total,
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'Error fetching views',
                context: [
                    'exception' => $e->getMessage(),
                ]
            );
            return new JSONResponse(
                data: [
                    'error'   => 'Failed to fetch views',
                    'message' => $e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end index()

    /**
     * Get a specific view by ID
     *
     * @param string $id The view ID (UUID or numeric ID)
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with view or error
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
                    data: [
                        'error' => 'User not authenticated',
                    ],
                    statusCode: 401
                );
            }

            $view = $this->viewService->find(id: $id, owner: $userId);

            return new JSONResponse(
                data: [
                    'view' => $view->jsonSerialize(),
                ]
            );
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: [
                    'error' => 'View not found',
                ],
                statusCode: 404
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'Error fetching view',
                context: [
                    'id'        => $id,
                    'exception' => $e->getMessage(),
                ]
            );
            return new JSONResponse(
                data: [
                    'error'   => 'Failed to fetch view',
                    'message' => $e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end show()

    /**
     * Create a new view
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with created view or error
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
                    data: [
                        'error' => 'User not authenticated',
                    ],
                    statusCode: 401
                );
            }

            $data = $this->request->getParams();

            // Validate required fields.
            if (isset($data['name']) === false || empty($data['name']) === true) {
                return new JSONResponse(
                    data: [
                        'error' => 'View name is required',
                    ],
                    statusCode: 400
                );
            }

            // Extract query parameters from configuration or query.
            if (($data['configuration'] ?? null) !== null && is_array($data['configuration']) === true) {
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
            } else if (($data['query'] ?? null) !== null && is_array($data['query']) === true) {
                // Direct query parameter.
                $query = $data['query'];
            }

            if (($data['configuration'] ?? null) === null && (($data['query'] ?? null) === null || is_array($data['query']) === false)) {
                return new JSONResponse(
                    data: [
                        'error' => 'View query or configuration is required',
                    ],
                    statusCode: 400
                );
            }

            $view = $this->viewService->create(
                name: $data['name'],
                description: $data['description'] ?? '',
                owner: $userId,
                isPublic: $data['isPublic'] ?? false,
                isDefault: $data['isDefault'] ?? false,
                query: $query
            );

            return new JSONResponse(
                data: [
                    'view' => $view->jsonSerialize(),
                ],
                statusCode: 201
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'Error creating view',
                context: [
                    'exception' => $e->getMessage(),
                ]
            );
            return new JSONResponse(
                data: [
                    'error'   => 'Failed to create view',
                    'message' => $e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end create()

    /**
     * Update an existing view
     *
     * @param string $id The view ID
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with updated view or error
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
                    data: [
                        'error' => 'User not authenticated',
                    ],
                    statusCode: 401
                );
            }

            $data = $this->request->getParams();

            // Validate required fields.
            if (isset($data['name']) === false || empty($data['name']) === true) {
                return new JSONResponse(
                    data: [
                        'error' => 'View name is required',
                    ],
                    statusCode: 400
                );
            }

            // Extract query parameters from configuration or query.
            if (($data['configuration'] ?? null) !== null && is_array($data['configuration']) === true) {
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
            } else if (($data['query'] ?? null) !== null && is_array($data['query']) === true) {
                // Direct query parameter.
                $query = $data['query'];
            }

            if (($data['configuration'] ?? null) === null && (($data['query'] ?? null) === null || is_array($data['query']) === false)) {
                return new JSONResponse(
                    data: [
                        'error' => 'View query or configuration is required',
                    ],
                    statusCode: 400
                );
            }

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
                data: [
                    'view' => $view->jsonSerialize(),
                ]
            );
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: [
                    'error' => 'View not found',
                ],
                statusCode: 404
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'Error updating view',
                context: [
                    'id'        => $id,
                    'exception' => $e->getMessage(),
                ]
            );
            return new JSONResponse(
                data: [
                    'error'   => 'Failed to update view',
                    'message' => $e->getMessage(),
                ],
                statusCode: 500
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
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with patched view or error
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
                    data: [
                        'error' => 'User not authenticated',
                    ],
                    statusCode: 401
                );
            }

            // Get existing view.
            $view = $this->viewService->find(id: $id, owner: $userId);

            $data = $this->request->getParams();

            // Use existing values for fields not provided.
            $name        = $data['name'] ?? $view->getName() ?? '';
            $description = $data['description'] ?? $view->getDescription() ?? '';
            $isPublic    = $view->getIsPublic();
            if (($data['isPublic'] ?? null) !== null) {
                $isPublic = $data['isPublic'];
            }

            $isDefault = $view->getIsDefault();
            if (($data['isDefault'] ?? null) !== null) {
                $isDefault = $data['isDefault'];
            }

            $favoredBy = $data['favoredBy'] ?? $view->getFavoredBy();

            // Handle query parameter.
            $query = $view->getQuery() ?? [];
            if (($data['configuration'] ?? null) !== null && is_array($data['configuration']) === true) {
                $config = $data['configuration'];
                $query  = [
                    'registers'     => $config['registers'] ?? [],
                    'schemas'       => $config['schemas'] ?? [],
                    'source'        => $config['source'] ?? 'auto',
                    'searchTerms'   => $config['searchTerms'] ?? [],
                    'facetFilters'  => $config['facetFilters'] ?? [],
                    'enabledFacets' => $config['enabledFacets'] ?? [],
                ];
            } else if (($data['query'] ?? null) !== null && is_array($data['query']) === true) {
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
                data: [
                    'view' => $updatedView->jsonSerialize(),
                ]
            );
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: [
                    'error' => 'View not found',
                ],
                statusCode: 404
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'Error patching view',
                context: [
                    'id'        => $id,
                    'exception' => $e->getMessage(),
                ]
            );
            return new JSONResponse(
                data: [
                    'error'   => 'Failed to patch view',
                    'message' => $e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end patch()

    /**
     * Delete a view
     *
     * @param string $id The view ID
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with delete confirmation or error
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
                    data: [
                        'error' => 'User not authenticated',
                    ],
                    statusCode: 401
                );
            }

            $user = $this->userSession->getUser();
            if ($user === null) {
                return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => 'User not authenticated',
                    ],
                    statusCode: 401
                );
            }

            $this->viewService->delete(id: $id, owner: $user->getUID());

            return new JSONResponse(
                data: [
                    'message' => 'View deleted successfully',
                ],
                statusCode: 204
            );
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: [
                    'error' => 'View not found',
                ],
                statusCode: 404
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'Error deleting view',
                context: [
                    'id'        => $id,
                    'exception' => $e->getMessage(),
                ]
            );
            return new JSONResponse(
                data: [
                    'error'   => 'Failed to delete view',
                    'message' => $e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end destroy()
}//end class
