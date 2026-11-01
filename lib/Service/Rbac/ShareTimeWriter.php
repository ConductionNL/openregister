<?php

/**
 * Sets the share time of a share that was just created.
 *
 * Core stamps a new share with the current time in `createShare()` and its
 * provider's update never writes the share time, so a grant dated in the past
 * (an example set's "gedeeld op 6 okt") is written here, on the share row and
 * on the per-recipient rows of a group share.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
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
 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-grants-on-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use DateTimeInterface;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Writes `stime` of one share and its per-recipient rows.
 *
 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-grants-on-its-objects
 */
class ShareTimeWriter {

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
	 * Set the share time of one share.
	 *
	 * @param int               $shareId The share's own id (not the provider-prefixed one).
	 * @param DateTimeInterface $moment  The share time.
	 *
	 * @return int How many rows were written.
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-grants-on-its-objects
	 */
	public function stamp(int $shareId, DateTimeInterface $moment): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update('share')
			->set('stime', $qb->createNamedParameter($moment->getTimestamp(), IQueryBuilder::PARAM_INT))
			->where(
				$qb->expr()->orX(
					$qb->expr()->eq('id', $qb->createNamedParameter($shareId, IQueryBuilder::PARAM_INT)),
					$qb->expr()->eq('parent', $qb->createNamedParameter($shareId, IQueryBuilder::PARAM_INT))
				)
			);

		return $qb->executeStatement();
	}//end stamp()
}//end class
