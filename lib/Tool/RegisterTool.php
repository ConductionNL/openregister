<?php
/**
 * OpenRegister Register Tool
 *
 * LLphant function tool for managing registers through natural language.
 * Provides CRUD operations on registers with RBAC and multi-tenancy support.
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

use OCA\OpenRegister\Service\RegisterService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Register Tool
 *
 * Allows agents to manage registers in OpenRegister.
 * Use this tool when users ask to:
 * - View available registers
 * - Get details about a specific register
 * - Create a new register
 * - Update register settings
 * - Delete a register
 *
 * All operations respect user permissions and organization boundaries.
 *
 * @category Tool
 * @package  OCA\OpenRegister\Tool
 */
class RegisterTool extends AbstractTool
{

    /**
     * Register service
     *
     * @var RegisterService
     */
    private RegisterService $registerService;


    /**
     * Constructor
     *
     * @param IUserSession    $userSession     User session service
     * @param LoggerInterface $logger          Logger service
     * @param RegisterService $registerService Register service
     */
    public function __construct(
        IUserSession $userSession,
        LoggerInterface $logger,
        RegisterService $registerService
    ) {
        parent::__construct($userSession, $logger);
        $this->registerService = $registerService;

    }//end __construct()


    /**
     * Get tool name
     *
     * @return string Tool name
     */
    public function getName(): string
    {
        return 'register';

    }//end getName()


    /**
     * Get tool description
     *
     * @return string Tool description
     */
    public function getDescription(): string
    {
        return 'Manage registers in OpenRegister. Registers are collections that organize schemas and objects. '.'Use this tool to list, view, create, update, or delete registers.';

    }//end getDescription()


