<?php

/**
 * ReadStateScope: the register and schema a seen-set read is narrowed to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\ObjectReadState
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\ObjectReadState;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * A register and a schema as stored on a read-state row; null or empty means no restriction.
 *
 * @spec openspec/specs/object-read-state/spec.md
 */
final class ReadStateScope {

	/**
	 * Constructor.
	 *
	 * @param string|null $register The register, as stored on the row.
	 * @param string|null $schema   The schema, as stored on the row.
	 */
	public function __construct(
		public readonly ?string $register=null,
		public readonly ?string $schema=null,
	) {
	}//end __construct()

	/**
	 * Narrow a read-state query to this scope.
	 *
	 * @param IQueryBuilder $qb The query being built.
	 *
	 * @return void
	 */
	public function applyTo(IQueryBuilder $qb): void {
		foreach (['register' => $this->register, 'schema' => $this->schema] as $column => $value) {
			if ($value !== null && $value !== '') {
				$qb->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($value)));
			}
		}
	}//end applyTo()
}//end class
