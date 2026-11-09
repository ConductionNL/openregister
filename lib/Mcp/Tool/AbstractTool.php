<?php

/**
 * OpenRegister Abstract Tool
 *
 * Base class for LLphant function tools providing common functionality
 * for user context management, view filtering, and error handling.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

namespace OCA\OpenRegister\Mcp\Tool;

use BadMethodCallException;
use InvalidArgumentException;
use OCA\OpenRegister\Mcp\Db\Agent;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Abstract Tool Base Class
 *
 * Provides common functionality for all tools:
 * - User session management
 * - View filtering logic
 * - Error handling and logging
 * - Result formatting
 *
 * @category Tool
 * @package  OCA\OpenRegister\Tool
 */
abstract class AbstractTool implements ToolInterface {

	/**
	 * User session
	 *
	 * @var IUserSession
	 */
	protected IUserSession $userSession;

	/**
	 * Logger
	 *
	 * @var LoggerInterface
	 */
	protected LoggerInterface $logger;

	/**
	 * Current agent (optional, set when tool is used by agent)
	 *
	 * @var Agent|null
	 */
	protected ?Agent $agent = null;

	/**
	 * Constructor
	 *
	 * @param IUserSession $userSession User session service
	 * @param LoggerInterface $logger Logger service
	 */
	public function __construct(
		IUserSession $userSession,
		LoggerInterface $logger,
	) {
		$this->userSession = $userSession;
		$this->logger = $logger;
	}//end __construct()

	/**
	 * Set the agent context
	 *
	 * Called when tool is used by an agent to provide agent context
	 * for view filtering and permissions.
	 *
	 * @param Agent|null $agent The agent using this tool
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function setAgent(?Agent $agent): void {
		$this->agent = $agent;
	}//end setAgent()

	/**
	 * The function rows of the tool; a subclass redeclares it.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	protected const FUNCTIONS = [];

	/**
	 * Get function definitions for LLphant, expanded from the FUNCTIONS rows.
	 *
	 * @return array<int, array<string, mixed>> Array of function definitions
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getFunctions(): array {
		return ToolFunctionTable::expand(rows: static::FUNCTIONS);
	}//end getFunctions()

	/**
	 * Run a function for a tool that refuses a call without a user context.
	 *
	 * The user context is checked before the function runs; a failure of the
	 * function is logged and returned as an error result.
	 *
	 * @param string       $functionName The snake_case function name
	 * @param array<mixed> $parameters   The parameters the model sent
	 * @param string|null  $userId       Explicit user id, if any
	 *
	 * @return array The function result, or an error result
	 */
	protected function executeWithUser(string $functionName, array $parameters, ?string $userId): array {
		$this->log(functionName: $functionName, parameters: $parameters);

		if ($this->hasUserContext(explicitUserId: $userId) === false) {
			return $this->formatError(message: 'No user context available. Tool cannot execute without user session.');
		}

		try {
			return $this->invokeFunction(functionName: $functionName, parameters: $parameters);
		} catch (\Exception $e) {
			$this->log(functionName: $functionName, parameters: $parameters, level: 'error', message: $e->getMessage());
			return $this->formatError(message: $e->getMessage());
		}
	}//end executeWithUser()

	/**
	 * Call the function's camelCase method with the parameters by position
	 * (LLPhant-compatible).
	 *
	 * @param string       $functionName The snake_case function name
	 * @param array<mixed> $parameters   The parameters the model sent
	 *
	 * @return array The method's result
	 */
	protected function invokeFunction(string $functionName, array $parameters): array {
		$methodName = $this->functionMethod(functionName: $functionName);

		return $this->$methodName(...array_values($parameters));
	}//end invokeFunction()

	/**
	 * The camelCase method of a snake_case function name, for PSR compliance.
	 *
	 * @param string $functionName The snake_case function name
	 *
	 * @return string The method name
	 */
	protected function functionMethod(string $functionName): string {
		return lcfirst(str_replace('_', '', ucwords($functionName, '_')));
	}//end functionMethod()

