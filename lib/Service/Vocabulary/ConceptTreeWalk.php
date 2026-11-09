<?php

/**
 * OpenRegister ConceptTreeWalk
 *
 * The state one step of {@see ConceptHierarchy::tree()} carries down: the
 * scheme's relations, the language and instant every node is judged in, how
 * many levels may still be walked and which uris are already on the path.
 * Immutable: descending returns a new walk, so sibling branches never see
 * each other's path.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vocabulary
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Vocabulary;

/**
 * One position in a bounded, cycle-safe walk down a concept tree.
 */
final class ConceptTreeWalk {

	/**
	 * Constructor.
	 *
	 * @param ConceptRelations   $relations The scheme's broader/narrower relations.
	 * @param ConceptReading     $reading   The language and instant every node is judged in.
	 * @param integer            $remaining How many more levels may be walked.
	 * @param array<string,true> $visited   The uris already on this path.
	 */
	public function __construct(
		public readonly ConceptRelations $relations,
		public readonly ConceptReading $reading,
		public readonly int $remaining,
		private readonly array $visited = [],
	) {

	}//end __construct()

	/**
	 * Whether the walk may go one level deeper.
	 *
	 * @return boolean True while levels remain.
	 */
	public function canDescend(): bool {
		return $this->remaining > 0;
	}//end canDescend()

	/**
	 * The walk one level below `$uri`, with `$uri` added to the path.
	 *
	 * @param string $uri The concept being descended from.
	 *
	 * @return self The walk for its children.
	 */
	public function below(string $uri): self {
		$visited = $this->visited;
		$visited[$uri] = true;

		return new self(
			relations: $this->relations,
			reading: $this->reading,
			remaining: ($this->remaining - 1),
			visited: $visited
		);
	}//end below()

	/**
	 * Whether `$uri` is already on the path walked so far.
	 *
	 * @param string $uri The concept's uri.
	 *
	 * @return boolean True when stepping into it would close a cycle.
	 */
	public function hasVisited(string $uri): bool {
		return isset($this->visited[$uri]) === true;
	}//end hasVisited()
}//end class
