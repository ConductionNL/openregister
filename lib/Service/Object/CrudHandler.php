<?php

/**
 * CRUD Handler
 *
 * Handles core CRUD (Create, Read, Update, Delete) operations for objects.
 * Coordinates between controller and ObjectService for data operations.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Objects\Handlers
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;

/**
 * CrudHandler
 *
 * Responsible for core CRUD operations.
 *
 * RESPONSIBILITIES:
 * - List objects with filtering and pagination
 * - Get single object
 * - Create new object
 * - Update existing object (full or partial)
 * - Delete object
 * - Build search queries
 *
 * NOTE: This handler coordinates CRUD operations but delegates heavy lifting to ObjectService.
 * It provides structure and logging for these operations.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Objects\Handlers
 */
class CrudHandler
{


    /**
     * Constructor
     *
     * @param ObjectEntityMapper $objectEntityMapper Object entity mapper
     * @param LoggerInterface    $logger             PSR-3 logger
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()


    /**
     * List objects with filters and pagination
     *
     * @param array       $query         Search query parameters
     * @param bool        $rbac          Apply RBAC filters
     * @param bool        $_multitenancy Apply multitenancy filters
     * @param bool        $published     Only return published objects
     * @param bool        $deleted       Include deleted objects
     * @param array|null  $ids           Optional array of object IDs to filter
     * @param string|null $uses          Optional object ID that results must use
     * @param array|null  $views         Optional view filters
     *
     * @return array Paginated results with objects
     *
     * @throws \Exception If listing fails
     */
    public function list(
        array $query=[],
        bool $rbac=true,
        bool $_multitenancy=true,
        bool $published=false,
        bool $deleted=false,
        ?array $ids=null,
        ?string $uses=null,
        ?array $views=null
    ): array {
        $this->logger->debug(
            message: '[CrudHandler] Listing objects',
            context: [
                'query_params'  => array_keys($query),
                'rbac'          => $rbac,
                '_multitenancy' => $_multitenancy,
                'published'     => $published,
                'deleted'       => $deleted,
            ]
        );

        try {
            // TODO: Implement proper search logic
            $result = ['results' => [], 'total' => 0];
            // $this->objectEntityMapper->searchObjectsPaginated(
            // query: $query,
            // _rbac: $rbac,
            // _multitenancy: $multi,
            // published: $published,
            // deleted: $deleted,
            // ids: $ids,
            // uses: $uses,
            // views: $views
            // );
            $this->logger->debug(
                message: '[CrudHandler] Objects listed',
                context: [
                    'total'   => $result['total'] ?? 0,
                    'results' => count($result['results'] ?? []),
                ]
            );

            return $result;
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[CrudHandler] Failed to list objects',
                context: [
                    'error' => $e->getMessage(),
                    'query' => $query,
                ]
            );
            throw $e;
        }//end try

    }//end list()


    /**
     * Get a single object by ID
     *
     * @param string $objectId      Object ID or UUID
     * @param bool   $rbac          Apply RBAC filters
     * @param bool   $_multitenancy Apply multitenancy filters
     *
     * @return ObjectEntity|null Object entity or null if not found
     *
     * @throws \Exception If retrieval fails
     */
    public function get(string $objectId, bool $rbac=true, bool $_multitenancy=true): ?ObjectEntity
    {
        $this->logger->debug(
            message: '[CrudHandler] Getting object',
            context: [
                'object_id'     => $objectId,
                'rbac'          => $rbac,
                '_multitenancy' => $_multitenancy,
            ]
        );

        try {
            // TODO: Implement proper find logic
            $object = null;
            // $this->objectEntityMapper->find(
            // id: $objectId,
            // _rbac: $rbac,
            // _multitenancy: $multi
            // );
            if ($object === null) {
                $this->logger->warning(
                    message: '[CrudHandler] Object not found',
                    context: ['object_id' => $objectId]
                );
                return null;
            }

            $this->logger->debug(
                message: '[CrudHandler] Object retrieved',
                context: [
                    'object_id' => $objectId,
                    'uuid'      => $object->getUuid(),
                ]
            );

            return $object;
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[CrudHandler] Failed to get object',
                context: [
                    'object_id' => $objectId,
                    'error'     => $e->getMessage(),
                ]
            );
            throw $e;
        }//end try

    }//end get()


    /**
     * Create a new object
     *
     * @param array $data          Object data
     * @param bool  $rbac          Apply RBAC filters
     * @param bool  $_multitenancy Apply multitenancy filters
     *
     * @return ObjectEntity Created object
     *
     * @throws \Exception If creation fails
     */
    public function create(array $data, bool $rbac=true, bool $_multitenancy=true): ObjectEntity
    {
        $this->logger->info(
            message: '[CrudHandler] Creating object',
            context: [
                'data_keys'     => array_keys($data),
                'rbac'          => $rbac,
                '_multitenancy' => $_multitenancy,
            ]
        );

        try {
            // TODO: Implement proper save logic
            $object = null;
            // $this->objectEntityMapper->saveObject(
            // objectId: null,
            // object: $data,
            // _rbac: $rbac,
            // _multitenancy: $multi
            // );
            $this->logger->info(
                message: '[CrudHandler] Object created',
                context: [
                    'object_id' => $object->getId(),
                    'uuid'      => $object->getUuid(),
                ]
            );

            return $object;
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[CrudHandler] Failed to create object',
                context: [
                    'error'     => $e->getMessage(),
                    'data_keys' => array_keys($data),
                ]
            );
            throw $e;
        }//end try

    }//end create()


    /**
     * Update an existing object (full update)
     *
     * @param string $objectId      Object ID or UUID
     * @param array  $data          Object data
     * @param bool   $rbac          Apply RBAC filters
     * @param bool   $_multitenancy Apply multitenancy filters
     *
     * @return ObjectEntity Updated object
     *
     * @throws \Exception If update fails
     */
    public function update(
        string $objectId,
        array $data,
        bool $rbac=true,
        bool $_multitenancy=true
    ): ObjectEntity {
        $this->logger->info(
            message: '[CrudHandler] Updating object',
            context: [
                'object_id'     => $objectId,
                'data_keys'     => array_keys($data),
                'rbac'          => $rbac,
                '_multitenancy' => $_multitenancy,
            ]
        );

        try {
            // TODO: Implement proper save logic
            $object = null;
            // $this->objectEntityMapper->saveObject(
            // objectId: $objectId,
            // object: $data,
            // _rbac: $rbac,
            // _multitenancy: $multi
            // );
            $this->logger->info(
                message: '[CrudHandler] Object updated',
                context: [
                    'object_id' => $objectId,
                    'uuid'      => $object->getUuid(),
                ]
            );

            return $object;
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[CrudHandler] Failed to update object',
                context: [
                    'object_id' => $objectId,
                    'error'     => $e->getMessage(),
                ]
            );
            throw $e;
        }//end try

    }//end update()


    /**
     * Patch an existing object (partial update)
     *
     * @param string $objectId      Object ID or UUID
     * @param array  $data          Partial object data
     * @param bool   $rbac          Apply RBAC filters
     * @param bool   $_multitenancy Apply multitenancy filters
     *
     * @return ObjectEntity Patched object
     *
     * @throws \Exception If patch fails
     */
    public function patch(
        string $objectId,
        array $data,
        bool $rbac=true,
        bool $_multitenancy=true
    ): ObjectEntity {
        $this->logger->info(
            message: '[CrudHandler] Patching object',
            context: [
                'object_id'     => $objectId,
                'data_keys'     => array_keys($data),
                'rbac'          => $rbac,
                '_multitenancy' => $_multitenancy,
            ]
        );

        try {
            // Get existing object.
            $object = $this->get($objectId, $rbac, $_multitenancy);

            if ($object === null) {
                throw new \Exception("Object not found: {$objectId}");
            }

            // Merge partial data with existing data.
            $existingData = $object->getObject();
            $mergedData   = array_merge($existingData, $data);

            // Save merged data.
            $updatedObject = $this->objectService->saveObject(
                objectId: $objectId,
                object: $mergedData,
                _rbac: $rbac,
                _multitenancy: $multi
            );

            $this->logger->info(
                message: '[CrudHandler] Object patched',
                context: [
                    'object_id' => $objectId,
                    'uuid'      => $updatedObject->getUuid(),
                ]
            );

            return $updatedObject;
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[CrudHandler] Failed to patch object',
                context: [
                    'object_id' => $objectId,
                    'error'     => $e->getMessage(),
                ]
            );
            throw $e;
        }//end try

    }//end patch()


    /**
     * Delete an object
     *
     * @param string $objectId      Object ID or UUID
     * @param bool   $rbac          Apply RBAC filters
     * @param bool   $_multitenancy Apply multitenancy filters
     *
     * @return bool True if deleted successfully
     *
     * @throws \Exception If deletion fails
     */
    public function delete(string $objectId, bool $rbac=true, bool $_multitenancy=true): bool
    {
        $this->logger->info(
            message: '[CrudHandler] Deleting object',
            context: [
                'object_id'     => $objectId,
                'rbac'          => $rbac,
                '_multitenancy' => $_multitenancy,
            ]
        );

        try {
            // TODO: Implement proper delete logic
            // $this->objectEntityMapper->deleteObject(
            // uuid: $objectId,
            // _rbac: $rbac,
            // _multitenancy: $multi
            // );
            $this->logger->info(
                message: '[CrudHandler] Object deleted',
                context: ['object_id' => $objectId]
            );

            return true;
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[CrudHandler] Failed to delete object',
                context: [
                    'object_id' => $objectId,
                    'error'     => $e->getMessage(),
                ]
            );
            throw $e;
        }//end try

    }//end delete()


    /**
     * Build search query from request parameters
     *
     * @param array       $requestParams Request parameters
     * @param string|null $register      Optional register ID/slug
     * @param string|null $schema        Optional schema ID/slug
     *
     * @return array Normalized search query
     */
    public function buildSearchQuery(
        array $requestParams,
        ?string $register=null,
        ?string $schema=null
    ): array {
        $this->logger->debug(
            message: '[CrudHandler] Building search query',
            context: [
                'params_count' => count($requestParams),
                'register'     => $register,
                'schema'       => $schema,
            ]
        );

        return $this->objectService->buildSearchQuery(
            requestParams: $requestParams,
            register: $register,
            schema: $schema
        );

    }//end buildSearchQuery()


}//end class
