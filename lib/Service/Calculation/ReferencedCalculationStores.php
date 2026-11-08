<?php

/**
 * OpenRegister ReferencedCalculationStores
 *
 * The register, schema and row stores the referenced-calculation refresher reads.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The register, schema and row stores the referenced-calculation refresher reads.
 *
 * Groups collaborators of {@see ReferencedCalculationRefresher} so its constructor stays small;
 * the container autowires it.
 */
final class ReferencedCalculationStores {

	/**
	 * Bundle the collaborators.
	 *
	 * @param RegisterMapperInterface $registers The registers.
	 * @param SchemaMapperInterface   $schemas   The schemas.
	 * @param MagicMapperInterface    $rows      Finds the referring objects.
	 */
	public function __construct(
		public readonly RegisterMapperInterface $registers,
		public readonly SchemaMapperInterface $schemas,
		public readonly MagicMapperInterface $rows,
	) {
	}//end __construct()
}//end class
