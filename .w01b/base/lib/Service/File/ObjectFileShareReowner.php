<?php

/**
 * Points the published link shares of moved object files at the openregister account.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-existing-files-move-into-openregisters-own-account-req-ofoa-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * A link share whose owner no longer holds the file stops resolving; this re-owns it.
 *
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-existing-files-move-into-openregisters-own-account-req-ofoa-004
 */
class ObjectFileShareReowner {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database.
	 */
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * Re-own the link shares of these files to an account.
	 *
	 * @param array<int, int> $fileIds The moved files.
	 * @param string          $owner   The account that now holds them.
	 *
	 * @return int How many shares were re-owned.
	 *
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-existing-files-move-into-openregisters-own-account-req-ofoa-004
	 */
	public function reown(array $fileIds, string $owner): int {
		$count = 0;
		foreach (array_chunk($fileIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('share')
				->set('uid_owner', $qb->createNamedParameter($owner))
				->where($qb->expr()->in('file_source', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter(3, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->neq('uid_owner', $qb->createNamedParameter($owner)));
			$count += $qb->executeStatement();
		}

		return $count;
	}//end reown()
}//end class
