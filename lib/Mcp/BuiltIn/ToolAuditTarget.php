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

/**
 * What one tool invocation audit record is about: the tool and, for a
 * schema-derived tool, its register and schema.
 */
final readonly class ToolAuditTarget {

	/**
	 * Constructor.
	 *
	 * @param string   $toolId   Full namespaced tool id.
	 * @param int|null $register The register id, when there is one.
	 * @param int|null $schema   The schema id, when there is one.
	 */
	public function __construct(
		public string $toolId,
		public ?int $register = null,
		public ?int $schema = null,
	) {
	}//end __construct()
}//end class
