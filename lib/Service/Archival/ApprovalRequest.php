<?php

/**
 * One approval an archivist gives a destruction list.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * The archivist's choice, whether the list needs a second approver, and the
 * persisted list the execution job reloads.
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
final class ApprovalRequest {
	/**
	 * Hold the approval.
	 *
	 * @param ApprovalChoice $choice       Approve all, or approve with records excluded and why.
	 * @param bool           $requiresDual Whether two-step approval is required.
	 * @param string|null    $listUuid     UUID of the persisted destruction list, for the execution job.
	 */
	public function __construct(
		public readonly ApprovalChoice $choice,
		public readonly bool $requiresDual,
		public readonly ?string $listUuid,
	) {
	}//end __construct()
}//end class
