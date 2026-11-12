<?php

/**
 * OpenRegister DeletionTarget
 *
 * The object a delete acts on, together with the register and schema it lives in.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The resolved object, register and schema of one delete.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class DeletionTarget {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity  $object   The object to delete.
	 * @param Register|null $register The register the object lives in.
	 * @param Schema|null   $schema   The schema the object conforms to.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly ?Register $register,
		public readonly ?Schema $schema,
	) {
	}//end __construct()

	/**
	 * The object's register id as the int the cache expects, or null when it is not numeric.
	 *
	 * @return int|null The numeric register id.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function registerCacheId(): ?int {
		return self::numericOrNull(id: $this->object->getRegister());
	}//end registerCacheId()

	/**
	 * The object's schema id as the int the cache expects, or null when it is not numeric.
	 *
	 * @return int|null The numeric schema id.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function schemaCacheId(): ?int {
		return self::numericOrNull(id: $this->object->getSchema());
	}//end schemaCacheId()

	/**
	 * An id as an int, or null when it is not numeric.
	 *
	 * @param string|int|null $id The stored id.
	 *
	 * @return int|null The numeric id.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function numericOrNull(string|int|null $id): ?int {
		if (is_numeric($id) === true) {
			return (int)$id;
		}

		return null;
	}//end numericOrNull()
}//end class
