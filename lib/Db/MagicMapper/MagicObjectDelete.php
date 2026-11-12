<?php

/**
 * MagicObjectDelete
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
 * One object to delete: the object, whether the row is removed or marked deleted and whether the delete is announced.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class MagicObjectDelete {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $entity         The object to delete.
	 * @param bool         $hardDelete     Remove the row instead of marking it deleted.
	 * @param bool         $dispatchEvents Whether to run the deleting hooks and events.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly ObjectEntity $entity,
		public readonly bool $hardDelete=false,
		public readonly bool $dispatchEvents=true,
	) {
	}//end __construct()
}//end class
