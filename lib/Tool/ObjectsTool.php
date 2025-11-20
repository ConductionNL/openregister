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
     * @param IUserSession    $userSession   User session service
     * @param LoggerInterface $logger        Logger service
     * @param ObjectService   $objectService Object service
     */
    public function __construct(
        IUserSession $userSession,
        LoggerInterface $logger,
        ObjectService $objectService
    ) {
        parent::__construct($userSession, $logger);
        $this->objectService = $objectService;

    }//end __construct()


    /**
     * Get tool name
     *
     * @return string Tool name
     *
     * @psalm-return 'objects'
     */
    public function getName(): string
    {
        return 'objects';

    }//end getName()


    /**
     * Get tool description
     *
     * @return string Tool description
     *
     * @psalm-return 'Manage objects: search, view, create, update, or delete objects. Objects are data records conforming to schemas.'
     */
    public function getDescription(): string
    {
        return 'Manage objects: search, view, create, update, or delete objects. Objects are data records conforming to schemas.';

    }//end getDescription()


    /**
     * Get function definitions for LLphant
     *
     * @return (((string|string[])[]|string)[]|string)[][] Array of function definitions
     *
     * @psalm-return list<array<string, mixed>>
     */
    public function getFunctions(): array
    {
        return [
            [
                'name'        => 'search_objects',
                'description' => 'Search for objects with optional filters',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'query'    => [
                            'type'        => 'string',
                            'description' => 'Search query text (optional)',
                        ],
                        'register' => [
                            'type'        => 'string',
                            'description' => 'Filter by register ID (optional)',
                        ],
                        'schema'   => [
                            'type'        => 'string',
                            'description' => 'Filter by schema ID (optional)',
                        ],
                        'limit'    => [
                            'type'        => 'integer',
                            'description' => 'Maximum number of results (default: 20)',
                        ],
                        'offset'   => [
                            'type'        => 'integer',
                            'description' => 'Number of results to skip (default: 0)',
                        ],
                    ],
                    'required'   => [],
                ],
            ],
            [
                'name'        => 'get_object',
                'description' => 'Get details about a specific object by ID or UUID',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'id' => [
                            'type'        => 'string',
                            'description' => 'The object ID or UUID to retrieve',
                        ],
                    ],
                    'required'   => ['id'],
                ],
            ],
            [
                'name'        => 'create_object',
                'description' => 'Create a new object with data',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'register' => [
                            'type'        => 'string',
                            'description' => 'The register ID where the object should be created',
                        ],
                        'schema'   => [
                            'type'        => 'string',
                            'description' => 'The schema ID that defines the object structure',
                        ],
                        'data'     => [
                            'type'        => 'object',
                            'description' => 'The object data conforming to the schema',
                        ],
                    ],
                    'required'   => ['register', 'schema', 'data'],
                ],
            ],
            [
                'name'        => 'update_object',
                'description' => 'Update an existing object',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'id'   => [
                            'type'        => 'string',
                            'description' => 'The object ID to update',
                        ],
                        'data' => [
                            'type'        => 'object',
                            'description' => 'The updated object data (partial updates supported)',
                        ],
                    ],
                    'required'   => ['id', 'data'],
                ],
            ],
            [
                'name'        => 'delete_object',
                'description' => 'Delete an object by ID',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'id' => [
                            'type'        => 'string',
                            'description' => 'The object ID to delete',
                        ],
                    ],
                    'required'   => ['id'],
                ],
            ],
        ];

    }//end getFunctions()


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
    public function executeFunction(string $functionName, array $parameters, ?string $userId=null): array
    {
        $this->log($functionName, $parameters);

        if ($this->hasUserContext($userId) === false) {
            return $this->formatError('No user context available. Tool cannot execute without user session.');
        }

        try {
            // Convert snake_case to camelCase for PSR compliance.
            $methodName = lcfirst(str_replace('_', '', ucwords($functionName, '_')));

            // Call the method directly (LLPhant-compatible).
            return $this->$methodName(...array_values($parameters));
        } catch (\Exception $e) {
            $this->log($functionName, $parameters, 'error', $e->getMessage());
            return $this->formatError($e->getMessage());
        }

    }//end executeFunction()


    /**
     * Search for objects
     *
     * @param int         $limit    Result limit
     * @param int         $offset   Result offset
     * @param string|null $register Register filter
     * @param string|null $schema   Schema filter
     * @param string|null $query    Search query
     *
     * @return array Result with list of objects
     */
    public function searchObjects(int $limit=20, int $offset=0, ?string $register=null, ?string $schema=null, ?string $query=null): array
    {
        $filters = [];
        if ($register !== null) {
            $filters['register'] = $register;
        }

        if ($schema !== null) {
            $filters['schema'] = $schema;
        }

        if ($query !== null && $query !== '') {
            $filters['_search'] = $query;
        }

        $filters = $this->applyViewFilters($filters);

        $result = $this->objectService->findAll(
            null,
            // Register ID.
            null,
            // Schema ID.
            $limit,
            $offset,
            $filters
        );

        $objectList = array_map(
                function ($object) {
                    return [
                        'id'       => $object->getId(),
                        'uuid'     => $object->getUuid(),
                        'register' => $object->getRegister(),
                        'schema'   => $object->getSchema(),
                        'data'     => $object->getObject(),
                        'created'  => $object->getCreated()?->format('Y-m-d H:i:s'),
                        'updated'  => $object->getUpdated()?->format('Y-m-d H:i:s'),
                    ];
                },
                $result['results'] ?? []
                );

        return $this->formatSuccess(
            [
                'objects' => $objectList,
                'total'   => $result['total'] ?? count($objectList),
            ],
            sprintf('Found %d objects', count($objectList))
        );

    }//end searchObjects()


    /**
     * Get a specific object
     *
     * @param string $id Object ID
     *
     * @return array Result with object details
     *
     * @throws \Exception If object not found
     */
    public function getObject(string $id): array
    {
        $object = $this->objectService->find($id);

        return $this->formatSuccess(
            [
                'id'           => $object->getId(),
                'uuid'         => $object->getUuid(),
                'register'     => $object->getRegister(),
                'schema'       => $object->getSchema(),
                'data'         => $object->getObject(),
                'organisation' => $object->getOrganisation(),
                'owner'        => $object->getOwner(),
                'created'      => $object->getCreated()?->format('Y-m-d H:i:s'),
                'updated'      => $object->getUpdated()?->format('Y-m-d H:i:s'),
            ],
            'Object retrieved successfully'
        );

    }//end getObject()


    /**
     * Create a new object
     *
     * @param string $register Register identifier
     * @param string $schema   Schema identifier
     * @param array  $data     Object data
     *
     * @return array Result with created object
     *
     * @throws \Exception If creation fails
     */
    public function createObject(string $register, string $schema, array $data): array
    {
        $objectData = array_merge(
            $data,
            [
                '@self' => [
                    'register' => $register,
                    'schema'   => $schema,
                ],
            ]
        );

        $object = $this->objectService->saveObject(
            object: $objectData,
            register: (int) $register,
            schema: (int) $schema
        );

        return $this->formatSuccess(
            [
                'id'       => $object->getId(),
                'uuid'     => $object->getUuid(),
                'register' => $object->getRegister(),
                'schema'   => $object->getSchema(),
                'data'     => $object->getObject(),
            ],
            'Object created successfully'
        );

    }//end createObject()


    /**
     * Update an existing object
     *
     * @param string $id   Object ID
     * @param array  $data Object data
     *
     * @return array Result with updated object
     *
     * @throws \Exception If update fails
     */
    public function updateObject(string $id, array $data): array
    {
        // Get existing object.
        $existingObject = $this->objectService->find($id);

        // Merge new data with existing data.
        $mergedData = array_merge(
            $existingObject->getObject(),
            $data
        );

        // Update object.
        $object = $this->objectService->saveObject(
            object: $mergedData,
            register: $existingObject->getRegister(),
            schema: $existingObject->getSchema(),
            uuid: $existingObject->getUuid()
        );

        return $this->formatSuccess(
            [
                'id'       => $object->getId(),
                'uuid'     => $object->getUuid(),
                'register' => $object->getRegister(),
                'schema'   => $object->getSchema(),
                'data'     => $object->getObject(),
            ],
            'Object updated successfully'
        );

    }//end updateObject()


    /**
     * Delete an object
     *
     * @param string $id Object ID
     *
     * @return array Result of deletion
     *
     * @throws \Exception If deletion fails
     */
    public function deleteObject(string $id): array
    {
        $object = $this->objectService->find($id);
        $this->objectService->deleteObject($object->getUuid() ?? $object->getId());

        return $this->formatSuccess(
            ['id' => $id],
            'Object deleted successfully'
        );

    }//end deleteObject()


}//end class
