<?php

/**
 * OpenRegister Transfer Package Request
 *
 * What to package for one transfer: its objects, the size limit and the format.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

/**
 * A request to package a transfer's objects as SIP archive(s).
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-bagit-output/spec.md
 */
final class TransferPackageRequest {

	/**
	 * Constructor.
	 *
	 * @param string                           $transferId       The transfer list UUID.
	 * @param array<int, array<string, mixed>> $objectsWithFiles Objects and their file metadata (object, files[]).
	 * @param int                              $maxPackageSize   Maximum package size in bytes; 0 or less for the configured one.
	 * @param string                           $format           Output format: `zip` (default) or `bagit` (RFC 8493).
	 */
	public function __construct(
		public readonly string $transferId,
		public readonly array $objectsWithFiles,
		public readonly int $maxPackageSize = 0,
		public readonly string $format = 'zip',
	) {
	}//end __construct()
}//end class
