<?php

/**
 * MagicObjectLock
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
 * One lock taken on an object: the object, how long, for which process and, for a run lock, the run.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class MagicObjectLock {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $entity   The object to lock.
	 * @param ?int         $duration The lock duration in seconds, or null for the default.
	 * @param ?string      $process  The process the lock is taken for, or null for the mapper tag.
	 * @param ?string      $runUuid  The run holding the lock, or null for a user lock.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly ObjectEntity $entity,
		public readonly ?int $duration=null,
		public readonly ?string $process=null,
		public readonly ?string $runUuid=null,
	) {
	}//end __construct()
}//end class
