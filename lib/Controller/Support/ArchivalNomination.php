<?php

/**
 * OpenRegister archival domain
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

use OCA\OpenRegister\Service\Archival\ArchivalNominationServiceInterface;
use OCA\OpenRegister\Service\Archival\DestructionListCreatorInterface;

/**
 * The domain services of the archival API.
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
final class ArchivalNomination {
	/**
	 * Bundle the services.
	 *
	 * @param ArchivalNominationServiceInterface $nominations Derives and writes an archival nomination.
	 * @param DestructionListCreatorInterface    $creator     Creates a destruction list for the objects an app names.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function __construct(
		public readonly ArchivalNominationServiceInterface $nominations,
		public readonly DestructionListCreatorInterface $creator,
	) {
	}//end __construct()
}//end class
