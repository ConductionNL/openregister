<?php

/**
 * OpenRegister Endpoint Service
 *
 * Service for handling endpoint execution and management.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use OCA\OpenRegister\Db\Endpoint;
use OCA\OpenRegister\Db\EndpointLog;
use OCA\OpenRegister\Db\EndpointLogMapper;
use OCA\OpenRegister\Db\EndpointMapper;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * EndpointService handles endpoint execution and logging
 *
 * Service for executing external API endpoints and logging execution results.
 * Supports multiple endpoint target types (view, agent, webhook, register, schema).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */
class EndpointService
{

    /**
     * Endpoint log mapper
     *
     * Handles database operations for endpoint execution logs.
     *
     * @var EndpointLogMapper Endpoint log mapper instance
     */
    private readonly EndpointLogMapper $endpointLogMapper;

    /**
     * Logger
     *
     * Used for logging endpoint execution, errors, and debug information.
     *
     * @var LoggerInterface Logger instance
     */
    private readonly LoggerInterface $logger;

    /**
     * User session
     *
     * Provides current user context for permission checks.
     *
     * @var IUserSession User session instance
     */
    private readonly IUserSession $userSession;

    /**
     * Group manager
     *
     * Used for checking user group permissions for endpoint access.
     *
     * @var IGroupManager Group manager instance
     */
    private readonly IGroupManager $groupManager;

    /**
     * Test an endpoint by executing it with test data
     *
     * Executes endpoint with optional test data to verify endpoint configuration
     * and functionality. Checks permissions before execution and logs results.
     *
     * @param Endpoint             $endpoint The endpoint to test
     * @param array<string, mixed> $testData Optional test data to use in execution
     *
     * @return array<string, mixed> Test result with success status, status code, response, and optional error
     *
     * @phpstan-return array{success: bool, statusCode: int, response: mixed, error?: string}
     * @psalm-return   array{success: bool, statusCode: int, response: mixed, error?: string}
     */
    public function testEndpoint(Endpoint $endpoint, array $testData=[]): array
    {
        try {
            // Step 1: Check if user has permission to execute this endpoint.
            // Validates user group membership and endpoint access permissions.
            if ($this->canExecuteEndpoint($endpoint) === false) {
                return [
                    'success'    => false,
                    'statusCode' => 403,
                    'response'   => null,
                    'error'      => 'Access denied: You do not have permission to execute this endpoint.',
                ];
            }

            // Step 2: Prepare test request data from endpoint configuration.
            // Combines endpoint method and path with provided test data.
            $request = [
                'method'  => $endpoint->getMethod() ?? 'GET',
                'path'    => $endpoint->getEndpoint(),
                'data'    => $testData,
                'headers' => [],
            ];

            // Step 3: Execute the endpoint based on target type.
            // Different target types (view, agent, webhook, etc.) have different execution logic.
            $result = $this->executeEndpoint(endpoint: $endpoint, request: $request);

            // Step 4: Log the test execution for audit trail and debugging.
            $this->logEndpointCall(endpoint: $endpoint, request: $request, result: $result);

            // Step 5: Return execution result.
            return $result;
        } catch (\Exception $e) {
            // Log error for debugging and monitoring.
            $this->logger->error(
                'Error testing endpoint: '.$e->getMessage(),
                [
                    'endpoint_id' => $endpoint->getId(),
                    'trace'       => $e->getTraceAsString(),
                ]
            );

            // Return error result.
            return [
                'success'    => false,
                'statusCode' => 500,
                'response'   => null,
                'error'      => $e->getMessage(),
            ];
        }//end try
    }//end testEndpoint()

    /**
     * Execute an endpoint with given request data
     *
     * Routes endpoint execution to appropriate handler based on target type.
     * Supports multiple target types: view, agent, webhook, register, and schema.
     *
     * @param Endpoint             $endpoint The endpoint to execute
     * @param array<string, mixed> $request  Request data containing method, path, data, and headers
     *
     * @return array<string, mixed> Execution result with success status, status code, response, and optional error
     *
     * @phpstan-return array{success: bool, statusCode: int, response: mixed, error?: string}
     * @psalm-return   array{success: bool, statusCode: int, response: mixed, error?: string}
     */
    private function executeEndpoint(Endpoint $endpoint, array $request): array
    {
        // Route execution to appropriate handler based on endpoint target type.
        // Each target type has specific execution logic.
        switch ($endpoint->getTargetType()) {
            case 'view':
                // Execute view-based endpoint (queries view data).
                return $this->executeViewEndpoint(_endpoint: $endpoint, _request: $request);
            case 'agent':
                // Execute agent-based endpoint (uses AI agent).
                return $this->executeAgentEndpoint(endpoint: $endpoint, request: $request);
            case 'webhook':
                // Execute webhook-based endpoint (HTTP webhook call).
                return $this->executeWebhookEndpoint(_endpoint: $endpoint, _request: $request);
            case 'register':
                // Execute register-based endpoint (queries register data).
                return $this->executeRegisterEndpoint(_endpoint: $endpoint, _request: $request);
            case 'schema':
                // Execute schema-based endpoint (queries schema data).
                return $this->executeSchemaEndpoint(_endpoint: $endpoint, _request: $request);
            default:
                return [
                    'success'    => false,
                    'statusCode' => 400,
                    'response'   => null,
                    'error'      => 'Unknown target type: '.$endpoint->getTargetType(),
                ];
        }//end switch
    }//end executeEndpoint()

