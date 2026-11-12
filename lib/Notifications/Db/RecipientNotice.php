<?php

/**
 * RecipientNotice: one notification history row, as the recipient it belongs to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-notification-may-be-snoozed-or-archived-and-the-list-has-an-axis-req-ors-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Db;

/**
 * The history row id and the uid the notice is addressed to; a write touches
 * the row only when both match.
 *
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-notification-may-be-snoozed-or-archived-and-the-list-has-an-axis-req-ors-004
 */
final class RecipientNotice {
	/**
	 * Constructor.
	 *
	 * @param integer $id        The history row id.
	 * @param string  $recipient The uid the notice is addressed to.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $recipient,
	) {
	}//end __construct()
}//end class
