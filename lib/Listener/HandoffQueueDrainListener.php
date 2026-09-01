<?php

/**
 * OpenRegister Handoff Queue Drain Listener
 *
 * Event-driven drain triggers for `whenUnavailable: queue` handoffs
 * (ADR-051): when a schema is saved (its implemented types — including
 * `allOf` ancestors, via the resolver — may now cover a parked kind) or an
 * app is (re-)enabled (its lingering schemas become resolvable again), sweep
 * the parked queue and execute every entry whose kind now resolves. The
 * fallback {@see \OCA\OpenRegister\BackgroundJob\HandoffQueueDrainJob} catches paths
 * that bypass these events (e.g. register import).
 *
 * The sweep is cheap when nothing is parked (one indexed SELECT), so running
 * it synchronously on schema-save is acceptable.
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

use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Service\Handoff\HandoffService;
use OCP\App\Events\AppEnableEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Drain parked queue-mode handoffs when a provider may have appeared.
 *
 * @template-implements IEventListener<SchemaCreatedEvent|SchemaUpdatedEvent|AppEnableEvent>
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 *   (Scenario: No provider installed, queue mode)
 */
class HandoffQueueDrainListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param HandoffService $handoffService The handoff engine (drain surface).
	 * @param LoggerInterface $logger Structured logging.
	 */
	public function __construct(
		private readonly HandoffService $handoffService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle a schema-save or app-enable event by sweeping the parked queue.
	 *
	 * Every parked entry whose kind now resolves (the drain re-checks through
	 * `SemanticTypeResolver`, which covers `allOf`-ancestor inheritance) is
	 * executed as its original requester; unresolvable kinds stay parked.
	 * Never lets a drain failure break the triggering save/enable.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: No provider installed, queue mode)
	 */
	public function handle(Event $event): void {
		$isDrainTrigger = ($event instanceof SchemaCreatedEvent
			|| $event instanceof SchemaUpdatedEvent
			|| $event instanceof AppEnableEvent);
		if ($isDrainTrigger === false) {
			return;
		}

		try {
			$summary = $this->handoffService->drainParked();
			if ($summary['drained'] > 0 || $summary['failed'] > 0) {
				$this->logger->info(
					message: '[HandoffQueueDrainListener] Drained parked handoffs after ' . get_class($event),
					context: ['file' => __FILE__, 'line' => __LINE__, 'summary' => $summary]
				);
			}
		} catch (\Throwable $e) {
			// The triggering save/enable must never fail because of a drain error.
			$this->logger->warning(
				message: '[HandoffQueueDrainListener] Drain sweep failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'event' => get_class($event)]
			);
		}

	}//end handle()
}//end class
