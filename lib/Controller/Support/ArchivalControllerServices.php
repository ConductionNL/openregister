<?php

/**
 * OpenRegister archival controller services
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

/**
 * The service bundles of the archival API.
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
final class ArchivalControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ControllerSession  $session    The session bundle.
	 * @param ArchivalMappers    $mappers    The mappers bundle.
	 * @param ArchivalDecisions  $decisions  The decisions bundle.
	 * @param ArchivalNomination $nomination The nomination bundle.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function __construct(
		public readonly ControllerSession $session,
		public readonly ArchivalMappers $mappers,
		public readonly ArchivalDecisions $decisions,
		public readonly ArchivalNomination $nomination,
	) {
	}//end __construct()
}//end class
