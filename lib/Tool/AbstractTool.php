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
        $this->logger = $logger;
    }

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
    }

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
    protected function getUserId(?string $explicitUserId = null): ?string
    {
        // Use explicit user ID if provided
        if ($explicitUserId !== null) {
            return $explicitUserId;
        }

        // Try to get from session
        $user = $this->userSession->getUser();
        if ($user !== null) {
            return $user->getUID();
        }

        // Fall back to agent's user (for cron scenarios)
        if ($this->agent !== null && $this->agent->getUser() !== null) {
            return $this->agent->getUser();
        }

        return null;
    }

    /**
     * Check if a user context is available
     *
     * @param string|null $explicitUserId Explicitly provided user ID
     *
     * @return bool True if user context is available
     */
    protected function hasUserContext(?string $explicitUserId = null): bool
    {
        return $this->getUserId($explicitUserId) !== null;
    }

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
        if ($views === null || empty($views)) {
            return $params;
        }

        // TODO: Implement view filtering in mappers
        // View filtering allows agents to only see data filtered by predefined views
        // For now, this is disabled as the mappers don't have a 'views' column yet
        // $params['_views'] = $views;

        return $params;
    }

    /**
     * Format a success result
     *
     * @param mixed  $data    Result data
     * @param string $message Success message
     *
     * @return array Formatted result
     */
    protected function formatSuccess($data, string $message = 'Success'): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];
    }

    /**
     * Format an error result
     *
     * @param string $message Error message
     * @param mixed  $details Additional error details
     *
     * @return array Formatted error result
     */
    protected function formatError(string $message, $details = null): array
    {
        $result = [
            'success' => false,
            'error' => $message,
        ];

        if ($details !== null) {
            $result['details'] = $details;
        }

        return $result;
    }

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
    protected function log(string $functionName, array $parameters, string $level = 'info', string $message = ''): void
    {
        $context = [
            'tool' => $this->getName(),
            'function' => $functionName,
            'parameters' => $parameters,
            'agent' => $this->agent?->getId(),
            'user' => $this->getUserId(),
        ];

        $logMessage = sprintf(
            '[Tool:%s] %s: %s',
            $this->getName(),
            $functionName,
            $message ?: 'Executing function'
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
    }

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
            if (!isset($parameters[$param])) {
                throw new \InvalidArgumentException("Missing required parameter: {$param}");
            }
        }
    }

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
        // Convert snake_case to camelCase
        $camelCaseMethod = lcfirst(str_replace('_', '', ucwords($name, '_')));
        
        if (method_exists($this, $camelCaseMethod)) {
            // Get method reflection to understand parameter types
            $reflection = new \ReflectionMethod($this, $camelCaseMethod);
            $parameters = $reflection->getParameters();
            
            // Type-cast arguments based on method signature
            $typedArguments = [];
            foreach ($parameters as $index => $param) {
                $value = $arguments[$index] ?? null;
                
                // Handle string 'null' from LLM
                if ($value === 'null' || $value === null) {
                    // Use default value if available, otherwise null
                    $value = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
                } elseif ($param->hasType()) {
                    // Cast to the expected type
                    $type = $param->getType();
                    if ($type && $type instanceof \ReflectionNamedType) {
                        $typeName = $type->getName();
                        if ($typeName === 'int') {
                            $value = (int) $value;
                        } elseif ($typeName === 'float') {
                            $value = (float) $value;
                        } elseif ($typeName === 'bool') {
                            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                        } elseif ($typeName === 'string') {
                            $value = (string) $value;
                        } elseif ($typeName === 'array') {
                            $value = is_array($value) ? $value : [];
                        }
                    }
                }
                
                $typedArguments[] = $value;
            }
            
            $result = $this->$camelCaseMethod(...$typedArguments);
            
            // LLPhant expects tool results to be JSON strings, not arrays
            // Convert array results to JSON for LLM consumption
            if (is_array($result)) {
                return json_encode($result);
            }
            
            return $result;
        }
        
        throw new \BadMethodCallException("Method {$name} (or {$camelCaseMethod}) does not exist");
    }
}

