<?php

/**
 * One retention source being scanned, and what the scan collected so far.
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The source descriptor, which rows count and how many may still be taken,
 * and the outcome: the objects collected, the rows inspected and whether the
 * cap was reached.
 *
 * Never leaves RetentionRowScanner.
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
final class SourceScan {

	/**
	 * The objects collected, the rows inspected, and whether the cap was reached.
	 *
	 * @var array{objects: ObjectEntity[], scanned: int, truncated: bool}
	 */
	public array $outcome = ['objects' => [], 'scanned' => 0, 'truncated' => false];

	/**
	 * Start scanning one source.
	 *
	 * @param array<string, mixed>  $source   The source descriptor.
	 * @param RetentionScanCriteria $criteria Which rows count, and how many may still be taken.
	 */
	public function __construct(
		public readonly array $source,
		public readonly RetentionScanCriteria $criteria,
	) {
	}//end __construct()
}//end class
