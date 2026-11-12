<?php

/**
 * A relation as a schema property declares it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\DeletionGraph
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\DeletionGraph;

/**
 * One property of a schema, read as a relation for the relation index.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final readonly class DeclaredRelation {

	/**
	 * Constructor.
	 *
	 * @param string               $sourceSchemaId The schema that declares the property.
	 * @param int|string           $property       The property name.
	 * @param array<string, mixed> $definition     The property configuration.
	 */
	public function __construct(
		public string $sourceSchemaId,
		public int|string $property,
		public array $definition,
	) {
	}//end __construct()

	/**
	 * The relation index entry for this relation.
	 *
	 * @param string      $onDelete     The uppercase onDelete action.
	 * @param string|null $referenceKey The target's key property, or null for a uuid reference.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function indexEntry(string $onDelete, ?string $referenceKey): array {
		return [
			'sourceSchemaId' => $this->sourceSchemaId,
			'property' => $this->property,
			'onDelete' => $onDelete,
			'isArray' => ($this->definition['type'] ?? null) === 'array',
			'referenceKey' => $referenceKey,
		];
	}//end indexEntry()
}//end class
