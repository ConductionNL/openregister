<?php

/**
 * The list_schemas function of the schema tool.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tool
 * @package  OCA\OpenRegister\Mcp\Tool
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Mcp\Tool;

/**
 * One page of schemas, optionally of one register.
 *
 * The model's arguments bind to this constructor by name or by position.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class ListSchemasQuery implements ToolOperation {
	/**
	 * Constructor.
	 *
	 * @param int     $limit    Result limit
	 * @param int     $offset   Result offset
	 * @param string|null$register Register filter
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly int $limit = 100,
		public readonly int $offset = 0,
		public readonly ?string $register = null,
	) {
	}//end __construct()
}//end class
