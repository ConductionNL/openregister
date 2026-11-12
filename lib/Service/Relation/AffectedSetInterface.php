<?php

/**
 * AffectedSetInterface: the affected-set derivation the relations controller asks for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

/**
 * The methods of AffectedSet that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface AffectedSetInterface {

	/**
	 * Derive the affected set from a graph answer.
	 *
	 * 🔴 A NODE THE CALLER MAY NOT READ IS NEITHER IN THE ANSWER NOR WALKED
	 * THROUGH. The walk loads every node under the caller's RBAC, so an
	 * unreadable record arrives unresolved; its edges are dropped before the
	 * walk, so nothing reachable only through it appears either, and no path
	 * names its uuid. Only resolved and external nodes are walked.
	 *
	 * @param array<string, mixed> $graph The answer from the bounded walk.
	 * @param AffectedSetQuery     $query The types to follow and prune, and the party schemas.
	 *
	 * @return array<string, mixed> The affected set.
	 *
	 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
	 */
	public function derive(array $graph, AffectedSetQuery $query): array;
}//end interface
