<?php
/**
 * OpenRegister Tool Interface
 *
 * Interface for LLphant function tools that agents can use to interact
 * with OpenRegister data.
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

/**
 * Tool Interface
 *
 * Defines the contract for LLphant function tools that agents can use.
 * Tools provide agents with capabilities to interact with OpenRegister
 * data (registers, schemas, objects) through natural language.
 *
 * @category Tool
 * @package  OCA\OpenRegister\Tool
 */
interface ToolInterface
{
    /**
     * Get the tool name
     *
     * This name is used to identify the tool and must be unique.
     * It's also used in the Agent's tools array to enable/disable tools.
     *
     * @return string Tool name (e.g., 'register', 'schema', 'objects')
     */
    public function getName(): string;

    /**
     * Get the tool description
     *
     * This description helps the LLM understand when and how to use this tool.
     * Should clearly describe the tool's purpose and capabilities.
     *
     * @return string Tool description for LLM
     */
    public function getDescription(): string;

    /**
     * Get the tool's function definitions for LLphant
     *
     * Returns an array of function definitions that LLphant can call.
     * Each function definition includes name, description, and parameters schema.
     *
     * Format:
     * [
     *     [
     *         'name' => 'function_name',
     *         'description' => 'What this function does',
     *         'parameters' => [
     *             'type' => 'object',
     *             'properties' => [
     *                 'param1' => ['type' => 'string', 'description' => '...'],
     *                 'param2' => ['type' => 'integer', 'description' => '...']
     *             ],
     *             'required' => ['param1']
     *         ]
     *     ]
     * ]
     *
     * @return array Array of function definitions
     */
    public function getFunctions(): array;

    /**
     * Execute a tool function
     *
     * Called by the LLM when it wants to use this tool.
     * The function name and parameters are provided by the LLM.
     *
     * @param string      $functionName Name of the function to execute
     * @param array       $parameters   Function parameters from LLM
     * @param string|null $userId       User ID for session context (optional)
     *
     * @return array Result of the function execution
     *
     * @throws \Exception If function execution fails
     */
    public function executeFunction(string $functionName, array $parameters, ?string $userId = null): array;
}

