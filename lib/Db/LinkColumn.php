<?php

/**
 * LinkColumn: a column and the value it must equal, optionally followed by more columns.
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
 * A column matched by equality; an integer value binds as an integer and null matches IS NULL.
 *
 * @spec exclude shared mapper plumbing, not a capability of its own
 */
final class LinkColumn {

	/**
	 * The next column that must match as well, if any.
	 *
	 * @var LinkColumn|null
	 */
	private ?LinkColumn $next = null;

	/**
	 * Constructor.
	 *
	 * @param string     $name  The column name.
	 * @param int|string|null $value The value to match; null matches IS NULL.
	 */
	public function __construct(
		private readonly string $name,
		private readonly int|string|null $value,
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
		if ($this->value === null) {
			return $qb->expr()->isNull($this->name);
		}

		if (is_int($this->value) === true) {
			return $qb->expr()->eq($this->name, $qb->createNamedParameter($this->value, IQueryBuilder::PARAM_INT));
		}

		return $qb->expr()->eq($this->name, $qb->createNamedParameter($this->value));
	}//end equals()

	/**
	 * These columns followed by one more.
	 *
	 * @param LinkColumn $column The column that must match as well.
	 *
	 * @return LinkColumn The longer key.
	 *
	 * @spec exclude shared mapper plumbing, not a capability of its own
	 */
	public function also(LinkColumn $column): LinkColumn {
		$copy       = clone $this;
		$copy->next = $column;
		if ($this->next !== null) {
			$copy->next = $this->next->also(column: $column);
		}

		return $copy;
	}//end also()

	/**
	 * Add one AND condition per column to a query, in order.
	 *
	 * @param IQueryBuilder $qb The query.
	 *
	 * @return void
	 *
	 * @spec exclude shared mapper plumbing, not a capability of its own
	 */
	public function applyTo(IQueryBuilder $qb): void {
		$qb->andWhere($this->equals(qb: $qb));
		$this->next?->applyTo(qb: $qb);
	}//end applyTo()
}//end class
