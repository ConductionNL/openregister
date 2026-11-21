<?php

/**
 * BoundQueryColumn
 *
 * A column in a QueryBuilder condition, with the builder that binds its values.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * A column a QueryBuilder condition compares, together with the query builder
 * that binds the compared values as named parameters (ADR-011 Rule 2, value
 * object).
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */
final class BoundQueryColumn
{

	/**
	 * Constructor.
	 *
	 * @param IQueryBuilder $qb  The query builder that binds the values.
	 * @param string        $ref The column reference, with its table alias (`t.<column>`).
	 */
	public function __construct(
		public readonly IQueryBuilder $qb,
		public readonly string $ref,
	) {
	}//end __construct()
}//end class
