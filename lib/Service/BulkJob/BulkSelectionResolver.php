<?php

/**
 * Turns a bulk job's selection into the objects it stands for.
 *
 * "Select all" means the page in one product and the whole result set in
 * another, and the difference is four hundred cases. A selection here is
 * therefore one of two declared things: an explicit list of uuids, or a
 * query with its filters. The job stores which, and a query-backed job is
 * re-resolved at commit so a selection that grew is a visible fact rather
 * than a surprise (D-2).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\BulkJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\BulkJob;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use Psr\Log\LoggerInterface;

/**
 * Class BulkSelectionResolver
 */
class BulkSelectionResolver {

	/**
	 * How many uuids one hydration query asks for at a time.
	 *
	 * @var int
	 */
	private const HYDRATION_CHUNK = 200;

	/**
	 * Query keys that would make the search return rendered projections
	 * instead of entities, and which a selection has no use for anyway.
	 *
	 * @var array<int, string>
	 */
	private const STRIPPED_QUERY_KEYS = ['_extend', '_fields', '_filter', '_unset', '_limit', '_offset', '_count', '_page'];

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objectService The object read path.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The uuids an id-list selection names, deduplicated, as strings, in a stable order.
	 *
	 * @param array<string, mixed> $criteria The stored selection, `{"ids": [...]}`.
	 *
	 * @return array<int, string> The selected uuids; empty when `ids` is not a list.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function idsOf(array $criteria): array {
		$ids = ($criteria['ids'] ?? []);

		if (is_array($ids) === false) {
			return [];
		}

		return array_values(array_unique(array_map(static fn ($id): string => (string)$id, $ids)));
	}//end idsOf()

	/**
	 * Load the objects behind a list of uuids, keyed by uuid.
	 *
	 * A uuid the caller may not read simply does not come back, which the
	 * executor reports as a refusal rather than dropping it (D-3).
	 *
	 * @param array<int, string> $uuids The uuids to load.
	 * @param BulkJobSelection   $scope The selection whose register and schema scope the read.
	 *
	 * @return array<string, ObjectEntity> The objects, keyed by uuid.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function hydrate(array $uuids, BulkJobSelection $scope): array {
		$objects = [];

		foreach (array_chunk($uuids, self::HYDRATION_CHUNK) as $chunk) {
			$found = $this->objectService->searchObjects(
				query: $this->scopedQuery(query: [], scope: $scope),
				ids: $chunk
			);

			if (is_array($found) === false) {
				continue;
			}

			foreach ($found as $object) {
				if (($object instanceof ObjectEntity) === false) {
					continue;
				}

				$objects[(string)$object->getUuid()] = $object;
			}
		}//end foreach

		return $objects;
	}//end hydrate()

	/**
	 * Resolve a query-backed selection to uuids.
	 *
	 * One more than the ceiling is asked for, so the caller can tell a
	 * selection that exactly fills the ceiling from one that overflows it.
	 *
	 * @param BulkJobSelection $selection The stored selection, `{"query": {...}}`, and its register and schema.
	 * @param int              $ceiling   The largest selection a job may carry.
	 *
	 * @return array<int, string> The matching uuids.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function queryUuids(BulkJobSelection $selection, int $ceiling): array {
		$scoped = $this->scopedQuery(query: ($selection->criteria['query'] ?? []), scope: $selection);
		$scoped['_limit'] = ($ceiling + 1);

		$found = $this->objectService->searchObjects(query: $scoped);

		if (is_array($found) === false) {
			$this->logger->warning(
				message: '[BulkSelectionResolver] The selection query answered a count rather than objects',
				context: ['register' => $selection->registerId, 'schema' => $selection->schemaId]
			);

			return [];
		}

		$uuids = [];
		foreach ($found as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			$uuids[] = (string)$object->getUuid();
		}

		return $uuids;
	}//end queryUuids()

	/**
	 * The caller's query, scoped to the job's register and schema and
	 * stripped of the keys that would turn entities into projections.
	 *
	 * @param array<string, mixed> $query The caller's query.
	 * @param BulkJobSelection     $scope The selection whose register and schema scope the query.
	 *
	 * @return array<string, mixed> The scoped query.
	 */
	private function scopedQuery(array $query, BulkJobSelection $scope): array {
		foreach (self::STRIPPED_QUERY_KEYS as $key) {
			unset($query[$key]);
		}

		$self = ($query['@self'] ?? []);
		if (is_array($self) === false) {
			$self = [];
		}

		if ($scope->registerId !== null) {
			$self['register'] = $scope->registerId;
		}

		if ($scope->schemaId !== null) {
			$self['schema'] = $scope->schemaId;
		}

		if ($self !== []) {
			$query['@self'] = $self;
		}

		return $query;
	}//end scopedQuery()
}//end class
