<?php
/**
 * SchemaFormDependentsInterface: the published forms a schema save re-checks, as the schema API asks it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

use OCA\OpenRegister\Db\Schema;

/**
 * Judges, before a schema save, the published forms that submit into the schema, and applies the verdict after it.
 *
 * The schema API (Core) asks; the form dependents check (Processes) answers
 * and is bound to this contract in its own registrar.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-saving-a-schema-must-re-check-the-forms-that-submit-into-it
 */
interface SchemaFormDependentsInterface {
	/**
	 * Say that the caller of this save judges the dependent forms itself.
	 *
	 * The schema API assesses BEFORE the write, so it can refuse the save, and
	 * applies after it. Every other writer of a schema (the configuration
	 * importer, the import endpoint, the AI and MCP tools) has no response to
	 * refuse, and is covered by {@see \OCA\OpenRegister\Listener\FormDestinationSchemaListener}, which
	 * skips a save its caller has claimed so no form is unpublished or
	 * announced twice.
	 *
	 * @param int $schemaId The schema about to be saved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-saving-a-schema-must-re-check-the-forms-that-submit-into-it
	 */
	public function claim(int $schemaId): void;

	/**
	 * Judge every published form that submits into the proposed schema.
	 *
	 * @param Schema $schema The proposed schema definition.
	 *
	 * @return array<string, mixed> The assessment: `refused`, `schema`, and `affected` (id, app, title, break, findings, form).
	 *
	 * @psalm-return array{refused: bool, schema: string, affected: list<array<string, mixed>>}
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-saving-a-schema-must-re-check-the-forms-that-submit-into-it
	 */
	public function assess(Schema $schema): array;

	/**
	 * After the save: unpublish each broken form and tell its author.
	 *
	 * @param array{refused: bool, schema: string, affected: array<int, array<string, mixed>>} $assessment The assessment of the saved schema.
	 *
	 * @return array<int, array<string, mixed>> Each affected form (id, app, title, outcome, findings), for the save response.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-saving-a-schema-must-re-check-the-forms-that-submit-into-it
	 */
	public function apply(array $assessment): array;
}//end interface
