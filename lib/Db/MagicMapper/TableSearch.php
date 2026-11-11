<?php

/**
 * TableSearch
 *
 * A search of one register+schema magic table.
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

use OCA\OpenRegister\Db\RegisterSchema;

/**
 * One search of one register+schema magic table, apart from the request
 * query: the register and schema the table belongs to, its name, and the
 * columns whose real type is a date or timestamp (ADR-011 Rule 2, operation
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
final class TableSearch
{

	/**
	 * Constructor.
	 *
	 * @param RegisterSchema          $table           The register and schema of the table.
	 * @param string                  $tableName       The dynamic table name.
	 * @param array<int, string>|null $dateTimeColumns Columns whose real type is a date or timestamp; null when unknown.
	 */
	public function __construct(
		public readonly RegisterSchema $table,
		public readonly string $tableName,
		public readonly ?array $dateTimeColumns = null,
	) {
	}//end __construct()
}//end class
