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
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version  GIT: <git_id>
 *
 * @link     https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tool;

use LLPhant\Chat\FunctionInfo\FunctionInfo;
use LLPhant\Chat\FunctionInfo\Parameter;
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
    }

    /**
     * Get the tool name
     *
     * @return string Tool name
     */
    public function getName(): string
    {
        return 'Agent Management';
    }

    /**
     * Get the tool description
     *
     * @return string Tool description for LLM
     */
    public function getDescription(): string
    {
        return 'Manage AI agents in OpenRegister. Agents are AI assistants that can perform tasks, answer questions, and interact with data. Use this tool to list, view, create, update, or delete agents. Operations respect RBAC permissions, organisation boundaries, and privacy settings.';
    }

    /**
     * Get function definitions for LLM function calling
     *
     * @return FunctionInfo[] Array of function definitions
     */
    public function getFunctions(): array
    {
        return [
            // List agents
            new FunctionInfo(
                name: 'list_agents',
                description: 'List all agents accessible to the current user in their organisation. Returns basic information about each agent including name, type, and status. Respects privacy settings.',
                parameters: [
                    Parameter::int(
                        name: 'limit',
                        description: 'Maximum number of results to return (default: 50)',
                        required: false
                    ),
                    Parameter::int(
                        name: 'offset',
                        description: 'Number of results to skip for pagination (default: 0)',
                        required: false
                    ),
                ],
                fn: [$this, 'listAgents']
            ),

            // Get agent details
            new FunctionInfo(
                name: 'get_agent',
                description: 'Get detailed information about a specific agent by its UUID. Returns full agent configuration including system prompt, model settings, and enabled tools.',
                parameters: [
                    Parameter::string(
                        name: 'uuid',
                        description: 'UUID of the agent to retrieve',
                        required: true
                    ),
                ],
                fn: [$this, 'getAgent']
            ),

            // Create agent
            new FunctionInfo(
                name: 'create_agent',
                description: 'Create a new AI agent in the current organisation. Requires a name and system prompt. Can configure model, temperature, tools, and privacy settings.',
                parameters: [
                    Parameter::string(
                        name: 'name',
                        description: 'Name of the agent (required)',
                        required: true
                    ),
                    Parameter::string(
                        name: 'description',
                        description: 'Description of what the agent does',
                        required: false
                    ),
                    Parameter::string(
                        name: 'type',
                        description: 'Type of agent (e.g., "assistant", "support", "analyzer")',
                        required: false
                    ),
                    Parameter::string(
                        name: 'systemPrompt',
                        description: 'System prompt that defines the agent\'s behavior and personality',
                        required: false
                    ),
                ],
                fn: [$this, 'createAgent']
            ),

            // Update agent
            new FunctionInfo(
                name: 'update_agent',
                description: 'Update an existing agent. Only the owner can modify agents. Provide the UUID and fields to update.',
                parameters: [
                    Parameter::string(
                        name: 'uuid',
                        description: 'UUID of the agent to update',
                        required: true
                    ),
                    Parameter::string(
                        name: 'name',
                        description: 'New name for the agent',
                        required: false
                    ),
                    Parameter::string(
                        name: 'description',
                        description: 'New description',
                        required: false
                    ),
                    Parameter::string(
                        name: 'systemPrompt',
                        description: 'New system prompt',
                        required: false
                    ),
                ],
                fn: [$this, 'updateAgent']
            ),

            // Delete agent
            new FunctionInfo(
                name: 'delete_agent',
                description: 'Delete an agent permanently. Only the owner can delete agents. This will also delete all conversations associated with the agent. This action cannot be undone.',
                parameters: [
                    Parameter::string(
                        name: 'uuid',
                        description: 'UUID of the agent to delete',
                        required: true
                    ),
                ],
                fn: [$this, 'deleteAgent']
            ),
        ];
    }

    /**
     * List agents
     *
     * @param int $limit  Maximum number of results (default: 50)
     * @param int $offset Offset for pagination (default: 0)
     *
     * @return array Response with agents list
     */
    public function listAgents(int $limit = 50, int $offset = 0): array
    {
        try {
            $this->logger->info('[AgentTool] Listing agents', [
                'limit' => $limit,
                'offset' => $offset,
            ]);

            // Get agents via mapper (RBAC is enforced in mapper)
            $agents = $this->agentMapper->findAll($limit, $offset);
            $total = $this->agentMapper->count();

            // Convert to array
            $results = array_map(fn ($agent) => $agent->jsonSerialize(), $agents);

            return $this->formatSuccess([
                'agents' => $results,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ], "Found {$total} agents.");
        } catch (\Exception $e) {
            $this->logger->error('[AgentTool] Failed to list agents', [
                'error' => $e->getMessage(),
            ]);
            return $this->formatError('Failed to list agents: ' . $e->getMessage());
        }
    }

    /**
     * Get agent details
     *
     * @param string $uuid Agent UUID
     *
     * @return array Response with agent details
     */
    public function getAgent(string $uuid): array
    {
        try {
            $this->logger->info('[AgentTool] Getting agent', ['uuid' => $uuid]);

            // Find agent (RBAC enforced in mapper)
            $agent = $this->agentMapper->findByUuid($uuid);

            return $this->formatSuccess(
                $agent->jsonSerialize(),
                "Agent '{$agent->getName()}' retrieved successfully."
            );
        } catch (DoesNotExistException $e) {
            return $this->formatError("Agent with UUID '{$uuid}' not found.");
        } catch (\Exception $e) {
            $this->logger->error('[AgentTool] Failed to get agent', [
                'uuid' => $uuid,
                'error' => $e->getMessage(),
            ]);
            return $this->formatError('Failed to get agent: ' . $e->getMessage());
        }
    }

    /**
     * Create agent
     *
     * @param string      $name         Agent name
     * @param string|null $description  Agent description
     * @param string|null $type         Agent type
     * @param string|null $systemPrompt Agent system prompt
     *
     * @return array Response with created agent
     */
    public function createAgent(
        string $name,
        ?string $description = null,
        ?string $type = null,
        ?string $systemPrompt = null
    ): array {
        try {
            $this->logger->info('[AgentTool] Creating agent', ['name' => $name]);

            // Create agent entity
            $agent = new Agent();
            $agent->setName($name);
            
            if ($description) {
                $agent->setDescription($description);
            }
            if ($type) {
                $agent->setType($type);
            }
            if ($systemPrompt) {
                $agent->setSystemPrompt($systemPrompt);
            }

            // Set current user as owner if we have agent context
            if ($this->agent) {
                $agent->setOwner($this->agent->getOwner());
            }

            // Save via mapper (RBAC and organisation are enforced in mapper)
            $agent = $this->agentMapper->insert($agent);

            return $this->formatSuccess(
                $agent->jsonSerialize(),
                "Agent '{$name}' created successfully with UUID {$agent->getUuid()}."
            );
        } catch (\Exception $e) {
            $this->logger->error('[AgentTool] Failed to create agent', [
                'name' => $name,
                'error' => $e->getMessage(),
            ]);
            return $this->formatError('Failed to create agent: ' . $e->getMessage());
        }
    }

    /**
     * Update agent
     *
     * @param string      $uuid         Agent UUID
     * @param string|null $name         New name
     * @param string|null $description  New description
     * @param string|null $systemPrompt New system prompt
     *
     * @return array Response with updated agent
     */
    public function updateAgent(
        string $uuid,
        ?string $name = null,
        ?string $description = null,
        ?string $systemPrompt = null
    ): array {
        try {
            $this->logger->info('[AgentTool] Updating agent', ['uuid' => $uuid]);

            // Find agent (RBAC enforced in mapper)
            $agent = $this->agentMapper->findByUuid($uuid);

            // Update fields
            if ($name !== null) {
                $agent->setName($name);
            }
            if ($description !== null) {
                $agent->setDescription($description);
            }
            if ($systemPrompt !== null) {
                $agent->setSystemPrompt($systemPrompt);
            }

            // Save changes (RBAC enforced in mapper)
            $agent = $this->agentMapper->update($agent);

            return $this->formatSuccess(
                $agent->jsonSerialize(),
                "Agent updated successfully."
            );
        } catch (DoesNotExistException $e) {
            return $this->formatError("Agent with UUID '{$uuid}' not found.");
        } catch (\Exception $e) {
            $this->logger->error('[AgentTool] Failed to update agent', [
                'uuid' => $uuid,
                'error' => $e->getMessage(),
            ]);
            return $this->formatError('Failed to update agent: ' . $e->getMessage());
        }
    }

    /**
     * Delete agent
     *
     * @param string $uuid Agent UUID
     *
     * @return array Response confirming deletion
     */
    public function deleteAgent(string $uuid): array
    {
        try {
            $this->logger->info('[AgentTool] Deleting agent', ['uuid' => $uuid]);

            // Find agent (RBAC enforced in mapper)
            $agent = $this->agentMapper->findByUuid($uuid);
            $name = $agent->getName();

            // Delete (RBAC enforced in mapper)
            $this->agentMapper->delete($agent);

            return $this->formatSuccess(
                ['uuid' => $uuid],
                "Agent '{$name}' deleted successfully."
            );
        } catch (DoesNotExistException $e) {
            return $this->formatError("Agent with UUID '{$uuid}' not found.");
        } catch (\Exception $e) {
            $this->logger->error('[AgentTool] Failed to delete agent', [
                'uuid' => $uuid,
                'error' => $e->getMessage(),
            ]);
            return $this->formatError('Failed to delete agent: ' . $e->getMessage());
        }
    }

    /**
     * Execute a function by name
     *
     * @param string      $functionName Name of the function to execute
     * @param array       $parameters   Function parameters
     * @param string|null $userId       User ID for session context (optional)
     *
     * @return array Response
     */
    public function executeFunction(string $functionName, array $parameters, ?string $userId = null): array
    {
        return match ($functionName) {
            'list_agents' => $this->listAgents(
                $parameters['limit'] ?? 50,
                $parameters['offset'] ?? 0
            ),
            'get_agent' => $this->getAgent($parameters['uuid']),
            'create_agent' => $this->createAgent(
                $parameters['name'],
                $parameters['description'] ?? null,
                $parameters['type'] ?? null,
                $parameters['systemPrompt'] ?? null
            ),
            'update_agent' => $this->updateAgent(
                $parameters['uuid'],
                $parameters['name'] ?? null,
                $parameters['description'] ?? null,
                $parameters['systemPrompt'] ?? null
            ),
            'delete_agent' => $this->deleteAgent($parameters['uuid']),
            default => $this->formatError("Unknown function: {$functionName}"),
        };
    }
}
