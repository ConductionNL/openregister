<?php

/**
 * FormDependentsCheckInterface: what callers outside lib/Service use of FormDependentsCheck.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

use OCA\OpenRegister\Db\Schema;

/**
 * The part of FormDependentsCheck that code outside lib/Service uses.
 */
interface FormDependentsCheckInterface {

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
	 * Whether the caller of this schema's save judges the dependent forms itself.
	 *
	 * @param int $schemaId The schema.
	 *
	 * @return bool True when it was claimed in this request.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-saving-a-schema-must-re-check-the-forms-that-submit-into-it
	 */
	public function isClaimed(int $schemaId): bool;

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
