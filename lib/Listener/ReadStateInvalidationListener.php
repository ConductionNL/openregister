<?php

/**
 * A substantive change puts an object back to unread for everybody but its author.
 *
 * The listener is deliberately a wire and nothing else: it asks
 * SubstantiveChangeEvaluator whether the write was news and asks ReadStateService
 * to invalidate. Both answers live in exactly one place, so the badge cannot
 * disagree with itself between the write path and the read path.
 *
 * Best-effort: a failure to invalidate never blocks a save. The cost of the
 * failure is a badge that does not light up, which is strictly better than a
 * write that does not land.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Interaction\ReadStateService;
use OCA\OpenRegister\Service\Interaction\SubstantiveChangeEvaluator;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Invalidates read states on a substantive object change.
 *
 * @template-implements IEventListener<ObjectUpdatedEvent>
 */
final class ReadStateInvalidationListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ReadStateService $readState The read-state primitive, which owns the write.
	 * @param SubstantiveChangeEvaluator $evaluator Decides whether the change was news.
	 * @param IUserSession $userSession Names the actor, whose own read state survives.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly ReadStateService $readState,
		private readonly SubstantiveChangeEvaluator $evaluator,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle the event by invalidating every read state but the actor's.
	 *
	 * @param Event $event Dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatedEvent) === false) {
			return;
		}

		try {
			$new = $event->getNewObject();
			$uuid = (string)$new->getUuid();
			if ($uuid === '') {
				return;
			}

			if ($this->evaluator->isSubstantive(new: $new, old: $event->getOldObject()) === false) {
				// A technical touch or a recomputed value. Nobody has anything
				// new to see, so nobody's badge lights up.
				return;
			}

			$this->readState->invalidate(
				objectUuid: $uuid,
				actorUid: $this->userSession->getUser()?->getUID()
			);
		} catch (\Throwable $e) {
			$this->logger->debug(
				sprintf('[ReadStateInvalidationListener] invalidation skipped: %s', $e->getMessage())
			);
		}//end try

	}//end handle()
}//end class
