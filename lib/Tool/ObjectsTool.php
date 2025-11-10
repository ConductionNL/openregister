<?php
/**
 * OpenRegister Objects Tool
 *
 * LLphant function tool for managing objects through natural language.
 * Provides CRUD operations on objects with RBAC and multi-tenancy support.
 *
 * @category Tool
 * @package  OCA\OpenRegister\Tool
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Tool;

use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Objects Tool
 *
 * Allows agents to manage objects in OpenRegister.
 * Use this tool when users ask to:
 * - Search for objects
 * - View object details
 * - Create new objects
 * - Update existing objects
 * - Delete objects
 *
 * All operations respect user permissions and organization boundaries.
 *
 * @category Tool
 * @package  OCA\OpenRegister\Tool
 */
class ObjectsTool extends AbstractTool
{
    /**
     * Object service
     *
     * @var ObjectService
     */
    private ObjectService $objectService;

    /**
     * Constructor
     *
     * @param IUserSession    $userSession    User session service
     * @param LoggerInterface $logger         Logger service
     * @param ObjectService   $objectService  Object service
     */
    public function __construct(
        IUserSession $userSession,
        LoggerInterface $logger,
        ObjectService $objectService
    ) {
        parent::__construct($userSession, $logger);
        $this->objectService = $objectService;
    }

    /**
     * Get tool name
     *
     * @return string Tool name
     */
    public function getName(): string
    {
        return 'objects';
    }

    /**
     * Get tool description
     *
     * @return string Tool description
     */
    public function getDescription(): string
    {
        return 'Manage objects in OpenRegister. Objects are data records that conform to schemas. '
             . 'Use this tool to search, view, create, update, or delete objects.';
    }

