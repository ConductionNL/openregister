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
use OCA\OpenRegister\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Exception\SchemaNotFoundException;
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
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
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
     * Resolve register and schema slugs/IDs to numeric IDs.
     *
     * This method handles both slugs and numeric IDs by attempting to set them
     * in the ObjectService, which will resolve slugs to IDs.
     *
     * @param string        $register      The register slug or ID
     * @param string        $schema        The schema slug or ID
     * @param ObjectService $objectService The object service
     *
     * @return array{register: int, schema: int} Resolved numeric IDs
     *
     * @throws RegisterNotFoundException If register not found
     * @throws SchemaNotFoundException If schema not found
     *
     * @psalm-return   array{register: int, schema: int}
     * @phpstan-return array{register: int, schema: int}
     */
    private function resolveRegisterSchemaIds(string $register, string $schema, ObjectService $objectService): array
    {
        try {
            // Resolve register slug/ID to numeric ID.
            $objectService->setRegister(register: $register);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            throw new RegisterNotFoundException(registerSlugOrId: $register, code: 404, previous: $e);
        }

        try {
            // Resolve schema slug/ID to numeric ID.
            $objectService->setSchema(schema: $schema);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            throw new SchemaNotFoundException(schemaSlugOrId: $schema, code: 404, previous: $e);
        }

        // Get resolved numeric IDs.
        $resolvedRegisterId = $objectService->getRegister();
        $resolvedSchemaId   = $objectService->getSchema();

        // Reset ObjectService with resolved numeric IDs for consistency.
        $objectService->setRegister(register: (string) $resolvedRegisterId)->setSchema(schema: (string) $resolvedSchemaId);

        return [
            'register' => $resolvedRegisterId,
            'schema'   => $resolvedSchemaId,
        ];
    }//end resolveRegisterSchemaIds()

    /**
     * Perform bulk delete operations on objects
     *
     * @param string $register The register identifier
     * @param string $schema   The schema identifier
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with bulk delete result
     */
    public function delete(string $register, string $schema): JSONResponse
    {
        try {
            // Check if user is admin.
            if ($this->isCurrentUserAdmin() === false) {
                return new JSONResponse(
                    data: ['error' => 'Insufficient permissions. Admin access required.'],
                    statusCode: Http::STATUS_FORBIDDEN
                );
            }

            // Resolve slugs to numeric IDs.
            try {
                $resolved = $this->resolveRegisterSchemaIds(
                    register: $register,
                    schema: $schema,
                    objectService: $this->objectService
                );
            } catch (RegisterNotFoundException | SchemaNotFoundException $e) {
                return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
            }

            // Get request data.
            $data  = $this->request->getParams();
            $uuids = $data['uuids'] ?? [];

            // Validate input.
            if (empty($uuids) === true || is_array($uuids) === false) {
                return new JSONResponse(
                    data: ['error' => 'Invalid input. "uuids" array is required.'],
                    statusCode: Http::STATUS_BAD_REQUEST
                );
            }

            // Set register and schema context using resolved IDs.
            $this->objectService->setRegister((string) $resolved['register']);
            $this->objectService->setSchema((string) $resolved['schema']);

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
            return new JSONResponse(
                data: ['error' => 'Bulk delete operation failed: '.$e->getMessage()],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
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
     * @return JSONResponse JSON response with bulk publish result
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function publish(string $register, string $schema): JSONResponse
    {
        try {
            // Check if user is admin.
            if ($this->isCurrentUserAdmin() === false) {
                return new JSONResponse(
                    data: ['error' => 'Insufficient permissions. Admin access required.'],
                    statusCode: Http::STATUS_FORBIDDEN
                );
            }

            // Get request data.
            $data     = $this->request->getParams();
            $uuids    = $data['uuids'] ?? [];
            $datetime = $data['datetime'] ?? true;

            // Validate input.
            if (empty($uuids) === true || is_array($uuids) === false) {
                return new JSONResponse(
                    data: ['error' => 'Invalid input. "uuids" array is required.'],
                    statusCode: Http::STATUS_BAD_REQUEST
                );
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
            $datetimeUsed = $datetime;
            if ($datetime instanceof \DateTime) {
                $datetimeUsed = $datetime->format('Y-m-d H:i:s');
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
            return new JSONResponse(
                data: ['error' => 'Bulk publish operation failed: '.$e->getMessage()],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end publish()

    /**
     * Perform bulk depublish operations on objects
     *
     * @param string $_register The register identifier (used by routing)
     * @param string $_schema   The schema identifier (used by routing)
     *
     * @NoCSRFRequired
     *
     * @SuppressWarnings                             (PHPMD.UnusedFormalParameter) Parameters used by route resolver
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @return JSONResponse JSON response with bulk depublish result
     */
    public function depublish(string $_register, string $_schema): JSONResponse
    {
        try {
            // Check if user is admin.
            if ($this->isCurrentUserAdmin() === false) {
                return new JSONResponse(
                    data: ['error' => 'Insufficient permissions. Admin access required.'],
                    statusCode: Http::STATUS_FORBIDDEN
                );
            }

            // Get request data.
            $data     = $this->request->getParams();
            $uuids    = $data['uuids'] ?? [];
            $datetime = $data['datetime'] ?? true;

            // Validate input.
            if (empty($uuids) === true || is_array($uuids) === false) {
                return new JSONResponse(
                    data: ['error' => 'Invalid input. "uuids" array is required.'],
                    statusCode: Http::STATUS_BAD_REQUEST
                );
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

            // Perform bulk depublish operation (resolveRegisterSchemaIds already set context).
            $depublishedUuids = $this->objectService->depublishObjects(uuids: $uuids, datetime: $datetime ?? true);

            // Format datetime for response.
            $datetimeUsed = $datetime;
            if ($datetime instanceof \DateTime) {
                $datetimeUsed = $datetime->format('Y-m-d H:i:s');
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
            return new JSONResponse(
                data: ['error' => 'Bulk depublish operation failed: '.$e->getMessage()],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
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
     * @return JSONResponse JSON response with bulk save operation results
     *
     * @psalm-return JSONResponse<200|400|403|404|500,
     *     array{error?: string, success?: true,
     *     message?: 'Bulk save operation completed successfully',
     *     saved_count?: mixed, saved_objects?: array<string, mixed>,
     *     requested_count?: int<0, max>}, array<never, never>>
     */
    public function save(string $register, string $schema): JSONResponse
    {
        try {
            // Check if user is admin.
            if ($this->isCurrentUserAdmin() === false) {
                return new JSONResponse(
                    data: ['error' => 'Insufficient permissions. Admin access required.'],
                    statusCode: Http::STATUS_FORBIDDEN
                );
            }

            // Resolve slugs to numeric IDs.
            try {
                $resolved = $this->resolveRegisterSchemaIds(
                    register: $register,
                    schema: $schema,
                    objectService: $this->objectService
                );
            } catch (RegisterNotFoundException | SchemaNotFoundException $e) {
                return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
            }

            // Get request data.
            $data    = $this->request->getParams();
            $objects = $data['objects'] ?? [];

            // Validate input.
            if (empty($objects) === true || is_array($objects) === false) {
                return new JSONResponse(
                    data: ['error' => 'Invalid input. "objects" array is required.'],
                    statusCode: Http::STATUS_BAD_REQUEST
                );
            }

            // FLEXIBLE SCHEMA HANDLING: Support both single-schema and mixed-schema operations.
            // Use schema=0 to indicate mixed-schema operations where objects specify their own schemas.
            $isMixedSchema = ($resolved['schema'] === 0);

            // Determine schema to use (null for mixed-schema, resolved for single-schema).
            $schemaToUse = $resolved['schema'];
            if ($isMixedSchema === true) {
                $schemaToUse = null;
            }

            $savedObjects = $this->objectService->saveObjects(
                objects: $objects,
                register: $resolved['register'],
                schema: $schemaToUse,
                _rbac: true,
                _multitenancy: true,
                validation: true,
                events: false
            );

            $savedCount = ($savedObjects['statistics']['saved'] ?? 0) + ($savedObjects['statistics']['updated'] ?? 0);

            return new JSONResponse(
                data: [
                    'success'         => true,
                    'message'         => 'Bulk save operation completed successfully',
                    'saved_count'     => $savedCount,
                    'saved_objects'   => $savedObjects,
                    'requested_count' => count($objects),
                ]
            );
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['error' => 'Bulk save operation failed: '.$e->getMessage()],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
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
     * @return JSONResponse JSON response with schema publish result
     */
    public function publishSchema(string $register, string $schema): JSONResponse
    {
        try {
            // Check if user is admin.
            if ($this->isCurrentUserAdmin() === false) {
                return new JSONResponse(
                    data: ['error' => 'Insufficient permissions. Admin access required.'],
                    statusCode: Http::STATUS_FORBIDDEN
                );
            }

            // Validate input.
            if (is_numeric($schema) === false) {
                return new JSONResponse(
                    data: ['error' => 'Invalid schema ID. Must be numeric.'],
                    statusCode: Http::STATUS_BAD_REQUEST
                );
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
            return new JSONResponse(
                data: ['error' => 'Schema objects publishing failed: '.$e->getMessage()],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
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
     * @return JSONResponse JSON response with schema delete result
     */
    public function deleteSchema(string $register, string $schema): JSONResponse
    {
        try {
            // Check if user is admin.
            if ($this->isCurrentUserAdmin() === false) {
                return new JSONResponse(
                    data: ['error' => 'Insufficient permissions. Admin access required.'],
                    statusCode: Http::STATUS_FORBIDDEN
                );
            }

            // Validate input.
            if (is_numeric($schema) === false) {
                return new JSONResponse(
                    data: ['error' => 'Invalid schema ID. Must be numeric.'],
                    statusCode: Http::STATUS_BAD_REQUEST
                );
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
            return new JSONResponse(
                data: ['error' => 'Schema objects deletion failed: '.$e->getMessage()],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end deleteSchema()

    /**
     * Delete all objects belonging to a specific register
     *
     * @param string $register The register identifier
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with register delete result
     */
    public function deleteRegister(string $register): JSONResponse
    {
        try {
            // Check if user is admin.
            if ($this->isCurrentUserAdmin() === false) {
                return new JSONResponse(
                    data: ['error' => 'Insufficient permissions. Admin access required.'],
                    statusCode: Http::STATUS_FORBIDDEN
                );
            }

            // Validate input.
            if (is_numeric($register) === false) {
                return new JSONResponse(
                    data: ['error' => 'Invalid register ID. Must be numeric.'],
                    statusCode: Http::STATUS_BAD_REQUEST
                );
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
            return new JSONResponse(
                data: ['error' => 'Register objects deletion failed: '.$e->getMessage()],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end deleteRegister()

    /**
     * Validate all objects belonging to a specific schema
     *
     * @param string $schema The schema identifier
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with validation result
     */
    public function validateSchema(string $schema): JSONResponse
    {
        try {
            // Check if user is admin.
            if ($this->isCurrentUserAdmin() === false) {
                return new JSONResponse(
                    data: ['error' => 'Insufficient permissions. Admin access required.'],
                    statusCode: Http::STATUS_FORBIDDEN
                );
            }

            // Validate input.
            if (is_numeric($schema) === false) {
                return new JSONResponse(
                    data: ['error' => 'Invalid schema ID. Must be numeric.'],
                    statusCode: Http::STATUS_BAD_REQUEST
                );
            }

            // Perform schema validation operation and return service result directly.
            $result = $this->objectService->validateObjectsBySchema((int) $schema);

            return new JSONResponse(data: $result);
        } catch (Exception $e) {
            $errorMsg = 'Schema validation failed: '.$e->getMessage();
            return new JSONResponse(
                data: ['error' => $errorMsg],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end validateSchema()
}//end class