	/**
	 * Get the current user ID
	 *
	 * Returns user ID from:
	 * 1. Current user session (if available)
	 * 2. Agent's user property (for cron scenarios)
	 * 3. Explicit userId parameter
	 *
	 * @param string|null $explicitUserId Explicitly provided user ID
	 *
	 * @return string|null User ID or null if no user context
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	protected function getUserId(?string $explicitUserId = null): ?string {
		// Use explicit user ID if provided.
		if ($explicitUserId !== null) {
			return $explicitUserId;
		}

		// Try to get from session.
		$user = $this->userSession->getUser();
		if ($user !== null) {
			return $user->getUID();
		}

		// Fall back to agent's user (for cron scenarios).
		if ($this->agent !== null && $this->agent->getUser() !== null) {
			return $this->agent->getUser();
		}

		return null;
	}//end getUserId()

	/**
	 * Check if a user context is available
	 *
	 * @param string|null $explicitUserId Explicitly provided user ID
	 *
	 * @return bool True if user context is available
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	protected function hasUserContext(?string $explicitUserId = null): bool {
		return $this->getUserId(explicitUserId: $explicitUserId) !== null;
	}//end hasUserContext()

	/**
	 * Apply view filters to query parameters
	 *
	 * If agent has views configured, adds view filtering to query parameters.
	 * Views are used to filter data the agent can access.
	 *
	 * @param array $params Query parameters
	 *
	 * @return array Query parameters with view filters applied
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	protected function applyViewFilters(array $params): array {
		if ($this->agent === null) {
			return $params;
		}

		$views = $this->agent->getViews();
		if ($views === null || $views === []) {
			return $params;
		}

		// TODO: Implement view filtering in mappers.
		// View filtering allows agents to only see data filtered by predefined views.
		// For now, this is disabled as the mappers don't have a 'views' column yet.
		// $params['_views'] = $views;.
		return $params;
	}//end applyViewFilters()

	/**
	 * Format a success result
	 *
	 * @param mixed $data Result data
	 * @param string $message Success message
	 *
	 * @return (mixed|string|true)[] Formatted result
	 *
	 * @psalm-return array{success: true, message: string, data: mixed}
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	protected function formatSuccess($data, string $message = 'Success'): array {
		return [
			'success' => true,
			'message' => $message,
			'data' => $data,
		];
	}//end formatSuccess()

	/**
	 * Format an error result
	 *
	 * @param string $message Error message
	 * @param mixed $details Additional error details
	 *
	 * @return (false|mixed|string)[] Formatted error result
	 *
	 * @psalm-return array{success: false, error: string, details?: mixed}
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	protected function formatError(string $message, $details = null): array {
		$result = [
			'success' => false,
			'error' => $message,
		];

		if ($details !== null) {
			$result['details'] = $details;
		}

		return $result;
	}//end formatError()

	/**
	 * Log tool execution
	 *
	 * Logs tool function execution with context information including tool name,
	 * function name, parameters, agent ID, and user ID. Supports different log levels
	 * for error tracking and debugging.
	 *
	 * @param string $functionName Function being executed
	 * @param array $parameters Function parameters (will be logged in context)
	 * @param string $level Log level: 'info', 'warning', or 'error' (default: 'info')
	 * @param string $message Custom log message (default: 'Executing function')
	 *
	 * @return void
	 *
	 * @psalm-suppress PossiblyNullArgument
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	protected function log(string $functionName, array $parameters, string $level = 'info', string $message = ''): void {
		$this->writeLog(
			call: ['function' => $functionName, 'parameters' => $parameters],
			entry: ['level' => $level, 'message' => $message]
		);
	}//end log()

	/**
	 * Write the log entry of a tool function call.
	 *
	 * @param array<string, mixed>  $call  The function name (`function`) and its parameters (`parameters`).
	 * @param array<string, string> $entry The log level (`level`) and custom message (`message`, empty for the default).
	 *
	 * @return void
	 *
	 * @psalm-suppress PossiblyNullArgument
	 */
	private function writeLog(array $call, array $entry): void {
		// Build context array with tool execution metadata.
		// Includes tool name, function name, parameters, agent ID, and user ID.
		$context = [
			'file' => __FILE__,
			'line' => __LINE__,
			'tool' => $this->getName(),
			'function' => $call['function'],
			'parameters' => $call['parameters'],
			'agent' => $this->agent?->getId(),
			'user' => $this->getUserId(),
		];

		// Use custom message if provided, otherwise use default message.
		$messageText = 'Executing function';
		if ($entry['message'] !== '') {
			$messageText = $entry['message'];
		}

		// Format log message with class name, tool name, function name, and message text.
		$className = (new ReflectionClass($this))->getShortName();
		$toolName = $this->getName();
		$logMessage = sprintf(
			'[%s] %s: %s: %s',
			$className,
			$toolName,
			$call['function'],
			$messageText
		);

		// Log based on severity level.
		// Different log levels help filter and prioritize log entries.
		// Add file and line to context for all log calls.
		$contextWithLocation = array_merge(['file' => __FILE__, 'line' => __LINE__], $context);
		switch ($entry['level']) {
			case 'error':
				// Log errors for critical issues that need attention.
				$this->logger->error(message: $logMessage, context: $contextWithLocation);
				break;
			case 'warning':
				// Log warnings for non-critical issues.
				$this->logger->warning(message: $logMessage, context: $contextWithLocation);
				break;
			default:
				// Log info for normal operations (default level).
				$this->logger->info(message: $logMessage, context: $contextWithLocation);
				break;
		}
	}//end writeLog()

