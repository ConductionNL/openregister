<?php

/**
 * OpenRegister WellKnownController services
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

use OCA\OpenRegister\Service\WellKnown\SecurityTxtBuilderInterface;
use OCP\IURLGenerator;

/**
 * The collaborators of WellKnownController, wired by DI autowiring.
 *
 * @spec openspec/specs/api-surface-governance/spec.md
 */
final class WellKnownControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param SecurityTxtBuilderInterface $securityTxt  Builds the administered security contact file.
	 * @param IURLGenerator               $urlGenerator Names this instance's own base URL.
	 */
	public function __construct(
		public readonly SecurityTxtBuilderInterface $securityTxt,
		public readonly IURLGenerator $urlGenerator,
	) {
	}//end __construct()
}//end class
