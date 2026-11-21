<?php

/**
 * OpenRegister ResolvedInputs
 *
 * A decision table's declared inputs together with the caller's values coerced to them.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Dmn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/shared-decision-table-evaluator/specs/shared-decision-tables/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Dmn;

/**
 * The declared inputs, positionally aligned with a rule's input entries, and the coerced values keyed by name.
 *
 * @spec openspec/changes/shared-decision-table-evaluator/specs/shared-decision-tables/spec.md
 */
final class ResolvedInputs {

	/**
	 * Constructor.
	 *
	 * @param array<int, array{name: string, type: string}> $declared The declared inputs, in column order.
	 * @param array<string, mixed>                           $coerced  The coerced runtime values, keyed by input name.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $declared,
		public readonly array $coerced,
	) {
	}//end __construct()
}//end class
