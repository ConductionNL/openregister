<?php

/**
 * The update_register function of the register tool.
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
 * A change to a register: its id and the fields to change; null keeps a field.
 *
 * The model's arguments bind to this constructor by name or by position.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class UpdateRegisterCommand implements ToolOperation {
	/**
	 * Constructor.
	 *
	 * @param string  $id          Register ID
	 * @param string|null$title       Register title
	 * @param string|null$description Register description
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly string $id,
		public readonly ?string $title = null,
		public readonly ?string $description = null,
	) {
	}//end __construct()
}//end class
