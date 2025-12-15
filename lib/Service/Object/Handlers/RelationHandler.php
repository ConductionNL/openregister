<?php

/**
 * Relation Handler
 *
 * Handles object relationship operations including contracts, uses, and used-by.
 * Manages the graph of relationships between objects.
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

namespace OCA\OpenRegister\Service\Object\Handlers;

use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;

/**
 * RelationHandler
 *
 * Responsible for managing object relationships.
 *
 * RESPONSIBILITIES:
 * - Get contracts for objects
 * - Get objects that this object uses (outgoing relations)
 * - Get objects that use this object (incoming relations)
 * - Resolve and validate relationships
 *
 * RELATION TYPES:
 * - Contracts: Not yet implemented (placeholder)
 * - Uses (A -> B): Objects that this object references
 * - Used (B -> A): Objects that reference this object
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Objects\Handlers
 */
class RelationHandler
{


    /**
     * Constructor
     *
     * @param ObjectService   $objectService Object service
     * @param LoggerInterface $logger        PSR-3 logger
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()


    /**
     * Get contracts for an object
     *
     * NOTE: Contract functionality is not yet implemented.
     * Returns empty array as placeholder.
     *
     * @param string $objectId Object ID or UUID
     * @param array  $filters  Optional filters for pagination
     *
     * @return array Empty array (placeholder for future implementation)
     */
    public function getContracts(string $objectId, array $filters=[]): array
    {
        $this->logger->debug(
            message: '[RelationHandler] Getting contracts (not implemented)',
            context: [
                'object_id' => $objectId,
                'filters'   => $filters,
            ]
        );

        // NOTE: Contract functionality is not yet implemented.
        // This is a placeholder for future contract management.
        return [
            'results' => [],
            'total'   => 0,
            'limit'   => $filters['limit'] ?? 20,
            'offset'  => $filters['offset'] ?? 0,
            'page'    => $filters['page'] ?? 1,
        ];

    }//end getContracts()


    /**
     * Get objects that this object uses (outgoing relations)
     *
     * Returns all objects that this object references.
     * A -> B means that A (this object) references B (another object).
     *
     * @param string $objectId Object ID or UUID
     * @param array  $query    Search query parameters
     * @param bool   $rbac     Apply RBAC filters
     * @param bool   $multi    Apply multitenancy filters
     *
     * @return array Paginated results with related objects
     *
     * @throws \Exception If retrieval fails
     */
    public function getUses(
        string $objectId,
        array $query=[],
        bool $rbac=true,
        bool $multi=true
    ): array {
        $this->logger->debug(
            message: '[RelationHandler] Getting objects used by object',
            context: [
                'object_id' => $objectId,
                'rbac'      => $rbac,
                'multi'     => $multi,
            ]
        );

        try {
            // Get the object to retrieve its relations.
            $object = $this->objectService->find(id: $objectId);

            if ($object === null) {
                $this->logger->warning(
                    message: '[RelationHandler] Object not found',
                    context: ['object_id' => $objectId]
                );

                return [
                    'results'   => [],
                    'total'     => 0,
                    'relations' => [],
                ];
            }

            // Get relations array from object.
            $relationsArray = $object->getRelations();
            $relations      = array_values($relationsArray ?? []);

            $this->logger->debug(
                message: '[RelationHandler] Found relations',
                context: [
                    'object_id'       => $objectId,
                    'relations_count' => count($relations),
                ]
            );

            // Search for related objects using their IDs.
            $result = $this->objectService->searchObjectsPaginated(
                query: $query,
                _rbac: $rbac,
                _multitenancy: $multi,
                published: true,
                deleted: false,
                ids: $relations
            );

            // Add relations list to result for debugging.
            $result['relations'] = $relations;

            return $result;
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[RelationHandler] Failed to get uses',
                context: [
                    'object_id' => $objectId,
                    'error'     => $e->getMessage(),
                ]
            );
            throw $e;
        }//end try

    }//end getUses()


    /**
     * Get objects that use this object (incoming relations)
     *
     * Returns all objects that reference (use) this object.
     * B -> A means that B (another object) references A (this object).
     *
     * @param string $objectId Object ID or UUID
     * @param array  $query    Search query parameters
     * @param bool   $rbac     Apply RBAC filters
     * @param bool   $multi    Apply multitenancy filters
     *
     * @return array Paginated results with referencing objects
     *
     * @throws \Exception If retrieval fails
     */
    public function getUsedBy(
        string $objectId,
        array $query=[],
        bool $rbac=true,
        bool $multi=true
    ): array {
        $this->logger->debug(
            message: '[RelationHandler] Getting objects that use this object',
            context: [
                'object_id' => $objectId,
                'rbac'      => $rbac,
                'multi'     => $multi,
            ]
        );

        try {
            // Search for objects that reference this object.
            // Use 'uses' parameter to find objects where their relations contain this object's ID.
            $result = $this->objectService->searchObjectsPaginated(
                query: $query,
                _rbac: $rbac,
                _multitenancy: $multi,
                published: true,
                deleted: false,
                ids: null,
                uses: $objectId
            );

            // Add the target object ID to result for reference.
            $result['uses'] = $objectId;

            $this->logger->debug(
                message: '[RelationHandler] Found objects using this object',
                context: [
                    'object_id' => $objectId,
                    'count'     => $result['total'] ?? 0,
                ]
            );

            return $result;
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[RelationHandler] Failed to get used by',
                context: [
                    'object_id' => $objectId,
                    'error'     => $e->getMessage(),
                ]
            );
            throw $e;
        }//end try

    }//end getUsedBy()


    /**
     * Resolve object references to full objects
     *
     * Takes an array of object IDs and resolves them to full object data.
     *
     * @param array $objectIds Array of object IDs/UUIDs
     * @param bool  $rbac      Apply RBAC filters
     * @param bool  $multi     Apply multitenancy filters
     *
     * @return array Array of resolved objects
     *
     * @throws \Exception If resolution fails
     */
    public function resolveReferences(
        array $objectIds,
        bool $rbac=true,
        bool $multi=true
    ): array {
        $this->logger->debug(
            message: '[RelationHandler] Resolving object references',
            context: [
                'object_ids_count' => count($objectIds),
                'rbac'             => $rbac,
                'multi'            => $multi,
            ]
        );

        if (empty($objectIds) === true) {
            return [];
        }

        try {
            // Search for objects by their IDs.
            $result = $this->objectService->searchObjectsPaginated(
                query: ['_limit' => count($objectIds)],
                _rbac: $rbac,
                _multitenancy: $multi,
                published: true,
                deleted: false,
                ids: $objectIds
            );

            $resolvedObjects = $result['results'] ?? [];

            $this->logger->debug(
                message: '[RelationHandler] References resolved',
                context: [
                    'requested' => count($objectIds),
                    'resolved'  => count($resolvedObjects),
                ]
            );

            return $resolvedObjects;
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[RelationHandler] Failed to resolve references',
                context: [
                    'object_ids_count' => count($objectIds),
                    'error'            => $e->getMessage(),
                ]
            );
            throw $e;
        }//end try

    }//end resolveReferences()


}//end class
