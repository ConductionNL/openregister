<?php

/**
 * OpenRegister GdprEntity index lookup for the scoped data-subject service.
 *
 * Runs the GdprEntity ⋈ entity_relations join and groups the rows per owning
 * object. Only the DISCOVERY half: nothing here loads an object, so nothing
 * here decides what the caller may read. That stays with the RBAC + tenant
 * scoped object load in {@see SubjectObjectLoader}.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Subject
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

namespace OCA\OpenRegister\Service\Gdpr\Subject;

use OCA\OpenRegister\Service\Gdpr\SubjectQuery;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Cross-register PII index lookup, grouped by owning object.
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md#requirement-consumable-rbac-tenant-scoped-data-subject-request-service
 */
class SubjectEntityIndex {

	/**
	 * Columns read from the entity ⋈ relation join.
	 *
	 * @var list<string>
	 */
	private const COLUMNS = ['e.id', 'e.type', 'e.value', 'e.category', 'e.detected_at', 'r.object_id', 'r.object_uuid'];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection   $db     GdprEntity + entity_relations join.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Run the index join, grouped by owning object.
	 *
	 * LIKE wildcards in the subject are escaped before anchoring. Rows naming
	 * no object (no uuid and no positive id) are dropped.
	 *
	 * @param SubjectQuery $query The subject value, optional GdprEntity type filter and match mode (`exact` or `ilike`).
	 *
	 * @return array<string, array{object_id: int, object_uuid: string, gdprEntities: array}>
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md#requirement-consumable-rbac-tenant-scoped-data-subject-request-service
	 */
	public function discover(SubjectQuery $query): array {
		$grouped = [];
		foreach ($this->lookup(query: $query) as $hit) {
			$key = $this->groupKey(hit: $hit);
			if ($key === null) {
				continue;
			}

			$grouped[$key] ??= [
				'object_id' => (int)($hit['object_id'] ?? 0),
				'object_uuid' => (string)($hit['object_uuid'] ?? ''),
				'gdprEntities' => [],
			];

			$grouped[$key]['gdprEntities'][] = $this->entityOf(hit: $hit);
		}

		return $grouped;

	}//end discover()

	/**
	 * The grouping key of a row: its object uuid, else its object id, else none.
	 *
	 * @param array<string, mixed> $hit One index row.
	 *
	 * @return string|null `uuid:<uuid>`, `id:<id>`, or null when the row names no object.
	 */
	private function groupKey(array $hit): ?string {
		$uuid = (string)($hit['object_uuid'] ?? '');
		if ($uuid !== '') {
			return 'uuid:' . $uuid;
		}

		$id = (int)($hit['object_id'] ?? 0);
		if ($id > 0) {
			return 'id:' . $id;
		}

		return null;

	}//end groupKey()

	/**
	 * The matched-attribute envelope of one row.
	 *
	 * @param array<string, mixed> $hit One index row.
	 *
	 * @return array{type: string, value: string, category: string, detectedAt: string}
	 */
	private function entityOf(array $hit): array {
		return [
			'type' => (string)($hit['type'] ?? ''),
			'value' => (string)($hit['value'] ?? ''),
			'category' => (string)($hit['category'] ?? ''),
			'detectedAt' => (string)($hit['detected_at'] ?? ''),
		];

	}//end entityOf()

	/**
	 * The index lookup (one row per entity/object pair); empty on failure.
	 *
	 * @param SubjectQuery $query The subject value, optional type filter and match mode.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function lookup(SubjectQuery $query): array {
		try {
			return $this->rowsOf(qb: $this->query(query: $query));
		} catch (\Throwable $e) {
			// No subject and no driver message: both can carry the data subject's
			// identifier, and the app log is no place for personal data.
			$this->logger->warning(
				message: '[DSR] GdprEntity lookup failed',
				context: ['type' => $query->type, 'error' => $e::class]
			);
			return [];
		}

	}//end lookup()

	/**
	 * Build the join, anchored on the escaped subject and the optional type.
	 *
	 * @param SubjectQuery $query The subject value, optional type filter and match mode.
	 *
	 * @return IQueryBuilder
	 */
	private function query(SubjectQuery $query): IQueryBuilder {
		$type = $query->type;
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct(self::COLUMNS)
			->from('openregister_entities', 'e')
			->innerJoin('e', 'openregister_entity_relations', 'r', $qb->expr()->eq('r.entity_id', 'e.id'))
			->where($qb->expr()->isNotNull('r.object_id'));

		$pattern = $this->pattern(subject: $query->subjectId, mode: $query->mode);
		$qb->andWhere($qb->expr()->iLike('e.value', $qb->createNamedParameter($pattern)));

		if ($type !== null && $type !== '') {
			$qb->andWhere($qb->expr()->eq('e.type', $qb->createNamedParameter($type)));
		}

		return $qb;

	}//end query()

	/**
	 * The LIKE pattern: the escaped subject, wrapped in `%` for `ilike`.
	 *
	 * @param string $subject Subject value.
	 * @param string $mode    `exact` or `ilike`.
	 *
	 * @return string
	 */
	private function pattern(string $subject, string $mode): string {
		$escaped = $this->db->escapeLikeParameter($subject);
		if ($mode === 'ilike') {
			return '%' . $escaped . '%';
		}

		return $escaped;

	}//end pattern()

	/**
	 * Execute the query and hand back every row, closing the cursor.
	 *
	 * @param IQueryBuilder $qb The built query.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rowsOf(IQueryBuilder $qb): array {
		$cursor = $qb->executeQuery();
		$rows = $cursor->fetchAll();
		$cursor->closeCursor();

		return $rows;

	}//end rowsOf()
}//end class
