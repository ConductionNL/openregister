<?php

/**
 * OpenRegister tool function table
 *
 * Expands the compact function rows of an LLphant tool into OpenAI
 * function calling definitions.
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

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\Tool;

/**
 * Turns `[name, subject, action, description, [parameter => [type,
 * description]], required]` rows into function definitions.
 */
final class ToolFunctionTable {

	/**
	 * Expand every row into a function definition.
	 *
	 * @param array<int, array<int, mixed>> $rows The compact rows
	 *
	 * @return array<int, array<string, mixed>> The function definitions
	 */
	public static function expand(array $rows): array {
		return array_map(callback: [self::class, 'definition'], array: $rows);
	}//end expand()

	/**
	 * One function definition.
	 *
	 * @param array<int, mixed> $row The compact row
	 *
	 * @return array<string, mixed> The function definition
	 */
	public static function definition(array $row): array {
		$properties = [];
		foreach ($row[4] as $parameter => $spec) {
			$properties[$parameter] = ['type' => $spec[0], 'description' => $spec[1]];
		}

		return [
			'name' => $row[0],
			'subject' => $row[1],
			'action' => $row[2],
			'description' => $row[3],
			'parameters' => [
				'type' => 'object',
				'properties' => $properties,
				'required' => $row[5],
			],
		];
	}//end definition()
}//end class
