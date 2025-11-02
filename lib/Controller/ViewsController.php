<?php

declare(strict_types=1);

/**
 * ViewsController
 *
 * Controller for managing saved search views across multiple registers and schemas.
 * Provides CRUD operations for views that store search configurations.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 * @author   Conduction Development Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
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
        $this->logger = $logger;
        
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
            $user = $this->userSession->getUser();
            $userId = $user ? $user->getUID() : '';
            
            if (empty($userId)) {
                return new JSONResponse([
                    'error' => 'User not authenticated',
                ], 401);
            }
            
            $views = $this->viewService->findAll($userId);
            
            return new JSONResponse([
                'results' => array_map(fn($view) => $view->jsonSerialize(), $views),
                'total' => count($views),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error fetching views', [
                'exception' => $e->getMessage(),
            ]);
            return new JSONResponse([
                'error' => 'Failed to fetch views',
                'message' => $e->getMessage(),
            ], 500);
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
            $user = $this->userSession->getUser();
            $userId = $user ? $user->getUID() : '';
            
            if (empty($userId)) {
                return new JSONResponse([
                    'error' => 'User not authenticated',
                ], 401);
            }
            
            $view = $this->viewService->find($id, $userId);
            
            return new JSONResponse([
                'view' => $view->jsonSerialize(),
            ]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse([
                'error' => 'View not found',
            ], 404);
        } catch (\Exception $e) {
            $this->logger->error('Error fetching view', [
                'id' => $id,
                'exception' => $e->getMessage(),
            ]);
            return new JSONResponse([
                'error' => 'Failed to fetch view',
                'message' => $e->getMessage(),
            ], 500);
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
            $user = $this->userSession->getUser();
            $userId = $user ? $user->getUID() : '';
            
            if (empty($userId)) {
                return new JSONResponse([
                    'error' => 'User not authenticated',
                ], 401);
            }
            
            $data = $this->request->getParams();
            
            // Validate required fields
            if (!isset($data['name']) || empty($data['name'])) {
                return new JSONResponse([
                    'error' => 'View name is required',
                ], 400);
            }
            
            // Extract query parameters from configuration or query
            $query = [];
            if (isset($data['configuration']) && is_array($data['configuration'])) {
                // Frontend still sends 'configuration', extract only query params
                $config = $data['configuration'];
                $query = [
                    'registers' => $config['registers'] ?? [],
                    'schemas' => $config['schemas'] ?? [],
                    'source' => $config['source'] ?? 'auto',
                    'searchTerms' => $config['searchTerms'] ?? [],
                    'facetFilters' => $config['facetFilters'] ?? [],
                    'enabledFacets' => $config['enabledFacets'] ?? [],
                ];
            } elseif (isset($data['query']) && is_array($data['query'])) {
                // Direct query parameter
                $query = $data['query'];
            } else {
                return new JSONResponse([
                    'error' => 'View query or configuration is required',
                ], 400);
            }
            
            $view = $this->viewService->create(
                name: $data['name'],
                description: $data['description'] ?? '',
                owner: $userId,
                isPublic: $data['isPublic'] ?? false,
                isDefault: $data['isDefault'] ?? false,
                query: $query
            );
            
            return new JSONResponse([
                'view' => $view->jsonSerialize(),
            ], 201);
        } catch (\Exception $e) {
            $this->logger->error('Error creating view', [
                'exception' => $e->getMessage(),
            ]);
            return new JSONResponse([
                'error' => 'Failed to create view',
                'message' => $e->getMessage(),
            ], 500);
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
            $user = $this->userSession->getUser();
            $userId = $user ? $user->getUID() : '';
            
            if (empty($userId)) {
                return new JSONResponse([
                    'error' => 'User not authenticated',
                ], 401);
            }
            
            $data = $this->request->getParams();
            
            // Validate required fields
            if (!isset($data['name']) || empty($data['name'])) {
                return new JSONResponse([
                    'error' => 'View name is required',
                ], 400);
            }
            
            // Extract query parameters from configuration or query
            $query = [];
            if (isset($data['configuration']) && is_array($data['configuration'])) {
                // Frontend still sends 'configuration', extract only query params
                $config = $data['configuration'];
                $query = [
                    'registers' => $config['registers'] ?? [],
                    'schemas' => $config['schemas'] ?? [],
                    'source' => $config['source'] ?? 'auto',
                    'searchTerms' => $config['searchTerms'] ?? [],
                    'facetFilters' => $config['facetFilters'] ?? [],
                    'enabledFacets' => $config['enabledFacets'] ?? [],
                ];
            } elseif (isset($data['query']) && is_array($data['query'])) {
                // Direct query parameter
                $query = $data['query'];
            } else {
                return new JSONResponse([
                    'error' => 'View query or configuration is required',
                ], 400);
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
            
            return new JSONResponse([
                'view' => $view->jsonSerialize(),
            ]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse([
                'error' => 'View not found',
            ], 404);
        } catch (\Exception $e) {
            $this->logger->error('Error updating view', [
                'id' => $id,
                'exception' => $e->getMessage(),
            ]);
            return new JSONResponse([
                'error' => 'Failed to update view',
                'message' => $e->getMessage(),
            ], 500);
        }//end try
        
    }//end update()
    
    
    /**
     * Patch view details (alias for update)
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $id View ID
     *
     * @return JSONResponse Updated view data
     */
    public function patch(string $id): JSONResponse
    {
        return $this->update($id);

    }//end patch()
    
    
    /**
     * Toggle favorite status for a view
     *
     * @param string $id The view ID
     *
     * @return JSONResponse A JSON response with updated view
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function favorite(string $id): JSONResponse
    {
        try {
            $user = $this->userSession->getUser();
            $userId = $user ? $user->getUID() : '';
            
            if (empty($userId)) {
                return new JSONResponse([
                    'error' => 'User not authenticated',
                ], 401);
            }
            
            $data = $this->request->getParams();
            $favor = $data['favor'] ?? true; // Default to true (add favorite)
            
            $view = $this->viewService->toggleFavorite($id, $userId, $favor);
            
            return new JSONResponse([
                'view' => $view->jsonSerialize(),
            ]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse([
                'error' => 'View not found',
            ], 404);
        } catch (\Exception $e) {
            $this->logger->error('Error toggling favorite', [
                'id' => $id,
                'exception' => $e->getMessage(),
            ]);
            return new JSONResponse([
                'error' => 'Failed to toggle favorite',
                'message' => $e->getMessage(),
            ], 500);
        }//end try
        
    }//end favorite()
    
    
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
            $user = $this->userSession->getUser();
            $userId = $user ? $user->getUID() : '';
            
            if (empty($userId)) {
                return new JSONResponse([
                    'error' => 'User not authenticated',
                ], 401);
            }
            
            $this->viewService->delete($id, $userId);
            
            return new JSONResponse([
                'message' => 'View deleted successfully',
            ], 204);
        } catch (DoesNotExistException $e) {
            return new JSONResponse([
                'error' => 'View not found',
            ], 404);
        } catch (\Exception $e) {
            $this->logger->error('Error deleting view', [
                'id' => $id,
                'exception' => $e->getMessage(),
            ]);
            return new JSONResponse([
                'error' => 'Failed to delete view',
                'message' => $e->getMessage(),
            ], 500);
        }//end try
        
    }//end destroy()
    
    
}//end class
