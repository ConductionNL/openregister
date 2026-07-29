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
use OCP\IUserSession;
use UnexpectedValueException;

/**
 * MCP tools for listing and running flows.
 */
class FlowMcpToolProvider implements IMcpToolProvider
{
    /**
     * Constructor.
     *
     * @param FlowRunService $runner      Queues a flow run.
     * @param FlowRunMapper  $mapper      Reads recent runs (for listing / status).
     * @param IUserSession   $userSession The invoking session — an MCP tool call always
     *                                    arrives inside one, and its user is the actor
     *                                    a queued run is attributed to.
     */
    public function __construct(
        private readonly FlowRunService $runner,
        private readonly FlowRunMapper $mapper,
        private readonly IUserSession $userSession
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
     * Both descriptors declare their MCP 2025-11-25 annotation hints and an
     * advisory `scope` (ADR-063, ConductionNL/openregister#2159) rather than
     * leaving a consumer to guess. Without them a consumer's fail-closed
     * fallback classifies BOTH tools as write/destructive, which puts a
     * read-only status poll behind the same approval prompt as starting a run.
     * `runFlow` is genuinely not read-only and not idempotent — each call
     * queues another run — and is marked destructive because the flow it
     * starts may do anything a flow can do, including delete. The hints are
     * advisory UX metadata only; OpenRegister RBAC remains the authoritative
     * invoke-time gate.
     *
     * @return list<array{id: string, name: string, description: string, inputSchema: array,
     *         readOnlyHint: bool, destructiveHint: bool, idempotentHint: bool, scope: string}> The tools.
     *
     * @spec openspec/changes/or-flow-mcp/specs/flow-mcp/spec.md
     */
    public function getTools(): array
    {
        return [
            [
                'id'              => 'openregister.runFlow',
                'name'            => 'Run a flow',
                'description'     => 'Queue an OpenRegister flow against an object. Returns the run uuid; poll it with openregister.flowRunStatus.',
                'inputSchema'     => [
                    'type'       => 'object',
                    'properties' => [
                        'flowId'   => ['type' => 'string', 'description' => 'The id of the flow to run.'],
                        'uuid'     => ['type' => 'string', 'description' => 'The subject object uuid (optional for a subjectless flow).'],
                        'register' => ['type' => 'string', 'description' => 'The subject object register slug (optional).'],
                        'schema'   => ['type' => 'string', 'description' => 'The subject object schema slug (optional).'],
                    ],
                    'required'   => ['flowId'],
                ],
                'readOnlyHint'    => false,
                'destructiveHint' => true,
                'idempotentHint'  => false,
                'scope'           => 'create',
            ],
            [
                'id'              => 'openregister.flowRunStatus',
                'name'            => 'Get a flow run status',
                'description'     => 'Read the status, per-step log and result items of a flow run by its uuid.',
                'inputSchema'     => [
                    'type'       => 'object',
                    'properties' => [
                        'runUuid' => ['type' => 'string', 'description' => 'The uuid returned by openregister.runFlow.'],
                    ],
                    'required'   => ['runUuid'],
                ],
                'readOnlyHint'    => true,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'scope'           => 'read',
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
     * The uid of the user whose session this tool call arrived in.
     *
     * An MCP tool call is not anonymous — it is served by a controller that
     * Nextcloud already resolved a session for, exactly like any other request.
     * That uid is the actor a queued run must be attributed to; without it the
     * run's `triggeredBy` is null and every downstream node that insists on an
     * owner (`ObjectWriteNode`, hermiq's agent node) refuses or degrades.
     *
     * Returns null rather than inventing an owner when there genuinely is no
     * session — fail closed, the same shape `FlowRunService::queue()` already
     * accepts from every other caller.
     *
     * @return string|null The acting user's uid, or null when there is no session user.
     *
     * @spec openspec/changes/or-flow-mcp/specs/flow-mcp/spec.md
     */
    private function actingUser(): ?string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        return $user->getUID();

    }//end actingUser()

    /**
     * Queue a flow run and return its uuid.
     *
     * The run is attributed to the acting session user, so the worker executes
     * it as a person rather than as nobody (ConductionNL/openregister#2158).
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
            trigger: 'mcp',
            user: $this->actingUser()
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
