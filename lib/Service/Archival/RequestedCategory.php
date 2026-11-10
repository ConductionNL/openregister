<?php

/**
 * The selectielijst category a record asks for through its override property.
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
 * The override property the schema declares, and the value the record gives it.
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
final class RequestedCategory {
	/**
	 * Hold the values.
	 *
	 * @param string|null $property The override property, or null when none is declared.
	 * @param mixed       $value    The requested override as requested() reads it, or null when the record asks for none.
	 */
	public function __construct(
		public readonly ?string $property,
		public readonly mixed $value,
	) {
	}//end __construct()
}//end class
