<?php

/**
 * The create_object function of the objects tool.
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
 * A new object: the register and schema it goes in and its data.
 *
 * The model's arguments bind to this constructor by name or by position.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class CreateObjectCommand implements ToolOperation {
	/**
	 * Constructor.
	 *
	 * @param string $register Register identifier
	 * @param string $schema   Schema identifier
	 * @param array  $data     Object data
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly string $register,
		public readonly string $schema,
		public readonly array $data,
	) {
	}//end __construct()
}//end class
