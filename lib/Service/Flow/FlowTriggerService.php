<?php

/**
 * Turns a Nextcloud event into queued flow runs.
 *
 * This is the trigger side of the engine. When something happens — an object is
 * created, a file changes, a share is declined — this service asks every
 * resolver which of its flows are wired to that event, and queues a run for
 * each. It does NOT execute them: a trigger fires inside the dispatch of the
 * thing that caused it (often a user's save), and an arbitrary graph must not
 * sit on that critical path. The FlowRunWorker executes the queue off-request.
 *
 * A Nextcloud-native trigger is the differentiator the whole programme rests
 * on: an external automation tool sees Nextcloud over WebDAV and a generic
 * connector; a flow triggered by a *share being declined*, with the sharee's
 * identity and the instance's RBAC already resolved, is not something that tool
 * can reproduce.
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
 * @spec openspec/changes/or-flow-triggers/specs/flow-triggers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Queues a flow run for every flow wired to a fired event.
 */
class FlowTriggerService
{
    /**
     * Constructor.
     *
     * @param FlowResolverRegistry $resolvers Finds flows wired to an event.
     * @param FlowRunService       $runner    Queues the runs.
     * @param LoggerInterface      $logger    The logger.
     */
    public function __construct(
        private readonly FlowResolverRegistry $resolvers,
        private readonly FlowRunService $runner,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()

    /**
     * Fire an event: queue a run for every flow wired to it.
     *
     * Never throws into the caller. A trigger runs inside the dispatch of a user
     * action (a save, a share change); a failure to queue must not break that
     * action, so it is logged and swallowed.
     *
     * @param string      $event   The event id (e.g. `object.created`).
     * @param array       $subject `{uuid, register, schema}` of the object, when there is one.
     * @param string|null $user    The user whose action fired the event.
     * @param array       $context Extra run-level metadata (the event payload, say).
     *
     * @return int The number of runs queued.
     *
     * @spec openspec/changes/or-flow-triggers/specs/flow-triggers/spec.md
     */
    public function fire(string $event, array $subject=[], ?string $user=null, array $context=[]): int
    {
        try {
            $register = (string) ($subject['register'] ?? '');
            $schema   = (string) ($subject['schema'] ?? '');
            $flowIds  = $this->resolvers->flowsForTrigger($event, $register, $schema);
            if ($flowIds === []) {
                return 0;
            }

            $queued = 0;
            foreach ($flowIds as $flowId) {
                $this->runner->queue(
                    flowId: $flowId,
                    subject: $subject,
                    trigger: $event,
                    context: $context,
                    user: $user
                );
                $queued++;
            }

            $this->logger->debug(
                message: '[FlowTriggerService] Queued flow runs for an event',
                context: ['file' => __FILE__, 'line' => __LINE__, 'event' => $event, 'queued' => $queued]
            );

            return $queued;
        } catch (Throwable $e) {
            $this->logger->error(
                message: '[FlowTriggerService] Failed to queue flow runs for an event: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__, 'event' => $event]
            );

            return 0;
        }//end try

    }//end fire()
}//end class
