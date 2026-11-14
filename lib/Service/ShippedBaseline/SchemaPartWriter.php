<?php

/**
 * Write one leaf of a schema's guarded parts, leaving every sibling as it was stored.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ShippedBaseline
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ShippedBaseline;

use OCA\OpenRegister\Db\Schema;

/**
 * The one place a reset or an accepted conflict changes a schema or a recorded baseline.
 *
 * The guard compares normalised parts (scalar lists sorted, keys sorted);
 * writing a whole normalised definition back would reorder every enum and
 * every other local list. So only the leaf at the path is replaced, or
 * removed when the value is null.
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */
final class SchemaPartWriter {

	/**
	 * Replace or remove one leaf in a tree, leaving every sibling as it was.
	 *
	 * @param mixed     $tree The tree the leaf's segments start in.
	 * @param LeafReset $leaf The segments below the tree and the value.
	 *
	 * @return mixed The tree with the leaf replaced, or removed when the value is null.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function into(mixed $tree, LeafReset $leaf): mixed {
		if ($leaf->segments === [] && $leaf->removes() === true) {
			return [];
		}

		if ($leaf->segments === []) {
			return $leaf->to;
		}

		if (is_array($tree) === false) {
			$tree = [];
		}

		$key = $leaf->segments[0];
		if (count($leaf->segments) === 1 && $leaf->removes() === true) {
			unset($tree[$key]);
			return $tree;
		}

		$tree[$key] = $this->into(tree: ($tree[$key] ?? []), leaf: $leaf->below());
		return $tree;
	}//end into()

	/**
	 * Set the top-level part a leaf falls in on the schema, with the leaf replaced.
	 *
	 * @param Schema    $schema The schema, as stored.
	 * @param LeafReset $leaf   The full path, top-level part first, and the value.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function write(Schema $schema, LeafReset $leaf): void {
		$top = $leaf->segments[0];
		$value = $this->into(tree: LiveDescriptor::of(schema: $schema)[$top], leaf: $leaf->below());
		if (is_array($value) === false) {
			$value = [];
		}

		match ($top) {
			'properties' => $schema->setProperties($value),
			'required' => $schema->setRequired($value),
			GuardedAnnotations::PART => $schema->setConfiguration(
				(new GuardedAnnotations())->intoStored(configuration: $schema->getConfiguration(), guarded: $value)
			),
			default => $schema->setAuthorization($value),
		};
	}//end write()
}//end class
