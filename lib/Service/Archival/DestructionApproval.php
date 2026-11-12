<?php

/**
 * OpenRegister DestructionApproval
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * One approval of a destruction list as it is marked approved: who approved
 * it, with which action, and the UUID of the persisted list the execution job
 * reloads (null when the caller did not give one).
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
final readonly class DestructionApproval {

	/**
	 * Bundle the approval.
	 *
	 * @param string      $userId   The approver.
	 * @param string      $action   The approval action.
	 * @param string|null $listUuid UUID of the persisted list, when given.
	 */
	public function __construct(
		public string $userId,
		public string $action,
		public ?string $listUuid,
	) {
	}//end __construct()
}//end class
