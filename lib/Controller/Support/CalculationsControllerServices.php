<?php

/**
 * OpenRegister calculations controller services
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

use OCA\OpenRegister\Db\MagicMapper\MagicTableRowReaderInterface;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Service\Calculation\CalculationTrialServiceInterface;
use OCA\OpenRegister\Service\Calculation\OperatorCatalogueInterface;

/**
 * The services of the calculations API.
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
final class CalculationsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param OperatorCatalogueInterface       $catalogue     The published operator catalogue.
	 * @param CalculationTrialServiceInterface $trials        Dry-run evaluation of an unsaved declaration.
	 * @param SchemaCatalogInterface           $schemaCatalog Register and schema lookups, scoped to the caller.
	 * @param MagicTableRowReaderInterface     $objectMapper  Object lookup, scoped to the caller by RBAC and tenancy.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function __construct(
		public readonly OperatorCatalogueInterface $catalogue,
		public readonly CalculationTrialServiceInterface $trials,
		public readonly SchemaCatalogInterface $schemaCatalog,
		public readonly MagicTableRowReaderInterface $objectMapper,
	) {
	}//end __construct()
}//end class
