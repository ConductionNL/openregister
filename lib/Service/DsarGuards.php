<?php

/**
 * OpenRegister DsarGuards
 *
 * What a subject access request is guarded by: the caller, the admin group check and the archival retention guard.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/dsar-case-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * What a subject access request is guarded by: the caller, the admin group check and the archival retention guard.
 *
 * @spec openspec/specs/dsar-case-api/spec.md
 */
final readonly class DsarGuards {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IUserSession           $userSession   Current user, for soft-delete metadata and the in-service privilege guard.
	 * @param IGroupManager          $groupManager  Group manager used by the in-service admin guard.
	 * @param ArchivalRetentionGuard $archivalGuard Refuses erasure of a legally retained record and puts the refusal into words.
	 */
	public function __construct(
		public IUserSession $userSession,
		public IGroupManager $groupManager,
		public ArchivalRetentionGuard $archivalGuard,
	) {
	}//end __construct()
}//end class
