<?php

/**
 * One object read: where it reads, what it asks and how many it may return.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The resolved register and schema, the rendered filters and the bounded cap
 * of one object read. Who the read runs as is passed beside it, never in it.
 *
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */
final class ObjectReadQuery {
	/**
	 * Bundle the read.
	 *
	 * @param Register             $register The resolved register.
	 * @param Schema               $schema   The resolved schema.
	 * @param array<string, mixed> $filters  The rendered filters.
	 * @param int                  $limit    The bounded result cap.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function __construct(
		public readonly Register $register,
		public readonly Schema $schema,
		public readonly array $filters,
		public readonly int $limit,
	) {
	}//end __construct()
}//end class
