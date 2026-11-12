<?php

/**
 * DeclaredGroupInventoryInterface: what the occ declared-groups command asks of DeclaredGroupInventoryService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Authorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Authorization;

/**
 * The read-only inventory of declared authorization groups, as the occ
 * declared-groups command reports it. Only the method it calls.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
interface DeclaredGroupInventoryInterface {

	/**
	 * Every declared group with whether it exists and how many members it has.
	 *
	 * @return array{
	 *     groups: array<int, array{group: string, exists: bool, members: int|null, grantsNobody: bool}>,
	 *     declared: int,
	 *     missing: int,
	 *     empty: int,
	 *     unknown: int
	 * } The per-group rows plus the counts a caller would otherwise recompute.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function inventory(): array;
}//end interface
