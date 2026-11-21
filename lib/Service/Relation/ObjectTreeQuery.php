<?php

/**
 * What a caller asks of the tree around one object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * A tree query: the object to walk from, which way to walk, how deep, and which properties each node carries.
 *
 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
 */
final class ObjectTreeQuery {
	/**
	 * The query.
	 *
	 * @param ObjectEntity $root    The object to walk from, already read under the caller's RBAC.
	 * @param string   $direction One of the ObjectTreeServiceInterface::DIRECTION_ constants.
	 * @param int|null $depth     The depth asked for; null means the declared bound.
	 * @param string[] $fields    Object properties to copy onto each node, after the read-side redaction.
	 *
	 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
	 */
	public function __construct(
		public readonly ObjectEntity $root,
		public readonly string $direction = ObjectTreeServiceInterface::DIRECTION_DESCENDANTS,
		public readonly ?int $depth = null,
		public readonly array $fields = [],
	) {
	}//end __construct()
}//end class
