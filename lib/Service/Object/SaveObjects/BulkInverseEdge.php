<?php

/**
 * One inverse relation a bulk chunk writes: which property of the target gets which source uuid.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObjects;

/**
 * The target property and the source uuid of one inverse relation.
 */
final readonly class BulkInverseEdge {

	/**
	 * Constructor.
	 *
	 * @param string $inversedBy The property on the target that lists its sources.
	 * @param string $sourceUuid The uuid of the object that points at the target.
	 */
	public function __construct(
		public string $inversedBy,
		public string $sourceUuid,
	) {
	}//end __construct()
}//end class
