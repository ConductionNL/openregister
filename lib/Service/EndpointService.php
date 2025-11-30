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
 */
class EndpointService
{

    /**
     * Endpoint log mapper
     *
     * @var EndpointLogMapper
     */
    private EndpointLogMapper $endpointLogMapper;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * User session
     *
     * @var IUserSession
     */
    private IUserSession $userSession;

    /**
     * Group manager
     *
     * @var IGroupManager
     */
    private IGroupManager $groupManager;



    /**
     * Test an endpoint by executing it with test data
     *
     * @param Endpoint $endpoint The endpoint to test
     * @param array    $testData Optional test data to use
     *
     * @return         array Test result with success status and response
     * @phpstan-return array{success: bool, statusCode: int, response: mixed, error?: string}
     * @psalm-return   array{success: bool, statusCode: int, response: mixed, error?: string}
     */
    public function testEndpoint(Endpoint $endpoint, array $testData=[]): array
    {
        try {
            // Check if user has permission to execute this endpoint.
            if ($this->canExecuteEndpoint($endpoint) === false) {
                return [
                    'success'    => false,
                    'statusCode' => 403,
                    'response'   => null,
                    'error'      => 'Access denied: You do not have permission to execute this endpoint.',
                ];
            }

            // Prepare test request data.
            $request = [
                'method'  => $endpoint->getMethod() ?? 'GET',
                'path'    => $endpoint->getEndpoint(),
                'data'    => $testData,
                'headers' => [],
            ];

            // Execute the endpoint based on target type.
            $result = $this->executeEndpoint($endpoint, $request);

            // Log the test execution.
            $this->logEndpointCall($endpoint, $request, $result);

            return $result;
        } catch (\Exception $e) {
            $this->logger->error(
                'Error testing endpoint: '.$e->getMessage(),
                [
                    'endpoint_id' => $endpoint->getId(),
                    'trace'       => $e->getTraceAsString(),
                ]
            );

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
     * @param Endpoint $endpoint The endpoint to execute
     * @param array    $request  Request data
     *
     * @return         array Execution result
     * @phpstan-return array{success: bool, statusCode: int, response: mixed, error?: string}
     * @psalm-return   array{success: bool, statusCode: int, response: mixed, error?: string}
     */
    private function executeEndpoint(Endpoint $endpoint, array $request): array
    {
        // Based on targetType, execute different logic.
        switch ($endpoint->getTargetType()) {
            case 'view':
                return $this->executeViewEndpoint($endpoint, $request);
            case 'agent':
                return $this->executeAgentEndpoint($endpoint, $request);
            case 'webhook':
                return $this->executeWebhookEndpoint($endpoint, $request);
            case 'register':
                return $this->executeRegisterEndpoint($endpoint, $request);
            case 'schema':
                return $this->executeSchemaEndpoint($endpoint, $request);
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
     * @param Endpoint $endpoint The endpoint to execute
     * @param array    $request  Request data
     *
     * @return         array Execution result
     * @phpstan-return array{success: bool, statusCode: int, response: mixed, error?: string}
     * @psalm-return   array{success: bool, statusCode: int, response: mixed, error?: string}
     */
    private function executeViewEndpoint(Endpoint $endpoint, array $request): array
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

            if (!$agent) {
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

            if (!empty($agentTools)) {
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
                    if (!$tool) {
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
                        if (is_string($result)) {
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
     * @param Endpoint $endpoint The endpoint to execute
     * @param array    $request  Request data
     *
     * @return         array Execution result
     * @phpstan-return array{success: bool, statusCode: int, response: mixed, error?: string}
     * @psalm-return   array{success: bool, statusCode: int, response: mixed, error?: string}
     */
    private function executeWebhookEndpoint(Endpoint $endpoint, array $request): array
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
     * @param Endpoint $endpoint The endpoint to execute
     * @param array    $request  Request data
     *
     * @return         array Execution result
     * @phpstan-return array{success: bool, statusCode: int, response: mixed, error?: string}
     * @psalm-return   array{success: bool, statusCode: int, response: mixed, error?: string}
     */
    private function executeRegisterEndpoint(Endpoint $endpoint, array $request): array
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
     * @param Endpoint $endpoint The endpoint to execute
     * @param array    $request  Request data
     *
     * @return         array Execution result
     * @phpstan-return array{success: bool, statusCode: int, response: mixed, error?: string}
     * @psalm-return   array{success: bool, statusCode: int, response: mixed, error?: string}
     */
    private function executeSchemaEndpoint(Endpoint $endpoint, array $request): array
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
                    [
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
