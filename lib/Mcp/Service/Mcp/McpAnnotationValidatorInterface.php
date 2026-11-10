<?php

/**
 * McpAnnotationValidatorInterface: the x-mcp annotation check the schema save path runs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Mcp\Service\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\Service\Mcp;

/**
 * The methods of McpAnnotationValidator that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface McpAnnotationValidatorInterface {

	/**
	 * Validate the annotation.
	 *
	 * Expects the annotation shape `SchemaMapper` hands every annotation
	 * validator: `['properties' => [...], 'x-openregister-mcp' => [...]]`.
	 *
	 * @param array<string, mixed> $schema The schema shape to validate.
	 *
	 * @return array<int, array{code: string, message: string}> Aggregated errors (empty = valid).
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-DIALECT-001 — The x-openregister-mcp schema dialect)
	 */
	public function validate(array $schema): array;
}//end interface
