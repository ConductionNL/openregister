<?php

/**
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
 * @spec openspec/specs/ai-mcp/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\BuiltIn;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * One schema-derived tool invocation: the target schema, its owning register
 * when resolvable, and the tool arguments.
 */
final readonly class DerivedToolCall {

	/**
	 * Constructor.
	 *
	 * @param Schema               $schema    The target schema.
	 * @param Register|null        $register  The schema's owning register, when resolvable.
	 * @param array<string, mixed> $arguments JSON-decoded tool arguments.
	 */
	public function __construct(
		public Schema $schema,
		public ?Register $register,
		public array $arguments,
	) {
	}//end __construct()
}//end class
