<?php

/**
 * MultiSchemaSearch
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

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * One search across several schemas: its queries and scope, and the registers,
 * schemas, tables and total it collects while resolving them.
 *
 * @spec openspec/specs/unified-search-provider/spec.md
 */
final class MultiSchemaSearch {

	/**
	 * Loaded registers by id.
	 *
	 * @var array<int, Register>
	 */
	public array $registers = [];

	/**
	 * Serialised registers by id, for the response.
	 *
	 * @var array<int, array>
	 */
	public array $registersCache = [];

	/**
	 * Serialised schemas by id, for the response.
	 *
	 * @var array<int, array>
	 */
	public array $schemasCache = [];

	/**
	 * Owning register id by schema id.
	 *
	 * @var array<int, int>
	 */
	public array $schemaToRegisterId = [];

	/**
	 * The register and schema of every table to search.
	 *
	 * @var list<array{register: Register, schema: Schema}>
	 */
	public array $pairs = [];

	/**
	 * Sum of the per-table counts.
	 *
	 * @var integer
	 */
	public int $total = 0;

	/**
	 * Whether RBAC applies.
	 *
	 * @var boolean
	 */
	private bool $rbac = true;

	/**
	 * Whether multitenancy applies.
	 *
	 * @var boolean
	 */
	private bool $multitenancy = true;

	/**
	 * Constructor.
	 *
	 * @param array $searchQuery The search query.
	 * @param array $countQuery  The count query.
	 * @param array $registerIds Registers to search; empty means every schema's owning register.
	 * @param array $schemaIds   Schemas to search.
	 */
	public function __construct(
		public readonly array $searchQuery,
		public readonly array $countQuery,
		public readonly array $registerIds,
		public readonly array $schemaIds,
	) {
	}//end __construct()

	/**
	 * Set the security filters the search applies.
	 *
	 * @param bool $rbac         Whether RBAC applies.
	 * @param bool $multitenancy Whether multitenancy applies.
	 *
	 * @return self This search.
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	public function securedBy(bool $rbac, bool $multitenancy): self {
		$this->rbac         = $rbac;
		$this->multitenancy = $multitenancy;
		return $this;
	}//end securedBy()

	/**
	 * A query with this search's RBAC and multitenancy flags set.
	 *
	 * @param array $query The query.
	 *
	 * @return array The query with `_rbac` and `_multitenancy`.
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	public function withFlags(array $query): array {
		$query['_rbac']         = $this->rbac;
		$query['_multitenancy'] = $this->multitenancy;
		return $query;
	}//end withFlags()
}//end class
