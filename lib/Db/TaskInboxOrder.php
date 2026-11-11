<?php

/**
 * How an inbox read is sorted.
 *
 * Part of one inbox read's {@see TaskInboxCriteria}.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * The sort key, its direction, and whether open tasks come first.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) sortDescending and openFirst
 * are VALUES carried into the ORDER BY, not behaviour switches.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
 */
final class TaskInboxOrder {

	/**
	 * Constructor.
	 *
	 * @param string $sort           One of the TaskInboxCriteria::SORT_* values.
	 * @param bool   $sortDescending Whether to invert the sort.
	 * @param bool   $openFirst      When true, open tasks sort before finished ones,
	 *                               and the chosen sort orders within each.
	 */
	public function __construct(
		public readonly string $sort = TaskInboxCriteria::SORT_DUE,
		public readonly bool $sortDescending = false,
		public readonly bool $openFirst = false,
	) {
	}//end __construct()
}//end class
