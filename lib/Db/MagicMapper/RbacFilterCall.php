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
 * One application of the RBAC read filter: the query it narrows, the schema
 * and action it decides, the caller, and the schema's authorization block.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class RbacFilterCall {

	/**
	 * The CRUD action being checked.
	 *
	 * @var string
	 */
	public string $action = 'read';

	/**
	 * The bare magic table being read, or null.
	 *
	 * @var string|null
	 */
	public ?string $tableName = null;

	/**
	 * The calling user's id, or null for an anonymous caller.
	 *
	 * @var string|null
	 */
	public ?string $userId = null;

	/**
	 * The calling user's group ids.
	 *
	 * @var array<int, string>
	 */
	public array $userGroups = [];

	/**
	 * The schema's resolved authorization block; null when it has none.
	 *
	 * @var array|null
	 */
	public ?array $authorization = null;

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
		public readonly Schema $schema,
	) {
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
