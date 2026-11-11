<?php

/**
 * The create_application function of the application tool.
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
 * A new application: its name, optional description and domain.
 *
 * The model's arguments bind to this constructor by name or by position.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class CreateApplicationCommand implements ToolOperation {
	/**
	 * Constructor.
	 *
	 * @param string  $name        Application name
	 * @param string|null$description Application description
	 * @param string|null$domain      Application domain or URL (not stored)
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly string $name,
		public readonly ?string $description = null,
		public readonly ?string $domain = null,
	) {
	}//end __construct()
}//end class
