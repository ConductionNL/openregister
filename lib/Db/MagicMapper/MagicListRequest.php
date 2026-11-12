<?php

/**
 * MagicListRequest
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

use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Db\RegisterSchema;

/**
 * One listing of a register+schema magic table: the table, the page, sort and filters, and the ids and relation the objects must match.
 *
 * @spec openspec/specs/magic-table-reads/spec.md
 */
final class MagicListRequest {

	/**
	 * Constructor.
	 *
	 * @param ?RegisterSchema $table The register and schema to list, or null when the caller named none.
	 * @param ListQuery       $query The limit (unbounded for all rows), offset, filters, sort and free-text search.
	 * @param ?array          $ids   The ids or uuids the objects must have, or null for any.
	 * @param ?string         $uses  A value that must be present in the relations, or null for any.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function __construct(
		public readonly ?RegisterSchema $table,
		public readonly ListQuery $query,
		public readonly ?array $ids=null,
		public readonly ?string $uses=null,
	) {
	}//end __construct()
}//end class
