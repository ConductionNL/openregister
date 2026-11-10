<?php

/**
 * The update_schema function of the schema tool.
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
 * A change to a schema: its id and the fields to change; null keeps a field.
 *
 * The model's arguments bind to this constructor by name or by position.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class UpdateSchemaCommand implements ToolOperation {
	/**
	 * Constructor.
	 *
	 * @param string  $id          Schema ID
	 * @param string|null$title       Schema title
	 * @param string|null$description Schema description
	 * @param array|null $properties  Schema properties
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly string $id,
		public readonly ?string $title = null,
		public readonly ?string $description = null,
		public readonly ?array $properties = null,
	) {
	}//end __construct()
}//end class
