<?php

/**
 * OpenRegister ExportTable
 *
 * The rows of one export section with their columns and resolved relation names.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
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

namespace OCA\OpenRegister\Service\Export;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The rows of one export section with their columns and resolved relation names.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ExportTable {
	/**
	 * Hold one section's table.
	 *
	 * @param ObjectEntity[]        $objects       The exported objects, one per row.
	 * @param array<string, string> $headers       Header labels keyed by column letter.
	 * @param array<string, string> $nameColumns   Companion name columns: column letter to source property.
	 * @param array<string, string> $uuidToNameMap Relation UUIDs to their human-readable names.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly array $objects,
		public readonly array $headers,
		public readonly array $nameColumns,
		public readonly array $uuidToNameMap,
	) {
	}//end __construct()
}//end class
