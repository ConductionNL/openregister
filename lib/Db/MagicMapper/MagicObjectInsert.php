<?php

/**
 * MagicObjectInsert
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Db
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One object to insert: the object, whether its creating hooks run and whether a taken identifier is refused.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class MagicObjectInsert {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $entity         The object to insert.
	 * @param bool         $dispatchEvents Whether to run the creating hooks.
	 * @param bool         $failIfExists   Insert-only: refuse instead of updating when the identifier is taken.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly ObjectEntity $entity,
		public readonly bool $dispatchEvents=true,
		public readonly bool $failIfExists=false,
	) {
	}//end __construct()
}//end class
