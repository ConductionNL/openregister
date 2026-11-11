<?php

/**
 * ReferencedByPage — one page request of the reverse view.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Hinge
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hinge;

/**
 * What every schema group of one reverse-view read is asked for: the object
 * referenced, the page of each group, and whether the caller's access is
 * applied inside the query.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hinge
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */
final class ReferencedByPage {

	/**
	 * Constructor.
	 *
	 * @param string $targetUuid The uuid every returned record references.
	 * @param int    $limit      Page size for each group.
	 * @param int    $offset     Page offset for each group.
	 * @param bool   $rbac       Apply the caller's access inside the query.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function __construct(
		public readonly string $targetUuid,
		public readonly int $limit,
		public readonly int $offset,
		public readonly bool $rbac,
	) {
	}//end __construct()

	/**
	 * The filters every query of a group carries: the reference and the access flag.
	 *
	 * @return array{_relations_contains: string, _rbac: bool} The query filters.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function filters(): array {
		return [
			'_relations_contains' => $this->targetUuid,
			'_rbac' => $this->rbac,
		];
	}//end filters()
}//end class
