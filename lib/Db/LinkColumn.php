<?php

/**
 * LinkColumn: the second key column of an object link and its value.
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
 * One column of a link table matched by equality; an integer value binds as an integer.
 *
 * @spec exclude shared mapper plumbing, not a capability of its own
 */
final class LinkColumn {

	/**
	 * Constructor.
	 *
	 * @param string     $name  The column name.
	 * @param int|string $value The value to match.
	 */
	public function __construct(
		private readonly string $name,
		private readonly int|string $value,
	) {
	}//end __construct()

	/**
	 * The equality condition for this column on a query.
	 *
	 * @param IQueryBuilder $qb The query the condition is for.
	 *
	 * @return string The condition.
	 *
	 * @spec exclude shared mapper plumbing, not a capability of its own
	 */
	public function equals(IQueryBuilder $qb): string {
		if (is_int($this->value) === true) {
			return $qb->expr()->eq($this->name, $qb->createNamedParameter($this->value, IQueryBuilder::PARAM_INT));
		}

		return $qb->expr()->eq($this->name, $qb->createNamedParameter($this->value));
	}//end equals()
}//end class
