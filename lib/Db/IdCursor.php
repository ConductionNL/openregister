<?php

/**
 * IdCursor: the keyset position and size of a batch read.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The rows after one id, at most a given number of them.
 *
 * @spec exclude shared mapper plumbing, not a capability of its own
 */
final class IdCursor {

	/**
	 * Constructor.
	 *
	 * @param int $afterId Only rows with a higher id.
	 * @param int $limit   Maximum number of rows.
	 */
	public function __construct(
		public readonly int $afterId,
		public readonly int $limit,
	) {
	}//end __construct()

	/**
	 * The condition that keeps the rows after the cursor.
	 *
	 * @param IQueryBuilder $qb The query the condition is for.
	 *
	 * @return string The condition.
	 *
	 * @spec exclude shared mapper plumbing, not a capability of its own
	 */
	public function after(IQueryBuilder $qb): string {
		return $qb->expr()->gt('id', $qb->createNamedParameter($this->afterId, IQueryBuilder::PARAM_INT));
	}//end after()
}//end class
