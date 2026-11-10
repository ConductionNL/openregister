<?php

/**
 * The create_register function of the register tool.
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
 * A new register: its title, an optional description and slug.
 *
 * The model's arguments bind to this constructor by name or by position.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class CreateRegisterCommand implements ToolOperation {
	/**
	 * Constructor.
	 *
	 * @param string  $title       Register title
	 * @param string  $description Register description
	 * @param string|null$slug        Register slug
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly string $title,
		public readonly string $description = '',
		public readonly ?string $slug = null,
	) {
	}//end __construct()
}//end class
