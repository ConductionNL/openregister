<?php

/**
 * OpenRegister e-Depot Transfer Exclusion
 *
 * Objects taken off a transfer list in review, with the reason.
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
 * Objects taken off a transfer list in review, with the reason.
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-transfer-list-management
 */
final class TransferExclusion {

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $objectUuids UUIDs of the objects to exclude.
	 * @param string             $reason      The reason for exclusion.
	 */
	public function __construct(
		public readonly array $objectUuids,
		public readonly string $reason,
	) {
	}//end __construct()
}//end class
