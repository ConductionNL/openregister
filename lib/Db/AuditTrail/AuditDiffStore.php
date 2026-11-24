<?php

/**
 * AuditDiffStore: one record's stored audit diffs, read and rewritten by an anonymisation.
 *
 * The audit trail is otherwise append-only. The anonymisation is the one act
 * that rewrites a stored diff (design D-5 of anonymising-as-an-archival-outcome),
 * so the rewrite lives here, in a store of its own, rather than as one more
 * method on the audit writer every other caller uses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\AuditTrail
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/gdpr-data-subject-rights/spec.md#requirement-the-trail-keeps-the-act-and-loses-the-values-req-aao-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\AuditTrail;

use OCA\OpenRegister\Db\AuditTrail;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Reads and rewrites the stored diffs of one record's audit rows.
 *
 * @template-extends QBMapper<AuditTrail>
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/gdpr-data-subject-rights/spec.md#requirement-the-trail-keeps-the-act-and-loses-the-values-req-aao-006
 */
class AuditDiffStore extends QBMapper implements AuditDiffStoreInterface {

	/**
	 * The audit trail table.
	 *
	 * @var string
	 */
	private const TABLE = 'openregister_audit_trails';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: self::TABLE, entityClass: AuditTrail::class);
	}//end __construct()

	/**
	 * The stored diffs of one record, oldest first, keyed by audit row id.
	 *
	 * @param string $objectUuid The record.
	 *
	 * @return array<int, array<string, mixed>> The diffs, by row id.
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/gdpr-data-subject-rights/spec.md#requirement-the-trail-keeps-the-act-and-loses-the-values-req-aao-006
	 */
	public function diffsOfObject(string $objectUuid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'changed')
			->from(self::TABLE)
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere($qb->expr()->isNull('purged_at'))
			->orderBy('id', 'ASC');

		$result = $qb->executeQuery();
		$rows   = $result->fetchAll();
		$result->closeCursor();

		$diffs = [];
		foreach ($rows as $row) {
			$diffs[(int)$row['id']] = self::decoded(changed: ($row['changed'] ?? null));
		}

		return $diffs;
	}//end diffsOfObject()

	/**
	 * A stored diff as an array, whatever the driver handed back.
	 *
	 * @param mixed $changed The column value: JSON text, an array, or null.
	 *
	 * @return array<string, mixed> The diff; empty when there is none or it is unreadable.
	 */
	private static function decoded(mixed $changed): array {
		if (is_array($changed) === true) {
			return $changed;
		}

		if (is_string($changed) === false || $changed === '') {
			return [];
		}

		$decoded = json_decode($changed, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end decoded()

	/**
	 * Replace one row's stored diff.
	 *
	 * @param int                  $id      The audit row id.
	 * @param array<string, mixed> $changed The diff to store.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/gdpr-data-subject-rights/spec.md#requirement-the-trail-keeps-the-act-and-loses-the-values-req-aao-006
	 */
	public function rewriteDiff(int $id, array $changed): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('changed', $qb->createNamedParameter(json_encode($changed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}//end rewriteDiff()

	/**
	 * Insert the entry that records an act on the trail.
	 *
	 * Left unsealed, like every other inserted row: the caller re-seals the
	 * chain over it, or the seal sweep chains it.
	 *
	 * @param AuditTrail $entry The entry, not yet inserted.
	 *
	 * @return AuditTrail The inserted entry, carrying its id.
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/gdpr-data-subject-rights/spec.md#requirement-the-trail-keeps-the-act-and-loses-the-values-req-aao-006
	 */
	public function recordAct(AuditTrail $entry): AuditTrail {
		return $this->insert(entity: $entry);
	}//end recordAct()
}//end class
