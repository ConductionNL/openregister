<?php

/**
 * The parent chain a schema's party annotation declares, for the object tree walk.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Party
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/party-model/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Party;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\Core\PartyParentInterface;

/**
 * Answers the tree walk's party parent question from the party definition.
 *
 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
 */
final class PartyParent implements PartyParentInterface {

	/**
	 * The parent property, the declared depth bound and the name property, or null when the schema declares no party parent.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array{property: string, maxDepth: int, nameProperty: string|null}|null The declaration.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) PartyDefinition::fromSchema() is the party model's one reader of the annotation.
	 *
	 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
	 */
	public function parentOf(Schema $schema): ?array {
		$party = PartyDefinition::fromSchema(schema: $schema);
		if ($party === null || $party->parentProperty() === null) {
			return null;
		}

		return [
			'property' => $party->parentProperty(),
			'maxDepth' => $party->maxDepth(),
			// What a node of the party's tree is called when the object has no name of its own.
			'nameProperty' => $party->nameProperty(),
		];
	}//end parentOf()
}//end class
