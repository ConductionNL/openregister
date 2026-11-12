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
use ReflectionNamedType;

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
	 * The logger method each log level is written with; anything else is info.
	 *
	 * @var array<string, string>
	 */
	private const LOG_METHODS = [
		'error' => 'error',
		'warning' => 'warning',
	];

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
	 * @param ToolCallTrail $trail The session that names the caller and the log of each call.
	 */
	public function __construct(ToolCallTrail $trail) {
		$this->userSession = $trail->userSession;
		$this->logger = $trail->logger;
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
	 * @param ToolCall    $call   The function and the parameters the model sent
	 * @param string|null $userId Explicit user id, if any
	 *
	 * @return array The function result, or an error result
	 */
	protected function executeWithUser(ToolCall $call, ?string $userId): array {
		$this->log(call: $call);

		if ($this->hasUserContext(explicitUserId: $userId) === false) {
			return $this->formatError(message: 'No user context available. Tool cannot execute without user session.');
		}

		try {
			return $this->invokeFunction(functionName: $call->functionName, parameters: $call->parameters);
		} catch (\Exception $e) {
			$this->log(call: $call, options: new ToolLogOptions(level: 'error', message: $e->getMessage()));
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

		return $this->callMethod(methodName: $methodName, arguments: array_values($parameters));
	}//end invokeFunction()

	/**
	 * The signature the model's arguments bind to: the method's own
	 * parameters, or the constructor of the operation object it takes.
	 *
	 * @param string $methodName The camelCase method name
	 *
	 * @return ReflectionMethod The binding signature
	 */
	protected function bindingTarget(string $methodName): ReflectionMethod {
		$method    = new ReflectionMethod($this, $methodName);
		$operation = $this->operationClass(method: $method);
		if ($operation === null) {
			return $method;
		}

		return new ReflectionMethod($operation, '__construct');
	}//end bindingTarget()

	/**
	 * Call a tool method with bound arguments, built into its operation
	 * object first when the method takes one.
	 *
	 * @param string       $methodName The camelCase method name
	 * @param array<mixed> $arguments  The arguments, bound to {@see bindingTarget()}
	 *
	 * @return mixed The method's result
	 */
	protected function callMethod(string $methodName, array $arguments): mixed {
		if (method_exists($this, $methodName) === false) {
			// Reaches __call(), which refuses an unknown function.
			return $this->$methodName(...$arguments);
		}

		$operation = $this->operationClass(method: new ReflectionMethod($this, $methodName));
		if ($operation === null) {
			return $this->$methodName(...$arguments);
		}

		return $this->$methodName(new $operation(...$arguments));
	}//end callMethod()

	/**
	 * The operation class a tool method takes as its one parameter, if any.
	 *
	 * @param ReflectionMethod $method The tool method
	 *
	 * @return class-string<ToolOperation>|null The operation class, or null when the method takes its arguments one by one
	 */
	private function operationClass(ReflectionMethod $method): ?string {
		$parameters = $method->getParameters();
		$type       = null;
		if (count($parameters) === 1) {
			$type = $parameters[0]->getType();
		}

		if ($type instanceof ReflectionNamedType === false || is_subclass_of($type->getName(), ToolOperation::class) === false) {
			return null;
		}

		return $type->getName();
	}//end operationClass()

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
	 * @param ToolCall            $call    Function being executed and its parameters (logged in context)
	 * @param ToolLogOptions|null $options The level and custom message; null logs 'Executing function' at info
	 *
	 * @return void
	 *
	 * @psalm-suppress PossiblyNullArgument
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	protected function log(ToolCall $call, ?ToolLogOptions $options = null): void {
		$this->writeLog(call: $call, entry: ($options ?? new ToolLogOptions()));
	}//end log()

	/**
	 * Write the log entry of a tool function call.
	 *
	 * @param ToolCall       $call  The function and its parameters.
	 * @param ToolLogOptions $entry The log level and custom message (empty for the default).
	 *
	 * @return void
	 *
	 * @psalm-suppress PossiblyNullArgument
	 */
	private function writeLog(ToolCall $call, ToolLogOptions $entry): void {
		$context    = $this->logContext(call: $call);
		$logMessage = $this->logMessage(call: $call, entry: $entry);

		// Log based on severity level.
		// Different log levels help filter and prioritize log entries:
		// errors for critical issues that need attention, warnings for
		// non-critical issues, info for normal operations (the default level).
		// Add file and line to context for all log calls.
		$contextWithLocation = array_merge(['file' => __FILE__, 'line' => __LINE__], $context);
		$method = (self::LOG_METHODS[$entry->level] ?? 'info');
		$this->logger->{$method}(message: $logMessage, context: $contextWithLocation);
	}//end writeLog()

	/**
	 * The tool execution metadata a log entry carries.
	 *
	 * Includes tool name, function name, parameters, agent ID, and user ID.
	 *
	 * @param ToolCall $call The function call being logged.
	 *
	 * @return array<string, mixed>
	 */
	private function logContext(ToolCall $call): array {
		return [
			'file' => __FILE__,
			'line' => __LINE__,
			'tool' => $this->getName(),
			'function' => $call->functionName,
			'parameters' => $call->parameters,
			'agent' => $this->agent?->getId(),
			'user' => $this->getUserId(),
		];
	}//end logContext()

	/**
	 * The log line: class name, tool name, function name and message text.
	 *
	 * @param ToolCall        $call  The function call being logged.
	 * @param ToolLogOptions  $entry The custom message (default: "Executing function").
	 *
	 * @return string
	 */
	private function logMessage(ToolCall $call, ToolLogOptions $entry): string {
		// Use custom message if provided, otherwise use default message.
		$messageText = 'Executing function';
		if ($entry->message !== '') {
			$messageText = $entry->message;
		}

		$className = (new ReflectionClass($this))->getShortName();
		$toolName  = $this->getName();
		return sprintf(
			'[%s] %s: %s: %s',
			$className,
			$toolName,
			$call->functionName,
			$messageText
		);
	}//end logMessage()

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

		// Steps 3-5: Match the arguments to the method's parameters, or to the
		// constructor of the operation object it takes (named or positional),
		// apply defaults for (string) null and type-cast the rest.
		$coercer = new ToolArgumentCoercer();
		$typedArguments = $coercer->argumentsFor(
			method: $this->bindingTarget(methodName: $camelCaseMethod),
			arguments: $arguments
		);

		// Step 6: Call the camelCase method with type-cast arguments.
		$result = $this->callMethod(methodName: $camelCaseMethod, arguments: $typedArguments);

		// Step 7: Convert array results to JSON for LLPhant compatibility.
		// LLPhant expects tool results to be JSON strings, not PHP arrays.
		if (is_array($result) === true) {
			return json_encode($result);
		}

		// Return non-array results as-is.
		return $result;
	}//end __call()

	/**
	 * Apply the non-null fields of a patch to an entity through its setters.
	 *
	 * @param object               $entity The entity being patched.
	 * @param array<string, mixed> $fields Setter name → value; null values are left alone.
	 *
	 * @return void
	 */
	protected static function applySetters(object $entity, array $fields): void {
		foreach ($fields as $setter => $value) {
			if ($value !== null) {
				$entity->$setter($value);
			}
		}
	}//end applySetters()
}//end class
