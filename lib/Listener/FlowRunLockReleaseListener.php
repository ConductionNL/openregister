<?php

/**
 * Releases a terminal run's object locks. Release layer 1.
 *
 * WHY A LISTENER AND NOT A NODE
 * -----------------------------
 * A release that depended on a flow reaching an unlock step would be no
 * release at all in the cases that matter: a run that failed, was stopped, or
 * was reaped after its worker died never reaches another step. Hooking the
 * terminal event instead means the release does not depend on the graph.
 *
 * WHY ONE HOOK COVERS EVERYTHING
 * ------------------------------
 * `FlowRunMapper::update()` dispatches `FlowRunTerminalEvent` whenever the
 * persisted row `isTerminal()`. That is a predicate, not a status whitelist,
 * so all four terminal statuses fire it (completed, stopped, failed,
 * dead_letter), and so does every reaper, because they all terminate through
 * the same `update()`.
 *
 * 🔴 The event can fire MORE THAN ONCE for one run, and it is dispatched
 * INSIDE `FlowRunCommit`'s open transaction on the stream-walk path. So this
 * listener is idempotent and never rethrows: a throw here would unwind the
 * run's own terminal write, and lock bookkeeping must not be able to do that.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-every-lock-a-run-holds-is-released-when-the-run-ends
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\FlowRunTerminalEvent;
use OCA\OpenRegister\Service\Object\RunLockRegistry;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Releases every object lock a terminal run holds.
 *
 * @template-implements IEventListener<FlowRunTerminalEvent>
 */
class FlowRunLockReleaseListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param RunLockRegistry $locks The run-lock lifecycle.
	 * @param LoggerInterface $logger Failure reporting.
	 */
	public function __construct(
		private readonly RunLockRegistry $locks,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Release the run's locks.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-every-lock-a-run-holds-is-released-when-the-run-ends
	 */
	public function handle(Event $event): void {
		if ($event instanceof FlowRunTerminalEvent === false) {
			return;
		}

		try {
			$released = $this->locks->releaseRunLocks(runUuid: $event->getRunUuid());
			if ($released > 0) {
				$this->logger->info(
					sprintf(
						'[FlowRunLockReleaseListener] Released %d object lock(s) of run %s (%s).',
						$released,
						$event->getRunUuid(),
						$event->getStatus()
					)
				);
			}
		} catch (Throwable $failure) {
			// Never rethrow: see the class docblock. The TTL and the sweep
			// remain as layers 3 and 2.
			$this->logger->error(
				'[FlowRunLockReleaseListener] Lock release failed: ' . $failure->getMessage(),
				['run' => $event->getRunUuid(), 'exception' => $failure]
			);
		}//end try
	}//end handle()
}//end class
