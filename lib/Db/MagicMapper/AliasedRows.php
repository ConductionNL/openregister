<?php

/**
 * AliasedRows: the magic-table rows a subquery reads under an alias, and the action they are read for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * The alias the rows carry, the CRUD action they are filtered for and their bare magic table.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class AliasedRows {

	/**
	 * Constructor.
	 *
	 * @param string      $alias     The alias the rows carry in the subquery.
	 * @param string      $action    The CRUD action being filtered.
	 * @param string|null $tableName The bare magic table of the aliased rows; a `_status` column it lacks is left out of the draft predicate.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function __construct(
		public readonly string $alias,
		public readonly string $action = 'read',
		public readonly ?string $tableName = null,
	) {
	}//end __construct()
}//end class
