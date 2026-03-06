<?php

/**
 * OpenRegister N8nAdapter
 *
 * @category WorkflowEngine
 * @package  OCA\OpenRegister\WorkflowEngine
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\WorkflowEngine;

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Adapter for n8n workflow engine.
 *
 * Translates WorkflowEngineInterface calls to n8n's REST API.
 * Supports routing through the ExApp proxy when n8n runs as a Nextcloud ExApp.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class N8nAdapter implements WorkflowEngineInterface
{

    /**
     * Base URL for the n8n API.
     *
     * @var string
     */
    private string $baseUrl;

    /**
     * Authentication configuration for engine connection.
     *
     * @var array<string, mixed>
     */
    private array $authConfig;

    /**
     * Constructor for N8nAdapter.
     *
     * @param IClientService  $clientService HTTP client
     * @param LoggerInterface $logger        Logger
     */
    public function __construct(
        private readonly IClientService $clientService,
        private readonly LoggerInterface $logger
    ) {
        $this->baseUrl    = '';
        $this->authConfig = [];
    }//end __construct()

    /**
     * Configure the adapter with engine settings.
     *
     * @param string               $baseUrl    Engine base URL
     * @param array<string, mixed> $authConfig Authentication configuration
     *
     * @return void
     */
    public function configure(string $baseUrl, array $authConfig=[]): void
    {
        $this->baseUrl    = rtrim($baseUrl, '/');
        $this->authConfig = $authConfig;
    }//end configure()

    /**
     * Deploy a workflow to n8n.
     *
     * @param array<string, mixed> $workflowDefinition Workflow definition
     *
     * @return string The workflow ID
     */
    public function deployWorkflow(array $workflowDefinition): string
    {
        $client   = $this->clientService->newClient();
        $response = $client->post(
            $this->baseUrl.'/rest/workflows',
            $this->buildRequestOptions(extra: ['json' => $workflowDefinition])
        );

        $data = json_decode($response->getBody(), true);

        return (string) ($data['id'] ?? '');
    }//end deployWorkflow()

    /**
     * Delete a workflow from n8n.
     *
     * @param string $workflowId The workflow ID to delete
     *
     * @return void
     */
    public function deleteWorkflow(string $workflowId): void
    {
        $client = $this->clientService->newClient();
        $client->delete(
            $this->baseUrl.'/rest/workflows/'.$workflowId,
            $this->buildRequestOptions()
        );
    }//end deleteWorkflow()

    /**
     * Activate a workflow in n8n.
     *
     * @param string $workflowId The workflow ID to activate
     *
     * @return void
     */
    public function activateWorkflow(string $workflowId): void
    {
        $client = $this->clientService->newClient();
        $client->patch(
            $this->baseUrl.'/rest/workflows/'.$workflowId,
            $this->buildRequestOptions(extra: ['json' => ['active' => true]])
        );
    }//end activateWorkflow()

    /**
     * Deactivate a workflow in n8n.
     *
     * @param string $workflowId The workflow ID to deactivate
     *
     * @return void
     */
    public function deactivateWorkflow(string $workflowId): void
    {
        $client = $this->clientService->newClient();
        $client->patch(
            $this->baseUrl.'/rest/workflows/'.$workflowId,
            $this->buildRequestOptions(extra: ['json' => ['active' => false]])
        );
    }//end deactivateWorkflow()

    /**
     * Execute a workflow in n8n via webhook.
     *
     * @param string               $workflowId The workflow ID to execute
     * @param array<string, mixed> $data       Input data for the workflow
     * @param int                  $timeout    Timeout in seconds
     *
     * @return WorkflowResult The execution result
     */
    public function executeWorkflow(string $workflowId, array $data, int $timeout=30): WorkflowResult
    {
        try {
            $webhookUrl = $this->getWebhookUrl(workflowId: $workflowId);
            $client     = $this->clientService->newClient();

            $response = $client->post(
                $webhookUrl,
                $this->buildRequestOptions(
                        extra: [
                            'json'    => $data,
                            'timeout' => $timeout,
                        ]
                        )
            );

            $responseData = json_decode($response->getBody(), true);

            return $this->parseWorkflowResponse(responseData: $responseData);
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[N8nAdapter] Workflow execution failed',
                context: [
                    'workflowId' => $workflowId,
                    'error'      => $e->getMessage(),
                ]
            );

            if (str_contains($e->getMessage(), 'timed out') === true
                || str_contains($e->getMessage(), 'timeout') === true
            ) {
                return WorkflowResult::error(
                    message: 'Workflow execution timed out after '.$timeout.' seconds',
                    metadata: ['engine' => 'n8n', 'workflowId' => $workflowId]
                );
            }

            return WorkflowResult::error(
                message: $e->getMessage(),
                metadata: ['engine' => 'n8n', 'workflowId' => $workflowId]
            );
        }//end try
    }//end executeWorkflow()

    /**
     * Get the webhook URL for a workflow.
     *
     * @param string $workflowId The workflow ID
     *
     * @return string The webhook URL
     */
    public function getWebhookUrl(string $workflowId): string
    {
        return $this->baseUrl.'/webhook/'.$workflowId;
    }//end getWebhookUrl()

    /**
     * List all workflows from n8n.
     *
     * @return array<int, array<string, mixed>> List of workflows
     */
    public function listWorkflows(): array
    {
        try {
            $client   = $this->clientService->newClient();
            $response = $client->get(
                $this->baseUrl.'/rest/workflows',
                $this->buildRequestOptions()
            );

            $data      = json_decode($response->getBody(), true);
            $workflows = [];

            foreach (($data['data'] ?? []) as $workflow) {
                $workflows[] = [
                    'id'     => (string) $workflow['id'],
                    'name'   => $workflow['name'] ?? '',
                    'active' => $workflow['active'] ?? false,
                ];
            }

            return $workflows;
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[N8nAdapter] Failed to list workflows',
                context: ['error' => $e->getMessage()]
            );

            return [];
        }//end try
    }//end listWorkflows()

    /**
     * Check the health of the n8n engine.
     *
     * @return bool True if healthy
     */
    public function healthCheck(): bool
    {
        try {
            $client   = $this->clientService->newClient();
            $response = $client->get(
                $this->baseUrl.'/rest/settings',
                $this->buildRequestOptions(extra: ['timeout' => 5])
            );

            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            $this->logger->debug(
                message: '[N8nAdapter] Health check failed',
                context: ['error' => $e->getMessage()]
            );

            return false;
        }
    }//end healthCheck()

    /**
     * Build HTTP request options with authentication headers.
     *
     * @param array<string, mixed> $extra Additional options
     *
     * @return array<string, mixed>
     */
    private function buildRequestOptions(array $extra=[]): array
    {
        $options = array_merge(
                [
                    'headers' => $this->buildAuthHeaders(),
                ],
                $extra
                );

        return $options;
    }//end buildRequestOptions()

    /**
     * Build authentication headers based on auth config.
     *
     * @return array<string, string>
     */
    private function buildAuthHeaders(): array
    {
        $headers = ['Accept' => 'application/json'];

        $authType = $this->authConfig['authType'] ?? 'none';

        if ($authType === 'bearer' && isset($this->authConfig['token']) === true) {
            $headers['Authorization'] = 'Bearer '.$this->authConfig['token'];
        } else if ($authType === 'basic' && isset($this->authConfig['username'], $this->authConfig['password']) === true) {
            $headers['Authorization'] = 'Basic '.base64_encode(
                $this->authConfig['username'].':'.$this->authConfig['password']
            );
        }

        return $headers;
    }//end buildAuthHeaders()

    /**
     * Parse n8n workflow response into a WorkflowResult.
     *
     * @param array<string, mixed>|null $responseData Response from n8n
     *
     * @return WorkflowResult
     */
    private function parseWorkflowResponse(?array $responseData): WorkflowResult
    {
        if ($responseData === null) {
            return WorkflowResult::approved(metadata: ['engine' => 'n8n']);
        }

        $status = $responseData['status'] ?? 'approved';

        return match ($status) {
            'rejected' => WorkflowResult::rejected(
                errors: $responseData['errors'] ?? [],
                metadata: array_merge(['engine' => 'n8n'], $responseData['metadata'] ?? [])
            ),
            'modified' => WorkflowResult::modified(
                data: $responseData['data'] ?? [],
                metadata: array_merge(['engine' => 'n8n'], $responseData['metadata'] ?? [])
            ),
            'error' => WorkflowResult::error(
                message: $responseData['errors'][0]['message'] ?? 'Unknown error',
                metadata: array_merge(['engine' => 'n8n'], $responseData['metadata'] ?? [])
            ),
            default => WorkflowResult::approved(
                metadata: array_merge(['engine' => 'n8n'], $responseData['metadata'] ?? [])
            ),
        };
    }//end parseWorkflowResponse()
}//end class
