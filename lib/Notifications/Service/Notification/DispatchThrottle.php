<?php

/**
 * Per-rule, per-recipient throttling of a notification rule.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * The rate-limit and burst-coalescing gates, moved out of the dispatcher.
 *
 * A missing limiter or coalescer lets every delivery through.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class DispatchThrottle {
	/**
	 * Constructor.
	 *
	 * @param RateLimiter|null           $rateLimiter Per-rule, per-recipient rate limiter.
	 * @param NotificationCoalescer|null $coalescer   Burst coalescer.
	 */
	public function __construct(
		private readonly ?RateLimiter $rateLimiter = null,
		private readonly ?NotificationCoalescer $coalescer = null,
	) {
	}//end __construct()

	/**
	 * Whether the rule's rate budget lets this recipient through.
	 *
	 * @param RuleFiring $firing    The rule and its per-rule override.
	 * @param string     $recipient The recipient uid, or a broadcast pseudo-recipient.
	 *
	 * @return bool True when the delivery may proceed.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function rateAllows(RuleFiring $firing, string $recipient): bool {
		if ($this->rateLimiter === null) {
			return true;
		}

		return $this->rateLimiter->tryConsume(ruleId: $firing->ruleId, recipient: $recipient, perRuleOverride: $firing->rateLimit);
	}//end rateAllows()

	/**
	 * Whether the coalescer lets this recipient through (no burst duplicate).
	 *
	 * @param RuleFiring $firing    The rule and its per-rule override.
	 * @param string     $recipient The recipient uid, or a broadcast pseudo-recipient.
	 *
	 * @return bool True when the delivery may proceed.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function coalesceAllows(RuleFiring $firing, string $recipient): bool {
		if ($this->coalescer === null) {
			return true;
		}

		return $this->coalescer->shouldDispatch(bucket: new CoalesceBucket(ruleId: $firing->ruleId, recipient: $recipient), perRuleOverride: $firing->coalesce);
	}//end coalesceAllows()
}//end class
