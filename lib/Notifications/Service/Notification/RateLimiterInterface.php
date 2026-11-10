<?php

/**
 * RateLimiterInterface: the public surface of RateLimiter, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notifications
 * @package  OCA\OpenRegister\Notifications\Service\Notification
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
 * Every public method of RateLimiter. Callers depend on this interface, not on the class.
 */
interface RateLimiterInterface {

	/**
	 * Try to consume one token for the given (rule, recipient).
	 *
	 * Returns true when the dispatch may proceed. Returns false when
	 * the bucket is empty — the caller MUST then skip the dispatch.
	 * Logs an info-level entry on drop so operators can grep
	 * "[NotificationRateLimiter] dropped".
	 *
	 * Fails open: when the limiter is disabled, the cache is
	 * unavailable, or a state read fails — return true so a broken
	 * limiter never blocks legitimate notifications.
	 *
	 * @param string $ruleId Stable rule identifier (annotation key).
	 * @param string $recipient Recipient ID (uid, group id, webhook URL hash, etc.).
	 * @param array<string, mixed>|null $perRuleOverride Optional `rateLimit` block from the rule spec.
	 *
	 * @return bool True when the dispatch may proceed.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-8
	 */
	public function tryConsume(string $ruleId, string $recipient, ?array $perRuleOverride = null): bool;
}//end interface
