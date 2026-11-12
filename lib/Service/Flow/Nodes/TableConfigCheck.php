<?php

/**
 * One check of a decision-table step's configuration against the table it
 * resolves to: the state the refusal helpers share.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-decision-tables/spec.md#requirement-a-table-the-evaluator-cannot-execute-is-refused-at-save
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

/**
 * The step configuration and its resolved table, while the configuration is checked.
 *
 * @spec openspec/specs/flow-decision-tables/spec.md#requirement-a-table-the-evaluator-cannot-execute-is-refused-at-save
 */
final class TableConfigCheck {
	/**
	 * Describe the check.
	 *
	 * @param array $config The step configuration.
	 * @param array $table  The resolved table definition.
	 *
	 * @spec openspec/specs/flow-decision-tables/spec.md#requirement-a-table-the-evaluator-cannot-execute-is-refused-at-save
	 */
	public function __construct(
		public readonly array $config,
		public readonly array $table,
	) {
	}//end __construct()
}//end class
