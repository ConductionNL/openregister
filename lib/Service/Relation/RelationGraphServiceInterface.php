<?php

/**
 * The bounded graph walk out from one object, as the object relations API asks for it.
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
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

/**
 * The bounded graph walk out from one object, as the object relations API asks for it.
 *
 * Declares only what callers outside lib/Service use; the container binds it
 * to {@see RelationGraphService} in ContractBindings.
 */
interface RelationGraphServiceInterface {

	/**
	 * The objects reachable from a root, with the typed edges between them.
	 *
	 * A request above the administered maximum is BOUNDED at the maximum and
	 * marked truncated rather than refused: refusing sends the caller back to
	 * guess a number, and answering silently at a smaller depth is the shape
	 * of wrongness this whole method exists to avoid. The answer always says
	 * what it is.
	 *
	 * @param string $rootUuid The object to walk out from.
	 * @param int $depth The depth asked for.
	 * @param string $language The BCP-47 tag to resolve labels in.
	 *
	 * @return array{root: string, depth: int, requestedDepth: int, maxDepth: int,
	 *     nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>,
	 *     truncated: bool, truncatedBy: string|null}
	 *     The graph.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function graph(string $rootUuid, int $depth = 1, string $language = 'nl'): array;

	/**
	 * The export rows of a graph, header first.
	 *
	 * Separate from export() so a caller that narrows the graph first (the
	 * controller drops the nodes of schemas whose purpose was not named) writes
	 * the rows of what it kept, not of the whole walk.
	 *
	 * @param array<string, mixed> $graph The graph, as graph() answers it or narrowed.
	 *
	 * @return array<int, array<int, string>> The rows.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function rowsOf(array $graph): array;
}//end interface
