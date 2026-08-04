<?php

/**
 * Loads flow documents and the objects their runs are about.
 *
 * This replaces the resolver layer. `IFlowResolver` + `FlowResolverRegistry`
 * existed for exactly one reason: flows were stored as OpenRegister objects, in
 * a different register per app, so something had to ask each app in turn "is
 * this one yours". With one native store that question has one answer, and the
 * indirection was buying nothing but a way for two apps to disagree about who
 * owned a flow id.
 *
 * The method names are unchanged from the registry deliberately, so the call
 * sites in the worker, the trigger service, the scheduler and the sub-flow node
 * change only their injected type.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The engine's read path into the flow store.
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */
class FlowLocator
{

    /**
     * Per-request memo of resolved flow documents, keyed by flow id.
     *
     * A single object write can fire several triggers that resolve the same
     * flow, and each resolution is a database read on a path that is held to a
     * sub-500ms budget. Null is memoised too, so a miss is not re-queried.
     *
     * @var array<string, array|null>
     */
    private array $flowMemo = [];

    /**
     * Constructor.
     *
     * @param FlowMapper      $mapper        Reads flow definitions.
     * @param ObjectService   $objectService Loads the object a run is about.
     * @param LoggerInterface $logger        Records refusals and failures.
     */
    public function __construct(
        private readonly FlowMapper $mapper,
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()

    /**
     * Load a flow document by its id.
     *
     * @param string $flowId The flow's uuid.
     *
     * @return array|null The flow document, or null when there is no such flow.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
     */
    public function resolveFlow(string $flowId): ?array
    {
        if (array_key_exists($flowId, $this->flowMemo) === true) {
            return $this->flowMemo[$flowId];
        }

        $this->flowMemo[$flowId] = null;

        try {
            $flow = $this->mapper->findByUuid($flowId);
        } catch (Throwable $e) {
            return null;
        }

        $document = [
            'id'            => (string) $flow->getUuid(),
            'name'          => (string) $flow->getName(),
            'nodes'         => ($flow->getNodes() ?? []),
            'edges'         => ($flow->getEdges() ?? []),
            'limits'        => ($flow->getLimits() ?? []),
            'executionMode' => (string) ($flow->getExecutionMode() ?? Flow::MODE_ASYNC),
            'owner'         => $flow->getOwner(),
            'organisation'  => $flow->getOrganisation(),
        ];

        $this->flowMemo[$flowId] = $document;

        return $document;

    }//end resolveFlow()

    /**
     * Load the object a run walks against.
     *
     * Loads with RBAC and multitenancy OFF, which is correct here and ONLY
     * here: a trigger fires with no acting user, so there is no session whose
     * permissions could be applied, and refusing would mean an object-created
     * trigger silently never fires for objects the (absent) user cannot read.
     *
     * This method is reachable from the queue worker and the trigger service —
     * both background paths — and from no controller. That boundary is the
     * whole safety argument: an unauthenticated load reached from a REQUEST is
     * an IDOR, and the same call is only defensible because no request can get
     * here. Anything that later routes a request into this method must apply
     * its own authorisation first.
     *
     * @param string $uuid     The subject uuid.
     * @param string $register The register slug.
     * @param string $schema   The schema slug.
     *
     * @return object|null The subject, or null when it cannot be found.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
     */
    public function resolveSubject(string $uuid, string $register, string $schema): ?object
    {
        if ($uuid === '' || $register === '' || $schema === '') {
            return null;
        }

        try {
            $object = $this->objectService->find(
                id: $uuid,
                register: $register,
                schema: $schema,
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            return null;
        }

        if (($object instanceof ObjectEntity) === true) {
            return $object;
        }

        return null;

    }//end resolveSubject()

    /**
     * The ids of the flows a fired event should start.
     *
     * A flow that matches the event but cannot dispatch is REFUSED OUT LOUD
     * rather than filtered away in SQL. The distinction matters: a flow that
     * silently disappears from this list is indistinguishable from "no flow was
     * interested", which is precisely how a misconfigured trigger reads as
     * working. An ownerless flow is the common case — a trigger has no acting
     * user, so the flow's owner is the only identity a run could execute as.
     *
     * @param string $event    The event id.
     * @param string $register The register the event fired on.
     * @param string $schema   The schema the event fired on.
     *
     * @return array<int, string> The ids of the flows to run.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
     */
    public function flowsForTrigger(string $event, string $register, string $schema): array
    {
        try {
            $candidates = $this->mapper->findByTrigger(
                trigger: $event,
                register: $register,
                schema: $schema
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[FlowLocator] Could not list flows for trigger "'.$event.'": '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return [];
        }

        $ids = [];
        foreach ($candidates as $flow) {
            if ($flow->canDispatch() === false) {
                $this->logger->warning(
                    message: '[FlowLocator] Flow "'.$flow->getUuid().'" matched trigger "'.$event
                        .'" but was not dispatched: it has no owner, so there is no identity to run it as.',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $flow->getUuid()]
                );
                continue;
            }

            $ids[] = (string) $flow->getUuid();
        }//end foreach

        return $ids;

    }//end flowsForTrigger()

    /**
     * The flows that run on a cron schedule.
     *
     * Shape is unchanged from the registry so `FlowScheduleService` is
     * untouched by this refactor.
     *
     * @return array<int, array{id: string, enabled: bool, trigger: string, cron: string, owner: string|null}>
     *         The candidate flows.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
     */
    public function scheduledFlows(): array
    {
        try {
            $flows = $this->mapper->findScheduled();
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[FlowLocator] Could not list scheduled flows: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return [];
        }

        $candidates = [];
        foreach ($flows as $flow) {
            if ($flow->canDispatch() === false) {
                $this->logger->warning(
                    message: '[FlowLocator] Scheduled flow "'.$flow->getUuid()
                        .'" was not dispatched: it has no owner, so there is no identity to run it as.',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $flow->getUuid()]
                );
                continue;
            }

            $candidates[] = [
                'id'      => (string) $flow->getUuid(),
                'enabled' => (bool) $flow->getEnabled(),
                'trigger' => (string) ($flow->getTrigger() ?? ''),
                'cron'    => (string) ($flow->getCron() ?? ''),
                'owner'   => $flow->getOwner(),
            ];
        }//end foreach

        return $candidates;

    }//end scheduledFlows()
}//end class
