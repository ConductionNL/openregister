<?php

/**
 * The property a brondatum is read from.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * The property, and the configuration key that named it, for the log line when it is missing or unreadable.
 *
 * @spec openspec/specs/retention-management/spec.md#requirement-the-system-must-calculate-archiefactiedatum-using-configurable-afleidingswijzen
 */
final class DateSource {
	/**
	 * Hold the values.
	 *
	 * @param string|null $property The date property, or null when none is configured.
	 * @param string      $label    The configuration key that names it.
	 */
	public function __construct(
		public readonly ?string $property,
		public readonly string $label,
	) {
	}//end __construct()
}//end class
