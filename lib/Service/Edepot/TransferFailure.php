<?php

/**
 * OpenRegister e-Depot Transfer Failure
 *
 * Why a transfer failed, and over which transport.
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
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-log-all-transfer-actions-in-the-audit-trail
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

/**
 * A failed transfer, as {@see TransferAuditLog::failed()} records it.
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-log-all-transfer-actions-in-the-audit-trail
 */
final class TransferFailure {

	/**
	 * Constructor.
	 *
	 * @param string $error     Error details.
	 * @param string $transport The transport protocol, or '' when the failure is not one transport's.
	 */
	public function __construct(
		public readonly string $error,
		public readonly string $transport,
	) {
	}//end __construct()
}//end class
