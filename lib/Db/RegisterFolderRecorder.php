<?php

/**
 * OpenRegister register folder recorder
 *
 * Records the node id of the folder the file service made for a register, as
 * bookkeeping rather than as an edit of the register. The first upload into a
 * register is often a portal request with no Nextcloud session, which may not
 * update registers and acts in the default organisation, so storing the folder
 * id through RegisterMapper::update() refused it and the upload failed on every
 * fresh instance (portaliq#29). This write touches the one column, dispatches no
 * register-updated event, and only lands while the stored value is still empty
 * or what the caller read, so it can never repoint a folder another request
 * recorded first (register-folder-on-first-upload). It also answers whether
 * another register records a folder id, so a register delete removes only a
 * folder no live register holds (openregister#4107).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-recording-a-registers-folder-id-is-bookkeeping-req-rffu-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Writes a register's folder id, and nothing else, with a compare-and-set; and says who else holds one.
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-recording-a-registers-folder-id-is-bookkeeping-req-rffu-002
 */
class RegisterFolderRecorder {

	/**
	 * The registers table.
	 *
	 * @var string
	 */
	private const TABLE = 'openregister_registers';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * Record a register's folder id while the stored value is still empty or what the caller read.
	 *
	 * @param int $registerId The register the upload resolved.
	 * @param string|null $expected The folder value read before the folder was made: null or '' for none,
	 *                              or the stale id or legacy path that no longer resolves.
	 * @param string $folderId The node id of the folder the file service made or found.
	 *
	 * @return bool True when this call recorded the id; false when another request recorded one first.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-recording-a-registers-folder-id-is-bookkeeping-req-rffu-002
	 */
	public function record(int $registerId, ?string $expected, string $folderId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('folder', $qb->createNamedParameter($folderId))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->isNull('folder'),
					$qb->expr()->eq('folder', $qb->createNamedParameter((string)$expected))
				)
			);

		return $qb->executeStatement() > 0;
	}//end record()

	/**
	 * Whether a register other than the given one records this folder id.
	 *
	 * Two registers of one title are handed the same folder, so a register
	 * delete asks this before removing its folder. Read without RBAC or
	 * organisation filters on purpose: a register the deleting user cannot see
	 * still holds the folder.
	 *
	 * @param string $folderId The folder id the deleted register recorded.
	 * @param int $registerId The register being deleted.
	 *
	 * @return bool True when another register row holds the same folder id.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function isRecordedByAnotherRegister(string $folderId, int $registerId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from(self::TABLE)
			->where($qb->expr()->eq('folder', $qb->createNamedParameter($folderId)))
			->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$found = $result->fetchOne();
		$result->closeCursor();

		return $found !== false;
	}//end isRecordedByAnotherRegister()
}//end class
