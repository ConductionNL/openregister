<?php

/**
 * MagicObjectChange
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
 * One update of a stored object: the object as it is to be written and, when the caller already read it, the object as it was.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class MagicObjectChange {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity  $entity    The object to write.
	 * @param ?ObjectEntity $oldEntity The object as stored before the update, or null to read it.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly ObjectEntity $entity,
		public readonly ?ObjectEntity $oldEntity=null,
	) {
	}//end __construct()
}//end class
