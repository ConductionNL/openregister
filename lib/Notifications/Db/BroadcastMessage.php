<?php

/**
 * What a broadcast says: its one-line subject and its longer text.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-broadcast-reaches-every-user-once-recorded-req-nrg-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Db;

/**
 * What a broadcast says: its one-line subject and its longer text.
 *
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-broadcast-reaches-every-user-once-recorded-req-nrg-005
 */
final readonly class BroadcastMessage {
	/**
	 * Hold the values.
	 *
	 * @param string      $subject The one-line message.
	 * @param string|null $body    The longer text, when there is one.
	 */
	public function __construct(
		public string $subject,
		public ?string $body,
	) {
	}//end __construct()
}//end class
