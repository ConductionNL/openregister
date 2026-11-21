<?php

/**
 * AntivirusScan: one answer from the malware scanner
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
 * What the scanner said about one run of bytes.
 *
 * UNAVAILABLE and UNCHECKED are kept apart only for the log line: the gate
 * refuses both the same way, because a scan that did not happen is not a pass.
 *
 * @spec openspec/changes/upload-malware-scan/specs/file-actions/spec.md#requirement-every-upload-is-scanned-by-files_antivirus-and-without-it-nothing-is-accepted-req-ums-001
 */
final class AntivirusScan {

	public const CLEAN = 'clean';

	public const INFECTED = 'infected';

	public const UNCHECKED = 'unchecked';

	public const UNAVAILABLE = 'unavailable';

	/**
	 * Constructor.
	 *
	 * @param string $status  One of the constants.
	 * @param string $engine  Which engine answered, empty when none did.
	 * @param string $finding What the engine found or why it could not scan.
	 */
	public function __construct(
		public readonly string $status,
		public readonly string $engine='',
		public readonly string $finding='',
	) {
	}//end __construct()
}//end class
