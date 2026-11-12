<?php

/**
 * The create_schema function of the schema tool.
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
 * A new schema: its title and properties, an optional description and required list.
 *
 * The model's arguments bind to this constructor by name or by position.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class CreateSchemaCommand implements ToolOperation {
	/**
	 * Constructor.
	 *
	 * @param string $title       Schema title
	 * @param array  $properties  Schema properties
	 * @param string $description Schema description
	 * @param array|null$required    Required properties
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly string $title,
		public readonly array $properties,
		public readonly string $description = '',
		public readonly ?array $required = null,
	) {
	}//end __construct()
}//end class
