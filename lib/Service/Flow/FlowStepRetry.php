<?php

/**
 * OpenRegister FlowStepRetry
 *
 * The one reading of a step's `retry` declaration (flow-error-branch-and-step-retry D-1): how many
 * extra tries a failed step gets, how long the run sleeps before each, and whether the delay is
 * fixed or doubles per attempt. Read from the lowered step beside `onError`, the way
 * FlowErrorPolicy reads that, so every walk takes the same answer. The caps (10 attempts, one hour)
 * are what the save refuses above; a declaration that breaks them reads here as no retry, so a
 * stored flow that predates the check never retries without bound.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-error-branch-and-step-retry/specs/flow-engine/spec.md#requirement-a-failed-step-can-retry-itself-before-it-counts-as-failed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * A step's retry settings: extra attempts, the delay before each, and the backoff.
 *
 * @spec openspec/changes/flow-error-branch-and-step-retry/specs/flow-engine/spec.md#requirement-a-failed-step-can-retry-itself-before-it-counts-as-failed
 */
final class FlowStepRetry {

	/**
	 * The most extra attempts a step may declare.
	 *
	 * @var integer
	 */
	public const MAX_ATTEMPTS = 10;

	/**
	 * The longest delay a step may declare, in seconds.
	 *
	 * @var integer
	 */
	public const MAX_DELAY_SECONDS = 3600;

	/**
	 * The delay stays the same for every attempt.
	 *
	 * @var string
	 */
	public const BACKOFF_FIXED = 'fixed';

	/**
	 * The delay doubles per attempt.
	 *
	 * @var string
	 */
	public const BACKOFF_EXPONENTIAL = 'exponential';

	/**
	 * Constructor.
	 *
	 * @param integer $attempts     Extra tries after the first failure.
	 * @param integer $delaySeconds The delay before the first extra try.
	 * @param string  $backoff      `fixed` or `exponential`.
	 */
	private function __construct(
		public readonly int $attempts,
		public readonly int $delaySeconds,
		public readonly string $backoff,
	) {
	}//end __construct()

	/**
	 * The retry a lowered step declares, or null when it declares none or one the save would refuse.
	 *
	 * @param array<string, mixed>|null $step The lowered step.
	 *
	 * @return self|null The retry settings.
	 *
	 * @spec openspec/changes/flow-error-branch-and-step-retry/specs/flow-engine/spec.md#requirement-a-failed-step-can-retry-itself-before-it-counts-as-failed
	 */
	public static function for(?array $step): ?self {
		$retry = ($step['retry'] ?? null);
		if (is_array($retry) === false || self::faults(retry: $retry) !== []) {
			return null;
		}

		$attempts = (int)($retry['attempts'] ?? 0);
		if ($attempts === 0) {
			return null;
		}

		return new self(
			attempts: $attempts,
			delaySeconds: (int)($retry['delaySeconds'] ?? 0),
			backoff: (string)($retry['backoff'] ?? self::BACKOFF_FIXED)
		);
	}//end for()

	/**
	 * What is wrong with a declared `retry`, one line per fault, naming the key and its cap.
	 *
	 * @param mixed $retry The declared value.
	 *
	 * @return list<string> The faults; empty when the declaration is absent or sound.
	 *
	 * @spec openspec/changes/flow-error-branch-and-step-retry/specs/flow-engine/spec.md#requirement-a-failed-step-can-retry-itself-before-it-counts-as-failed
	 */
	public static function faults(mixed $retry): array {
		if ($retry === null) {
			return [];
		}

		if (is_array($retry) === false) {
			return ['retry must be an object with attempts, delaySeconds and backoff'];
		}

		return array_values(
			array_filter(
				[
					self::boundFault(key: 'attempts', value: ($retry['attempts'] ?? 0), max: self::MAX_ATTEMPTS),
					self::boundFault(key: 'delaySeconds', value: ($retry['delaySeconds'] ?? 0), max: self::MAX_DELAY_SECONDS),
					self::backoffFault(value: ($retry['backoff'] ?? self::BACKOFF_FIXED)),
				]
			)
		);
	}//end faults()

	/**
	 * Whether a step that has made this many extra tries may try once more.
	 *
	 * @param integer $made The extra tries made so far.
	 *
	 * @return boolean
	 *
	 * @spec openspec/changes/flow-error-branch-and-step-retry/specs/flow-engine/spec.md#requirement-a-failed-step-can-retry-itself-before-it-counts-as-failed
	 */
	public function allowsAnother(int $made): bool {
		return $made < $this->attempts;
	}//end allowsAnother()

	/**
	 * The delay before an extra try, numbered from 1.
	 *
	 * Fixed keeps the declared delay; exponential doubles it per try. Neither
	 * waits longer than the one-hour cap.
	 *
	 * @param integer $attempt The extra try, 1 for the first.
	 *
	 * @return integer The delay in seconds.
	 *
	 * @spec openspec/changes/flow-error-branch-and-step-retry/specs/flow-engine/spec.md#requirement-a-failed-step-can-retry-itself-before-it-counts-as-failed
	 */
	public function delayBefore(int $attempt): int {
		if ($this->backoff !== self::BACKOFF_EXPONENTIAL || $attempt <= 1) {
			return $this->delaySeconds;
		}

		$delay = $this->delaySeconds;
		for ($i = 1; $i < $attempt && $delay < self::MAX_DELAY_SECONDS; $i++) {
			$delay *= 2;
		}

		return min($delay, self::MAX_DELAY_SECONDS);
	}//end delayBefore()

	/**
	 * The fault of a whole-number setting outside 0..max, or null.
	 *
	 * @param string $key   The setting.
	 * @param mixed  $value Its declared value.
	 * @param int    $max   Its cap.
	 *
	 * @return string|null The fault.
	 */
	private static function boundFault(string $key, mixed $value, int $max): ?string {
		if (is_int($value) === false || $value < 0) {
			return 'retry.' . $key . ' must be a whole number from 0 to ' . $max;
		}

		if ($value > $max) {
			return 'retry.' . $key . ' is ' . $value . '; the cap is ' . $max;
		}

		return null;
	}//end boundFault()

	/**
	 * The fault of a backoff other than fixed or exponential, or null.
	 *
	 * @param mixed $value The declared backoff.
	 *
	 * @return string|null The fault.
	 */
	private static function backoffFault(mixed $value): ?string {
		if ($value === self::BACKOFF_FIXED || $value === self::BACKOFF_EXPONENTIAL) {
			return null;
		}

		return 'retry.backoff must be "fixed" or "exponential"';
	}//end backoffFault()
}//end class
