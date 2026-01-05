<?php

/**
 * AgentTool
 *
 * LLphant function tool for AI agents to manage other agents.
 * Provides CRUD operations for agents with RBAC enforcement.
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

declare(strict_types=1);

namespace OCA\OpenRegister\Tool;

use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * AgentTool
 *
 * Provides function calling capabilities for AI agents to perform CRUD operations on other agents.
 * All operations respect the agent's configured views, RBAC permissions, and organisation boundaries.
 * Note: An agent can manage other agents but should be mindful of access control and privacy settings.
 *
 * @package OCA\OpenRegister\Tool
 */
class AgentTool extends AbstractTool implements ToolInterface
{

    /**
     * Agent mapper for database operations
     *
     * @var AgentMapper
     */
    private AgentMapper $agentMapper;

    /**
     * AgentTool constructor
     *
     * @param AgentMapper     $agentMapper Agent mapper instance
     * @param IUserSession    $userSession User session
     * @param LoggerInterface $logger      Logger instance
     */
    public function __construct(
        AgentMapper $agentMapper,
        IUserSession $userSession,
        LoggerInterface $logger
    ) {
        parent::__construct($userSession, $logger);
        $this->agentMapper = $agentMapper;
    }//end __construct()

    /**
     * Get the tool name
     *
     * @return string Tool name
     *
     * @psalm-return 'Agent Management'
     */
    public function getName(): string
    {
        return 'Agent Management';
    }//end getName()

    /**
     * Get the tool description
     *
     * @return string The tool description
     */
    public function getDescription(): string
    {
        $desc = 'Manage AI agents: list, view, create, update, or delete agents ';
        return $desc.'with RBAC permissions and organisation boundaries.';
    }//end getDescription()

