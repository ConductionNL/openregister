<?php

/**
 * One value to write at a dotted field path.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-decision-tables/spec.md#requirement-a-decision-table-step-evaluates-its-table-against-every-item
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * A rendered field path, optionally dotted, and the value to write there.
 *
 * Read by {@see FlowFieldPath::assign()}.
 *
 * @spec openspec/specs/flow-decision-tables/spec.md#requirement-a-decision-table-step-evaluates-its-table-against-every-item
 */
final class FieldAssignment {
	/**
	 * Constructor.
	 *
	 * @param string $path  The field path, optionally dotted.
	 * @param mixed  $value The value to write.
	 *
	 * @spec openspec/specs/flow-decision-tables/spec.md#requirement-a-decision-table-step-evaluates-its-table-against-every-item
	 */
	public function __construct(
		public readonly string $path,
		public readonly mixed $value,
	) {
	}//end __construct()
}//end class
