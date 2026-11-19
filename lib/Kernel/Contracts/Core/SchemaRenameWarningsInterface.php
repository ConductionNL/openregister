<?php
/**
 * SchemaRenameWarningsInterface: what a declared property rename leaves behind, as the schema API asks it.
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
use OCA\OpenRegister\Service\Schema\SchemaChangeSet;

/**
 * Warns, after a schema update, about what still watches a property the update renamed.
 *
 * The schema API (Core) asks; the flow trigger rename check (Processes)
 * answers and is bound to this contract in its own registrar.
 *
 * @spec openspec/changes/flow-trigger-transitions-and-changed-fields/specs/flow-engine/spec.md#requirement-an-update-trigger-can-watch-named-fields
 */
interface SchemaRenameWarningsInterface {

	/**
	 * The warnings for the renames the change set declares; none when nothing watches a renamed property.
	 *
	 * @param Schema          $schema    The schema after the update.
	 * @param SchemaChangeSet $changeSet The classified update, with its declared renames.
	 *
	 * @return list<array{flow: string, step: string, field: string, renamedTo: string, message: string}>
	 *
	 * @spec openspec/changes/flow-trigger-transitions-and-changed-fields/specs/flow-engine/spec.md#requirement-an-update-trigger-can-watch-named-fields
	 */
	public function warningsFor(Schema $schema, SchemaChangeSet $changeSet): array;
}//end interface
