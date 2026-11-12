<?php

/**
 * OpenRegister ConflictVeto
 *
 * The one way a delete guard refuses: the refusal and its 409 on the event,
 * and the event stopped.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener\Support;

use OCA\OpenRegister\Event\ObjectDeletingEvent;

/**
 * Stops a delete as a conflict with the row's state.
 */
trait ConflictVeto {

	/**
	 * Stop the delete, carrying the refusal and its 409.
	 *
	 * The errors read `code`, `message`, `status` and then the figures behind
	 * the refusal, in that order.
	 *
	 * @param ObjectDeletingEvent  $event   The delete event.
	 * @param array<string,mixed> $refusal The `code` and `message` of the refusal.
	 * @param array<string,mixed> $detail  The figures behind the refusal.
	 *
	 * @return void
	 */
	private function vetoAsConflict(ObjectDeletingEvent $event, array $refusal, array $detail): void {
		// A referenced or locked row refused is a conflict, not a malformed
		// body; the delete handler reads this and answers 409.
		$event->setErrors(array_merge($refusal, ['status' => 409], $detail));
		$event->stopPropagation();
	}//end vetoAsConflict()
}//end trait