	/**
	 * Validate required parameters
	 *
	 * Checks that all required parameters are present in the parameters array.
	 * Throws InvalidArgumentException if any required parameter is missing.
	 * Used to ensure tool functions receive all necessary input before execution.
	 *
	 * @param array<string, mixed> $parameters Function parameters to validate
	 * @param array<string> $required Required parameter names
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException If any required parameter is missing
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	protected function validateParameters(array $parameters, array $required): void {
		// Iterate through each required parameter name.
		foreach ($required as $param) {
			// Check if parameter exists in parameters array.
			// Isset() checks both existence and non-null value.
			if (isset($parameters[$param]) === false) {
				// Throw exception with descriptive error message.
				throw new InvalidArgumentException("Missing required parameter: {$param}");
			}
		}
	}//end validateParameters()

	/**
	 * Magic method to support snake_case method calls for LLPhant compatibility
	 *
	 * Automatically converts snake_case method calls to camelCase for PSR compliance.
	 * This enables LLPhant (LLM function calling library) to call methods using
	 * snake_case naming convention while maintaining PSR camelCase standards internally.
	 *
	 * Example: list_registers() -> listRegisters()
	 *
	 * The method also handles type coercion, default values, and converts results
	 * to JSON format expected by LLPhant.
	 *
	 * @param string $name Method name in snake_case format
	 * @param array $arguments Method arguments (can be positional or associative)
	 *
	 * @return mixed Method result (arrays are JSON-encoded for LLPhant compatibility)
	 *
	 * @throws \BadMethodCallException If the camelCase method doesn't exist
	 *
	 * @psalm-suppress MixedAssignment
	 * @psalm-suppress MixedArgument
	 * @psalm-suppress MixedMethodCall
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __call(string $name, array $arguments) {
		// Step 1: Convert snake_case method name to camelCase.
		// Example: 'list_registers' -> 'listRegisters'.
		// Ucwords() capitalizes first letter of each word separated by underscore.
		// Str_replace() removes underscores.
		// Lcfirst() makes first letter lowercase.
		$camelCaseMethod = lcfirst(str_replace('_', '', ucwords($name, '_')));

		// Step 2: Check if camelCase method exists.
		if (method_exists($this, $camelCaseMethod) === false) {
			// Method doesn't exist in either snake_case or camelCase format.
			throw new BadMethodCallException("Method {$name} (or {$camelCaseMethod}) does not exist");
		}

		// Steps 3-5: Match the arguments to the method's parameters (named or
		// positional), apply defaults for (string) null and type-cast the rest.
		$coercer = new ToolArgumentCoercer();
		$typedArguments = $coercer->argumentsFor(
			method: new ReflectionMethod($this, $camelCaseMethod),
			arguments: $arguments
		);

		// Step 6: Call the camelCase method with type-cast arguments.
		$result = $this->$camelCaseMethod(...$typedArguments);

		// Step 7: Convert array results to JSON for LLPhant compatibility.
		// LLPhant expects tool results to be JSON strings, not PHP arrays.
		if (is_array($result) === true) {
			return json_encode($result);
		}

		// Return non-array results as-is.
		return $result;
	}//end __call()
}//end class
