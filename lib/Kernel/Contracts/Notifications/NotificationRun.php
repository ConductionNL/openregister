<?php
/**
 * NotificationRun: the outcome of one notification rule evaluation, to be recorded.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Notifications
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/automation-run-records/design.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Notifications;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One evaluated notification rule, as its automation run record states it. Recipients are counted, never named.
 *
 * @spec openspec/changes/automation-run-records/design.md
 */
final class NotificationRun {

	/**
	 * Constructor.
	 *
	 * @param mixed             $key      The rule's declared key (anything but text counts as none).
	 * @param string            $name     The rule id.
	 * @param ObjectEntity|null $object   The object the rule fired for.
	 * @param string|null       $eventId  The stored event id, when any.
	 * @param string            $outcome  The run outcome.
	 * @param string|null       $reason   Why the run had that outcome, when stated.
	 * @param string|null       $message  The error that stopped the run, when any.
	 * @param string            $channels The per-channel counts, as JSON.
	 *
	 * @spec openspec/changes/automation-run-records/design.md
	 */
	public function __construct(
		public readonly mixed $key,
		public readonly string $name,
		public readonly ?ObjectEntity $object,
		public readonly ?string $eventId,
		public readonly string $outcome,
		public readonly ?string $reason,
		public readonly ?string $message,
		public readonly string $channels,
	) {
	}//end __construct()
}//end class
