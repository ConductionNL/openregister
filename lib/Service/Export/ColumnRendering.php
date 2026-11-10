<?php

/**
 * ColumnRendering — how one rendered export column reads.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
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

namespace OCA\OpenRegister\Service\Export;

/**
 * What a rendered cell of one column is resolved against: the column's schema
 * property, for code list labels, and the uuid to name map of the export.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ColumnRendering {

	/**
	 * Describe one column.
	 *
	 * @param array<string, mixed>  $property The schema property, or [] when the schema did not resolve.
	 * @param array<string, string> $names    Uuid to object name map.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $property,
		public readonly array $names,
	) {
	}//end __construct()
}//end class
