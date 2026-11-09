<?php

/**
 * OpenRegister Bulk Job Selection
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\BulkJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\BulkJob;

/**
 * The objects a bulk job acts on: the selection, and the register and schema it lives in.
 *
 * @spec openspec/specs/bulk-action-jobs/spec.md
 */
final readonly class BulkJobSelection {
	/**
	 * Name the selection.
	 *
	 * @param array<string, mixed> $criteria   Either `{"ids": [...]}` or `{"query": {...}}`.
	 * @param int|null             $registerId The register the selection lives in.
	 * @param int|null             $schemaId   The schema the selection lives in.
	 */
	public function __construct(
		public array $criteria,
		public ?int $registerId,
		public ?int $schemaId,
	) {
	}//end __construct()
}//end class