    /**
     * Execute a view endpoint
     *
     * @param Endpoint $_endpoint The endpoint to execute
     * @param array    $_request  Request data
     *
     * @return (int|string[]|true)[]
     *
     * @phpstan-return array{success: bool, statusCode: int, response: mixed, error?: string}
     *
     * @psalm-return array{success: true, statusCode: 200, response: array{message: 'View endpoint executed (placeholder)'}}
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function executeViewEndpoint(Endpoint $_endpoint, array $_request): array
    {
        // Placeholder for view execution logic.
        // This would integrate with the view service to execute the view.
        return [
            'success'    => true,
            'statusCode' => 200,
            'response'   => ['message' => 'View endpoint executed (placeholder)'],
        ];
    }//end executeViewEndpoint()

    /**
     * Execute an agent endpoint
     *
     * @param Endpoint $endpoint The endpoint to execute
     * @param array    $request  Request data
     *
     * @return         array Execution result
     * @phpstan-return array{success: bool, statusCode: int, response: mixed, error?: string}
     * @psalm-return   array{success: bool, statusCode: int, response: mixed, error?: string}
     * @psalm-suppress UnusedParam - False positive: both parameters are used within the method.
     */
    private function executeAgentEndpoint(Endpoint $endpoint, array $request): array
    {
        try {
            // Get required services.
            $agentMapper     = \OC::$server->get(\OCA\OpenRegister\Db\AgentMapper::class);
            $toolRegistry    = \OC::$server->get(\OCA\OpenRegister\Service\ToolRegistry::class);
            $settingsService = \OC::$server->get(\OCA\OpenRegister\Service\SettingsService::class);

            $agentId = $endpoint->getTargetId();
            $this->logger->info('[EndpointService] Executing agent endpoint', ['agentId' => $agentId]);

            // Find agent by UUID.
            $agent = $agentMapper->findByUuid($agentId);

            if ($agent === null) {
                return [
                    'success'    => false,
                    'statusCode' => 404,
                    'response'   => null,
                    'error'      => 'Agent not found: '.$agentId,
                ];
            }

            // Extract message from request.
            $message = $request['data']['message'] ?? $request['message'] ?? '';

            if (empty($message) === true) {
                return [
                    'success'    => false,
                    'statusCode' => 400,
                    'response'   => null,
                    'error'      => 'Message is required',
                ];
            }

            $this->logger->info(
                '[EndpointService] Executing agent',
                [
                    'agent'    => $agent->getName(),
                    'provider' => $agent->getProvider(),
                    'model'    => $agent->getModel(),
                    'message'  => substr($message, 0, 100),
                ]
            );

            // Get LLM configuration.
            $llmConfig = $settingsService->getSettings()['llm'] ?? [];

            // Prepare tools/functions for the agent.
            $functions  = [];
            $agentTools = $agent->getTools() ?? [];

            if (empty($agentTools) === false) {
                foreach ($agentTools as $toolName) {
                    try {
                        $tool = $toolRegistry->getTool($toolName);
                        if ($tool !== null) {
                            $tool->setAgent($agent);
                            $toolFunctions = $tool->getFunctions();
                            $functions     = array_merge($functions, $toolFunctions);

                            $this->logger->debug(
                                '[EndpointService] Added tool functions',
                                [
                                    'tool'      => $toolName,
                                    'functions' => count($toolFunctions),
                                ]
                            );
                        }
                    } catch (\Exception $e) {
                        $this->logger->warning(
                            '[EndpointService] Failed to load tool: '.$toolName,
                            [
                                'error' => $e->getMessage(),
                            ]
                        );
                    }//end try
                }//end foreach
            }//end if

            $this->logger->info(
                '[EndpointService] Agent has tools configured',
                [
                    'totalFunctions' => count($functions),
                ]
            );

            // Call LLM based on provider.
            if ($agent->getProvider() === 'ollama') {
                $ollamaConfig = $llmConfig['ollamaConfig'] ?? [];
                $ollamaUrl    = $ollamaConfig['url'] ?? 'http://host.docker.internal:11434';

                $this->logger->info(
                    '[EndpointService] Calling Ollama',
                    [
                        'url'                => $ollamaUrl,
                        'model'              => $agent->getModel(),
                        'functionsAvailable' => count($functions),
                    ]
                );

                // Build messages.
                $messages = [];
                if (($agent->getPrompt() !== null && $agent->getPrompt() !== '') === true) {
                    $messages[] = [
                        'role'    => 'system',
                        'content' => $agent->getPrompt(),
                    ];
                }

                $messages[] = [
                    'role'    => 'user',
                    'content' => $message,
                ];

                // Call Ollama API directly.
                $response = $this->callOllamaWithTools($ollamaUrl, $agent->getModel(), $messages, $functions, $agent, $toolRegistry);

                return [
                    'success'    => true,
                    'statusCode' => 200,
                    'response'   => $response,
                ];
            } else {
                return [
                    'success'    => false,
                    'statusCode' => 501,
                    'response'   => null,
                    'error'      => 'Provider '.$agent->getProvider().' not yet implemented for endpoint execution',
                ];
            }//end if
        } catch (\Exception $e) {
            $this->logger->error(
                '[EndpointService] Error executing agent endpoint: '.$e->getMessage(),
                [
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return [
                'success'    => false,
                'statusCode' => 500,
                'response'   => null,
                'error'      => $e->getMessage(),
            ];
        }//end try
    }//end executeAgentEndpoint()

    /**
     * Execute a tool function
     *
     * @param string $functionName Function name
     * @param array  $arguments    Function arguments
     * @param mixed  $agent        The agent
     * @param mixed  $toolRegistry Tool registry
     *
     * @return array Function result
     */
    private function executeToolFunction(string $functionName, array $arguments, $agent, $toolRegistry): array
    {
        try {
            // Get agent's tools.
            $agentTools = $agent->getTools() ?? [];

            // Find which tool has this function.
            foreach ($agentTools as $toolName) {
                try {
                    $tool = $toolRegistry->getTool($toolName);
                    if ($tool === null) {
                        continue;
                    }

                    $tool->setAgent($agent);

                    // Check if this tool has the function.
                    $toolFunctions = $tool->getFunctions();
                    $hasFunction   = false;

                    foreach ($toolFunctions as $func) {
                        if ($func['name'] === $functionName) {
                            $hasFunction = true;
                            break;
                        }
                    }

                    if ($hasFunction === true) {
                        $this->logger->info(
                            '[EndpointService] Calling tool function',
                            [
                                'tool'      => $toolName,
                                'function'  => $functionName,
                                'arguments' => $arguments,
                            ]
                        );

                        // Call the function via __call magic method.
                        // The tool's __call handles name conversion (e.g., cms_create_menu -> createMenu).
                        // Spread the arguments array as individual parameters to avoid double-wrapping.
                        $result = $tool->$functionName(...array_values([$arguments]));

                        // If result is JSON string (from __call), decode it.
                        if (is_string($result) === true) {
                            $decoded = json_decode($result, true);
                            if ($decoded !== null) {
                                $result = $decoded;
                            }
                        }

                        return $result;
                    }//end if
                } catch (\Exception $e) {
                    $this->logger->error(
                        '[EndpointService] Error checking tool: '.$toolName,
                        [
                            'error' => $e->getMessage(),
                        ]
                    );
                }//end try
            }//end foreach

            return [
                'error' => 'Function not found: '.$functionName,
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                '[EndpointService] Error executing tool function',
                [
                    'function' => $functionName,
                    'error'    => $e->getMessage(),
                ]
            );

            return [
                'error' => $e->getMessage(),
            ];
        }//end try
    }//end executeToolFunction()

    /**
     * Execute a webhook endpoint
     *
     * @param Endpoint $_endpoint The endpoint to execute
     * @param array    $_request  Request data
     *
     * @return (int|string[]|true)[]
     *
     * @phpstan-return array{success: bool, statusCode: int, response: mixed, error?: string}
     *
     * @psalm-return array{success: true, statusCode: 200, response: array{message: 'Webhook endpoint executed (placeholder)'}}
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function executeWebhookEndpoint(Endpoint $_endpoint, array $_request): array
    {
        // Placeholder for webhook execution logic.
        // This would integrate with the webhook service to trigger the webhook.
        return [
            'success'    => true,
            'statusCode' => 200,
            'response'   => ['message' => 'Webhook endpoint executed (placeholder)'],
        ];
    }//end executeWebhookEndpoint()

    /**
     * Execute a register endpoint
     *
     * @param Endpoint $_endpoint The endpoint to execute
     * @param array    $_request  Request data
     *
     * @return (int|string[]|true)[]
     *
     * @phpstan-return array{success: bool, statusCode: int, response: mixed, error?: string}
     *
     * @psalm-return array{success: true, statusCode: 200, response: array{message: 'Register endpoint executed (placeholder)'}}
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function executeRegisterEndpoint(Endpoint $_endpoint, array $_request): array
    {
        // Placeholder for register execution logic.
        // This would integrate with the register/object service to handle CRUD operations.
        return [
            'success'    => true,
            'statusCode' => 200,
            'response'   => ['message' => 'Register endpoint executed (placeholder)'],
        ];
    }//end executeRegisterEndpoint()

    /**
     * Execute a schema endpoint
     *
     * @param Endpoint $_endpoint The endpoint to execute
     * @param array    $_request  Request data
     *
     * @return (int|string[]|true)[]
     *
     * @phpstan-return array{success: bool, statusCode: int, response: mixed, error?: string}
     *
     * @psalm-return array{success: true, statusCode: 200, response: array{message: 'Schema endpoint executed (placeholder)'}}
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function executeSchemaEndpoint(Endpoint $_endpoint, array $_request): array
    {
        // Placeholder for schema execution logic.
        // This would integrate with the schema/object service to handle schema-specific operations.
        return [
            'success'    => true,
            'statusCode' => 200,
            'response'   => ['message' => 'Schema endpoint executed (placeholder)'],
        ];
    }//end executeSchemaEndpoint()

    /**
     * Check if the current user can execute an endpoint
     *
     * @param Endpoint $endpoint The endpoint to check
     *
     * @return bool True if user can execute, false otherwise
     */
    private function canExecuteEndpoint(Endpoint $endpoint): bool
    {
        // Get current user.
        $user = $this->userSession->getUser();
        if ($user === null) {
            // No user logged in - check if endpoint allows public access.
            $groups = $endpoint->getGroups();
            return empty($groups);
        }

        // Get user's groups.
        $userGroups = $this->groupManager->getUserGroupIds($user);

        // Check if user is admin.
        if (in_array('admin', $userGroups) === true) {
            return true;
        }

        // Check endpoint groups configuration.
        $endpointGroups = $endpoint->getGroups();

        // If no groups defined, allow all authenticated users.
        if (empty($endpointGroups) === true) {
            return true;
        }

        // Check if user is in any of the allowed groups.
        foreach ($userGroups as $groupId) {
            if (in_array($groupId, $endpointGroups) === true) {
                return true;
            }
        }

        return false;
    }//end canExecuteEndpoint()

    /**
     * Log an endpoint call
     *
     * @param Endpoint $endpoint The endpoint that was called
     * @param array    $request  Request data
     * @param array    $result   Result data
     *
     * @return void
     */
    private function logEndpointCall(Endpoint $endpoint, array $request, array $result): void
    {
        try {
            $log = new EndpointLog();

            // Generate UUID.
            $log->setUuid(Uuid::v4()->toRfc4122());

            // Set endpoint ID.
            $log->setEndpointId($endpoint->getId());

            // Set user info.
            $user = $this->userSession->getUser();
            if ($user !== null) {
                $log->setUserId($user->getUID());
            }

            // Set request/response data.
            $log->setRequest($request);
            $log->setResponse(
                response: [
                    'statusCode' => $result['statusCode'],
                    'body'       => $result['response'],
                ]
            );

            // Set status.
            $log->setStatusCode($result['statusCode']);
            $log->setStatusMessage($result['error'] ?? 'Success');

            // Set timestamps.
            $log->setCreated(new DateTime());

            // Set expiry (1 week from now).
            $expires = new DateTime();
            $expires->modify('+1 week');
            $log->setExpires($expires);

            // Calculate size.
            $log->calculateSize();

            // Insert log.
            $this->endpointLogMapper->insert($log);

            $this->logger->debug(
                'Endpoint call logged',
                [
                    'endpoint_id' => $endpoint->getId(),
                    'status_code' => $result['statusCode'],
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'Error logging endpoint call: '.$e->getMessage(),
                [
                    'endpoint_id' => $endpoint->getId(),
                    'trace'       => $e->getTraceAsString(),
                ]
            );
        }//end try
    }//end logEndpointCall()
}//end class
