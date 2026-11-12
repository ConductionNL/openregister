<?php

/**
 * Where a cascaded nested object goes: under which parent, by which property definition.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The parent object a nested object is cascaded from, and the definition of the
 * property it sits in (its `$ref`, `inversedBy` and `register`).
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class NestedObjectTarget {
	/**
	 * The target.
	 *
	 * @param ObjectEntity $parent     The parent object.
	 * @param array        $definition The definition of the property the nested object is found in.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly ObjectEntity $parent,
		public readonly array $definition,
	) {
	}//end __construct()
}//end class
