<?php

declare(strict_types=1);

namespace OCA\OpenRegister\WorkflowEngine;

/**
 * Interface for workflow engine adapters.
 *
 * Each supported workflow engine (n8n, Windmill, etc.) implements this interface
 * to provide a unified way to deploy, execute, and manage workflows.
 */
interface WorkflowEngineInterface
{

    /**
     * Deploy a workflow definition to the engine.
     *
     * @param array<string, mixed> $workflowDefinition Engine-specific workflow JSON
     *
     * @return string Engine-specific workflow ID
     */
    public function deployWorkflow(array $workflowDefinition): string;

    /**
     * Remove a workflow from the engine.
     *
     * @param string $workflowId Engine-specific workflow ID
     *
     * @return void
     */
    public function deleteWorkflow(string $workflowId): void;

    /**
     * Activate a workflow so it can receive triggers.
     *
     * @param string $workflowId Engine-specific workflow ID
     *
     * @return void
     */
    public function activateWorkflow(string $workflowId): void;

    /**
     * Deactivate a workflow.
     *
     * @param string $workflowId Engine-specific workflow ID
     *
     * @return void
     */
    public function deactivateWorkflow(string $workflowId): void;

    /**
     * Execute a workflow synchronously and return the response.
     *
     * @param string             $workflowId Engine-specific workflow ID
     * @param array<string, mixed> $data       Data to pass to the workflow
     * @param int                $timeout    Timeout in seconds (default 30)
     *
     * @return WorkflowResult Structured execution result
     */
    public function executeWorkflow(string $workflowId, array $data, int $timeout = 30): WorkflowResult;

    /**
     * Get the webhook URL that triggers a specific workflow.
     *
     * @param string $workflowId Engine-specific workflow ID
     *
     * @return string Webhook URL
     */
    public function getWebhookUrl(string $workflowId): string;

    /**
     * List all workflows in the engine.
     *
     * @return array<int, array{id: string, name: string}> Array of workflow summaries
     */
    public function listWorkflows(): array;

    /**
     * Check engine health/connectivity.
     *
     * @return bool True if the engine is reachable and responsive
     */
    public function healthCheck(): bool;
}
