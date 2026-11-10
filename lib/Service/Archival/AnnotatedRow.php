<?php

/**
 * One row and the archival annotation its retention is evaluated under.
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

use DateTimeInterface;

/**
 * The schema's `x-openregister-archival` block, the row's data and its creation moment.
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-4
 */
final class AnnotatedRow {
	/**
	 * Hold the values.
	 *
	 * @param array             $annotation Full `x-openregister-archival` block.
	 * @param array             $row        Row data keyed by field name.
	 * @param DateTimeInterface $createdAt  The row's `_created` timestamp.
	 */
	public function __construct(
		public readonly array $annotation,
		public readonly array $row,
		public readonly DateTimeInterface $createdAt,
	) {
	}//end __construct()
}//end class
