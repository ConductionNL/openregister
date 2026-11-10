<?php

/**
 * OpenRegister decision-table validation run.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/flow-decision-tables/spec.md#requirement-a-table-the-evaluator-cannot-execute-is-refused-at-save
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Dmn;

/**
 * The state of one DecisionTableValidator::validate() call (ADR-011 Rule 3).
 *
 * The validator's helpers share the table, the problems found so far, the
 * validated columns and the rule being checked. This run carries them, as
 * CascadeRun does for SaveObject. It never leaves DecisionTableValidator.
 *
 * @spec openspec/specs/flow-decision-tables/spec.md#requirement-a-table-the-evaluator-cannot-execute-is-refused-at-save
 */
final class TableValidationRun {

	/**
	 * The problems found so far, each naming the offending part.
	 *
	 * @var array<int, string>
	 */
	public array $problems = [];

	/**
	 * The validated input columns, positionally, and the output count. Set
	 * once the columns are read, before any rule is checked.
	 *
	 * @var DecisionTableColumns
	 */
	public DecisionTableColumns $columns;

	/**
	 * The id (or `#<position>`) of the rule being checked, for the messages.
	 *
	 * @var string
	 */
	public string $ruleId = '';

	/**
	 * Constructor. No column is known until the table's columns are read.
	 *
	 * @param array<string, mixed> $table The table definition being validated.
	 */
	public function __construct(
		public readonly array $table,
	) {
		$this->columns = new DecisionTableColumns(inputs: [], outputCount: 0);
	}//end __construct()
}//end class
