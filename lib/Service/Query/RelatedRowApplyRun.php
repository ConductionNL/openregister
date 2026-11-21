<?php

/**
 * One pass of RelatedRowQueryApplier over a query's related-row blocks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Query
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Query;

use Closure;
use OCA\OpenRegister\Db\Register;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The query being narrowed, the register its related rows live in, the
 * organisation scope per related table, the SQL engine, and how many clauses
 * were applied so far. Built by RelatedRowQueryApplier::apply and never handed out.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class RelatedRowApplyRun {

	/**
	 * How many clauses were applied so far; also the position of the next block.
	 *
	 * @var integer
	 */
	public int $applied = 0;

	/**
	 * Constructor.
	 *
	 * @param IQueryBuilder $qb                   The query being built.
	 * @param Register      $register             The register the related rows live in.
	 * @param Closure       $organisationScopeFor Given the related schema and the register id, the organisation scope.
	 * @param string        $engine               The SQL engine the clauses are rendered for.
	 */
	public function __construct(
		public readonly IQueryBuilder $qb,
		public readonly Register $register,
		public readonly Closure $organisationScopeFor,
		public readonly string $engine,
	) {
	}//end __construct()
}//end class
