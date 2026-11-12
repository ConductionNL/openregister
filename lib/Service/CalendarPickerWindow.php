<?php

/**
 * OpenRegister CalendarPickerWindow
 *
 * Which events of a calendar the link picker lists.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use DateTimeInterface;

/**
 * The earliest start and the maximum number of events the picker lists.
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */
final class CalendarPickerWindow {
	/**
	 * Constructor.
	 *
	 * @param DateTimeInterface|null $after Only include events with dtstart >= $after; null for no lower bound.
	 * @param int|null               $limit Max rows; null or 0 for no cap.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function __construct(
		public readonly ?DateTimeInterface $after = null,
		public readonly ?int $limit = 100,
	) {
	}//end __construct()
}//end class
