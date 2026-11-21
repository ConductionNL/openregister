<?php

/**
 * What one object export served: its format and how many rows left the instance.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

/**
 * The format an export was written in and the rows it served.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ServedExport {

	/**
	 * Constructor.
	 *
	 * @param string $format   The format written: csv, json, pdf, excel or ods.
	 * @param int    $rowCount How many rows left the instance.
	 */
	public function __construct(
		public readonly string $format,
		public readonly int $rowCount,
	) {
	}//end __construct()
}//end class
