<?php

/**
 * OpenRegister e-Depot Transfer Rejection
 *
 * An archivist's rejection of a transfer list in review, with the reason.
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
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-transfer-list-management
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

/**
 * An archivist's rejection of a transfer list in review, with the reason.
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-transfer-list-management
 */
final class TransferRejection {

	/**
	 * Constructor.
	 *
	 * @param string $archivistId The rejecting archivist user ID.
	 * @param string $reason      The reason for rejection; required.
	 */
	public function __construct(
		public readonly string $archivistId,
		public readonly string $reason,
	) {
	}//end __construct()
}//end class
