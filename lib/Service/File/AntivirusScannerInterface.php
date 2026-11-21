<?php

/**
 * AntivirusScannerInterface: the scanner the upload gate asks
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

/**
 * Scans bytes. The one seam between OpenRegister and files_antivirus.
 *
 * @spec openspec/changes/upload-malware-scan/specs/file-actions/spec.md#requirement-every-upload-is-scanned-by-files_antivirus-and-without-it-nothing-is-accepted-req-ums-001
 */
interface AntivirusScannerInterface {

	/**
	 * Scan one run of bytes.
	 *
	 * Never throws: a scanner that cannot be reached answers UNAVAILABLE.
	 *
	 * @param string $name  The file or member name, for the engine's log.
	 * @param string $bytes The content.
	 *
	 * @return AntivirusScan
	 *
	 * @spec openspec/changes/upload-malware-scan/specs/file-actions/spec.md#requirement-every-upload-is-scanned-by-files_antivirus-and-without-it-nothing-is-accepted-req-ums-001
	 */
	public function scan(string $name, string $bytes): AntivirusScan;
}//end interface
