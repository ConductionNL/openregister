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
 * @spec openspec/specs/chat-ai/spec.md
 */

namespace OCA\OpenRegister\Mcp\Service\Chat;

use LLPhant\Chat\FunctionInfo\FunctionInfo;
use LLPhant\Chat\FunctionInfo\Parameter;
use OCA\OpenRegister\Mcp\Db\Agent;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Mcp\Service\ToolRegistry;
use OCA\OpenRegister\Mcp\Tool\ToolInterface;
use Psr\Log\LoggerInterface;

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
class ToolManagementHandler {

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
	 * @param AgentMapper $agentMapper Agent mapper.
	 * @param ToolRegistry $toolRegistry Tool registry.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function __construct(
		AgentMapper $agentMapper,
		ToolRegistry $toolRegistry,
		LoggerInterface $logger,
	) {
		$this->agentMapper = $agentMapper;
		$this->toolRegistry = $toolRegistry;
		$this->logger = $logger;
	}//end __construct()

	/**
	 * Get enabled tools for agent
	 *
	 * Loads and initializes tools enabled for the given agent.
	 * Filters by selectedTools if provided.
	 *
	 * @param Agent|null $agent Agent entity (optional).
	 * @param array $selectedTools Tool UUIDs to use (empty = all agent tools).
	 *
	 * @return array Array of ToolInterface instances
	 *
	 * @psalm-return list<ToolInterface>
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) getAgentTools() iterates tool ids, tries multiple
	 * candidate key formats (raw / prefixed) per id, and logs both found and not-found results — each
	 * branch is a required backward-compatibility guard for agent records from different schema eras.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Three independent early-returns (null agent, empty
	 * enabledToolIds, filtered-to-empty selection) plus the two-candidate loop expand the NPath count
	 * without adding logical complexity.
	 */
	public function getAgentTools(?Agent $agent, array $selectedTools = []): array {
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
					'file' => __FILE__,
					'line' => __LINE__,
					'agentTools' => count($agent->getTools()),
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
				$candidates[] = 'openregister.' . $toolId;
			}

