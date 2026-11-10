<?php

/**
 * One usable retention rule and its place in the annotation.
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
 * The rule's index, its condition and the retention it gives when the condition matches.
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-4
 */
final class IndexedRule {
	/**
	 * Hold the values.
	 *
	 * @param int|string $index     The rule's index, for the warning on a malformed condition.
	 * @param string     $condition The condition.
	 * @param string     $retention The retention the rule gives.
	 */
	public function __construct(
		public readonly int|string $index,
		public readonly string $condition,
		public readonly string $retention,
	) {
	}//end __construct()
}//end class
