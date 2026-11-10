<?php

/**
 * OpenRegister TrialObject
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * The stored object a calculation trial runs against, with its schema,
 * already resolved for the acting user.
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
final class TrialObject {
	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object The object, already resolved for the acting user.
	 * @param Schema       $schema The object's schema.
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly Schema $schema,
	) {
	}//end __construct()
}//end class
