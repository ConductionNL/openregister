<?php

/**
 * OpenRegister Chat Tool Management Handler
 *
 * Handler for LLM tool/function calling management.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-9
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
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-9
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
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-9
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) getAgentTools() iterates tool ids, tries multiple
     * candidate key formats (raw / prefixed) per id, and logs both found and not-found results — each
     * branch is a required backward-compatibility guard for agent records from different schema eras.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Three independent early-returns (null agent, empty
     * enabledToolIds, filtered-to-empty selection) plus the two-candidate loop expand the NPath count
     * without adding logical complexity.
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
                message: '[ToolManagementHandler] Filtering tools',
                context: [
                    'file'          => __FILE__,
                    'line'          => __LINE__,
                    'agentTools'    => count($agent->getTools()),
                    'selectedTools' => count($selectedTools),
                    'filteredTools' => count($enabledToolIds),
                ]
            );
        }

        $tools = [];

        foreach ($enabledToolIds as $toolId) {
            // Try three formats in turn so agent records from different
            // eras keep working:
            // 1. The raw id as stored ("openbuild", "openregister.register")
            // 2. The legacy openregister-prefixed form ("openregister.objects"
            // when the agent stores just "objects")
            // 3. An "openbuild" -> "openbuild.{x}" fallback handled by
            // the McpProviderBridge — that bridge exposes every
            // function under one appId-level registration.
            $candidates = [$toolId];
            if (strpos($toolId, '.') === false) {
                $candidates[] = 'openregister.'.$toolId;
            }

            $tool       = null;
            $fullToolId = $toolId;
            foreach ($candidates as $candidate) {
                $tool = $this->toolRegistry->getTool($candidate);
                if ($tool !== null) {
                    $fullToolId = $candidate;
                    break;
                }
            }

            if ($tool !== null) {
                $tool->setAgent($agent);
                $tools[] = $tool;
                $this->logger->debug(
                    message: '[ToolManagementHandler] Loaded tool',
                    context: [
                        'file' => __FILE__,
                        'line' => __LINE__,
                        'id'   => $fullToolId,
                    ]
                );
            }

            if ($tool === null) {
                $this->logger->warning(
                    message: '[ToolManagementHandler] Tool not found',
                    context: [
                        'file' => __FILE__,
                        'line' => __LINE__,
                        'id'   => $fullToolId,
                    ]
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
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-9
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
     * Convert a single JSON-schema property into an LLPhant Parameter.
     *
     * LLPhant's formatters require `itemsOrProperties` to be either a STRING
     * (the element type of an array of scalars) or an array of Parameter
     * OBJECTS (an object's properties / an array of objects). Passing the raw
     * JSON-schema `items`/`properties` (associative arrays of schema fragments)
     * makes FunctionFormatter read `->name` on a string and throw. This builds
     * the correct shape, recursing one level for nested objects.
     *
     * @param string              $name The property name.
     * @param array<string,mixed> $def  The JSON-schema fragment for the property.
     *
     * @return Parameter
     */
    private function schemaToParameter(string $name, array $def): Parameter
    {
        $type        = $def['type'] ?? 'string';
        $description = $def['description'] ?? '';
        $enum        = $def['enum'] ?? [];
        $format      = $def['format'] ?? null;
        $itemsOrProperties = null;

        if ($type === 'object') {
            $properties = $this->propertiesToParameters(properties: ($def['properties'] ?? []));
            if (count($properties) === 0) {
                // Free-form object with no declared sub-properties (e.g. a
                // schema's `properties` map). LLPhant serialises an empty
                // object schema as "properties": [] (a JSON array), which
                // Ollama rejects with "Value looks like object, but can't find
                // closing '}'". Represent it as a JSON string the model fills.
                $type        = 'string';
                $description = $this->freeFormObjectDescription(description: $description);
            } else {
                $itemsOrProperties = $properties;
            }
        } else if ($type === 'array') {
            $items    = $def['items'] ?? [];
            $itemType = 'string';
            if (is_array($items) === true && isset($items['type']) === true) {
                $itemType = (string) $items['type'];
            }

            if ($itemType === 'object') {
                $properties = $this->propertiesToParameters(properties: ($items['properties'] ?? []));
                // Same empty-object guard for arrays of free-form objects.
                if (count($properties) === 0) {
                    $itemsOrProperties = 'string';
                } else {
                    $itemsOrProperties = $properties;
                }
            } else {
                // A scalar element type is passed as a plain string.
                $itemsOrProperties = $itemType;
            }
        }//end if

        return new Parameter($name, $type, $description, $enum, $format, $itemsOrProperties);

    }//end schemaToParameter()

    /**
     * Build the description used when a free-form object (no declared
     * sub-properties) is represented as a JSON string instead.
     *
     * @param string $description Original property description (may be empty).
     *
     * @return string Description guiding the model to pass a JSON object.
     */
    private function freeFormObjectDescription(string $description): string
    {
        if ($description === '') {
            return 'A JSON object.';
        }

        return ($description.' (pass as a JSON object).');
    }//end freeFormObjectDescription()

    /**
     * Convert a JSON-schema `properties` map into an array of Parameter objects.
     *
     * @param array<string,mixed> $properties The properties map (name => schema).
     *
     * @return Parameter[]
     */
    private function propertiesToParameters(array $properties): array
    {
        $out = [];
        foreach ($properties as $propName => $propDef) {
            $propDefArray = [];
            if (is_array($propDef) === true) {
                $propDefArray = $propDef;
            }

            $out[] = $this->schemaToParameter(
                name: (string) $propName,
                def: $propDefArray
            );
        }

        return $out;

    }//end propertiesToParameters()

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
     *
     * @spec openspec/changes/retrofit-2026-05-24-chat-ai/tasks.md#task-1
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Function conversion requires handling multiple parameter types
     * @SuppressWarnings(PHPMD.NPathComplexity)      Function conversion requires handling multiple parameter types
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
                    $paramDefArray = [];
                    if (is_array($paramDef) === true) {
                        $paramDefArray = $paramDef;
                    }

                    $parameters[] = $this->schemaToParameter(
                        name: (string) $paramName,
                        def: $paramDefArray
                    );
                }//end foreach
            }//end if

            if (($func['parameters']['required'] ?? null) !== null) {
                $required = $func['parameters']['required'];
            }

            // LLPhant's FunctionInfo expects requiredParameters as Parameter
            // OBJECTS, not name strings (ToolFormatter reads $param->name). Map
            // the required names back to the Parameter objects built above.
            $requiredParameters = [];
            foreach ($parameters as $parameterObject) {
                if (in_array($parameterObject->name, $required, true) === true) {
                    $requiredParameters[] = $parameterObject;
                }
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
                $requiredParameters
            );

            $functionInfoObjects[] = $functionInfo;
        }//end foreach

        return $functionInfoObjects;
    }//end convertFunctionsToFunctionInfo()
}//end class
