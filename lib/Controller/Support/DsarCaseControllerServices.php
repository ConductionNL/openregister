<?php

/**
 * OpenRegister dsar case controller services
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
 * The service bundles of the dsar case API.
 *
 * @spec openspec/specs/dsar-case-api/spec.md
 */
final class DsarCaseControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param DsarCaseCases      $cases      The cases bundle.
	 * @param DsarCaseDisclosure $disclosure The disclosure bundle.
	 * @param DsarCaseRegistries $registries The registries bundle.
	 * @param DsarCasePlatform   $platform   The platform bundle.
	 *
	 * @spec openspec/specs/dsar-case-api/spec.md
	 */
	public function __construct(
		public readonly DsarCaseCases $cases,
		public readonly DsarCaseDisclosure $disclosure,
		public readonly DsarCaseRegistries $registries,
		public readonly DsarCasePlatform $platform,
	) {
	}//end __construct()
}//end class
