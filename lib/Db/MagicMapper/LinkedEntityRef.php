<?php

/**
 * LinkedEntityRef
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
 * An entity linked from a magic-table column: the column that holds the link and the id of the entity it links to.
 *
 * @spec openspec/specs/magic-table-reads/spec.md
 */
final class LinkedEntityRef {

	/**
	 * Constructor.
	 *
	 * @param string $columnName The column name (e.g. '_mail', '_contacts').
	 * @param string $entityId   The entity ID to search for.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function __construct(
		public readonly string $columnName,
		public readonly string $entityId,
	) {
	}//end __construct()
}//end class
