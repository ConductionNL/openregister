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
     * @param string $functionName Function being executed
     * @param array  $parameters   Function parameters
     * @param string $level        Log level (info, warning, error)
     * @param string $message      Log message
     *
     * @return void
     */
    protected function log(string $functionName, array $parameters, string $level='info', string $message=''): void
    {
        $context = [
            'tool'       => $this->getName(),
            'function'   => $functionName,
            'parameters' => $parameters,
            'agent'      => $this->agent?->getId(),
            'user'       => $this->getUserId(),
        ];

        if ($message !== '') {
            $messageText = $message;
        } else {
            $messageText = 'Executing function';
        }

        $logMessage = sprintf(
            '[Tool:%s] %s: %s',
                $this->getName(),
            $functionName,
            $messageText
        );

        switch ($level) {
            case 'error':
                $this->logger->error($logMessage, $context);
                break;
            case 'warning':
                $this->logger->warning($logMessage, $context);
                break;
            default:
                $this->logger->info($logMessage, $context);
                break;
        }

    }//end log()


    /**
     * Validate required parameters
     *
     * @param array $parameters Function parameters
     * @param array $required   Required parameter names
     *
     * @return void
     *
     * @throws \InvalidArgumentException If required parameters are missing
     */
    protected function validateParameters(array $parameters, array $required): void
    {
        foreach ($required as $param) {
            if (isset($parameters[$param]) === false) {
                throw new \InvalidArgumentException("Missing required parameter: {$param}");
            }
        }

    }//end validateParameters()


    /**
     * Magic method to support snake_case method calls for LLPhant compatibility
     *
     * Automatically converts snake_case method calls to camelCase for PSR compliance.
     * Example: list_registers() -> listRegisters()
     *
     * @param string $name      Method name (snake_case)
     * @param array  $arguments Method arguments
     *
     * @return mixed Method result
     *
     * @throws \BadMethodCallException If the camelCase method doesn't exist
     */
    public function __call(string $name, array $arguments)
    {
        // Convert snake_case to camelCase.
        $camelCaseMethod = lcfirst(str_replace('_', '', ucwords($name, '_')));

        if (method_exists($this, $camelCaseMethod) === true) {
            // Get method reflection to understand parameter types.
            $reflection = new \ReflectionMethod($this, $camelCaseMethod);
            $parameters = $reflection->getParameters();

            // Type-cast arguments based on method signature.
            // Handle both positional and named arguments from LLPhant.
            $isAssociative = array_keys($arguments) !== range(0, count($arguments) - 1);

            $typedArguments = [];
            foreach ($parameters as $index => $param) {
                $paramName = $param->getName();

                // Get value from either named argument or positional argument.
                if ($isAssociative === true && isset($arguments[$paramName]) === true) {
                    $value = $arguments[$paramName];
                } else {
                    $value = $arguments[$index] ?? null;
                }

                // Handle string 'null' from LLM.
                if ($value === 'null' || $value === null) {
                    // Use default value if available, otherwise null.
                    if ($param->isDefaultValueAvailable() === true) {
                        $value = $param->getDefaultValue();
                    } else {
                        $value = null;
                    }
                } else if ($param->hasType() === true) {
                    // Cast to the expected type.
                    $type = $param->getType();
                    if ($type !== null && $type instanceof \ReflectionNamedType) {
                        $typeName = $type->getName();
                        if ($typeName === 'int') {
                            $value = (int) $value;
                        } else if ($typeName === 'float') {
                            $value = (float) $value;
                        } else if ($typeName === 'bool') {
                            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                        } else if ($typeName === 'string') {
                            $value = (string) $value;
                        } else if ($typeName === 'array') {
                            if (is_array($value) === true) {
                                $value = $value;
                            } else {
                                $value = [];
                            }
                        }
                    }
                }//end if

                $typedArguments[] = $value;
            }//end foreach

            $result = $this->$camelCaseMethod(...$typedArguments);

            // LLPhant expects tool results to be JSON strings, not arrays.
            // Convert array results to JSON for LLM consumption.
            if (is_array($result) === true) {
                return json_encode($result);
            }

            return $result;
        }//end if

        throw new \BadMethodCallException("Method {$name} (or {$camelCaseMethod}) does not exist");

    }//end __call()


}//end class
