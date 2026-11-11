<?php

/**
 * DecisionTableValidatorInterface: the decision-table validator as the decision-table validation listener reaches it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Dmn
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

namespace OCA\OpenRegister\Service\Dmn;

/**
 * The decision-table validator, typed for callers outside the Service component.
 *
 * Its only outside caller, the decision-table validation listener, calls validate(),
 * so that is the whole contract. Binding it in ContractBindings lets that caller
 * resolve the validator without naming the class.
 *
 * @spec openspec/specs/flow-decision-tables/spec.md#requirement-a-table-the-evaluator-cannot-execute-is-refused-at-save
 */
interface DecisionTableValidatorInterface {
	/**
	 * Validate a decision-table definition.
	 *
	 * @param array<string, mixed> $table The table definition (`hitPolicy`, `inputs`, `outputs`, `rules`).
	 *
	 * @return array<int, string> Problems, each naming the offending part. Empty means the evaluator can execute the table.
	 *
	 * @spec openspec/specs/flow-decision-tables/spec.md#requirement-a-table-the-evaluator-cannot-execute-is-refused-at-save
	 */
	public function validate(array $table): array;
}//end interface
