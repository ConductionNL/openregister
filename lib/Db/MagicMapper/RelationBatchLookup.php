<?php

/**
 * RelationBatchLookup
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
 * One batched relation lookup in a register+schema magic table: the uuids the relation fields must hold and which fields to look in.
 *
 * @spec openspec/specs/magic-table-reads/spec.md
 */
final class RelationBatchLookup {

	/**
	 * Constructor.
	 *
	 * @param array  $uuids                The uuids to look for.
	 * @param string $fieldName            The relation field the lookup is for (log context).
	 * @param array  $additionalFieldNames Further relation fields to match in.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function __construct(
		public readonly array $uuids,
		public readonly string $fieldName,
		public readonly array $additionalFieldNames=[],
	) {
	}//end __construct()
}//end class
