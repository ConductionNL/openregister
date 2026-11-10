<?php

/**
 * MagicIdentifierProbe
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

/**
 * What the cross-table scan looks for: the identifier matched against
 * _uuid/_slug/_uri, its numeric form for the bigint _id column, and whether
 * soft-deleted rows count.
 *
 * @spec openspec/specs/magic-table-reads/spec.md
 */
final class MagicIdentifierProbe {

	/**
	 * The identifier as an integer for the bigint _id column, or -1 when it is
	 * not numeric: -1 never matches a real row, and a UUID bound against a
	 * bigint column type-errors on PostgreSQL.
	 *
	 * @var integer
	 */
	public readonly int $idParam;

	/**
	 * Constructor.
	 *
	 * @param string $identifier     The identifier to match.
	 * @param bool   $includeDeleted Whether soft-deleted rows match.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function __construct(
		public readonly string $identifier,
		public readonly bool $includeDeleted,
	) {
		$idParam = -1;
		if (is_numeric($identifier) === true) {
			$idParam = (int)$identifier;
		}

		$this->idParam = $idParam;
	}//end __construct()
}//end class
