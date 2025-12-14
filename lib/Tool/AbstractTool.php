<?php
/**
 * OpenRegister Abstract Tool
 *
 * Base class for LLphant function tools providing common functionality
 * for user context management, view filtering, and error handling.
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

use ReflectionMethod;
use BadMethodCallException;
use OCA\OpenRegister\Db\Agent;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

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
abstract class AbstractTool implements ToolInterface
{

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
     * @param IUserSession    $userSession User session service
     * @param LoggerInterface $logger      Logger service
     */
    public function __construct(
        IUserSession $userSession,
        LoggerInterface $logger
    ) {
        $this->userSession = $userSession;
        $this->logger      = $logger;

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
     */
    public function setAgent(?Agent $agent): void
    {
        $this->agent = $agent;

    }//end setAgent()


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
     */
    protected function getUserId(?string $explicitUserId=null): ?string
    {
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
     */
    protected function hasUserContext(?string $explicitUserId=null): bool
    {
        return $this->getUserId($explicitUserId) !== null;

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
     */
    protected function applyViewFilters(array $params): array
    {
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
     * @param mixed  $data    Result data
     * @param string $message Success message
     *
     * @return (mixed|string|true)[] Formatted result
     *
     * @psalm-return array{success: true, message: string, data: mixed}
     */
    protected function formatSuccess($data, string $message='Success'): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ];

    }//end formatSuccess()


    /**
     * Format an error result
     *
     * @param string $message Error message
     * @param mixed  $details Additional error details
     *
     * @return (false|mixed|string)[] Formatted error result
     *
     * @psalm-return array{success: false, error: string, details?: mixed}
     */
    protected function formatError(string $message, $details=null): array
    {
        $result = [
            'success' => false,
            'error'   => $message,
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
     * @param array  $parameters   Function parameters (will be logged in context)
     * @param string $level        Log level: 'info', 'warning', or 'error' (default: 'info')
     * @param string $message      Custom log message (default: 'Executing function')
     *
     * @return void
     *
     * @psalm-suppress PossiblyNullArgument
     */
    protected function log(string $functionName, array $parameters, string $level='info', string $message=''): void
    {
        // Build context array with tool execution metadata.
        // Includes tool name, function name, parameters, agent ID, and user ID.
        $context = [
            'tool'       => $this->getName(),
            'function'   => $functionName,
            'parameters' => $parameters,
            'agent'      => $this->agent?->getId(),
            'user'       => $this->getUserId(),
        ];

        // Use custom message if provided, otherwise use default message.
        if ($message !== '') {
            $messageText = $message;
        } else {
            $messageText = 'Executing function';
        }

        // Format log message with tool name, function name, and message text.
        $logMessage = sprintf(
            '[Tool:%s] %s: %s',
                $this->getName(),
            $functionName,
            $messageText
        );

        // Log based on severity level.
        // Different log levels help filter and prioritize log entries.
        switch ($level) {
            case 'error':
                // Log errors for critical issues that need attention.
                $this->logger->error($logMessage, $context);
                break;
            case 'warning':
                // Log warnings for non-critical issues.
                $this->logger->warning($logMessage, $context);
                break;
            default:
                // Log info for normal operations (default level).
                $this->logger->info($logMessage, $context);
                break;
        }

    }//end log()


    /**
     * Validate required parameters
     *
     * Checks that all required parameters are present in the parameters array.
     * Throws InvalidArgumentException if any required parameter is missing.
     * Used to ensure tool functions receive all necessary input before execution.
     *
     * @param array<string, mixed> $parameters Function parameters to validate
     * @param array<string>        $required   Required parameter names
     *
     * @return void
     *
     * @throws \InvalidArgumentException If any required parameter is missing
     */
    protected function validateParameters(array $parameters, array $required): void
    {
        // Iterate through each required parameter name.
        foreach ($required as $param) {
            // Check if parameter exists in parameters array.
            // isset() checks both existence and non-null value.
            if (!isset($parameters[$param])) {
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
     * @param string $name      Method name in snake_case format
     * @param array  $arguments Method arguments (can be positional or associative)
     *
     * @return mixed Method result (arrays are JSON-encoded for LLPhant compatibility)
     *
     * @throws \BadMethodCallException If the camelCase method doesn't exist
     *
     * @psalm-suppress MixedAssignment
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedMethodCall
     */
    public function __call(string $name, array $arguments)
    {
        // Step 1: Convert snake_case method name to camelCase.
        // Example: 'list_registers' -> 'listRegisters'.
        // ucwords() capitalizes first letter of each word separated by underscore.
        // str_replace() removes underscores.
        // lcfirst() makes first letter lowercase.
        $camelCaseMethod = lcfirst(str_replace('_', '', ucwords($name, '_')));

        // Step 2: Check if camelCase method exists.
        if (method_exists($this, $camelCaseMethod) === true) {
            // Step 3: Use reflection to get method parameter information.
            // This allows us to understand expected types and default values.
            $reflection = new ReflectionMethod($this, $camelCaseMethod);
            $parameters = $reflection->getParameters();

            // Step 4: Determine if arguments are associative (named) or positional.
            // Associative arrays have non-sequential keys (e.g., ['param1' => 'value']).
            // Positional arrays have sequential numeric keys (e.g., [0 => 'value', 1 => 'value']).
            $isAssociative = array_keys($arguments) !== range(0, count($arguments) - 1);

            // Step 5: Process each parameter and type-cast arguments.
            $typedArguments = [];
            foreach ($parameters as $index => $param) {
                $paramName = $param->getName();

                // Step 5a: Extract argument value.
                // Priority: named argument > positional argument > null.
                if ($isAssociative === true && (($arguments[$paramName] ?? null) !== null)) {
                    // Use named argument if available (associative array).
                    $value = $arguments[$paramName];
                } else {
                    // Use positional argument if available (indexed array).
                    $value = $arguments[$index] ?? null;
                }

                // Step 5b: Handle null values and string 'null' from LLM.
                // LLMs sometimes return string 'null' instead of actual null value.
                if ($value === 'null' || $value === null) {
                    // Use default value if parameter has one, otherwise null.
                    if ($param->isDefaultValueAvailable() === true) {
                        $value = $param->getDefaultValue();
                    } else {
                        $value = null;
                    }
                } elseif ($param->hasType() === true) {
                    // Step 5c: Type-cast argument to match method signature.
                    // This ensures type safety when LLM provides loosely-typed values.
                    $type = $param->getType();
                    if ($type !== null && $type instanceof \ReflectionNamedType) {
                        $typeName = $type->getName();
                        
                        // Cast to integer type.
                        if ($typeName === 'int') {
                            $value = (int) $value;
                        }
                        // Cast to float type.
                        elseif ($typeName === 'float') {
                            $value = (float) $value;
                        }
                        // Cast to boolean type using filter_var for proper conversion.
                        // Handles 'true', 'false', '1', '0', etc.
                        elseif ($typeName === 'bool') {
                            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                        }
                        // Cast to string type.
                        elseif ($typeName === 'string') {
                            $value = (string) $value;
                        }
                        // Cast to array type.
                        // If already array, keep it; otherwise convert to empty array.
                        elseif ($typeName === 'array') {
                            if (is_array($value) === true) {
                                $value = $value;
                            } else {
                                $value = [];
                            }
                        }
                    }
                }//end if

                // Add processed argument to typed arguments array.
                $typedArguments[] = $value;
            }//end foreach

            // Step 6: Call the camelCase method with type-cast arguments.
            $result = $this->$camelCaseMethod(...$typedArguments);

            // Step 7: Convert array results to JSON for LLPhant compatibility.
            // LLPhant expects tool results to be JSON strings, not PHP arrays.
            if (is_array($result) === true) {
                return json_encode($result);
            }

            // Return non-array results as-is.
            return $result;
        }//end if

        // Method doesn't exist in either snake_case or camelCase format.
        throw new BadMethodCallException("Method {$name} (or {$camelCaseMethod}) does not exist");

    }//end __call()


}//end class
