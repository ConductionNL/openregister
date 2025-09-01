<?php
/**
 * OpenRegister Bulk Operations Controller
 *
 * Controller for handling bulk operations on objects in the OpenRegister app.
 * Provides endpoints for bulk delete, publish, and depublish operations.
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

use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IGroupManager;
use OCP\AppFramework\Db\DoesNotExistException;
use Exception;

/**
 * Bulk operations controller for OpenRegister
 */
class BulkController extends Controller
{


    /**
     * Constructor for the BulkController
     *
     * @param string        $appName       The name of the app
     * @param IRequest      $request       The request object
     * @param ObjectService $objectService The object service
     * @param IUserSession  $userSession   The user session
     * @param IGroupManager $groupManager  The group manager
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ObjectService $objectService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager
    ) {
        parent::__construct($appName, $request);

    }//end __construct()


    /**
     * Check if the current user is an admin
     *
     * @return bool True if the current user is an admin, false otherwise
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
     * Perform bulk delete operations on objects
     *
     * @param string $register The register identifier
     * @param string $schema   The schema identifier
     *
     * @return JSONResponse Response with the result of the bulk delete operation
     *
     * @NoCSRFRequired
     */
    public function delete(string $register, string $schema): JSONResponse
    {
        try {
            // Check if user is admin
            if (!$this->isCurrentUserAdmin()) {
                return new JSONResponse(
                    ['error' => 'Insufficient permissions. Admin access required.'],
                    Http::STATUS_FORBIDDEN
                );
            }

            // Get request data
            $data  = $this->request->getParams();
            $uuids = $data['uuids'] ?? [];

            // Validate input
            if (empty($uuids) || !is_array($uuids)) {
                return new JSONResponse(
                    ['error' => 'Invalid input. "uuids" array is required.'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            // Set register and schema context
            $this->objectService->setRegister($register);
            $this->objectService->setSchema($schema);

            // Perform bulk delete operation
            $deletedUuids = $this->objectService->deleteObjects($uuids);

            return new JSONResponse(
                    [
                        'success'         => true,
                        'message'         => 'Bulk delete operation completed successfully',
                        'deleted_count'   => count($deletedUuids),
                        'deleted_uuids'   => $deletedUuids,
                        'requested_count' => count($uuids),
                        'skipped_count'   => count($uuids) - count($deletedUuids),
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(
                ['error' => 'Bulk delete operation failed: '.$e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try

    }//end delete()


    /**
     * Perform bulk publish operations on objects
     *
     * @param string $register The register identifier
     * @param string $schema   The schema identifier
     *
     * @return JSONResponse Response with the result of the bulk publish operation
     *
     * @NoCSRFRequired
     */
    public function publish(string $register, string $schema): JSONResponse
    {
        try {
            // Check if user is admin
            if (!$this->isCurrentUserAdmin()) {
                return new JSONResponse(
                    ['error' => 'Insufficient permissions. Admin access required.'],
                    Http::STATUS_FORBIDDEN
                );
            }

            // Get request data
            $data     = $this->request->getParams();
            $uuids    = $data['uuids'] ?? [];
            $datetime = $data['datetime'] ?? true;

            // Validate input
            if (empty($uuids) || !is_array($uuids)) {
                return new JSONResponse(
                    ['error' => 'Invalid input. "uuids" array is required.'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            // Parse datetime if provided
            if ($datetime !== true && $datetime !== false && $datetime !== null) {
                try {
                    $datetime = new \DateTime($datetime);
                } catch (Exception $e) {
                    return new JSONResponse(
                        ['error' => 'Invalid datetime format. Use ISO 8601 format (e.g., "2024-01-01T12:00:00Z").'],
                        Http::STATUS_BAD_REQUEST
                    );
                }
            }

            // Set register and schema context
            $this->objectService->setRegister($register);
            $this->objectService->setSchema($schema);

            // Perform bulk publish operation
            $publishedUuids = $this->objectService->publishObjects($uuids, $datetime);

            return new JSONResponse(
                    [
                        'success'         => true,
                        'message'         => 'Bulk publish operation completed successfully',
                        'published_count' => count($publishedUuids),
                        'published_uuids' => $publishedUuids,
                        'requested_count' => count($uuids),
                        'skipped_count'   => count($uuids) - count($publishedUuids),
                        'datetime_used'   => $datetime instanceof \DateTime ? $datetime->format('Y-m-d H:i:s') : $datetime,
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(
                ['error' => 'Bulk publish operation failed: '.$e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try

    }//end publish()


    /**
     * Perform bulk depublish operations on objects
     *
     * @param string $register The register identifier
     * @param string $schema   The schema identifier
     *
     * @return JSONResponse Response with the result of the bulk depublish operation
     *
     * @NoCSRFRequired
     */
    public function depublish(string $register, string $schema): JSONResponse
    {
        try {
            // Check if user is admin
            if (!$this->isCurrentUserAdmin()) {
                return new JSONResponse(
                    ['error' => 'Insufficient permissions. Admin access required.'],
                    Http::STATUS_FORBIDDEN
                );
            }

            // Get request data
            $data     = $this->request->getParams();
            $uuids    = $data['uuids'] ?? [];
            $datetime = $data['datetime'] ?? true;

            // Validate input
            if (empty($uuids) || !is_array($uuids)) {
                return new JSONResponse(
                    ['error' => 'Invalid input. "uuids" array is required.'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            // Parse datetime if provided
            if ($datetime !== true && $datetime !== false && $datetime !== null) {
                try {
                    $datetime = new \DateTime($datetime);
                } catch (Exception $e) {
                    return new JSONResponse(
                        ['error' => 'Invalid datetime format. Use ISO 8601 format (e.g., "2024-01-01T12:00:00Z").'],
                        Http::STATUS_BAD_REQUEST
                    );
                }
            }

            // Set register and schema context
            $this->objectService->setRegister($register);
            $this->objectService->setSchema($schema);

            // Perform bulk depublish operation
            $depublishedUuids = $this->objectService->depublishObjects($uuids, $datetime);

            return new JSONResponse(
                    [
                        'success'           => true,
                        'message'           => 'Bulk depublish operation completed successfully',
                        'depublished_count' => count($depublishedUuids),
                        'depublished_uuids' => $depublishedUuids,
                        'requested_count'   => count($uuids),
                        'skipped_count'     => count($uuids) - count($depublishedUuids),
                        'datetime_used'     => $datetime instanceof \DateTime ? $datetime->format('Y-m-d H:i:s') : $datetime,
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(
                ['error' => 'Bulk depublish operation failed: '.$e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try

    }//end depublish()


    /**
     * Perform bulk save operations on objects
     *
     * @param string $register The register identifier
     * @param string $schema   The schema identifier
     *
     * @return JSONResponse Response with the result of the bulk save operation
     *
     * @NoCSRFRequired
     */
    public function save(string $register, string $schema): JSONResponse
    {
        try {
            // Check if user is admin
            if (!$this->isCurrentUserAdmin()) {
                return new JSONResponse(
                    ['error' => 'Insufficient permissions. Admin access required.'],
                    Http::STATUS_FORBIDDEN
                );
            }

            // Get request data
            $data    = $this->request->getParams();
            $objects = $data['objects'] ?? [];

            // Validate input
            if (empty($objects) || !is_array($objects)) {
                return new JSONResponse(
                    ['error' => 'Invalid input. "objects" array is required.'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            // FLEXIBLE SCHEMA HANDLING: Support both single-schema and mixed-schema operations
            // Use schema=0 to indicate mixed-schema operations where objects specify their own schemas
            
            $isMixedSchemaOperation = ($schema === '0' || $schema === 0);
            
            if ($isMixedSchemaOperation) {
                // Mixed-schema operation - don't set a specific schema context
                $this->objectService->setRegister($register);
                // Don't call setSchema() for mixed operations
                
                $savedObjects = $this->objectService->saveObjects(
                    objects: $objects,
                    register: $register,
                    schema: null, // Allow objects to specify their own schemas
                    rbac: true,
                    multi: true,
                    validation: true,
                    events: false
                );
            } else {
                // Single-schema operation - traditional behavior
                $this->objectService->setRegister($register);
                $this->objectService->setSchema($schema);

                $savedObjects = $this->objectService->saveObjects(
                    objects: $objects,
                    register: $register,
                    schema: $schema,
                    rbac: true,
                    multi: true,
                    validation: true,
                    events: false
                );
            }

            return new JSONResponse(
                    [
                        'success'         => true,
                        'message'         => 'Bulk save operation completed successfully',
                        'saved_count'     => ($savedObjects['statistics']['saved'] ?? 0) + ($savedObjects['statistics']['updated'] ?? 0),
                        'saved_objects'   => $savedObjects,
                        'requested_count' => count($objects),
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(
                ['error' => 'Bulk save operation failed: '.$e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try

    }//end save()


}//end class
