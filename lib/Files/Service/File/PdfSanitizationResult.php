<?php

/**
 * PdfSanitizationResult
 *
 * The sanitised PDF in a temporary file, and its report.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Files\Service\File
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

/**
 * Where the sanitised PDF is, and what was removed.
 */
final class PdfSanitizationResult {

	/**
	 * Constructor.
	 *
	 * @param string                $path   The temporary file holding the sanitised PDF; the caller removes it.
	 * @param PdfSanitizationReport $report What was removed and kept.
	 */
	public function __construct(
		public readonly string $path,
		public readonly PdfSanitizationReport $report,
	) {
	}//end __construct()
}//end class
