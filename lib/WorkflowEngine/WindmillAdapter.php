<?php

/**
 * OpenRegister WindmillAdapter
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
 *
 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-82
 * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-94
 */

declare(strict_types=1);

namespace OCA\OpenRegister\WorkflowEngine;

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Adapter for Windmill workflow engine.
 *
 * Translates WorkflowEngineInterface calls to Windmill's REST API.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class WindmillAdapter implements WorkflowEngineInterface
{

    /**
     * Base URL for the Windmill API.
     *
     * @var string
     */
    private string $baseUrl;

    /**
     * Windmill workspace identifier.
     *
     * @var string
     */
    private string $workspace;

    /**
     * Authentication configuration for engine connection.
     *
     * @var array<string, mixed>
     */
    private array $authConfig;

    /**
     * Constructor for WindmillAdapter.
     *
     * @param IClientService  $clientService HTTP client
     * @param LoggerInterface $logger        Logger
     */
    public function __construct(
        private readonly IClientService $clientService,
        private readonly LoggerInterface $logger
    ) {
        $this->baseUrl    = '';
        $this->workspace  = 'main';
        $this->authConfig = [];
    }//end __construct()

    /**
     * Configure the adapter with engine settings.
     *
     * @param string               $baseUrl    Engine base URL
     * @param array<string, mixed> $authConfig Authentication configuration
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-82
     */
    public function configure(string $baseUrl, array $authConfig=[]): void
    {
        $this->baseUrl    = rtrim($baseUrl, '/');
        $this->workspace  = $authConfig['workspace'] ?? 'main';
        $this->authConfig = $authConfig;
    }//end configure()

    /**
     * Deploy a workflow to Windmill.
     *
     * @param array<string, mixed> $workflowDefinition Workflow definition
     *
     * @return string The workflow ID
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-82
     */
    public function deployWorkflow(array $workflowDefinition): string
    {
        $client   = $this->clientService->newClient();
        $response = $client->post(
            $this->baseUrl.'/api/w/'.$this->workspace.'/flows/create',
            $this->buildRequestOptions(extra: ['json' => $workflowDefinition])
        );

        $data = json_decode($response->getBody(), true);

        return (string) ($data['path'] ?? $data['id'] ?? '');
    }//end deployWorkflow()

    /**
     * Update an existing workflow in Windmill.
     *
     * @param string               $workflowId         The workflow ID to update
     * @param array<string, mixed> $workflowDefinition Updated workflow definition
     *
     * @return string The workflow ID
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-82
     */
    public function updateWorkflow(string $workflowId, array $workflowDefinition): string
    {
        $client   = $this->clientService->newClient();
        $response = $client->post(
            $this->baseUrl.'/api/w/'.$this->workspace.'/flows/update/'.$workflowId,
            $this->buildRequestOptions(extra: ['json' => $workflowDefinition])
        );

        $data = json_decode($response->getBody(), true);

        return (string) ($data['path'] ?? $workflowId);
    }//end updateWorkflow()

    /**
     * Get a workflow definition from Windmill.
     *
     * @param string $workflowId The workflow ID to retrieve
     *
     * @return array<string, mixed> The workflow definition
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-82
     */
    public function getWorkflow(string $workflowId): array
    {
        $client   = $this->clientService->newClient();
        $response = $client->get(
            $this->baseUrl.'/api/w/'.$this->workspace.'/flows/get/'.$workflowId,
            $this->buildRequestOptions()
        );

        return json_decode($response->getBody(), true) ?? [];
    }//end getWorkflow()

    /**
     * Delete a workflow from Windmill.
     *
     * @param string $workflowId The workflow ID to delete
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-82
     */
    public function deleteWorkflow(string $workflowId): void
    {
        $client = $this->clientService->newClient();
        $client->delete(
            $this->baseUrl.'/api/w/'.$this->workspace.'/flows/delete/'.$workflowId,
            $this->buildRequestOptions()
        );
    }//end deleteWorkflow()

    /**
     * Activate a workflow in Windmill (no-op).
     *
     * @param string $workflowId The workflow ID to activate
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-82
     */
    public function activateWorkflow(string $workflowId): void
    {
        // Windmill flows are always active once created. No-op.
    }//end activateWorkflow()

    /**
     * Deactivate a workflow in Windmill (no-op).
     *
     * @param string $workflowId The workflow ID to deactivate
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-82
     */
    public function deactivateWorkflow(string $workflowId): void
    {
        // Windmill doesn't have an activate/deactivate toggle for flows.
        // Archiving could be used but is destructive. No-op.
    }//end deactivateWorkflow()

    /**
     * Execute a workflow in Windmill.
     *
     * @param string               $workflowId The workflow ID to execute
     * @param array<string, mixed> $data       Input data for the workflow
     * @param int                  $timeout    Timeout in seconds
     *
     * @return WorkflowResult The execution result
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-82
     */
    public function executeWorkflow(string $workflowId, array $data, int $timeout=30): WorkflowResult
    {
        try {
            $client   = $this->clientService->newClient();
            $response = $client->post(
                $this->baseUrl.'/api/w/'.$this->workspace.'/jobs/run_wait_result/f/'.$workflowId,
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
                message: '[WindmillAdapter] Workflow execution failed',
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
                    metadata: ['engine' => 'windmill', 'workflowId' => $workflowId]
                );
            }

            return WorkflowResult::error(
                message: $e->getMessage(),
                metadata: ['engine' => 'windmill', 'workflowId' => $workflowId]
            );
        }//end try
    }//end executeWorkflow()

    /**
     * Get the webhook URL for a Windmill workflow.
     *
     * @param string $workflowId The workflow ID
     *
     * @return string The webhook URL
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-82
     */
    public function getWebhookUrl(string $workflowId): string
    {
        return $this->baseUrl.'/api/w/'.$this->workspace.'/jobs/run/f/'.$workflowId;
    }//end getWebhookUrl()

    /**
     * List all workflows from Windmill.
     *
     * @return array<int, array{id: string, name: string}> List of workflows
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-82
     */
    public function listWorkflows(): array
    {
        try {
            $client   = $this->clientService->newClient();
            $response = $client->get(
                $this->baseUrl.'/api/w/'.$this->workspace.'/flows/list',
                $this->buildRequestOptions()
            );

            $data      = json_decode($response->getBody(), true);
            $workflows = [];

            foreach (($data ?? []) as $flow) {
                $workflows[] = [
                    'id'   => $flow['path'] ?? '',
                    'name' => $flow['summary'] ?? $flow['path'] ?? '',
                ];
            }

            return $workflows;
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[WindmillAdapter] Failed to list workflows',
                context: ['error' => $e->getMessage()]
            );

            return [];
        }//end try
    }//end listWorkflows()

    /**
     * Check the health of the Windmill engine.
     *
     * @return bool True if healthy
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-82
     */
    public function healthCheck(): bool
    {
        try {
            $client   = $this->clientService->newClient();
            $response = $client->get(
                $this->baseUrl.'/api/version',
                $this->buildRequestOptions(extra: ['timeout' => 5])
            );

            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            $this->logger->debug(
                message: '[WindmillAdapter] Health check failed',
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
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-82
     */
    private function buildRequestOptions(array $extra=[]): array
    {
        return array_merge(
                [
                    'headers' => $this->buildAuthHeaders(),
                ],
                $extra
                );
    }//end buildRequestOptions()

    /**
     * Build authentication headers.
     *
     * @return array<string, string>
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-82
     */
    private function buildAuthHeaders(): array
    {
        $headers = ['Accept' => 'application/json'];

        if (isset($this->authConfig['token']) === true) {
            $headers['Authorization'] = 'Bearer '.$this->authConfig['token'];
        }

        return $headers;
    }//end buildAuthHeaders()

    /**
     * Parse Windmill response into a WorkflowResult.
     *
     * @param array<string, mixed>|null $responseData Response from Windmill
     *
     * @return WorkflowResult
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-82
     */
    private function parseWorkflowResponse(?array $responseData): WorkflowResult
    {
        if ($responseData === null) {
            return WorkflowResult::approved(metadata: ['engine' => 'windmill']);
        }

        $status = $responseData['status'] ?? 'approved';

        return match ($status) {
            'rejected' => WorkflowResult::rejected(
                errors: $responseData['errors'] ?? [],
                metadata: array_merge(['engine' => 'windmill'], $responseData['metadata'] ?? [])
            ),
            'modified' => WorkflowResult::modified(
                data: $responseData['data'] ?? [],
                metadata: array_merge(['engine' => 'windmill'], $responseData['metadata'] ?? [])
            ),
            'error' => WorkflowResult::error(
                message: $responseData['errors'][0]['message'] ?? 'Unknown error',
                metadata: array_merge(['engine' => 'windmill'], $responseData['metadata'] ?? [])
            ),
            default => WorkflowResult::approved(
                metadata: array_merge(['engine' => 'windmill'], $responseData['metadata'] ?? [])
            ),
        };
    }//end parseWorkflowResponse()
}//end class
