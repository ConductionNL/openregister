<?php

/**
 * QuotedSqlColumn
 *
 * A column in a raw-SQL condition, with the connection that quotes its values.
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
 * A column a raw-SQL condition compares (the UNION search and facet paths),
 * together with the connection that quotes the compared values inline, since
 * those queries carry no bound parameters (ADR-011 Rule 2, value object).
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */
final class QuotedSqlColumn
{

	/**
	 * Constructor.
	 *
	 * @param string $name       The column as written in the SQL, already quoted where needed.
	 * @param object $connection The connection that quotes values.
	 */
	public function __construct(
		public readonly string $name,
		public readonly object $connection,
	) {
	}//end __construct()
}//end class
