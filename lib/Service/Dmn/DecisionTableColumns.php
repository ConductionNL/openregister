<?php

/**
 * OpenRegister DecisionTableColumns
 *
 * The declared columns every rule row of a decision table is checked against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Dmn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/flow-decision-tables/spec.md#requirement-a-table-the-evaluator-cannot-execute-is-refused-at-save
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Dmn;

/**
 * The declared columns every rule row of a decision table is checked against.
 *
 * The validated inputs and the output count travel together through the rule
 * checks of {@see DecisionTableValidator}, so they are passed as one.
 *
 * @spec openspec/specs/flow-decision-tables/spec.md#requirement-a-table-the-evaluator-cannot-execute-is-refused-at-save
 */
final class DecisionTableColumns {

	/**
	 * Name the columns.
	 *
	 * @param array<int, array{name: string, type: string}> $inputs      The declared inputs, positionally.
	 * @param int                                           $outputCount The declared output count.
	 *
	 * @spec openspec/specs/flow-decision-tables/spec.md#requirement-a-table-the-evaluator-cannot-execute-is-refused-at-save
	 */
	public function __construct(
		public readonly array $inputs,
		public readonly int $outputCount,
	) {
	}//end __construct()
}//end class
