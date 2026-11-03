<?php

/**
 * OpenRegister — Declared Group Inventory
 *
 * Answers the one question provisioning cannot: a declared group now always
 * EXISTS, but a group nobody belongs to still denies every caller. Creating it
 * removed the typo/drift failure; it did not make the app usable. Until an
 * administrator adds members, the access rule reads as configured and behaves
 * as denied — which is precisely the silent state this capability exists to
 * stop being silent.
 *
 * This service reports, per declared group, whether it exists and how many
 * members it has, and marks the actionable rows: a group with zero members
 * grants nobody anything.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Authorization
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

namespace OCA\OpenRegister\Service\Authorization;

/**
 * Reports the live state of every declared RBAC group.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
class DeclaredGroupInventoryService {

	/**
	 * Constructor.
	 *
	 * @param GroupReconciler $reconciler Supplies the declared group set.
	 * @param GroupProvisioner $provisioner Supplies each group's live membership state.
	 */
	public function __construct(
		private readonly GroupReconciler $reconciler,
		private readonly GroupProvisioner $provisioner,
	) {
	}//end __construct()

	/**
	 * Build the declared-group inventory.
	 *
	 * `members` is null where the group backend cannot report a count
	 * ({@see \OCP\IGroup::count()} returns `int|bool`). Such a row is NOT
	 * counted as empty — reporting an uncountable backend as zero members would
	 * raise the exact false alarm this surface exists to prevent.
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
	public function inventory(): array {
		$declared = $this->reconciler->collectDeclared();
		$liveState = $this->provisioner->inventory(groups: $declared);

		$rows = [];
		$missing = 0;
		$empty = 0;
		$unknown = 0;

		foreach ($declared as $group) {
			$state = ($liveState[$group] ?? [
				'exists' => false,
				'members' => null,
			]);
			$exists = ($state['exists'] === true);
			$members = $state['members'];

			// A group grants nobody anything when it is absent, or present with a
			// KNOWN zero membership. An unknown count is explicitly not that case.
			$grantsNobody = ($exists === false || $members === 0);

			if ($exists === false) {
				$missing++;
			} elseif ($members === null) {
				$unknown++;
			} elseif ($members === 0) {
				$empty++;
			}

			$rows[] = [
				'group' => $group,
				'exists' => $exists,
				'members' => $members,
				'grantsNobody' => $grantsNobody,
			];
		}//end foreach

		return [
			'groups' => $rows,
			'declared' => count($declared),
			'missing' => $missing,
			'empty' => $empty,
			'unknown' => $unknown,
		];
	}//end inventory()

}//end class
