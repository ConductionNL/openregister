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
     * Endpoint mapper
     *
     * @var EndpointMapper
     */
    private EndpointMapper $endpointMapper;

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
     * Constructor
     *
     * @param EndpointMapper    $endpointMapper    Endpoint mapper
     * @param EndpointLogMapper $endpointLogMapper Endpoint log mapper
     * @param LoggerInterface   $logger            Logger
     * @param IUserSession      $userSession       User session
     * @param IGroupManager     $groupManager      Group manager
     */
    public function __construct(
        EndpointMapper $endpointMapper,
        EndpointLogMapper $endpointLogMapper,
        LoggerInterface $logger,
        IUserSession $userSession,
        IGroupManager $groupManager
    ) {
        $this->endpointMapper    = $endpointMapper;
        $this->endpointLogMapper = $endpointLogMapper;
        $this->logger            = $logger;
        $this->userSession       = $userSession;
        $this->groupManager      = $groupManager;

    }//end __construct()


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
                'method'  => $endpoint->method ?? 'GET',
                'path'    => $endpoint->endpoint,
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
        switch ($endpoint->targetType) {
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
                    'error'      => 'Unknown target type: '.$endpoint->targetType,
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
        // Placeholder for agent execution logic.
        // This would integrate with the agent service to execute the agent.
        return [
            'success'    => true,
            'statusCode' => 200,
            'response'   => ['message' => 'Agent endpoint executed (placeholder)'],
        ];

    }//end executeAgentEndpoint()


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
