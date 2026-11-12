<?php

/**
 * OpenRegister related-row filter target
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Query
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Query;

use Closure;
use OCA\OpenRegister\Db\Register;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The query a related-row filter narrows, the register its related rows live in, and the organisation scope of a related table.
 *
 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
 */
final class RelatedRowFilterTarget {
	/**
	 * Constructor.
	 *
	 * @param IQueryBuilder $qb                   The query being built.
	 * @param Register      $register             The register the related rows live in.
	 * @param Closure       $organisationScopeFor Given the related schema and the register id, the organisation scope the list path applies to that table: `array{mode: string, uuids: array<int, string>}`.
	 */
	public function __construct(
		public readonly IQueryBuilder $qb,
		public readonly Register $register,
		public readonly Closure $organisationScopeFor,
	) {
	}//end __construct()
}//end class
