<?php

/**
 * TermsFacetUnion
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
 * One terms facet counted with UNION ALL over several magic tables.
 *
 * @spec openspec/specs/faceting-configuration/spec.md
 */
final class TermsFacetUnion {

	/**
	 * Constructor.
	 *
	 * @param array  $tableConfigs Entries of ['tableName' => string, 'register' => Register, 'schema' => Schema]
	 * @param string $field        The column the facet counts
	 * @param array  $baseQuery    The search filters, without the facet configuration
	 * @param bool   $isMetadata   Whether the column is a metadata column
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	public function __construct(
		public readonly array $tableConfigs,
		public readonly string $field,
		public readonly array $baseQuery,
		public readonly bool $isMetadata = false,
	) {
	}//end __construct()
}//end class
