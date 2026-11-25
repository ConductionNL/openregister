<?php

/**
 * OpenRegister transfer controller services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Service\Edepot\TransferListRunInterface;
use OCA\OpenRegister\Service\Edepot\TransferListServiceInterface;
use OCA\OpenRegister\Service\Edepot\TransferRecordServiceInterface;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * The services of the transfer API.
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
 */
final class TransferControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param TransferRecordServiceInterface $transferRecords       Durable transfer-record persistence.
	 * @param IJobList              $jobList               Background-job scheduler (dispatch execution).
	 * @param LoggerInterface       $logger                Logger.
	 * @param TransferListServiceInterface   $transferListService   Approves and rejects transfer lists.
	 * @param RetentionServiceInterface|null $retention             Finds the records due for transfer.
	 * @param TransferListRunInterface|null  $transferRun           Names the records already on an active transfer list.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
	 */
	public function __construct(
		public readonly TransferRecordServiceInterface $transferRecords,
		public readonly IJobList $jobList,
		public readonly LoggerInterface $logger,
		public readonly TransferListServiceInterface $transferListService,
		public readonly ?RetentionServiceInterface $retention=null,
		public readonly ?TransferListRunInterface $transferRun=null,
	) {
	}//end __construct()
}//end class
