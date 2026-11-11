<?php

/**
 * OpenRegister AffectedSetQuery
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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

use InvalidArgumentException;

/**
 * What an affected-set read asks for: the relation types to follow, the ones to prune, and which schemas are parties.
 *
 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
 */
final class AffectedSetQuery {

	/**
	 * The schemas whose objects are parties.
	 *
	 * @var array<int, string>
	 */
	private array $partySchemas = [];

	/**
	 * A query over the given relation types.
	 *
	 * An empty type list is refused: it reads as both "none" and "all", and
	 * those are opposite answers. Leave it out (null) to follow every type.
	 *
	 * @param array<int, string>|null $types The relation types to follow, or null for every type.
	 * @param array<int, string>      $prune The relation types not to traverse past.
	 *
	 * @throws InvalidArgumentException When the type list is empty.
	 *
	 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
	 */
	public function __construct(
		public readonly ?array $types = null,
		public readonly array $prune = [],
	) {
		if ($types === []) {
			throw new InvalidArgumentException(
				'An empty relation-type list is refused: leave it out to mean "every type", '
				.'because an empty list reads as both "none" and "all".'
			);
		}
	}//end __construct()

	/**
	 * The same query, with the schemas whose objects are parties.
	 *
	 * @param array<int, string> $schemas The party schema ids.
	 *
	 * @return self The query.
	 *
	 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
	 */
	public function withPartySchemas(array $schemas): self {
		$query = clone $this;
		$query->partySchemas = array_map('strval', array_values($schemas));

		return $query;
	}//end withPartySchemas()

	/**
	 * Whether an edge of this type is cut and reported.
	 *
	 * @param string $type The relation type.
	 *
	 * @return bool True when the type is pruned.
	 *
	 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
	 */
	public function prunes(string $type): bool {
		return in_array($type, $this->prune, true);
	}//end prunes()

	/**
	 * Whether an edge of this type is followed.
	 *
	 * @param string $type The relation type.
	 *
	 * @return bool True when no type filter is set or the type is named.
	 *
	 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
	 */
	public function follows(string $type): bool {
		return $this->types === null || in_array($type, $this->types, true);
	}//end follows()

	/**
	 * Whether objects of this schema are parties.
	 *
	 * @param string $schema The schema id.
	 *
	 * @return bool True for a party schema.
	 *
	 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
	 */
	public function isParty(string $schema): bool {
		return in_array($schema, $this->partySchemas, true);
	}//end isParty()
}//end class
