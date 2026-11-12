<?php

/**
 * RbacFilterCall
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Db
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Db\Schema;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * One application of the RBAC read filter: the query it narrows, on top of
 * the access decision it applies (on the magic table aliased `t`).
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class RbacFilterCall extends RbacAccessCall {

	/**
	 * Constructor.
	 *
	 * @param IQueryBuilder $qb     The query to narrow.
	 * @param Schema        $schema The schema whose authorization decides.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function __construct(
		public readonly IQueryBuilder $qb,
		Schema $schema,
	) {
		parent::__construct(schema: $schema, columnPrefix: 't.');
	}//end __construct()

	/**
	 * Narrow the query to no rows at all.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function denyAll(): void {
		$this->qb->andWhere($this->qb->expr()->eq($this->qb->createNamedParameter(1), $this->qb->createNamedParameter(0)));
	}//end denyAll()
}//end class
