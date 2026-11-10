<?php

/**
 * OpenRegister CredentialPrincipals
 *
 * Who the broker checks a call against: the session, the organisation and, for shares, groups and users.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/credential-broker/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Who the broker checks a call against: the session, the organisation and, for shares, groups and users.
 *
 * @spec openspec/specs/credential-broker/spec.md
 */
final readonly class CredentialPrincipals {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IUserSession                 $userSession         Current session (owner guard identity).
	 * @param OrganisationServiceInterface $organisationService Resolves organisation membership (organisation guard branch).
	 * @param IGroupManager|null           $groupManager        Resolves group principals for the share guard; null admits no group share.
	 * @param IUserManager|null            $userManager         Resolves the asserted uid's groups on the sessionless path; null means no groups.
	 */
	public function __construct(
		public IUserSession $userSession,
		public OrganisationServiceInterface $organisationService,
		public ?IGroupManager $groupManager = null,
		public ?IUserManager $userManager = null,
	) {
	}//end __construct()
}//end class
