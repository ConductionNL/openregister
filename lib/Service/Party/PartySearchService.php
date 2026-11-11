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
 * @spec openspec/specs/party-model/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Party;

use OCA\OpenRegister\Db\AuditTrail\PartyQueryRefusal;
use Exception;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCP\IAppConfig;
use Throwable;

/**
 * Runs a party query against the cap.
 *
 * @spec openspec/specs/party-model/spec.md#requirement-a-person-query-over-the-administered-cap-is-refused-req-prm-004
 */
class PartySearchService implements PartySearchInterface {

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
	 * @param ObjectOperationsInterface $objects The object layer party records live in.
	 * @param PartySchemaCatalogueInterface $parties The party schemas.
	 * @param AuditTrailMapperInterface $audit The audit trail, for a refused attempt.
	 * @param IAppConfig $config The administered cap.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objects,
		private readonly PartySchemaCatalogueInterface $parties,
		private readonly AuditTrailMapperInterface $audit,
		private readonly IAppConfig $config,
	) {
	}//end __construct()

	/**
	 * The administered maximum result count for a party query.
	 *
	 * @return int The cap, at least one.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-person-query-over-the-administered-cap-is-refused-req-prm-004
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
	 * @spec openspec/specs/party-model/spec.md#requirement-a-person-query-over-the-administered-cap-is-refused-req-prm-004
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

		$run   = new PartyQueryRun(term: $term, schemaIds: $schemaIds, cap: $this->cap());
		$total = $this->countAll(run: $run);
		if ($total > $run->cap) {
			$this->refuseOverCap(run: $run, total: $total);
		}

		return ['results' => $this->findAll(run: $run), 'total' => $total, 'cap' => $run->cap];
	}//end search()

	/**
	 * How many parties the term matches over the schemas.
	 *
	 * @param PartyQueryRun $run The term and the schemas.
	 *
	 * @return int The total.
	 */
	private function countAll(PartyQueryRun $run): int {
		$total = 0;
		foreach ($run->schemaIds as $id) {
			$total += $this->countIn(run: $run, schemaId: $id);
		}

		return $total;
	}//end countAll()

	/**
	 * Record the refused attempt on the trail and refuse it.
	 *
	 * Only name a schema when the query ran over exactly one, so the trail
	 * never implies a scope the caller did not ask for.
	 *
	 * @param PartyQueryRun $run   The term, the schemas searched and the cap.
	 * @param int           $total How many parties the term matches.
	 *
	 * @return never
	 *
	 * @throws Exception 403, always.
	 */
	private function refuseOverCap(PartyQueryRun $run, int $total): never {
		$searched = null;
		if (count($run->schemaIds) === 1) {
			$searched = $run->schemaIds[0];
		}

		$this->audit->recordPartyQueryRefusal(
			refusal: new PartyQueryRefusal(query: $run->term, cap: $run->cap, would: $total, schema: $searched)
		);

		throw new Exception(
			'The query matches ' . $total . ' parties, over the administered cap of ' . $run->cap
			. '. Narrow the query: results are refused, never truncated.',
			403
		);
	}//end refuseOverCap()

	/**
	 * The matches of every schema, in schema order.
	 *
	 * @param PartyQueryRun $run The term, the schemas and the cap.
	 *
	 * @return array<int, mixed> The matches.
	 */
	private function findAll(PartyQueryRun $run): array {
		$results = [];
		foreach ($run->schemaIds as $id) {
			foreach ($this->findIn(run: $run, schemaId: $id) as $party) {
				$results[] = $party;
			}
		}

		return $results;
	}//end findAll()

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
			if (self::selects(schemaId: $schemaId, schema: $party['schema']) === true) {
				$ids[] = (int)$party['schema']->getId();
			}
		}

		return $ids;
	}//end schemaIds()

	/**
	 * Whether a filter selects a schema: no filter, or its id, slug or uuid.
	 *
	 * @param int|string|null $schemaId The filter.
	 * @param Schema          $schema   The schema.
	 *
	 * @return bool Whether the schema is searched.
	 */
	private static function selects(int|string|null $schemaId, Schema $schema): bool {
		if ($schemaId === null) {
			return true;
		}

		return in_array((string)$schemaId, [(string)(int)$schema->getId(), (string)$schema->getSlug(), (string)$schema->getUuid()], true);
	}//end selects()

	/**
	 * How many parties of one schema the term matches.
	 *
	 * @param PartyQueryRun $run      The search term.
	 * @param int           $schemaId The schema.
	 *
	 * @return int The count, 0 when the count cannot be taken.
	 */
	private function countIn(PartyQueryRun $run, int $schemaId): int {
		try {
			return $this->objects->count(
				config: ['filters' => ['schema' => $schemaId], 'search' => $run->term]
			);
		} catch (Throwable) {
			return 0;
		}
	}//end countIn()

	/**
	 * The parties of one schema the term matches.
	 *
	 * @param PartyQueryRun $run      The search term and the cap, which the result set is already known not to exceed.
	 * @param int           $schemaId The schema.
	 *
	 * @return array<int, mixed> The matches.
	 */
	private function findIn(PartyQueryRun $run, int $schemaId): array {
		try {
			return $this->objects->findAll(
				config: ['filters' => ['schema' => $schemaId], 'search' => $run->term, 'limit' => $run->cap]
			);
		} catch (Throwable) {
			return [];
		}
	}//end findIn()
}//end class
