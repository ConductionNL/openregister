<?php

/**
 * OpenRegister CoalesceBucket
 *
 * The (rule, recipient) pair a coalesce window is counted for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
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

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * One coalesce bucket: its cache key and how a silenced event is logged.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class CoalesceBucket {

	/**
	 * Constructor.
	 *
	 * @param string $ruleId    Stable rule identifier (annotation key).
	 * @param string $recipient Recipient ID (uid, `__webhook__`, etc.).
	 */
	public function __construct(
		public readonly string $ruleId,
		public readonly string $recipient,
	) {
	}//end __construct()

	/**
	 * The cache key of the bucket.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function key(): string {
		// NOSEMGREP: php.lang.security.weak-crypto.weak-crypto -- The hash only builds a cache, lock or settings key, not a security value.
		return 'coalesce:'.sha1($this->ruleId.'|'.$this->recipient);
	}//end key()

	/**
	 * The info line for an event silenced inside the window.
	 *
	 * @param integer $count         The event count after this event.
	 * @param integer $windowSeconds The window length.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function silencedMessage(int $count, int $windowSeconds): string {
		return sprintf(
			'[NotificationCoalescer] silenced rule="%s" recipient="%s" count=%d windowSeconds=%d',
			$this->ruleId,
			$this->recipient,
			$count,
			$windowSeconds
		);
	}//end silencedMessage()
}//end class
