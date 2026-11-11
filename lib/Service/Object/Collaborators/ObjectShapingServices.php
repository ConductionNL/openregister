<?php

/**
 * OpenRegister ObjectService shaping services: data manipulation, utilities and datetime input
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\Collaborators
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

namespace OCA\OpenRegister\Service\Object\Collaborators;

use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\DataManipulationHandler;
use OCA\OpenRegister\Service\Object\UtilityHandler;

/**
 * ObjectService shaping services: data manipulation, utilities and datetime input.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class ObjectShapingServices {
	/**
	 * Bundle the services.
	 *
	 * @param DataManipulationHandler $dataManipHandler   Handler for data manipulation operations.
	 * @param UtilityHandler          $utilityHandler     Handler for utility operations.
	 * @param DateTimeNormalizer      $dateTimeNormalizer Normaliser for user-supplied datetime input.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function __construct(
		public readonly DataManipulationHandler $dataManipHandler,
		public readonly UtilityHandler $utilityHandler,
		public readonly DateTimeNormalizer $dateTimeNormalizer,
	) {
	}//end __construct()
}//end class
