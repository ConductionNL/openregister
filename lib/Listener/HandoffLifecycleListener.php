<?php

/**
 * OpenRegister Handoff Lifecycle Listener
 *
 * Executes `trigger: lifecycle:<state>` handoffs (ADR-051) when an object
 * transitions into the declared state, invoking the same
 * {@see \OCA\OpenRegister\Service\Handoff\HandoffService::execute()} used by
 * the manual REST trigger. V1 gates lifecycle handoffs to transitions
 * performed by a REAL actor — the transitioning user is the handoff actor
 * (their session RBAC applies); system-applied transitions (null user) do
 * not fire handoffs, so there is no system-user privilege lane.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Exception\HandoffException;
use OCA\OpenRegister\Service\Handoff\HandoffService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Fire declared lifecycle-triggered handoffs on state transitions.
 *
 * @template-implements IEventListener<ObjectTransitionedEvent>
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 *   (Requirement: `x-openregister-handoff` declarative dialect)
 */
class HandoffLifecycleListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param HandoffService  $handoffService The handoff engine.
     * @param SchemaMapper    $schemaMapper   Loads the transitioned object's schema.
     * @param LoggerInterface $logger         Structured logging.
     */
    public function __construct(
        private readonly HandoffService $handoffService,
        private readonly SchemaMapper $schemaMapper,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()

    /**
     * Execute every handoff the schema declares for `lifecycle:<to-state>`.
     *
     * A hide-mode provider-unavailable outcome is silent (the transition must
     * never fail because a peer app is absent); queue mode parks through the
     * engine's normal degradation path. Any other handoff failure is logged
     * and swallowed — the state transition itself has already happened.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
     *   (Requirement: `x-openregister-handoff` declarative dialect)
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectTransitionedEvent) === false) {
            return;
        }

        // V1: only transitions performed by a real actor fire handoffs.
        if ($event->getUserId() === null) {
            return;
        }

        $object = $event->getObject();

        try {
            $schema = $this->schemaMapper->find(id: (string) $object->getSchema());
        } catch (\Throwable $e) {
            return;
        }

        $wantedTrigger = 'lifecycle:'.$event->getTo();
        foreach ($this->handoffService->declaredHandoffs(schema: $schema) as $entry) {
            if (($entry['trigger'] ?? 'manual') !== $wantedTrigger) {
                continue;
            }

            $handoffId = (string) ($entry['id'] ?? '');

            try {
                $this->handoffService->execute(
                    register: (string) $object->getRegister(),
                    schema: (string) $object->getSchema(),
                    id: (string) $object->getUuid(),
                    handoffId: $handoffId
                );
            } catch (HandoffException $e) {
                // Hide-mode degradation: the transition stays intact and the
                // object keeps working standalone; nothing to surface here.
                $this->logger->debug(
                    message: '[HandoffLifecycleListener] Lifecycle handoff degraded: '.$e->getMessage(),
                    context: ['file' => __FILE__, 'line' => __LINE__, 'handoffId' => $handoffId]
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    message: '[HandoffLifecycleListener] Lifecycle handoff failed: '.$e->getMessage(),
                    context: [
                        'file'      => __FILE__,
                        'line'      => __LINE__,
                        'handoffId' => $handoffId,
                        'object'    => (string) $object->getUuid(),
                    ]
                );
            }//end try
        }//end foreach

    }//end handle()
}//end class
