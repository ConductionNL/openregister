<?php

/**
 * A value a form mapping fixes into one destination property.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

/**
 * One entry of a mapping's `fixed` block: the property and the value written into it.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
 */
final class FixedValue {
	/**
	 * Constructor.
	 *
	 * @param string $property The destination property.
	 * @param mixed  $value    The value fixed into it.
	 */
	public function __construct(
		public readonly string $property,
		public readonly mixed $value,
	) {
	}//end __construct()
}//end class
