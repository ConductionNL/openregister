<?php

/**
 * A survey's answers as a table: a title, the column labels and the rows.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Survey
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-006-answers-export-as-rows
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Survey;

/**
 * One row per answer set, keyed by column; the columns are their own labels.
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-006-answers-export-as-rows
 */
final class SurveyAnswerTable {

	/**
	 * Hold one table.
	 *
	 * @param string                           $title   The survey's title.
	 * @param list<string>                     $columns The columns, in order.
	 * @param array<int, array<string, mixed>> $rows    The rows, each keyed by column.
	 */
	public function __construct(
		public readonly string $title,
		public readonly array $columns,
		public readonly array $rows,
	) {
	}//end __construct()
}//end class
