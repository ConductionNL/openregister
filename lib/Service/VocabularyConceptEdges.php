<?php

/**
 * VocabularyConceptEdges — broader/narrower/related edge sets for one import.
 *
 * Every asserted edge is recorded in both directions (broader <-> narrower,
 * related <-> related), so a relation the source states only once is readable
 * from either concept (skos-concept-registers design.md D2). Only concepts of
 * the current import are seeded, so an edge removed from the source clears.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Accumulates concept relations by uuid.
 */
class VocabularyConceptEdges {
	/**
	 * Each relation predicate and the predicate its reverse edge is stored under.
	 */
	public const INVERSE = [
		'broader' => 'narrower',
		'narrower' => 'broader',
		'related' => 'related',
	];

	/**
	 * Predicate => concept uuid => target uuids.
	 *
	 * @var array<string,array<string,array<string>>>
	 */
	private array $edges = [];

	/**
	 * Seed every concept of this import with an empty edge set.
	 *
	 * @param array<string,string> $uuidByUri uri => uuid map for this import.
	 */
	public function __construct(
		private readonly array $uuidByUri,
	) {
		foreach (array_keys(self::INVERSE) as $predicate) {
			$this->edges[$predicate] = array_fill_keys(array_values($uuidByUri), []);
		}
	}//end __construct()

	/**
	 * Record one concept's asserted edges, resolving target uris to uuids;
	 * targets outside this import are ignored.
	 *
	 * @param string $uuid The concept's own uuid.
	 * @param array<string,array<string>> $targetsByPredicate Predicate => asserted target uris.
	 *
	 * @return void
	 */
	public function add(string $uuid, array $targetsByPredicate): void {
		foreach ($targetsByPredicate as $predicate => $targetUris) {
			foreach ($this->resolve(targetUris: $targetUris) as $targetUuid) {
				$this->edges[$predicate][$uuid][] = $targetUuid;
				$this->edges[self::INVERSE[$predicate]][$targetUuid][] = $uuid;
			}
		}
	}//end add()

	/**
	 * The de-duplicated relation fields of one concept.
	 *
	 * @param string $uuid The concept's uuid.
	 *
	 * @return array{broader: array<string>, narrower: array<string>, related: array<string>}
	 */
	public function relationsOf(string $uuid): array {
		$relations = [];
		foreach (array_keys(self::INVERSE) as $predicate) {
			$relations[$predicate] = array_values(array_unique($this->edges[$predicate][$uuid] ?? []));
		}

		return $relations;
	}//end relationsOf()

	/**
	 * The uuids of the target uris that belong to this import, in order.
	 *
	 * @param array<string> $targetUris Asserted target uris.
	 *
	 * @return array<int,string>
	 */
	private function resolve(array $targetUris): array {
		$uuids = array_map(fn (string $targetUri): ?string => ($this->uuidByUri[$targetUri] ?? null), $targetUris);
		return array_values(array_filter($uuids, static fn (?string $targetUuid): bool => $targetUuid !== null));
	}//end resolve()
}//end class
