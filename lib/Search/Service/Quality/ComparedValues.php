<?php

/**
 * OpenRegister ComparedValues
 *
 * The two field values a similarity method compares.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\Quality
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

namespace OCA\OpenRegister\Search\Service\Quality;

/**
 * The left and right value of one compared field, as read from the two payloads.
 *
 * @spec openspec/changes/mdm-foundation/tasks.md#task-4
 */
final class ComparedValues {
	/**
	 * Constructor.
	 *
	 * @param mixed $left  The value on the first payload.
	 * @param mixed $right The value on the second payload.
	 *
	 * @spec openspec/changes/mdm-foundation/tasks.md#task-4
	 */
	public function __construct(
		public readonly mixed $left,
		public readonly mixed $right,
	) {
	}//end __construct()
}//end class
