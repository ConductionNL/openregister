<?php

/**
 * Who is acting and which groups they are in, for the privacy office's checks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec exclude ADR-011 constructor aggregate, container plumbing with no behaviour of its own
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr;

use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The acting user, group membership and the log the privacy office's checks report to.
 *
 * Used by CaseAccessControl, ErasurePreviewStore and PrivacyOfficerRecipientResolver.
 *
 * @spec exclude ADR-011 constructor aggregate, container plumbing with no behaviour of its own
 */
final class GdprCallerMembership {

	/**
	 * Bundle the membership services.
	 *
	 * @param IUserSession    $userSession  The acting user.
	 * @param IGroupManager   $groupManager Group membership, members and the administrator check.
	 * @param LoggerInterface $logger       Logger for fail-closed and resolution diagnostics.
	 *
	 * @spec exclude ADR-011 constructor aggregate, container plumbing with no behaviour of its own
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly IGroupManager $groupManager,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
