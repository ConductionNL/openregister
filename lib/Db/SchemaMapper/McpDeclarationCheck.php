<?php

/**
 * McpDeclarationCheck: save-time refusal of a malformed MCP declaration.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\SchemaMapper;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Mcp\Service\Mcp\McpAnnotationValidators;

/**
 * Refuses a schema whose `x-openregister-mcp` block does not hold, with the validator's codes.
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */
final class McpDeclarationCheck {

	/**
	 * Validate the optional `x-openregister-mcp` annotation (ADR-063).
	 *
	 * This validates the declaration only: no MCP tool is emitted and no serving
	 * surface is altered here (that is the `or-mcp-derived-tool-provider`
	 * change). A malformed block fails the schema save loudly.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @throws \Exception When the annotation is malformed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-DIALECT-002 — Save-time validation of the dialect shape)
	 */
	public function check(Schema $schema): void {
		$annotation = new SchemaAnnotation(schema: $schema, key: 'x-openregister-mcp');
		if ($annotation->isBlock() === true) {
			$annotation->faults()->refuseCoded(errors: McpAnnotationValidators::validator()->validate($annotation->shape()));
		}
	}//end check()
}//end class
