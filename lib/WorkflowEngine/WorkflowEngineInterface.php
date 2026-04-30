<?php

/**
 * OpenRegister WorkflowEngine Interface
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
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-81
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-90
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-92
 */

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
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-81
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-90
     */
    public function deployWorkflow(array $workflowDefinition): string;

    /**
     * Update an existing workflow definition in the engine.
     *
     * @param string               $workflowId         Engine-specific workflow ID
     * @param array<string, mixed> $workflowDefinition Updated workflow definition
     *
     * @return string Engine-specific workflow ID (may change on some engines)
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-81
     */
    public function updateWorkflow(string $workflowId, array $workflowDefinition): string;

    /**
     * Get the full workflow definition from the engine.
     *
     * @param string $workflowId Engine-specific workflow ID
     *
     * @return array<string, mixed> Engine-specific workflow definition
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-81
     */
    public function getWorkflow(string $workflowId): array;

    /**
     * Remove a workflow from the engine.
     *
     * @param string $workflowId Engine-specific workflow ID
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-81
     */
    public function deleteWorkflow(string $workflowId): void;

    /**
     * Activate a workflow so it can receive triggers.
     *
     * @param string $workflowId Engine-specific workflow ID
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-81
     */
    public function activateWorkflow(string $workflowId): void;

    /**
     * Deactivate a workflow.
     *
     * @param string $workflowId Engine-specific workflow ID
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-81
     */
    public function deactivateWorkflow(string $workflowId): void;

    /**
     * Execute a workflow synchronously and return the response.
     *
     * @param string               $workflowId Engine-specific workflow ID
     * @param array<string, mixed> $data       Data to pass to the workflow
     * @param int                  $timeout    Timeout in seconds (default 30)
     *
     * @return WorkflowResult Structured execution result
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-81
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-92
     */
    public function executeWorkflow(string $workflowId, array $data, int $timeout=30): WorkflowResult;

    /**
     * Get the webhook URL that triggers a specific workflow.
     *
     * @param string $workflowId Engine-specific workflow ID
     *
     * @return string Webhook URL
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-81
     */
    public function getWebhookUrl(string $workflowId): string;

    /**
     * List all workflows in the engine.
     *
     * @return array<int, array{id: string, name: string}> Array of workflow summaries
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-81
     */
    public function listWorkflows(): array;

    /**
     * Check engine health/connectivity.
     *
     * @return bool True if the engine is reachable and responsive
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-81
     */
    public function healthCheck(): bool;
}//end interface
