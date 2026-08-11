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
use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
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
     * @param FlowRunService     $runner        Queues a flow run.
     * @param FlowRunMapper      $mapper        Reads recent runs (for listing / status).
     * @param FlowService        $flows         Authors flows, with the organisation scoping.
     * @param FlowNodePreflight  $preflight     Reports what a saved document is missing.
     * @param IUserSession       $userSession   The invoking session — an MCP tool
     *                                          call always
     * @param ObjectService|null $objectService Resolves the flow WITH RBAC for the run guard; nullable
     *                                          so adding it is not a fatal at existing construction sites.
     * @param IAppConfig|null    $appConfig     Reads the flow register/schema slugs.
     *                                          arrives inside one, and its user is the actor
     *                                          a queued run is attributed to.
     */
    public function __construct(
        private readonly FlowRunService $runner,
        private readonly FlowRunMapper $mapper,
        private readonly FlowService $flows,
        private readonly FlowNodePreflight $preflight,
        private readonly IUserSession $userSession,
        private readonly ?ObjectService $objectService=null,
        private readonly ?IAppConfig $appConfig=null
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
     *         readOnlyHint: bool, destructiveHint: bool, idempotentHint: bool, scope: string,
     *         reach: string}> The tools.
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
                // A flow can write objects other users read, and can drive an
                // outbound integration. `instance` is the floor, not a ceiling:
                // a consumer composing reach should take the MAX of this and
                // whatever the flow's own steps reach.
                'reach'           => 'instance',
            ],
            [
                'id'              => 'openregister.saveFlow',
                'name'            => 'Create or update a flow',
                'description'     => 'Author an OpenRegister flow: its nodes (each with a type and config) and the '
                    .'edges between them. Omit uuid to create, pass one to update. A flow needs at least one trigger '
                    .'node (openregister.trigger-manual, .trigger-schedule or .trigger-object) and at least one end '
                    .'node (openregister.end). A node carries the step; an edge carries only from/to — an edge with a '
                    .'type is refused. List the available node types first.',
                'inputSchema'     => [
                    'type'       => 'object',
                    'properties' => [
                        'uuid'        => ['type' => 'string', 'description' => 'The flow to update. Omit to create a new one.'],
                        'name'        => ['type' => 'string', 'description' => 'The flow name.'],
                        'description' => ['type' => 'string', 'description' => 'What the flow does.'],
                        'app'         => ['type' => 'string', 'description' => 'The owning app id (defaults to openregister).'],
                        'enabled'     => ['type' => 'boolean', 'description' => 'Whether triggers may fire it.'],
                        'nodes'       => [
                            'type'        => 'array',
                            'description' => 'The nodes. Each: {id, type, config, x?, y?}.',
                            'items'       => ['type' => 'object'],
                        ],
                        'edges'       => [
                            'type'        => 'array',
                            'description' => 'The connections. Each: {id, from, to}. NEVER a type.',
                            'items'       => ['type' => 'object'],
                        ],
                    ],
                    'required'   => ['name'],
                ],
                'readOnlyHint'    => false,
                'destructiveHint' => true,
                'idempotentHint'  => false,
                'scope'           => 'create',
                // Authoring a flow is authoring something that can later write
                // objects and call outbound integrations, so its reach is the
                // reach of what it may become — not the reach of one save.
                'reach'           => 'instance',
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
                // Polling a run the caller already started, through the same
                // RBAC as any other read. Nothing observes an effect, so `user`
                // — and declaring it matters: a consumer that fails closed on an
                // absent reach would otherwise gate the STATUS POLL of a flow it
                // had just been granted permission to start.
                'reach'           => 'user',
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

        if ($toolId === 'openregister.saveFlow') {
            return $this->saveFlow(arguments: $arguments);
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
    private function saveFlow(array $arguments): array
    {
        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '') {
            throw new UnexpectedValueException('saveFlow needs a name.');
        }

        // Through FlowService, never FlowMapper: the service is where the
        // organisation scoping, the owner stamping and the per-flow guard live,
        // and an MCP call is exactly the caller that must not bypass them.
        $uuid   = trim((string) ($arguments['uuid'] ?? ''));
        $target = null;
        if ($uuid !== '') {
            $target = $uuid;
        }

        $flow = $this->flows->save(
            data: [
                'name'        => $name,
                'description' => (string) ($arguments['description'] ?? ''),
                'app'         => (string) ($arguments['app'] ?? 'openregister'),
                'enabled'     => (bool) ($arguments['enabled'] ?? false),
                'nodes'       => (array) ($arguments['nodes'] ?? []),
                'edges'       => (array) ($arguments['edges'] ?? []),
            ],
            uuid: $target
        );

        // The preflight's findings come back with the flow. An agent that saved
        // a half-wired document otherwise learns nothing until the run silently
        // completes having done nothing — which is the failure this engine's
        // dead-end check exists to make visible.
        $report = $this->preflight->inspect(
            flow: [
                'nodes' => ($flow->getNodes() ?? []),
                'edges' => ($flow->getEdges() ?? []),
            ]
        );

        return [
            'uuid'     => $flow->getUuid(),
            'name'     => $flow->getName(),
            'enabled'  => $flow->getEnabled(),
            'nodes'    => count($flow->getNodes() ?? []),
            'edges'    => count($flow->getEdges() ?? []),
            'blocking' => $report['blocking'],
            'warnings' => $report['warnings'],
        ];

    }//end saveFlow()

    /**
     * Queue a run of a flow the caller may run.
     *
     * @param array<string, mixed> $arguments The tool arguments.
     *
     * @return array<string, mixed> The queued run.
     *
     * @throws UnexpectedValueException When no flowId is given.
     */
    private function runFlow(array $arguments): array
    {
        $flowId = trim((string) ($arguments['flowId'] ?? ''));
        if ($flowId === '') {
            throw new UnexpectedValueException('runFlow needs a flowId.');
        }

        // The third unguarded run entry point. An MCP tool call carries a real
        // user session, so the flow is resolved WITH RBAC and a caller who
        // cannot read it is refused — otherwise an agent could run any flow on
        // the instance by guessing an id.
        //
        // Running is an extension verb (core's bitmask has no `run`), so per
        // ADR-010 Rule 4 it is enforced here, at the endpoint that performs the
        // action, rather than by widening the RBAC vocabulary.
        $this->assertRunnable(flowId: $flowId);

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
     * Refuse unless the acting user may run this flow.
     *
     * `FlowResolverRegistry::resolveFlow()` loads with RBAC off — correct for the
     * engine, which runs a flow as its owner — so the check has to happen here,
     * where a real session exists.
     *
     * @param string $flowId The flow being run.
     *
     * @throws UnexpectedValueException When the caller may not run it.
     *
     * @return void
     */
    private function assertRunnable(string $flowId): void
    {
        // Resolved through FlowService — the SAME store the flow lives in.
        //
        // This used to look the flow up as an OpenRegister OBJECT, via
        // `ObjectService->find(register: 'flows', schema: 'flow')`. Flows are
        // not objects: they live in the native `oc_openregister_flows` table,
        // and the flow-storage spec forbids storing a definition as an object
        // outright. So the guard consulted a store no correctly-stored flow is
        // ever in, and answered "No such flow" for every one of them —
        // `openregister.runFlow` could only ever run a flow that violated the
        // spec.
        //
        // Found by using it: an agent authored a flow with `saveFlow`, then
        // `runFlow` refused to run the flow it had just created.
        //
        // `FlowService::find()` is organisation-scoped and throws for a flow
        // the caller may not see, which is exactly the check this needs — and
        // it is the same resolution `FlowService::run()` performs, so the guard
        // can no longer disagree with the thing it guards.
        try {
            $this->flows->find(uuid: $flowId);
        } catch (\Throwable $e) {
            // One message for "absent" and "not yours" alike, so this cannot be
            // used to discover which ids exist.
            throw new UnexpectedValueException('No such flow: '.$flowId);
        }
    }//end assertRunnable()

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
