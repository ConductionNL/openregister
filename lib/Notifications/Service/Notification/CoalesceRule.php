<?php

/**
 * OpenRegister CoalesceRule
 *
 * A rule's `coalesce` block: window length and optional event cap.
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
 * The `coalesce: {windowSeconds, maxEvents?}` settings of one rule.
 *
 * Each value is a positive integer or a string of digits; anything else
 * reads as absent (window 0 = no coalescing, no cap).
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class CoalesceRule {

	/**
	 * Window length in seconds (0 = disabled).
	 *
	 * @var integer
	 */
	public readonly int $windowSeconds;

	/**
	 * Max events per window (null = no cap).
	 *
	 * @var integer|null
	 */
	public readonly ?int $maxEvents;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $override The rule's `coalesce` config block.
	 */
	public function __construct(array $override) {
		$this->windowSeconds = ($this->positiveInt(value: ($override['windowSeconds'] ?? null)) ?? 0);
		$this->maxEvents     = $this->positiveInt(value: ($override['maxEvents'] ?? null));
	}//end __construct()

	/**
	 * Whether an event fires and opens a fresh window: the window is closed
	 * (or never opened), or this event reaches `maxEvents` inside it.
	 *
	 * @param array{count:int,opened:int} $state The bucket's current state.
	 * @param integer                     $now   The current unix timestamp.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function startsNewWindow(array $state, int $now): bool {
		if ($state['count'] === 0 || ($now - $state['opened']) >= $this->windowSeconds) {
			return true;
		}

		return $this->maxEvents !== null && ($state['count'] + 1) >= $this->maxEvents;
	}//end startsNewWindow()

	/**
	 * A positive integer, or a string of digits holding one; null otherwise.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return integer|null
	 */
	private function positiveInt(mixed $value): ?int {
		if (is_string($value) === true && ctype_digit($value) === true) {
			$value = (int)$value;
		}

		if (is_int($value) === true && $value > 0) {
			return $value;
		}

		return null;
	}//end positiveInt()
}//end class
