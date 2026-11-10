<?php

/**
 * WhereClauseRequest
 *
 * A request for the raw-SQL WHERE conditions of one magic table.
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

/**
 * What the raw-SQL condition builder of MagicSearchHandler needs to answer
 * one request for the WHERE conditions of one magic table (the UNION search
 * arms and the UNION facet path): the request query, the table read (schema,
 * register and table name) and, when known, the columns the table really has
 * (ADR-011 Rule 2, operation object).
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */
final class WhereClauseRequest
{

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>   $query           The request query.
	 * @param MagicReadTable         $table           The table the conditions are for.
	 * @param array<int, string>|null $existingColumns The columns the table has; null when unknown.
	 */
	public function __construct(
		public readonly array $query,
		public readonly MagicReadTable $table,
		public readonly ?array $existingColumns = null,
	) {
	}//end __construct()
}//end class
