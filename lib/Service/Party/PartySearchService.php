<?php

/**
 * A party query, capped.
 *
 * A person search that silently returns the first ten looks like a search
 * that found ten. This one refuses: the cap is administered, the refusal
 * names it, nothing comes back, and the attempt is on the audit trail. That
 * is the difference between proportionality and a page size.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Party
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Party;

use Exception;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use Throwable;

/**
 * Runs a party query against the cap.
 *
 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-person-query-over-the-administered-cap-is-refused-req-prm-004
 */
class PartySearchService {

	/**
	 * The app config key holding the cap.
	 */
	public const CAP_KEY = 'party_query_cap';

	/**
	 * The cap an instance that has never set one runs at.
	 */
	public const DEFAULT_CAP = 50;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objects The object layer party records live in.
	 * @param PartyService $parties The party schemas.
	 * @param AuditTrailMapper $audit The audit trail, for a refused attempt.
	 * @param IAppConfig $config The administered cap.
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly PartyService $parties,
		private readonly AuditTrailMapper $audit,
		private readonly IAppConfig $config,
	) {
	}//end __construct()

	/**
	 * The administered maximum result count for a party query.
	 *
	 * @return int The cap, at least one.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-person-query-over-the-administered-cap-is-refused-req-prm-004
	 */
	public function cap(): int {
		$cap = $this->config->getValueInt('openregister', self::CAP_KEY, self::DEFAULT_CAP);
		if ($cap < 1) {
			return self::DEFAULT_CAP;
		}

		return $cap;
	}//end cap()

	/**
	 * Search the parties, or refuse when the result set would exceed the cap.
	 *
	 * The count is taken before anything is read, so a refusal never reads
	 * the records it refuses to return.
	 *
	 * @param string $query The search term.
	 * @param int|string|null $schemaId Restrict to one party schema, or null for all of them.
	 *
	 * @return array{results: array<int, mixed>, total: int, cap: int} The matches.
	 *
	 * @throws Exception 400 when the query is empty, 403 naming the cap when it is exceeded.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-person-query-over-the-administered-cap-is-refused-req-prm-004
	 */
	public function search(string $query, int|string|null $schemaId = null): array {
		$term = trim($query);
		if ($term === '') {
			throw new Exception('A party query needs a search term', 400);
		}

		$schemaIds = $this->schemaIds(schemaId: $schemaId);
		if ($schemaIds === []) {
			return ['results' => [], 'total' => 0, 'cap' => $this->cap()];
		}

		$cap = $this->cap();
		$total = 0;
		foreach ($schemaIds as $id) {
			$total += $this->countIn(schemaId: $id, term: $term);
		}

		if ($total > $cap) {
			// Only name a schema when the query ran over exactly one, so the
			// trail never implies a scope the caller did not ask for.
			$searched = null;
			if (count($schemaIds) === 1) {
				$searched = $schemaIds[0];
			}

			$this->audit->createPartyQueryRefusalEntry(
				query: $term,
				cap: $cap,
				would: $total,
				schema: $searched
			);

			throw new Exception(
				'The query matches ' . $total . ' parties, over the administered cap of ' . $cap
				. '. Narrow the query: results are refused, never truncated.',
				403
			);
		}

		$results = [];
		foreach ($schemaIds as $id) {
			foreach ($this->findIn(schemaId: $id, term: $term, limit: $cap) as $party) {
				$results[] = $party;
			}
		}

		return ['results' => $results, 'total' => $total, 'cap' => $cap];
	}//end search()

	/**
	 * The party schema ids a query runs over.
	 *
	 * @param int|string|null $schemaId The named schema, or null for every party schema.
	 *
	 * @return array<int, int> The ids.
	 */
	private function schemaIds(int|string|null $schemaId): array {
		$ids = [];
		foreach ($this->parties->partySchemas() as $party) {
			$id = (int)$party['schema']->getId();
			if ($schemaId === null
				|| (string)$schemaId === (string)$id
				|| (string)$schemaId === (string)$party['schema']->getSlug()
				|| (string)$schemaId === (string)$party['schema']->getUuid()
			) {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end schemaIds()

	/**
	 * How many parties of one schema the term matches.
	 *
	 * @param int $schemaId The schema.
	 * @param string $term The search term.
	 *
	 * @return int The count, 0 when the count cannot be taken.
	 */
	private function countIn(int $schemaId, string $term): int {
		try {
			return $this->objects->count(
				config: ['filters' => ['schema' => $schemaId], 'search' => $term]
			);
		} catch (Throwable) {
			return 0;
		}
	}//end countIn()

	/**
	 * The parties of one schema the term matches.
	 *
	 * @param int $schemaId The schema.
	 * @param string $term The search term.
	 * @param int $limit The cap, which the result set is already known not to exceed.
	 *
	 * @return array<int, mixed> The matches.
	 */
	private function findIn(int $schemaId, string $term, int $limit): array {
		try {
			return $this->objects->findAll(
				config: ['filters' => ['schema' => $schemaId], 'search' => $term, 'limit' => $limit]
			);
		} catch (Throwable) {
			return [];
		}
	}//end findIn()
}//end class
