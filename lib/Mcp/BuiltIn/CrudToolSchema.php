<?php

/**
 * Input-schema fragments the CRUD built-in MCP tools share.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\BuiltIn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction BV
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/chat-ai/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\BuiltIn;

/**
 * The `action` property and the `limit`/`offset` paging properties of the
 * registers, schemas and objects tools, spread into their input schemas.
 */
final class CrudToolSchema {

	/**
	 * The CRUD action property.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public const ACTION = [
		'action' => [
			'type' => 'string',
			'enum' => ['list', 'get', 'create', 'update', 'delete'],
			'description' => 'The CRUD action to perform',
		],
	];

	/**
	 * The paging properties of the list action.
	 *
	 * @var array<string, array<string, string>>
	 */
	public const PAGING = [
		'limit' => [
			'type' => 'integer',
			'description' => 'Maximum number of results (for list)',
		],
		'offset' => [
			'type' => 'integer',
			'description' => 'Number of results to skip (for list)',
		],
	];
}//end class
