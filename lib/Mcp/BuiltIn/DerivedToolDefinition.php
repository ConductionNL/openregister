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

use OCA\OpenRegister\Db\Schema;

/**
 * One schema-derived tool being described: the schema, its slug, the verb,
 * that verb's dialect config and the namespaced tool id.
 */
final readonly class DerivedToolDefinition {

	/**
	 * Constructor.
	 *
	 * @param Schema               $schema     The schema being derived.
	 * @param string               $slug       The schema's slug (id-tail).
	 * @param string               $verb       One of {@see McpAnnotationValidator::VERBS}.
	 * @param array<string, mixed> $verbConfig That verb's dialect config (description/scope/filters/hints).
	 * @param string               $id         The fully-namespaced tool id.
	 */
	public function __construct(
		public Schema $schema,
		public string $slug,
		public string $verb,
		public array $verbConfig,
		public string $id,
	) {
	}//end __construct()
}//end class
