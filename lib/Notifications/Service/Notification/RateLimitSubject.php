<?php

/**
 * OpenRegister RateLimitSubject
 *
 * The rule and recipient one notification rate-limit bucket is kept for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
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
 * A stable rule identifier and the recipient a dispatch under that rule goes to.
 *
 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-8
 */
final class RateLimitSubject {
	/**
	 * Constructor.
	 *
	 * @param string $ruleId    Stable rule identifier (annotation key).
	 * @param string $recipient Recipient ID (uid, group id, webhook URL hash, etc.).
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-8
	 */
	public function __construct(
		public readonly string $ruleId,
		public readonly string $recipient,
	) {
	}//end __construct()
}//end class
