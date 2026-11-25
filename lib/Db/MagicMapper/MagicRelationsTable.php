<?php

/**
 * MagicRelationsTable: the relations column of one magic table.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use DateTime;
use OCP\IDBConnection;

/**
 * Add a UUID to the `_relations` of one row, and touch only that row's
 * `_relations` and `_updated`.
 *
 * This is the inverse side of a relation: the object pointed at learns who
 * points at it. It changes no property of that object, so it writes no
 * version; MagicMapper::appendRelation() dispatches the object's update event
 * once this write changed the row.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class MagicRelationsTable {
	private const RELATIONS = '_relations';

	private const UUID = '_uuid';

	private const DELETED = '_deleted';

	private const UPDATED = '_updated';

	/**
	 * Bind the table.
	 *
	 * @param IDBConnection $db        The connection.
	 * @param string        $tableName The magic table, without the `oc_` prefix.
	 */
	public function __construct(private readonly IDBConnection $db, private readonly string $tableName) {
	}//end __construct()

	/**
	 * Add a UUID to a live row's relations unless they already hold it.
	 *
	 * @param string $uuid        The row's object.
	 * @param string $relatedUuid The object that points at it.
	 *
	 * @return int Rows updated: 0 when the row is missing, deleted or already holds the UUID.
	 */
	public function append(string $uuid, string $relatedUuid): int {
		$relations = $this->relationsOf(uuid: $uuid);
		if ($relations === null || in_array($relatedUuid, $relations, true) === true) {
			return 0;
		}

		$relations[] = $relatedUuid;

		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName)
			->set(self::RELATIONS, $qb->createNamedParameter(json_encode($relations)))
			->set(self::UPDATED, $qb->createNamedParameter((new DateTime())->format('Y-m-d H:i:s')))
			->where($qb->expr()->eq(self::UUID, $qb->createNamedParameter($uuid)))
			->andWhere($qb->expr()->isNull(self::DELETED));

		return $qb->executeStatement();
	}//end append()

	/**
	 * The relations a live row holds, or null when there is no such row.
	 *
	 * @param string $uuid The row's object.
	 *
	 * @return array<int|string, mixed>|null The relations; an empty or unreadable column is an empty list.
	 */
	private function relationsOf(string $uuid): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(self::RELATIONS)
			->from($this->tableName)
			->where($qb->expr()->eq(self::UUID, $qb->createNamedParameter($uuid)))
			->andWhere($qb->expr()->isNull(self::DELETED))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$row    = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			return null;
		}

		$stored = $row[self::RELATIONS] ?? null;
		if (is_array($stored) === true) {
			return $stored;
		}

		$decoded = json_decode((string)$stored, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end relationsOf()
}//end class
