<?php
/**
 * OpenRegister Chat Tool Management Handler
 *
 * Handler for LLM tool/function calling management.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Chat
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service\Chat;

use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Service\ToolRegistry;
use OCA\OpenRegister\Tool\ToolInterface;
use Psr\Log\LoggerInterface;
use LLPhant\Chat\FunctionInfo\FunctionInfo;
use LLPhant\Chat\FunctionInfo\Parameter;

/**
 * ToolManagementHandler
 *
 * Handles LLM tool/function calling setup and management.
 * Converts tool definitions to formats expected by LLM providers.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Chat
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class ToolManagementHandler
{

    /**
     * Agent mapper
     *
     * @var AgentMapper
     */
    private AgentMapper $agentMapper;

    /**
     * Tool registry
     *
     * @var ToolRegistry
     */
    private ToolRegistry $toolRegistry;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;


    /**
     * Constructor
     *
     * @param AgentMapper     $agentMapper  Agent mapper.
     * @param ToolRegistry    $toolRegistry Tool registry.
     * @param LoggerInterface $logger       Logger.
     *
     * @return void
     */
    public function __construct(
        AgentMapper $agentMapper,
        ToolRegistry $toolRegistry,
        LoggerInterface $logger
    ) {
        $this->agentMapper  = $agentMapper;
        $this->toolRegistry = $toolRegistry;
        $this->logger       = $logger;

    }//end __construct()


    /**
     * Get enabled tools for agent
     *
     * Loads and initializes tools enabled for the given agent.
     * Filters by selectedTools if provided.
     *
     * @param Agent|null $agent         Agent entity (optional).
     * @param array      $selectedTools Tool UUIDs to use (empty = all agent tools).
     *
     * @return array Array of ToolInterface instances
     *
     * @psalm-return list<ToolInterface>
     */
    public function getAgentTools(?Agent $agent, array $selectedTools=[]): array
    {
        if ($agent === null) {
            return [];
        }

        $enabledToolIds = $agent->getTools();
        if ($enabledToolIds === null || empty($enabledToolIds) === true) {
            return [];
        }

        // If selectedTools provided, filter enabled tools.
        if (empty($selectedTools) === false) {
            $enabledToolIds = array_intersect($enabledToolIds, $selectedTools);
            $this->logger->info(
                message: '[ChatService] Filtering tools',
                context: [
                    'agentTools'    => count($agent->getTools()),
                    'selectedTools' => count($selectedTools),
                    'filteredTools' => count($enabledToolIds),
                ]
            );
        }

        $tools = [];

        foreach ($enabledToolIds as $toolId) {
            // Support both old format (register, schema, objects) and new format (app.tool).
            if (strpos($toolId, '.') !== false) {
                $fullToolId = $toolId;
            } else {
                $fullToolId = 'openregister.'.$toolId;
            }

            $tool = $this->toolRegistry->getTool($fullToolId);
            if ($tool !== null) {
                $tool->setAgent($agent);
                $tools[] = $tool;
                $this->logger->debug(
                    message: '[ChatService] Loaded tool',
                    context: ['id' => $fullToolId]
                );
            } else {
                $this->logger->warning(
                    message: '[ChatService] Tool not found',
                    context: ['id' => $fullToolId]
                );
            }
        }//end foreach

        return $tools;

    }//end getAgentTools()


    /**
     * Convert tools to OpenAI function format
     *
     * Converts tool definitions to the format expected by OpenAI's function calling API.
     *
     * @param array $tools Array of ToolInterface instances.
     *
     * @return array Array of function definitions for OpenAI
     *
     * @psalm-return list<array>
     */
    public function convertToolsToFunctions(array $tools): array
    {
        $functions = [];

        foreach ($tools as $tool) {
            $toolFunctions = $tool->getFunctions();
            foreach ($toolFunctions as $function) {
                $functions[] = $function;
            }
        }

        return $functions;

    }//end convertToolsToFunctions()


    /**
     * Convert array-based function definitions to FunctionInfo objects
     *
     * Converts the array format returned by Tool classes into
     * FunctionInfo objects that LLPhant expects for setTools().
     * Includes the tool instance so LLPhant can call methods directly.
     *
     * @param array $functions Array of function definitions.
     * @param array $tools     Tool instances that have the methods.
     *
     * @return array Array of FunctionInfo objects
     *
     * @psalm-return list<FunctionInfo>
     */
    public function convertFunctionsToFunctionInfo(array $functions, array $tools): array
    {
        $functionInfoObjects = [];

        foreach ($functions as $func) {
            // Create parameters array.
            $parameters = [];
            $required   = [];

            if (($func['parameters']['properties'] ?? null) !== null) {
                foreach ($func['parameters']['properties'] as $paramName => $paramDef) {
                    // Determine parameter type from definition.
                    $type        = $paramDef['type'] ?? 'string';
                    $description = $paramDef['description'] ?? '';
                    $enum        = $paramDef['enum'] ?? [];
                    $format      = $paramDef['format'] ?? null;
                    $itemsOrProperties = null;

                    // Handle nested object/array types.
                    if ($type === 'object') {
                        // For object types, pass the properties definition (empty array if not specified).
                        $itemsOrProperties = $paramDef['properties'] ?? [];
                    } else if ($type === 'array') {
                        // For array types, pass the items definition (empty array if not specified).
                        $itemsOrProperties = $paramDef['items'] ?? [];
                    }

                    // Create parameter using constructor.
                    // Constructor: __construct(string $name, string $type, string $description, array $enum=[], ?string $format=null, array|string|null $itemsOrProperties=null).
                    $parameters[] = new Parameter($paramName, $type, $description, $enum, $format, $itemsOrProperties);
                }//end foreach
            }//end if

            if (($func['parameters']['required'] ?? null) !== null) {
                $required = $func['parameters']['required'];
            }

            // Find the tool instance that has this function.
            $toolInstance = null;
            foreach ($tools as $tool) {
                $toolFunctions = $tool->getFunctions();
                foreach ($toolFunctions as $toolFunc) {
                    if ($toolFunc['name'] === $func['name']) {
                        $toolInstance = $tool;
                        break 2;
                    }
                }
            }

            // Create FunctionInfo object with the tool instance.
            // LLPhant will call $toolInstance->{$func['name']}(...$args).
            $functionInfo = new FunctionInfo(
                $func['name'],
                $toolInstance,
                // Pass the tool instance.
                $func['description'] ?? '',
                $parameters,
                $required
            );

            $functionInfoObjects[] = $functionInfo;
        }//end foreach

        return $functionInfoObjects;

    }//end convertFunctionsToFunctionInfo()


}//end class