    /**
     * Get function definitions for LLM function calling
     *
     * Returns function definitions in OpenAI function calling format.
     * These are used by LLMs to understand what capabilities this tool provides.
     *
     * @return array<int, array<string, mixed>> Array of function definitions
     */
    public function getFunctions(): array
    {
        $listDesc    = 'List all agents accessible to current user. ';
        $listDesc   .= 'Returns name, type, status with privacy settings respected.';
        $getDesc     = 'Get detailed agent information by UUID. ';
        $getDesc    .= 'Returns configuration, system prompt, model settings, and tools.';
        $createDesc  = 'Create a new AI agent. Requires name and system prompt. ';
        $createDesc .= 'Configure model, temperature, tools, and privacy.';
        $updateDesc  = 'Update an existing agent. Only the owner can modify agents. ';
        $updateDesc .= 'Provide the UUID and fields to update.';
        $deleteDesc  = 'Permanently delete agent (owner only). ';
        $deleteDesc .= 'Deletes all associated conversations. Cannot be undone.';

        return [
            [
                'name'        => 'list_agents',
                'description' => $listDesc,
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'limit'  => [
                            'type'        => 'integer',
                            'description' => 'Maximum number of results to return (default: 50)',
                        ],
                        'offset' => [
                            'type'        => 'integer',
                            'description' => 'Number of results to skip for pagination (default: 0)',
                        ],
                    ],
                    'required'   => [],
                ],
            ],
            [
                'name'        => 'get_agent',
                'description' => $getDesc,
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'uuid' => [
                            'type'        => 'string',
                            'description' => 'UUID of the agent to retrieve',
                        ],
                    ],
                    'required'   => ['uuid'],
                ],
            ],
            [
                'name'        => 'create_agent',
                'description' => $createDesc,
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'name'         => [
                            'type'        => 'string',
                            'description' => 'Name of the agent (required)',
                        ],
                        'description'  => [
                            'type'        => 'string',
                            'description' => 'Description of what the agent does',
                        ],
                        'type'         => [
                            'type'        => 'string',
                            'description' => 'Type of agent (e.g., "assistant", "support", "analyzer")',
                        ],
                        'systemPrompt' => [
                            'type'        => 'string',
                            'description' => 'System prompt that defines the agent\'s behavior and personality',
                        ],
                    ],
                    'required'   => ['name'],
                ],
            ],
            [
                'name'        => 'update_agent',
                'description' => $updateDesc,
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'uuid'         => [
                            'type'        => 'string',
                            'description' => 'UUID of the agent to update',
                        ],
                        'name'         => [
                            'type'        => 'string',
                            'description' => 'New name for the agent',
                        ],
                        'description'  => [
                            'type'        => 'string',
                            'description' => 'New description',
                        ],
                        'systemPrompt' => [
                            'type'        => 'string',
                            'description' => 'New system prompt',
                        ],
                    ],
                    'required'   => ['uuid'],
                ],
            ],
            [
                'name'        => 'delete_agent',
                'description' => $deleteDesc,
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'uuid' => [
                            'type'        => 'string',
                            'description' => 'UUID of the agent to delete',
                        ],
                    ],
                    'required'   => ['uuid'],
                ],
            ],
        ];
    }//end getFunctions()

    /**
     * List agents
     *
     * @param int $limit  Maximum number of results (default: 50)
     * @param int $offset Offset for pagination (default: 0)
     *
     * @return (bool|mixed|string)[] Response with agents list
     *
     * @psalm-return array{success: bool, error?: string, details?: mixed, message?: string, data?: mixed}
     */
    public function listAgents(int $limit=50, int $offset=0): array
    {
        try {
            $this->logger->info(
                '[AgentTool] Listing agents',
                [
                    'limit'  => $limit,
                    'offset' => $offset,
                ]
            );

            // Get agents via mapper (RBAC is enforced in mapper).
            $agents = $this->agentMapper->findAll(limit: $limit, offset: $offset);
            $total  = $this->agentMapper->count();

            // Convert to array.
            $results = array_map(fn ($agent) => $agent->jsonSerialize(), $agents);

            return $this->formatSuccess(
                data: [
                    'agents' => $results,
                    'total'  => $total,
                    'limit'  => $limit,
                    'offset' => $offset,
                ],
                message: "Found {$total} agents."
            );
        } catch (\Exception $e) {
            $this->logger->error(
                '[AgentTool] Failed to list agents',
                [
                    'error' => $e->getMessage(),
                ]
            );
            return $this->formatError(message: 'Failed to list agents: '.$e->getMessage());
        }//end try
    }//end listAgents()

    /**
     * Get agent details
     *
     * @param string $uuid Agent UUID
     *
     * @return (bool|mixed|string)[] Response with agent details
     *
     * @psalm-return array{success: bool, error?: string, details?: mixed, message?: string, data?: mixed}
     */
    public function getAgent(string $uuid): array
    {
        try {
            $this->logger->info('[AgentTool] Getting agent', ['uuid' => $uuid]);

            // Find agent (RBAC enforced in mapper).
            $agent = $this->agentMapper->findByUuid(uuid: $uuid);

            return $this->formatSuccess(
                data: $agent->jsonSerialize(),
                message: "Agent '{$agent->getName()}' retrieved successfully."
            );
        } catch (DoesNotExistException $e) {
            return $this->formatError(message: "Agent with UUID '{$uuid}' not found.");
        } catch (\Exception $e) {
            $this->logger->error(
                '[AgentTool] Failed to get agent',
                [
                    'uuid'  => $uuid,
                    'error' => $e->getMessage(),
                ]
            );
            return $this->formatError(message: 'Failed to get agent: '.$e->getMessage());
        }//end try
    }//end getAgent()

    /**
     * Create agent
     *
     * @param string      $name         Agent name
     * @param string|null $description  Agent description
     * @param string|null $type         Agent type
     * @param string|null $systemPrompt Agent system prompt
     *
     * @return (bool|mixed|string)[] Response with created agent
     *
     * @psalm-return array{success: bool, error?: string, details?: mixed, message?: string, data?: mixed}
     */
    public function createAgent(
        string $name,
        ?string $description=null,
        ?string $type=null,
        ?string $systemPrompt=null
    ): array {
        try {
            $this->logger->info('[AgentTool] Creating agent', ['name' => $name]);

            // Create agent entity.
            $agent = new Agent();
            $agent->setName($name);

            if ($description !== null && $description !== '') {
                $agent->setDescription($description);
            }

            if ($type !== null && $type !== '') {
                $agent->setType($type);
            }

            if ($systemPrompt !== null && $systemPrompt !== '') {
                $agent->setPrompt($systemPrompt);
            }

            // Set current user as owner if we have agent context.
            if ($this->agent !== null) {
                $agent->setOwner($this->agent->getOwner());
            }

            // Save via mapper (RBAC and organisation are enforced in mapper).
            $agent = $this->agentMapper->insert($agent);

            return $this->formatSuccess(
                data: $agent->jsonSerialize(),
                message: "Agent '{$name}' created successfully with UUID {$agent->getUuid()}."
            );
        } catch (\Exception $e) {
            $this->logger->error(
                '[AgentTool] Failed to create agent',
                [
                    'name'  => $name,
                    'error' => $e->getMessage(),
                ]
            );
            return $this->formatError(message: 'Failed to create agent: '.$e->getMessage());
        }//end try
    }//end createAgent()

    /**
     * Update agent
     *
     * @param string      $uuid         Agent UUID
     * @param string|null $name         New name
     * @param string|null $description  New description
     * @param string|null $systemPrompt New system prompt
     *
     * @return (bool|mixed|string)[] Response with updated agent
     *
     * @psalm-return array{success: bool, error?: string, details?: mixed, message?: string, data?: mixed}
     */
    public function updateAgent(
        string $uuid,
        ?string $name=null,
        ?string $description=null,
        ?string $systemPrompt=null
    ): array {
        try {
            $this->logger->info('[AgentTool] Updating agent', ['uuid' => $uuid]);

            // Find agent (RBAC enforced in mapper).
            $agent = $this->agentMapper->findByUuid(uuid: $uuid);

            // Update fields.
            if ($name !== null) {
                $agent->setName($name);
            }

            if ($description !== null) {
                $agent->setDescription($description);
            }

            if ($systemPrompt !== null) {
                $agent->setPrompt($systemPrompt);
            }

            // Save changes (RBAC enforced in mapper).
            $agent = $this->agentMapper->update($agent);

            return $this->formatSuccess(
                data: $agent->jsonSerialize(),
                message: "Agent updated successfully."
            );
        } catch (DoesNotExistException $e) {
            return $this->formatError(message: "Agent with UUID '{$uuid}' not found.");
        } catch (\Exception $e) {
            $this->logger->error(
                '[AgentTool] Failed to update agent',
                [
                    'uuid'  => $uuid,
                    'error' => $e->getMessage(),
                ]
            );
            return $this->formatError(message: 'Failed to update agent: '.$e->getMessage());
        }//end try
    }//end updateAgent()

    /**
     * Delete agent
     *
     * @param string $uuid Agent UUID
     *
     * @return (bool|mixed|string)[] Response confirming deletion
     *
     * @psalm-return array{success: bool, error?: string, details?: mixed, message?: string, data?: mixed}
     */
    public function deleteAgent(string $uuid): array
    {
        try {
            $this->logger->info('[AgentTool] Deleting agent', ['uuid' => $uuid]);

            // Find agent (RBAC enforced in mapper).
            $agent = $this->agentMapper->findByUuid(uuid: $uuid);
            $name  = $agent->getName();

            // Delete (RBAC enforced in mapper).
            $this->agentMapper->delete($agent);

            return $this->formatSuccess(
                data: ['uuid' => $uuid],
                message: "Agent '{$name}' deleted successfully."
            );
        } catch (DoesNotExistException $e) {
            return $this->formatError(message: "Agent with UUID '{$uuid}' not found.");
        } catch (\Exception $e) {
            $this->logger->error(
                '[AgentTool] Failed to delete agent',
                [
                    'uuid'  => $uuid,
                    'error' => $e->getMessage(),
                ]
            );
            return $this->formatError(message: 'Failed to delete agent: '.$e->getMessage());
        }//end try
    }//end deleteAgent()

    /**
     * Execute a function by name
     *
     * @param string      $functionName Name of the function to execute
     * @param array       $parameters   Function parameters
     * @param string|null $userId       User ID for session context (optional)
     *
     * @return array Response
     */
    public function executeFunction(string $functionName, array $parameters, ?string $userId=null): array
    {
        // Convert snake_case to camelCase for PSR compliance.
        $methodName = lcfirst(str_replace('_', '', ucwords($functionName, '_')));

        // Call the method directly (LLPhant-compatible).
        return $this->$methodName(...array_values($parameters));
    }//end executeFunction()
}//end class
