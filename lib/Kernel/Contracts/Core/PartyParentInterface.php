<?php
/**
 * PartyParentInterface: the parent property a schema's party annotation declares.
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
 * Reads the parent chain a schema's party annotation declares.
 *
 * The object tree walk (Core) asks when a schema declares no hierarchy; the party
 * model (Processes) reads its own annotation and binds its answer in its own
 * registrar.
 */
interface PartyParentInterface {

	/**
	 * The parent property and the declared depth bound, or null when the schema declares no party parent.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array{property: string, maxDepth: int, nameProperty: string|null}|null The declaration, the depth as the annotation states it.
	 *
	 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
	 */
	public function parentOf(Schema $schema): ?array;
}//end interface
