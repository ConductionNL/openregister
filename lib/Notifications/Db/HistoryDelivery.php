<?php

/**
 * HistoryDelivery: one delivery attempt a history row records.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Notifications\Db
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

namespace OCA\OpenRegister\Notifications\Db;

/**
 * Which rule delivered over which channel to whom, and how it went.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class HistoryDelivery {

	/**
	 * Constructor.
	 *
	 * @param string $ruleId    The annotation key.
	 * @param string $channel   The channel that fired.
	 * @param string $recipient Recipient identifier (uid or `__webhook__`/`__talk__`).
	 * @param string $status    `dispatched` | `rate-limited` | `failed`.
	 */
	public function __construct(
		public readonly string $ruleId,
		public readonly string $channel,
		public readonly string $recipient,
		public readonly string $status,
	) {
	}//end __construct()
}//end class
