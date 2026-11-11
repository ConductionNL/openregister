<?php

/**
 * OpenRegister CompanyLookupController services
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

use OCA\OpenRegister\Service\Integration\Providers\CompanySearchFactoryInterface;
use OCA\OpenRegister\Service\Integration\Providers\KvkLookupInterface;
use OCA\OpenRegister\Service\Integration\Providers\OpenCorporatesSearchInterface;

/**
 * The collaborators of CompanyLookupController, wired by DI autowiring.
 *
 * @spec openspec/specs/integration-company-lookup/spec.md
 */
final class CompanyLookupControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param KvkLookupInterface            $kvkProvider            KvK lookup leaf.
	 * @param OpenCorporatesSearchInterface $openCorporatesProvider OpenCorporates search leaf.
	 * @param CompanySearchFactoryInterface $searches               Builds the search either leaf takes.
	 */
	public function __construct(
		public readonly KvkLookupInterface $kvkProvider,
		public readonly OpenCorporatesSearchInterface $openCorporatesProvider,
		public readonly CompanySearchFactoryInterface $searches,
	) {
	}//end __construct()
}//end class
