<?php

/**
 * MagicPaginatedSearch
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
 * One paginated object search: the page query, the count query and the ids and relation both must match.
 *
 * @spec openspec/specs/unified-search-provider/spec.md
 */
final class MagicPaginatedSearch {

	/**
	 * Constructor.
	 *
	 * @param array   $searchQuery Query for search (with _limit, _offset).
	 * @param array   $countQuery  Query for count (without pagination).
	 * @param ?array  $ids         The ids or uuids the objects must have, or null for any.
	 * @param ?string $uses        A value that must be present in the relations, or null for any.
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	public function __construct(
		public readonly array $searchQuery,
		public readonly array $countQuery,
		public readonly ?array $ids=null,
		public readonly ?string $uses=null,
	) {
	}//end __construct()
}//end class
