<?php

/**
 * MagicObjectUnlock
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
 * One lock released on an object: the object, the run that releases it and whether the lock is broken.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class MagicObjectUnlock {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $entity  The object to unlock.
	 * @param ?string      $runUuid The run releasing its lock, or null.
	 * @param bool         $break   Whether to break a lock held by someone else (authorised at the call site).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly ObjectEntity $entity,
		public readonly ?string $runUuid=null,
		public readonly bool $break=false,
	) {
	}//end __construct()
}//end class
