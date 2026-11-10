<?php

/**
 * OpenRegister DryRunBatch
 *
 * The rows a dry run validates, the schema it validates them against, and when it started.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

use OCA\OpenRegister\Db\Schema;

/**
 * One dry-run batch: rows validated and never saved (ADR-011 Rule 2).
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md#dry-run
 */
final class DryRunBatch {
	/**
	 * Build the batch.
	 *
	 * @param array<int, array> $objects   The mapped and type-coerced objects that would be saved.
	 * @param Schema            $schema    The target schema to validate against.
	 * @param float             $startTime `microtime(true)` at the start of processing.
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md#dry-run
	 */
	public function __construct(
		public readonly array $objects,
		public readonly Schema $schema,
		public readonly float $startTime,
	) {
	}//end __construct()
}//end class
