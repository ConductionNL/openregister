<?php

/**
 * One data line of a selectielijst CSV and its place in the file.
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
 * The line's text and its 1-based number, for the refusal of a line whose columns do not match the header.
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
final class CsvLine {
	/**
	 * Hold the values.
	 *
	 * @param string $text   The line as read.
	 * @param int    $number The 1-based number of the data line.
	 */
	public function __construct(
		public readonly string $text,
		public readonly int $number,
	) {
	}//end __construct()
}//end class
