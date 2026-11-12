<?php

/**
 * The two kinds of person a person link writes: an addressbook contact and a Nextcloud user.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

/**
 * The two kinds of person a person link writes: an addressbook contact and a Nextcloud user.
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */
final class PersonLinkTargets {
	/**
	 * Bundle the collaborators.
	 *
	 * @param ContactService $contacts  Writes addressbook contact links.
	 * @param UserLinkWriter $userLinks Writes Nextcloud user links.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function __construct(
		public readonly ContactService $contacts,
		public readonly UserLinkWriter $userLinks,
	) {
	}//end __construct()
}//end class
