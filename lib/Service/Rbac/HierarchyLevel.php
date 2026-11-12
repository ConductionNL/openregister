<?php

/**
 * OpenRegister hierarchy level
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * Where one level of a hierarchy is read: the magic table, the column naming the parent and how many rows at most.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class HierarchyLevel {
	/**
	 * Constructor.
	 *
	 * @param string   $table        The magic table.
	 * @param string   $parentColumn The column naming the parent.
	 * @param int|null $limit        The most rows to read, or null for the whole level.
	 */
	public function __construct(
		public readonly string $table,
		public readonly string $parentColumn,
		public readonly ?int $limit = null,
	) {
	}//end __construct()
}//end class
