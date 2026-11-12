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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Exception\HandoffException;
use OCA\OpenRegister\Service\Handoff\HandoffServiceInterface;
use OCA\OpenRegister\Listener\Support\EventSchemaLookup;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Fire declared lifecycle-triggered handoffs on state transitions.
 *
 * @template-implements IEventListener<ObjectTransitionedEvent>
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 *   (Requirement: `x-openregister-handoff` declarative dialect)
 */
class HandoffLifecycleListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param HandoffServiceInterface $handoffService The handoff engine.
	 * @param EventSchemaLookup $lookup Resolves the object's schema and logs a failure.
	 */
	public function __construct(
		private readonly HandoffServiceInterface $handoffService,
		private readonly EventSchemaLookup $lookup,
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
	public function handle(Event $event): void {
		// V1: only transitions performed by a real actor fire handoffs.
		if ($event instanceof ObjectTransitionedEvent && $event->getUserId() !== null) {
			$this->executeFor(event: $event);
		}

	}//end handle()

	/**
	 * Execute the handoffs declared for an actor's transition.
	 *
	 * @param ObjectTransitionedEvent $event The transition.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 */
	private function executeFor(ObjectTransitionedEvent $event): void {
		$schema = $this->schemaOf(object: $event->getObject());
		if ($schema !== null) {
			$this->executeDeclared(schema: $schema, event: $event);
		}
	}//end executeFor()

	/**
	 * The transitioned object's schema, or null when it cannot be loaded.
	 *
	 * @param ObjectEntity $object The transitioned object.
	 *
	 * @return Schema|null The schema.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 */
	private function schemaOf(ObjectEntity $object): ?Schema {
		try {
			return $this->lookup->catalog->schemas()->find(id: (string)$object->getSchema());
		} catch (\Throwable $e) {
			return null;
		}
	}//end schemaOf()

	/**
	 * Execute every handoff the schema declares for the transition's target state.
	 *
	 * @param Schema                  $schema The object's schema.
	 * @param ObjectTransitionedEvent $event  The transition.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 */
	private function executeDeclared(Schema $schema, ObjectTransitionedEvent $event): void {
		$wantedTrigger = 'lifecycle:' . $event->getTo();
		foreach ($this->handoffService->declaredHandoffs(schema: $schema) as $entry) {
			if (($entry['trigger'] ?? 'manual') === $wantedTrigger) {
				$this->executeHandoff(object: $event->getObject(), handoffId: (string)($entry['id'] ?? ''));
			}
		}
	}//end executeDeclared()

	/**
	 * Execute one declared handoff, logging and swallowing any failure.
	 *
	 * @param ObjectEntity $object    The transitioned object.
	 * @param string       $handoffId The declared handoff's id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 */
	private function executeHandoff(ObjectEntity $object, string $handoffId): void {
		try {
			$this->handoffService->execute(
				register: (string)$object->getRegister(),
				schema: (string)$object->getSchema(),
				id: (string)$object->getUuid(),
				handoffId: $handoffId
			);
		} catch (HandoffException $e) {
			$this->logDegraded(handoffId: $handoffId, error: $e);
		} catch (\Throwable $e) {
			$this->logFailure(error: $e, context: ['handoffId' => $handoffId, 'object' => (string)$object->getUuid()]);
		}
	}//end executeHandoff()

	/**
	 * Note a hide-mode degradation: the transition stays intact and the object
	 * keeps working standalone; nothing to surface.
	 *
	 * @param string            $handoffId The declared handoff's id.
	 * @param HandoffException  $error     The degradation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 */
	private function logDegraded(string $handoffId, HandoffException $error): void {
		$this->lookup->logger->debug(
			message: '[HandoffLifecycleListener] Lifecycle handoff degraded: ' . $error->getMessage(),
			context: ['file' => __FILE__, 'line' => __LINE__, 'handoffId' => $handoffId]
		);
	}//end logDegraded()

	/**
	 * Log a handoff that failed for a reason other than degradation.
	 *
	 * @param \Throwable            $error   The failure.
	 * @param array<string, string> $context The declared handoff's id and the transitioned object's uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 */
	private function logFailure(\Throwable $error, array $context): void {
		$this->lookup->logger->warning(
			message: '[HandoffLifecycleListener] Lifecycle handoff failed: ' . $error->getMessage(),
			context: ['file' => __FILE__, 'line' => __LINE__] + $context
		);
	}//end logFailure()
}//end class
