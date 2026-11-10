<?php

/**
 * OpenRegister transfer execution records
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

use OCA\OpenRegister\Service\Edepot\EdepotTransferAttemptInterface;
use OCA\OpenRegister\Service\Edepot\TransferListRunInterface;
use OCA\OpenRegister\Service\Edepot\TransferRecordStoreInterface;

/**
 * The e-Depot services that run a transfer attempt and keep its transfer list.
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
 */
final class TransferExecutionRecords {
	/**
	 * Bundle the services.
	 *
	 * @param EdepotTransferAttemptInterface $transferService Runs one transfer attempt.
	 * @param TransferRecordStoreInterface   $recordService   Loads and saves the transfer list.
	 * @param TransferListRunInterface      $listService   Notifies archivists on final failure.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
	 */
	public function __construct(
		public readonly EdepotTransferAttemptInterface $transferService,
		public readonly TransferRecordStoreInterface $recordService,
		public readonly TransferListRunInterface $listService,
	) {
	}//end __construct()
}//end class