			$tool = null;
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
						'id' => $fullToolId,
					]
				);
			}

			if ($tool === null) {
				$this->logger->warning(
					message: '[ToolManagementHandler] Tool not found',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'id' => $fullToolId,
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
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function convertToolsToFunctions(array $tools): array {
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
	 * @param string $name The property name.
	 * @param array<string,mixed> $def The JSON-schema fragment for the property.
	 *
	 * @return Parameter
	 */
	private function schemaToParameter(string $name, array $def): Parameter {
		$shape = ['type' => ($def['type'] ?? 'string'), 'description' => ($def['description'] ?? ''), 'itemsOrProperties' => null];
		if ($shape['type'] === 'object') {
			$shape = $this->objectShape(shape: $shape, properties: ($def['properties'] ?? []));
		} elseif ($shape['type'] === 'array') {
			$shape['itemsOrProperties'] = $this->arrayItems(items: ($def['items'] ?? []));
		}

		$enum = ($def['enum'] ?? []);
		$format = ($def['format'] ?? null);
		return new Parameter($name, $shape['type'], $shape['description'], $enum, $format, $shape['itemsOrProperties']);
	}//end schemaToParameter()

	/**
	 * The type, description and properties of an object property.
	 *
	 * A free-form object with no declared sub-properties (e.g. a schema's
	 * `properties` map) becomes a JSON string the model fills: LLPhant
	 * serialises an empty object schema as "properties": [] (a JSON array),
	 * which Ollama rejects with "Value looks like object, but can't find
	 * closing '}'".
	 *
	 * @param array{type: mixed, description: mixed, itemsOrProperties: null} $shape      The property's shape so far.
	 * @param array<string,mixed>                                             $properties The declared sub-properties.
	 *
	 * @return array{type: mixed, description: mixed, itemsOrProperties: Parameter[]|null}
	 */
	private function objectShape(array $shape, array $properties): array {
		$parameters = $this->propertiesToParameters(properties: $properties);
		if (count($parameters) === 0) {
			$description = $this->freeFormObjectDescription(description: $shape['description']);
			return ['type' => 'string', 'description' => $description, 'itemsOrProperties' => null];
		}

		$shape['itemsOrProperties'] = $parameters;
		return $shape;
	}//end objectShape()

	/**
	 * The element of an array property: its scalar type as a string, or the properties of its objects.
	 *
	 * An array of free-form objects gets the same empty-object guard: its elements are strings.
	 *
	 * @param mixed $items The JSON-schema `items` fragment.
	 *
	 * @return string|Parameter[] The element type, or the element's properties.
	 */
	private function arrayItems(mixed $items): string|array {
		$itemType = $this->itemType(items: $items);
		if ($itemType !== 'object') {
			return $itemType;
		}

		$parameters = $this->propertiesToParameters(properties: ($items['properties'] ?? []));
		if (count($parameters) === 0) {
			return 'string';
		}

		return $parameters;
	}//end arrayItems()

	/**
	 * The element type an `items` fragment declares; `string` when it declares none.
	 *
	 * @param mixed $items The JSON-schema `items` fragment.
	 *
	 * @return string The element type.
	 */
	private function itemType(mixed $items): string {
		if (is_array($items) === true && isset($items['type']) === true) {
			return (string)$items['type'];
		}

		return 'string';
	}//end itemType()

	/**
	 * Build the description used when a free-form object (no declared
	 * sub-properties) is represented as a JSON string instead.
	 *
	 * @param string $description Original property description (may be empty).
	 *
	 * @return string Description guiding the model to pass a JSON object.
	 */
	private function freeFormObjectDescription(string $description): string {
		if ($description === '') {
			return 'A JSON object.';
		}

		return ($description . ' (pass as a JSON object).');
	}//end freeFormObjectDescription()

	/**
	 * Convert a JSON-schema `properties` map into an array of Parameter objects.
	 *
	 * @param array<string,mixed> $properties The properties map (name => schema).
	 *
	 * @return Parameter[]
	 */
	private function propertiesToParameters(array $properties): array {
		$out = [];
		foreach ($properties as $propName => $propDef) {
			$out[] = $this->parameterFor(name: $propName, def: $propDef);
		}

		return $out;
	}//end propertiesToParameters()

	/**
	 * One property as a Parameter; a definition that is not an array counts as an empty one.
	 *
	 * @param string|int $name The property name.
	 * @param mixed      $def  The JSON-schema fragment for the property.
	 *
	 * @return Parameter
	 */
	private function parameterFor(string|int $name, mixed $def): Parameter {
		if (is_array($def) === false) {
			$def = [];
		}

		return $this->schemaToParameter(name: (string)$name, def: $def);
	}//end parameterFor()

	/**
	 * Convert array-based function definitions to FunctionInfo objects
	 *
	 * Converts the array format returned by Tool classes into
	 * FunctionInfo objects that LLPhant expects for setTools().
	 * Includes the tool instance so LLPhant can call methods directly.
	 *
	 * @param array $functions Array of function definitions.
	 * @param array $tools Tool instances that have the methods.
	 *
	 * @return array Array of FunctionInfo objects
	 *
	 * @psalm-return list<FunctionInfo>
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function convertFunctionsToFunctionInfo(array $functions, array $tools): array {
		$functionInfoObjects = [];
		foreach ($functions as $func) {
			$functionInfoObjects[] = $this->functionInfo(func: $func, tools: $tools);
		}

		return $functionInfoObjects;
	}//end convertFunctionsToFunctionInfo()

	/**
	 * One function definition as a FunctionInfo, with the tool instance that has the function.
	 *
	 * LLPhant will call $toolInstance->{$func['name']}(...$args).
	 *
	 * @param array $func  The function definition.
	 * @param array $tools Tool instances that have the methods.
	 *
	 * @return FunctionInfo
	 */
	private function functionInfo(array $func, array $tools): FunctionInfo {
		$parameters = $this->functionParameters(func: $func);
		$required = $this->requiredParameters(parameters: $parameters, required: ($func['parameters']['required'] ?? []));
		$toolInstance = $this->toolWithFunction(tools: $tools, name: $func['name']);
		return new FunctionInfo($func['name'], $toolInstance, ($func['description'] ?? ''), $parameters, $required);
	}//end functionInfo()

	/**
	 * The Parameters of a function definition's `parameters.properties`; none when it is absent.
	 *
	 * @param array $func The function definition.
	 *
	 * @return Parameter[]
	 */
	private function functionParameters(array $func): array {
		$properties = ($func['parameters']['properties'] ?? null);
		if ($properties === null) {
			return [];
		}

		$parameters = [];
		foreach ($properties as $paramName => $paramDef) {
			$parameters[] = $this->parameterFor(name: $paramName, def: $paramDef);
		}

		return $parameters;
	}//end functionParameters()

	/**
	 * The Parameter objects whose names are required.
	 *
	 * LLPhant's FunctionInfo expects requiredParameters as Parameter OBJECTS,
	 * not name strings (ToolFormatter reads $param->name).
	 *
	 * @param Parameter[] $parameters The function's parameters.
	 * @param array       $required   The required parameter names.
	 *
	 * @return Parameter[]
	 */
	private function requiredParameters(array $parameters, array $required): array {
		$requiredParameters = [];
		foreach ($parameters as $parameterObject) {
			if (in_array($parameterObject->name, $required, true) === true) {
				$requiredParameters[] = $parameterObject;
			}
		}

		return $requiredParameters;
	}//end requiredParameters()

	/**
	 * The first tool instance with a function of the given name.
	 *
	 * @param array $tools Tool instances.
	 * @param mixed $name  The function name.
	 *
	 * @return ToolInterface|null The tool, or null when none has the function.
	 */
	private function toolWithFunction(array $tools, mixed $name): ?ToolInterface {
		foreach ($tools as $tool) {
			foreach ($tool->getFunctions() as $toolFunc) {
				if ($toolFunc['name'] === $name) {
					return $tool;
				}
			}
		}

		return null;
	}//end toolWithFunction()
}//end class
