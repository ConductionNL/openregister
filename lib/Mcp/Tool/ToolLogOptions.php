<?php

/**
 * How a tool function call is logged.
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
 * The level and message of a tool function call's log entry.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class ToolLogOptions {
	/**
	 * Constructor.
	 *
	 * @param string $level   Log level: 'info', 'warning' or 'error'.
	 * @param string $message Custom log message; empty for the default 'Executing function'.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly string $level = 'info',
		public readonly string $message = '',
	) {
	}//end __construct()
}//end class