    /**
     * Get function definitions for LLphant
     *
     * @return array Array of function definitions
     */
    public function getFunctions(): array
    {
        return [
            [
                'name' => 'search_objects',
                'description' => 'Search for objects with optional filters',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Search query text (optional)',
                        ],
                        'register' => [
                            'type' => 'string',
                            'description' => 'Filter by register ID (optional)',
                        ],
                        'schema' => [
                            'type' => 'string',
                            'description' => 'Filter by schema ID (optional)',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Maximum number of results (default: 20)',
                        ],
                        'offset' => [
                            'type' => 'integer',
                            'description' => 'Number of results to skip (default: 0)',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'get_object',
                'description' => 'Get details about a specific object by ID or UUID',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => [
                            'type' => 'string',
                            'description' => 'The object ID or UUID to retrieve',
                        ],
                    ],
                    'required' => ['id'],
                ],
            ],
            [
                'name' => 'create_object',
                'description' => 'Create a new object with data',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'register' => [
                            'type' => 'string',
                            'description' => 'The register ID where the object should be created',
                        ],
                        'schema' => [
                            'type' => 'string',
                            'description' => 'The schema ID that defines the object structure',
                        ],
                        'data' => [
                            'type' => 'object',
                            'description' => 'The object data conforming to the schema',
                        ],
                    ],
                    'required' => ['register', 'schema', 'data'],
                ],
            ],
            [
                'name' => 'update_object',
                'description' => 'Update an existing object',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => [
                            'type' => 'string',
                            'description' => 'The object ID to update',
                        ],
                        'data' => [
                            'type' => 'object',
                            'description' => 'The updated object data (partial updates supported)',
                        ],
                    ],
                    'required' => ['id', 'data'],
                ],
            ],
            [
                'name' => 'delete_object',
                'description' => 'Delete an object by ID',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => [
                            'type' => 'string',
                            'description' => 'The object ID to delete',
                        ],
                    ],
                    'required' => ['id'],
                ],
            ],
        ];
    }

    /**
     * Execute a function
     *
     * @param string      $functionName Function name
     * @param array       $parameters   Function parameters
     * @param string|null $userId       User ID for context
     *
     * @return array Function result
     *
     * @throws \Exception If function execution fails
     */
    public function executeFunction(string $functionName, array $parameters, ?string $userId = null): array
    {
        $this->log($functionName, $parameters);

        if (!$this->hasUserContext($userId)) {
            return $this->formatError('No user context available. Tool cannot execute without user session.');
        }

        try {
            switch ($functionName) {
                case 'search_objects':
                    return $this->searchObjects($parameters);
                case 'get_object':
                    return $this->getObject($parameters);
                case 'create_object':
                    return $this->createObject($parameters);
                case 'update_object':
                    return $this->updateObject($parameters);
                case 'delete_object':
                    return $this->deleteObject($parameters);
                default:
                    throw new \InvalidArgumentException("Unknown function: {$functionName}");
            }
        } catch (\Exception $e) {
            $this->log($functionName, $parameters, 'error', $e->getMessage());
            return $this->formatError($e->getMessage());
        }
    }

    /**
     * Search for objects
     *
     * @param array $parameters Function parameters
     *
     * @return array Result with list of objects
     */
    private function searchObjects(array $parameters): array
    {
        $limit = $parameters['limit'] ?? 20;
        $offset = $parameters['offset'] ?? 0;

        $filters = [];
        if (isset($parameters['register'])) {
            $filters['register'] = $parameters['register'];
        }
        if (isset($parameters['schema'])) {
            $filters['schema'] = $parameters['schema'];
        }
        if (isset($parameters['query']) && !empty($parameters['query'])) {
            $filters['_search'] = $parameters['query'];
        }

        $filters = $this->applyViewFilters($filters);

        $result = $this->objectService->findAll(
            null, // registerId
            null, // schemaId
            $limit,
            $offset,
            $filters
        );

        $objectList = array_map(function ($object) {
            return [
                'id' => $object->getId(),
                'uuid' => $object->getUuid(),
                'register' => $object->getRegister(),
                'schema' => $object->getSchema(),
                'data' => $object->getObject(),
                'created' => $object->getCreated()?->format('Y-m-d H:i:s'),
                'updated' => $object->getUpdated()?->format('Y-m-d H:i:s'),
            ];
        }, $result['results'] ?? []);

        return $this->formatSuccess(
            [
                'objects' => $objectList,
                'total' => $result['total'] ?? count($objectList),
            ],
            sprintf('Found %d objects', count($objectList))
        );
    }

    /**
     * Get a specific object
     *
     * @param array $parameters Function parameters
     *
     * @return array Result with object details
     *
     * @throws \Exception If object not found
     */
    private function getObject(array $parameters): array
    {
        $this->validateParameters($parameters, ['id']);

        $object = $this->objectService->findObject($parameters['id']);

        return $this->formatSuccess(
            [
                'id' => $object->getId(),
                'uuid' => $object->getUuid(),
                'register' => $object->getRegister(),
                'schema' => $object->getSchema(),
                'data' => $object->getObject(),
                'organisation' => $object->getOrganisation(),
                'owner' => $object->getOwner(),
                'created' => $object->getCreated()?->format('Y-m-d H:i:s'),
                'updated' => $object->getUpdated()?->format('Y-m-d H:i:s'),
            ],
            'Object retrieved successfully'
        );
    }

    /**
     * Create a new object
     *
     * @param array $parameters Function parameters
     *
     * @return array Result with created object
     *
     * @throws \Exception If creation fails
     */
    private function createObject(array $parameters): array
    {
        $this->validateParameters($parameters, ['register', 'schema', 'data']);

        $objectData = array_merge(
            $parameters['data'],
            [
                '@self' => [
                    'register' => $parameters['register'],
                    'schema' => $parameters['schema'],
                ],
            ]
        );

        $object = $this->objectService->saveObject(
            registerId: (int) $parameters['register'],
            schemaId: (int) $parameters['schema'],
            objectArray: $objectData
        );

        return $this->formatSuccess(
            [
                'id' => $object->getId(),
                'uuid' => $object->getUuid(),
                'register' => $object->getRegister(),
                'schema' => $object->getSchema(),
                'data' => $object->getObject(),
            ],
            'Object created successfully'
        );
    }

    /**
     * Update an existing object
     *
     * @param array $parameters Function parameters
     *
     * @return array Result with updated object
     *
     * @throws \Exception If update fails
     */
    private function updateObject(array $parameters): array
    {
        $this->validateParameters($parameters, ['id', 'data']);

        // Get existing object
        $existingObject = $this->objectService->findObject($parameters['id']);

        // Merge new data with existing data
        $mergedData = array_merge(
            $existingObject->getObject(),
            $parameters['data']
        );

        // Update object
        $object = $this->objectService->saveObject(
            registerId: $existingObject->getRegister(),
            schemaId: $existingObject->getSchema(),
            objectArray: $mergedData,
            id: $existingObject->getId()
        );

        return $this->formatSuccess(
            [
                'id' => $object->getId(),
                'uuid' => $object->getUuid(),
                'register' => $object->getRegister(),
                'schema' => $object->getSchema(),
                'data' => $object->getObject(),
            ],
            'Object updated successfully'
        );
    }

    /**
     * Delete an object
     *
     * @param array $parameters Function parameters
     *
     * @return array Result of deletion
     *
     * @throws \Exception If deletion fails
     */
    private function deleteObject(array $parameters): array
    {
        $this->validateParameters($parameters, ['id']);

        $object = $this->objectService->findObject($parameters['id']);
        $this->objectService->deleteObject($object->getId());

        return $this->formatSuccess(
            ['id' => $parameters['id']],
            'Object deleted successfully'
        );
    }
}

