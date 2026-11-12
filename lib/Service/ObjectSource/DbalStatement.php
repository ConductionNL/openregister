<?php

/**
 * DbalStatement: a query builder together with the connection that quotes its identifiers.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * A statement under construction and the connection whose platform quotes its identifiers.
 */
final class DbalStatement {

	/**
	 * Constructor.
	 *
	 * @param QueryBuilder $qb         The query builder (mutated by the predicates).
	 * @param Connection   $connection The DBAL connection.
	 */
	public function __construct(
		public readonly QueryBuilder $qb,
		public readonly Connection $connection,
	) {
	}//end __construct()

	/**
	 * Quote an identifier for the connection's platform.
	 *
	 * @param string $identifier The column or table name.
	 *
	 * @return string The quoted identifier.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function quote(string $identifier): string {
		return $this->connection->getDatabasePlatform()->quoteIdentifier($identifier);
	}//end quote()

	/**
	 * Add one bound `column = value` predicate per pair.
	 *
	 * @param array<int, array{0: string, 1: mixed}> $pairs Column and value pairs, in order.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function andEquals(array $pairs): void {
		foreach ($pairs as [$column, $value]) {
			$this->qb->andWhere($this->quote(identifier: $column) . ' = ' . $this->qb->createNamedParameter($value));
		}
	}//end andEquals()
}//end class
