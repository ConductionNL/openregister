<?php

/**
 * The update_application function of the application tool.
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
 * A change to an application: its UUID and the fields to change; null keeps a field.
 *
 * The model's arguments bind to this constructor by name or by position.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class UpdateApplicationCommand implements ToolOperation {
	/**
	 * Constructor.
	 *
	 * @param string  $uuid        Application UUID
	 * @param string|null$name        New name
	 * @param string|null$description New description
	 * @param string|null$domain      New domain or URL (not stored)
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly ?string $name = null,
		public readonly ?string $description = null,
		public readonly ?string $domain = null,
	) {
	}//end __construct()
}//end class
