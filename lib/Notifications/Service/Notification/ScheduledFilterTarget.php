<?php

/**
 * The object a scheduled filter is evaluated against, and the moment of the scan.
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
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use DateTimeImmutable;

/**
 * One object's data and the logical "now" of the scan pass.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class ScheduledFilterTarget {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $objectData Flat field map of the object.
	 * @param DateTimeImmutable    $now        Logical "now" for the scan pass.
	 */
	public function __construct(
		public readonly array $objectData,
		public readonly DateTimeImmutable $now,
	) {
	}//end __construct()
}//end class
