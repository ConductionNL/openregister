<?php

/**
 * OpenRegister e-Depot Transfer Pipeline
 *
 * The collaborators one e-Depot transfer attempt runs through.
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
 * @spec openspec/specs/edepot-transfer/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

/**
 * Bundles the steps of a transfer attempt for {@see EdepotTransferService}.
 *
 * @spec openspec/specs/edepot-transfer/spec.md
 */
final class TransferPipeline {

	/**
	 * Bundle the steps.
	 *
	 * @param TransferObjectGatherer  $gatherer  Reads the outstanding objects and their files.
	 * @param TransferResultProcessor $processor Records each object's outcome and the list status.
	 * @param TransferAuditLog        $auditLog  Logs, audits and notifies.
	 * @param TransferSettings        $settings  Reads the transport configuration.
	 */
	public function __construct(
		public readonly TransferObjectGatherer $gatherer,
		public readonly TransferResultProcessor $processor,
		public readonly TransferAuditLog $auditLog,
		public readonly TransferSettings $settings,
	) {
	}//end __construct()
}//end class
