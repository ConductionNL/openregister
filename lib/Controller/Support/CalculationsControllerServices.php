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

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Calculation\CalculationTrialServiceInterface;
use OCA\OpenRegister\Service\Calculation\OperatorCatalogue;

/**
 * The services of the calculations API.
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
final class CalculationsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param OperatorCatalogue                $catalogue      The published operator catalogue.
	 * @param CalculationTrialServiceInterface $trials         Dry-run evaluation of an unsaved declaration.
	 * @param RegisterMapperInterface          $registerMapper Register lookup, scoped to the caller.
	 * @param SchemaMapperInterface            $schemaMapper   Schema lookup, scoped to the caller.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function __construct(
		public readonly OperatorCatalogue $catalogue,
		public readonly CalculationTrialServiceInterface $trials,
		public readonly RegisterMapperInterface $registerMapper,
		public readonly SchemaMapperInterface $schemaMapper,
	) {
	}//end __construct()
}//end class
