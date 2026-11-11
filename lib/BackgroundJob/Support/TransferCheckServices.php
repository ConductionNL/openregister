<?php

/**
 * OpenRegister transfer check services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob\Support;

use OCA\OpenRegister\Service\Edepot\TransferListRunInterface;
use OCA\OpenRegister\Service\Edepot\TransferRecordServiceInterface;
use OCA\OpenRegister\Service\RetentionServiceInterface;

/**
 * The retention sweep, the transfer list run and the transfer records of the e-Depot transfer check.
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
 */
final readonly class TransferCheckServices {
	/**
	 * Bundle the services.
	 *
	 * @param RetentionServiceInterface      $retentionService    Owns the retention sweep both archival jobs share.
	 * @param TransferListRunInterface       $transferListService The transfer list service.
	 * @param TransferRecordServiceInterface $transferRecords     Durable transfer-list records.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
	 */
	public function __construct(
		public RetentionServiceInterface $retentionService,
		public TransferListRunInterface $transferListService,
		public TransferRecordServiceInterface $transferRecords,
	) {
	}//end __construct()
}//end class
