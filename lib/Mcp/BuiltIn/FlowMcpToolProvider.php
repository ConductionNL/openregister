<?php

/**
 * Exposes OpenRegister flows to AI agents as MCP tools.
 *
 * This is the MCP surface that is NOT redundant with an agent reaching MCP
 * itself. An agent node calls out to MCP servers agentically; this goes the
 * other way — it makes a flow a thing an agent can find and run. An agent can
 * list the flows on the instance and start one, so a flow becomes a callable
 * action rather than something only a person or a Nextcloud event can trigger.
 *
 * Running a flow queues it — the same as any trigger — rather than executing it
 * inline: the MCP call returns as soon as the run is recorded, and the worker
 * does the work off-request. The tool returns the run's uuid so the agent can
 * poll its status through the run-history surface.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\BuiltIn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-mcp/specs/flow-mcp/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\BuiltIn;

use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use UnexpectedValueException;

/**
 * MCP tools for listing and running flows.
 */
class FlowMcpToolProvider implements IMcpToolProvider
{
    /**
     * Constructor.
     *
     * @param FlowRunService $runner Queues a flow run.
     * @param FlowRunMapper  $mapper Reads recent runs (for listing / status).
     */
    public function __construct(
        private readonly FlowRunService $runner,
        private readonly FlowRunMapper $mapper
    ) {

    }//end __construct()

    /**
     * The owning app id.
     *
     * @return string The app id.
     */
    public function getAppId(): string
    {
        return 'openregister';

    }//end getAppId()

    /**
     * The tools this provider offers.
     *
     * @return list<array{id: string, name: string, description: string, inputSchema: array}> The tools.
     *
     * @spec openspec/changes/or-flow-mcp/specs/flow-mcp/spec.md
     */
    public function getTools(): array
    {
        return [
            [
                'id'          => 'openregister.runFlow',
                'name'        => 'Run a flow',
                'description' => 'Queue an OpenRegister flow against an object. Returns the run uuid; poll it with openregister.flowRunStatus.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'flowId'   => ['type' => 'string', 'description' => 'The id of the flow to run.'],
                        'uuid'     => ['type' => 'string', 'description' => 'The subject object uuid (optional for a subjectless flow).'],
                        'register' => ['type' => 'string', 'description' => 'The subject object register slug (optional).'],
                        'schema'   => ['type' => 'string', 'description' => 'The subject object schema slug (optional).'],
                    ],
                    'required'   => ['flowId'],
                ],
            ],
            [
                'id'          => 'openregister.flowRunStatus',
                'name'        => 'Get a flow run status',
                'description' => 'Read the status, per-step log and result items of a flow run by its uuid.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'runUuid' => ['type' => 'string', 'description' => 'The uuid returned by openregister.runFlow.'],
                    ],
                    'required'   => ['runUuid'],
                ],
            ],
        ];

    }//end getTools()

    /**
     * Invoke a flow tool.
     *
     * @param string               $toolId    The namespaced tool id.
     * @param array<string, mixed> $arguments The tool arguments.
     *
     * @return array<string, mixed> The result.
     *
     * @throws UnexpectedValueException On an unknown tool or a missing argument.
     *
     * @spec openspec/changes/or-flow-mcp/specs/flow-mcp/spec.md
     */
    public function invokeTool(string $toolId, array $arguments): array
    {
        if ($toolId === 'openregister.runFlow') {
            return $this->runFlow(arguments: $arguments);
        }

        if ($toolId === 'openregister.flowRunStatus') {
            return $this->flowRunStatus(arguments: $arguments);
        }

        throw new UnexpectedValueException(sprintf('Unknown flow tool "%s".', $toolId));

    }//end invokeTool()

    /**
     * Queue a flow run and return its uuid.
     *
     * @param array<string, mixed> $arguments The tool arguments.
     *
     * @return array<string, mixed> The queued run's uuid and status.
     *
     * @spec openspec/changes/or-flow-mcp/specs/flow-mcp/spec.md
     */
    private function runFlow(array $arguments): array
    {
        $flowId = trim((string) ($arguments['flowId'] ?? ''));
        if ($flowId === '') {
            throw new UnexpectedValueException('runFlow needs a flowId.');
        }

        $run = $this->runner->queue(
            flowId: $flowId,
            subject: [
                'uuid'     => (string) ($arguments['uuid'] ?? ''),
                'register' => (string) ($arguments['register'] ?? ''),
                'schema'   => (string) ($arguments['schema'] ?? ''),
            ],
            trigger: 'mcp'
        );

        return [
            'runUuid' => $run->getUuid(),
            'status'  => $run->getStatus(),
            'queued'  => true,
        ];

    }//end runFlow()

    /**
     * Read a run's status, log and items.
     *
     * @param array<string, mixed> $arguments The tool arguments.
     *
     * @return array<string, mixed> The run's public shape, or a not-found marker.
     *
     * @spec openspec/changes/or-flow-mcp/specs/flow-mcp/spec.md
     */
    private function flowRunStatus(array $arguments): array
    {
        $runUuid = trim((string) ($arguments['runUuid'] ?? ''));
        if ($runUuid === '') {
            throw new UnexpectedValueException('flowRunStatus needs a runUuid.');
        }

        try {
            $run = $this->mapper->findByUuid($runUuid);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return ['found' => false, 'runUuid' => $runUuid];
        }

        $data          = $run->jsonSerialize();
        $data['found'] = true;

        return $data;

    }//end flowRunStatus()
}//end class
