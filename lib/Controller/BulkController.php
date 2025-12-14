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
use DateTime;

/**
 * Bulk operations controller for OpenRegister
 */
/**
 * @psalm-suppress UnusedClass
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
        parent::__construct(appName: $appName, request: $request);

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
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400|403|500, array{error?: string, success?: true, message?: 'Bulk delete operation completed successfully', deleted_count?: int<0, max>, deleted_uuids?: array<int, string>, requested_count?: int<0, max>, skipped_count?: int<min, max>}, array<never, never>>
     */
    public function delete(string $register, string $schema): JSONResponse
    {
        try {
            // Check if user is admin.
            if ($this->isCurrentUserAdmin() === false) {
                return new JSONResponse(data: ['error' => 'Insufficient permissions. Admin access required.'], statusCode: Http::STATUS_FORBIDDEN);
            }

            // Get request data.
            $data  = $this->request->getParams();
            $uuids = $data['uuids'] ?? [];

            // Validate input.
            if (empty($uuids) === true || is_array($uuids) === false) {
                return new JSONResponse(data: ['error' => 'Invalid input. "uuids" array is required.'], statusCode: Http::STATUS_BAD_REQUEST);
            }

            // Set register and schema context.
            $this->objectService->setRegister($register);
            $this->objectService->setSchema($schema);

            // Perform bulk delete operation.
            $deletedUuids = $this->objectService->deleteObjects($uuids);

            return new JSONResponse(
                    data: [
                        'success'         => true,
                        'message'         => 'Bulk delete operation completed successfully',
                        'deleted_count'   => count($deletedUuids),
                        'deleted_uuids'   => $deletedUuids,
                        'requested_count' => count($uuids),
                        'skipped_count'   => count($uuids) - count($deletedUuids),
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => 'Bulk delete operation failed: '.$e->getMessage()], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end delete()


    /**
     * Perform bulk publish operations on objects
     *
     * @param string $register The register identifier
     * @param string $schema   The schema identifier
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400|403|500, array{error?: string, success: true, message: 'Bulk publish operation completed successfully', published_count: int<0, max>, published_uuids: array<int, string>, requested_count: int<0, max>, skipped_count: int<min, max>, datetime_used: bool|null|string}, array<never, never>>|JSONResponse<400|403|500, array{error: string}, array<never, never>>
     */
    public function publish(string $register, string $schema): JSONResponse
    {
        try {
            // Check if user is admin.
            if ($this->isCurrentUserAdmin() === false) {
                return new JSONResponse(data: ['error' => 'Insufficient permissions. Admin access required.'], statusCode: Http::STATUS_FORBIDDEN);
            }

            // Get request data.
            $data     = $this->request->getParams();
            $uuids    = $data['uuids'] ?? [];
            $datetime = $data['datetime'] ?? true;

            // Validate input.
            if (empty($uuids) === true || is_array($uuids) === false) {
                return new JSONResponse(data: ['error' => 'Invalid input. "uuids" array is required.'], statusCode: Http::STATUS_BAD_REQUEST);
            }

            // Parse datetime if provided.
            if ($datetime !== true && $datetime !== false && $datetime !== null) {
                try {
                    $datetime = new DateTime($datetime);
                } catch (Exception $e) {
                    return new JSONResponse(
                            data: ['error' => 'Invalid datetime format. Use ISO 8601 format (e.g., "2024-01-01T12:00:00Z").'],
                            statusCode: Http::STATUS_BAD_REQUEST
                    );
                }
            }

            // Set register and schema context.
            $this->objectService->setRegister($register);
            $this->objectService->setSchema($schema);

            // Perform bulk publish operation.
            $publishedUuids = $this->objectService->publishObjects(uuids: $uuids, datetime: $datetime ?? true);

            // Format datetime for response.
            if ($datetime instanceof \DateTime) {
                $datetimeUsed = $datetime->format('Y-m-d H:i:s');
            } else {
                $datetimeUsed = $datetime;
            }

            return new JSONResponse(
                    data: [
                        'success'         => true,
                        'message'         => 'Bulk publish operation completed successfully',
                        'published_count' => count($publishedUuids),
                        'published_uuids' => $publishedUuids,
                        'requested_count' => count($uuids),
                        'skipped_count'   => count($uuids) - count($publishedUuids),
                        'datetime_used'   => $datetimeUsed,
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => 'Bulk publish operation failed: '.$e->getMessage()], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end publish()


    /**
     * Perform bulk depublish operations on objects
     *
     * @param string $register The register identifier
     * @param string $schema   The schema identifier
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400|403|500, array{error?: string, success: true, message: 'Bulk depublish operation completed successfully', depublished_count: int<0, max>, depublished_uuids: array<int, string>, requested_count: int<0, max>, skipped_count: int<min, max>, datetime_used: bool|null|string}, array<never, never>>|JSONResponse<400|403|500, array{error: string}, array<never, never>>
     */
    public function depublish(string $register, string $schema): JSONResponse
    {
        try {
            // Check if user is admin.
            if ($this->isCurrentUserAdmin() === false) {
                return new JSONResponse(data: ['error' => 'Insufficient permissions. Admin access required.'], statusCode: Http::STATUS_FORBIDDEN);
            }

            // Get request data.
            $data     = $this->request->getParams();
            $uuids    = $data['uuids'] ?? [];
            $datetime = $data['datetime'] ?? true;

            // Validate input.
            if (empty($uuids) === true || is_array($uuids) === false) {
                return new JSONResponse(data: ['error' => 'Invalid input. "uuids" array is required.'], statusCode: Http::STATUS_BAD_REQUEST);
            }

            // Parse datetime if provided.
            if ($datetime !== true && $datetime !== false && $datetime !== null) {
                try {
                    $datetime = new DateTime($datetime);
                } catch (Exception $e) {
                    return new JSONResponse(
                            data: ['error' => 'Invalid datetime format. Use ISO 8601 format (e.g., "2024-01-01T12:00:00Z").'],
                            statusCode: Http::STATUS_BAD_REQUEST
                    );
                }
            }

            // Set register and schema context.
            $this->objectService->setRegister($register);
            $this->objectService->setSchema($schema);

            // Perform bulk depublish operation.
            $depublishedUuids = $this->objectService->depublishObjects(uuids: $uuids, datetime: $datetime ?? true);

            // Format datetime for response.
            if ($datetime instanceof \DateTime) {
                $datetimeUsed = $datetime->format('Y-m-d H:i:s');
            } else {
                $datetimeUsed = $datetime;
            }

            return new JSONResponse(
                    data: [
                        'success'           => true,
                        'message'           => 'Bulk depublish operation completed successfully',
                        'depublished_count' => count($depublishedUuids),
                        'depublished_uuids' => $depublishedUuids,
                        'requested_count'   => count($uuids),
                        'skipped_count'     => count($uuids) - count($depublishedUuids),
                        'datetime_used'     => $datetimeUsed,
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => 'Bulk depublish operation failed: '.$e->getMessage()], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end depublish()


    /**
     * Perform bulk save operations on objects
     *
     * @param string $register The register identifier
     * @param string $schema   The schema identifier
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400|403|500, array{error?: string, success?: true, message?: 'Bulk save operation completed successfully', saved_count?: mixed, saved_objects?: array<string, mixed>, requested_count?: int<0, max>}, array<never, never>>
     */
    public function save(string $register, string $schema): JSONResponse
    {
        try {
            // Check if user is admin.
            if ($this->isCurrentUserAdmin() === false) {
                return new JSONResponse(data: ['error' => 'Insufficient permissions. Admin access required.'], statusCode: Http::STATUS_FORBIDDEN);
            }

            // Get request data.
            $data    = $this->request->getParams();
            $objects = $data['objects'] ?? [];

            // Validate input.
            if (empty($objects) === true || is_array($objects) === false) {
                return new JSONResponse(data: ['error' => 'Invalid input. "objects" array is required.'], statusCode: Http::STATUS_BAD_REQUEST);
            }

            // FLEXIBLE SCHEMA HANDLING: Support both single-schema and mixed-schema operations.
            // Use schema=0 to indicate mixed-schema operations where objects specify their own schemas.
            $isMixedSchemaOperation = ($schema === '0' || (is_numeric($schema) && (int) $schema === 0));

            if ($isMixedSchemaOperation === true) {
                // Mixed-schema operation - don't set a specific schema context.
                $this->objectService->setRegister($register);
                // Don't call setSchema() for mixed operations.
                $savedObjects = $this->objectService->saveObjects(
                    objects: $objects,
                    register: $register,
                    schema: null,
                // Allow objects to specify their own schemas.
                    rbac: true,
                    multi: true,
                    validation: true,
                    events: false
                );
            } else {
                // Single-schema operation - traditional behavior.
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
            }//end if

            return new JSONResponse(
                    data: [
                        'success'         => true,
                        'message'         => 'Bulk save operation completed successfully',
                        'saved_count'     => ($savedObjects['statistics']['saved'] ?? 0) + ($savedObjects['statistics']['updated'] ?? 0),
                        'saved_objects'   => $savedObjects,
                        'requested_count' => count($objects),
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => 'Bulk save operation failed: '.$e->getMessage()], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end save()


    /**
     * Publish all objects belonging to a specific schema
     *
     * @param string $register The register identifier
     * @param string $schema   The schema identifier
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400|403|404|500, array{error?: string, success?: true, message?: 'Schema objects publishing completed successfully', published_count?: int, published_uuids?: array<int, string>, schema_id?: int, publish_all?: bool}, array<never, never>>
     */
    public function publishSchema(string $register, string $schema): JSONResponse
    {
        try {
            // Check if user is admin.
            if ($this->isCurrentUserAdmin() === false) {
                return new JSONResponse(data: ['error' => 'Insufficient permissions. Admin access required.'], statusCode: Http::STATUS_FORBIDDEN);
            }

            // Validate input.
            if (is_numeric($schema) === false) {
                return new JSONResponse(data: ['error' => 'Invalid schema ID. Must be numeric.'], statusCode: Http::STATUS_BAD_REQUEST);
            }

            // Get request data.
            $data       = $this->request->getParams();
            $publishAll = $data['publishAll'] ?? false;

            // Set register and schema context.
            $this->objectService->setRegister($register);
            $this->objectService->setSchema($schema);

            // Perform schema publishing operation.
            $result = $this->objectService->publishObjectsBySchema(schemaId: (int) $schema, publishAll: $publishAll);

            return new JSONResponse(
                    data: [
                        'success'         => true,
                        'message'         => 'Schema objects publishing completed successfully',
                        'published_count' => $result['published_count'],
                        'published_uuids' => $result['published_uuids'],
                        'schema_id'       => $result['schema_id'],
                        'publish_all'     => $publishAll,
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => 'Schema objects publishing failed: '.$e->getMessage()], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end publishSchema()


    /**
     * Delete all objects belonging to a specific schema
     *
     * @param string $register The register identifier
     * @param string $schema   The schema identifier
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400|403|404|500, array{error?: string, success?: true, message?: 'Schema objects deletion completed successfully', deleted_count?: int, deleted_uuids?: array<int, string>, schema_id?: int, hard_delete?: bool}, array<never, never>>
     */
    public function deleteSchema(string $register, string $schema): JSONResponse
    {
        try {
            // Check if user is admin.
            if ($this->isCurrentUserAdmin() === false) {
                return new JSONResponse(data: ['error' => 'Insufficient permissions. Admin access required.'], statusCode: Http::STATUS_FORBIDDEN);
            }

            // Validate input.
            if (is_numeric($schema) === false) {
                return new JSONResponse(data: ['error' => 'Invalid schema ID. Must be numeric.'], statusCode: Http::STATUS_BAD_REQUEST);
            }

            // Get request data.
            $data       = $this->request->getParams();
            $hardDelete = $data['hardDelete'] ?? false;

            // Set register and schema context.
            $this->objectService->setRegister($register);
            $this->objectService->setSchema($schema);

            // Perform schema deletion operation.
            $result = $this->objectService->deleteObjectsBySchema(schemaId: (int) $schema, hardDelete: $hardDelete);

            return new JSONResponse(
                    data: [
                        'success'       => true,
                        'message'       => 'Schema objects deletion completed successfully',
                        'deleted_count' => $result['deleted_count'],
                        'deleted_uuids' => $result['deleted_uuids'],
                        'schema_id'     => $result['schema_id'],
                        'hard_delete'   => $hardDelete,
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => 'Schema objects deletion failed: '.$e->getMessage()], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end deleteSchema()


    /**
     * Delete all objects belonging to a specific register
     *
     * @param string $register The register identifier
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400|403|404|500, array{error?: string, success?: true, message?: 'Register objects deletion completed successfully', deleted_count?: int, deleted_uuids?: array<int, string>, register_id?: int}, array<never, never>>
     */
    public function deleteRegister(string $register): JSONResponse
    {
        try {
            // Check if user is admin.
            if ($this->isCurrentUserAdmin() === false) {
                return new JSONResponse(data: ['error' => 'Insufficient permissions. Admin access required.'], statusCode: Http::STATUS_FORBIDDEN);
            }

            // Validate input.
            if (is_numeric($register) === false) {
                return new JSONResponse(data: ['error' => 'Invalid register ID. Must be numeric.'], statusCode: Http::STATUS_BAD_REQUEST);
            }

            // Set register context.
            $this->objectService->setRegister($register);

            // Perform register deletion operation.
            $result = $this->objectService->deleteObjectsByRegister((int) $register);

            return new JSONResponse(
                    data: [
                        'success'       => true,
                        'message'       => 'Register objects deletion completed successfully',
                        'deleted_count' => $result['deleted_count'],
                        'deleted_uuids' => $result['deleted_uuids'],
                        'register_id'   => $result['register_id'],
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => 'Register objects deletion failed: '.$e->getMessage()], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end deleteRegister()


    /**
     * Validate all objects belonging to a specific schema
     *
     * @param string $schema The schema identifier
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400|401|403|404|500, array{error?: string, valid_count?: int<0, max>, invalid_count?: int<0, max>, valid_objects?: list<array{data: array, id: int, name: null|string, uuid: null|string}>, invalid_objects?: list<array{data: array, errors: list<array{keyword: 'exception'|'validation'|mixed, message: mixed|non-falsy-string, path: 'general'|'unknown'|mixed}>, id: int, name: null|string, uuid: null|string}>, schema_id?: int}, array<never, never>>
     */
    public function validateSchema(string $schema): JSONResponse
    {
        try {
            // Check if user is admin.
            if ($this->isCurrentUserAdmin() === false) {
                return new JSONResponse(data: ['error' => 'Insufficient permissions. Admin access required.'], statusCode: Http::STATUS_FORBIDDEN);
            }

            // Validate input.
            if (is_numeric($schema) === false) {
                return new JSONResponse(data: ['error' => 'Invalid schema ID. Must be numeric.'], statusCode: Http::STATUS_BAD_REQUEST);
            }

            // Perform schema validation operation and return service result directly.
            $result = $this->objectService->validateObjectsBySchema((int) $schema);

            return new JSONResponse(data: $result);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => 'Schema validation failed: '.$e->getMessage()], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end validateSchema()


}//end class