    /**
     * Get function definitions for LLphant
     *
     * @return array Array of function definitions
     */
    public function getFunctions(): array
    {
        return [
            [
                'name'        => 'list_registers',
                'description' => 'Get a list of all accessible registers',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'limit'  => [
                            'type'        => 'integer',
                            'description' => 'Maximum number of registers to return (default: 100)',
                        ],
                        'offset' => [
                            'type'        => 'integer',
                            'description' => 'Number of registers to skip for pagination (default: 0)',
                        ],
                    ],
                    'required'   => [],
                ],
            ],
            [
                'name'        => 'get_register',
                'description' => 'Get details about a specific register by ID or slug',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'id' => [
                            'type'        => 'string',
                            'description' => 'The register ID or slug to retrieve',
                        ],
                    ],
                    'required'   => ['id'],
                ],
            ],
            [
                'name'        => 'create_register',
                'description' => 'Create a new register',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'title'       => [
                            'type'        => 'string',
                            'description' => 'The title of the register',
                        ],
                        'description' => [
                            'type'        => 'string',
                            'description' => 'A description of what this register is for',
                        ],
                        'slug'        => [
                            'type'        => 'string',
                            'description' => 'URL-friendly identifier (optional, generated from title if not provided)',
                        ],
                    ],
                    'required'   => ['title'],
                ],
            ],
            [
                'name'        => 'update_register',
                'description' => 'Update an existing register',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'id'          => [
                            'type'        => 'string',
                            'description' => 'The register ID to update',
                        ],
                        'title'       => [
                            'type'        => 'string',
                            'description' => 'New title for the register',
                        ],
                        'description' => [
                            'type'        => 'string',
                            'description' => 'New description for the register',
                        ],
                    ],
                    'required'   => ['id'],
                ],
            ],
            [
                'name'        => 'delete_register',
                'description' => 'Delete a register (only if it has no objects)',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'id' => [
                            'type'        => 'string',
                            'description' => 'The register ID to delete',
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

        if (!$this->hasUserContext($userId)) {
            return $this->formatError('No user context available. Tool cannot execute without user session.');
        }

        try {
            // Convert snake_case to camelCase for PSR compliance
            $methodName = lcfirst(str_replace('_', '', ucwords($functionName, '_')));

            // Call the method directly (LLPhant-compatible)
            return $this->$methodName(...array_values($parameters));
        } catch (\Exception $e) {
            $this->log($functionName, $parameters, 'error', $e->getMessage());
            return $this->formatError($e->getMessage());
        }

    }//end executeFunction()


    /**
     * List registers
     *
     * LLPhant-compatible method that can be called directly.
     *
     * @param int $limit  Maximum number of registers to return
     * @param int $offset Offset for pagination
     *
     * @return array Result with list of registers
     */
    public function listRegisters(int $limit=100, int $offset=0): array
    {

        $filters = [];
        $filters = $this->applyViewFilters($filters);

        $registers = $this->registerService->findAll($limit, $offset, $filters);

        $registerList = array_map(
                function ($register) {
                    return [
                        'id'          => $register->getId(),
                        'uuid'        => $register->getUuid(),
                        'title'       => $register->getTitle(),
                        'description' => $register->getDescription(),
                        'slug'        => $register->getSlug(),
                    ];
                },
                $registers
                );

        return $this->formatSuccess($registerList, sprintf('Found %d registers', count($registerList)));

    }//end listRegisters()


    /**
     * Get a specific register
     *
     * @param array $parameters Function parameters
     *
     * @return array Result with register details
     *
     * @throws \Exception If register not found
     */
    public function getRegister(string $id): array
    {
        $register = $this->registerService->find($id);

        return $this->formatSuccess(
            [
                'id'           => $register->getId(),
                'uuid'         => $register->getUuid(),
                'title'        => $register->getTitle(),
                'description'  => $register->getDescription(),
                'slug'         => $register->getSlug(),
                'folder'       => $register->getFolder(),
                'organisation' => $register->getOrganisation(),
                'created'      => $register->getCreated()?->format('Y-m-d H:i:s'),
                'updated'      => $register->getUpdated()?->format('Y-m-d H:i:s'),
            ],
            'Register retrieved successfully'
        );

    }//end getRegister()


    /**
     * Create a new register
     *
     * @param array $parameters Function parameters
     *
     * @return array Result with created register
     *
     * @throws \Exception If creation fails
     */
    public function createRegister(string $title, string $description='', ?string $slug=null): array
    {
        $data = [
            'title'       => $title,
            'description' => $description,
        ];

        if ($slug !== null) {
            $data['slug'] = $slug;
        }

        $register = $this->registerService->createFromArray($data);

        return $this->formatSuccess(
            [
                'id'          => $register->getId(),
                'uuid'        => $register->getUuid(),
                'title'       => $register->getTitle(),
                'description' => $register->getDescription(),
                'slug'        => $register->getSlug(),
            ],
            'Register created successfully'
        );

    }//end createRegister()


    /**
     * Update an existing register
     *
     * @param array $parameters Function parameters
     *
     * @return array Result with updated register
     *
     * @throws \Exception If update fails
     */
    public function updateRegister(string $id, ?string $title=null, ?string $description=null): array
    {
        $data = [];
        if ($title !== null) {
            $data['title'] = $title;
        }

        if ($description !== null) {
            $data['description'] = $description;
        }

        if (empty($data)) {
            throw new \InvalidArgumentException('No update data provided');
        }

        $register = $this->registerService->updateFromArray((int) $id, $data);

        return $this->formatSuccess(
            [
                'id'          => $register->getId(),
                'uuid'        => $register->getUuid(),
                'title'       => $register->getTitle(),
                'description' => $register->getDescription(),
                'slug'        => $register->getSlug(),
            ],
            'Register updated successfully'
        );

    }//end updateRegister()


    /**
     * Delete a register
     *
     * @param array $parameters Function parameters
     *
     * @return array Result of deletion
     *
     * @throws \Exception If deletion fails
     */
    public function deleteRegister(string $id): array
    {
        $register = $this->registerService->find($id);
        $this->registerService->delete($register);

        return $this->formatSuccess(
            ['id' => $id],
            'Register deleted successfully'
        );

    }//end deleteRegister()


}//end class
