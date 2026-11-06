<?php

/**
 * SchemaComposition: resolves allOf, oneOf and anyOf, and reduces a composed schema to its delta.
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

use Closure;
use Exception;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaPropertyInheritance;

/**
 * JSON Schema composition over the schemas the mapper loads.
 *
 * 1. 'allOf': an instance must validate against ALL schemas (multiple inheritance)
 * 2. 'oneOf': an instance must validate against EXACTLY ONE schema
 * 3. 'anyOf': an instance must validate against AT LEAST ONE schema
 *
 * The Liskov Substitution Principle holds: extended schemas can ONLY ADD
 * constraints, never relax them; metadata (title, description, order) can be
 * overridden; validation rules (type, format, enum, min/max, pattern) cannot be
 * relaxed.
 *
 * @spec exclude schema save plumbing moved out of SchemaMapper, not a capability of its own
 */
final class SchemaComposition {

	private const NOTHING_MERGED = ['properties' => [], 'required' => []];

	/**
	 * Constructor.
	 *
	 * @param Closure $loadSchema Loads a schema by id, uuid or slug; throws \Exception when there is none.
	 */
	public function __construct(
		private readonly Closure $loadSchema,
	) {
	}//end __construct()

	/**
	 * Resolve schema composition by merging referenced schemas.
	 *
	 * @param Schema       $schema  The schema to resolve.
	 * @param array<mixed> $visited Visited schema ids, to prevent circular references.
	 *
	 * @throws Exception If a circular reference is detected or a referenced schema is not found.
	 *
	 * @return Schema The resolved schema with merged properties.
	 *
	 * @spec exclude moved unchanged from SchemaMapper, which named no spec for it
	 */
	public function resolve(Schema $schema, array $visited = []): Schema {
		$currentId = $this->idOf(schema: $schema);
		if (in_array($currentId, $visited) === true) {
			throw new Exception("Circular schema composition detected: schema '{$currentId}' creates a loop");
		}

		$visited[] = $currentId;
		if (empty($schema->getAllOf()) === false) {
			return $this->resolveAllOf(schema: $schema, visited: $visited);
		}

		$this->assertAlternatives(schema: $schema, visited: $visited);

		return $schema;
	}//end resolve()

	/**
	 * Extract the delta between parent schemas and child schema properties.
	 *
	 * Called before saving a schema that uses composition. It removes any
	 * properties that are identical to the parent(s), keeping only the
	 * differences in the child schema. oneOf and anyOf properties are not
	 * merged, so those get no delta.
	 *
	 * @param Schema $schema The schema to extract the delta from.
	 *
	 * @return Schema The schema with only delta properties.
	 *
	 * @spec exclude moved unchanged from SchemaMapper, which named no spec for it
	 */
	public function extractDelta(Schema $schema): Schema {
		if (empty($schema->getOneOf()) === true && empty($schema->getAnyOf()) === true && empty($schema->getAllOf()) === false) {
			$this->extractAllOfDelta(schema: $schema);
		}

		return $schema;
	}//end extractDelta()

	/**
	 * Find which parent schema a property was inherited from.
	 *
	 * @param string       $propertyName The property name to search for.
	 * @param array<mixed> $parentRefs   Parent schema references.
	 *
	 * @return string|null The parent schema reference, or null if not found.
	 *
	 * @spec exclude moved unchanged from SchemaMapper, which named no spec for it
	 */
	public function propertySource(string $propertyName, array $parentRefs): ?string {
		foreach (array_filter(array_map([new ParentReference(), 'fromEntry'], $parentRefs)) as $parentRef) {
			if (isset($this->propertiesOf(parentRef: $parentRef)[$propertyName]) === true) {
				return (string)$parentRef;
			}
		}

		return null;
	}//end propertySource()

	/**
	 * The resolved properties of a parent, none when it cannot be loaded.
	 *
	 * @param mixed $parentRef The parent reference.
	 *
	 * @return array<mixed>
	 */
	private function propertiesOf(mixed $parentRef): array {
		try {
			return ($this->resolve(schema: ($this->loadSchema)($parentRef))->getProperties() ?? []);
		} catch (Exception $e) {
			return [];
		}
	}//end propertiesOf()

	/**
	 * Resolve the allOf composition pattern.
	 *
	 * An instance must validate against ALL referenced schemas. This is the
	 * recommended pattern for schema extension. Properties from all schemas are
	 * merged with the child schema, which can only add constraints.
	 *
	 * @param Schema       $schema  The child schema.
	 * @param array<mixed> $visited Visited schemas for circular reference detection.
	 *
	 * @throws Exception If a referenced schema is not found or a circular reference is detected.
	 *
	 * @return Schema The resolved schema with all properties merged.
	 */
	private function resolveAllOf(Schema $schema, array $visited): Schema {
		$merged = $this->resolvedParents(schema: $schema, visited: $visited);
		$resolved = clone $schema;
		$resolved->setProperties(
			(new SchemaPropertyInheritance(schemaId: (string)$this->idOf(schema: $schema)))->mergeProperties(
				parentProperties: $merged['properties'],
				childProperties: $schema->getProperties()
			)
		);
		$resolved->setRequired(array_unique(array_merge($merged['required'], $schema->getRequired())));

		return $resolved;
	}//end resolveAllOf()

	/**
	 * The named allOf parents of a schema, resolved and merged in order.
	 *
	 * @param Schema       $schema  The child schema.
	 * @param array<mixed> $visited Visited schemas for circular reference detection.
	 *
	 * @throws Exception If a referenced schema is not found or a circular reference is detected.
	 *
	 * @return array{properties: array<mixed>, required: array<mixed>}
	 */
	private function resolvedParents(Schema $schema, array $visited): array {
		$load = $this->parentLoader(schema: $schema, visited: $visited);
		$loaders = [$load, $this->tolerantLoader(child: $schema, load: $load)];
		$merged = self::NOTHING_MERGED;
		foreach ($schema->getAllOf() as $entry) {
			$ref = (new ParentReference())->fromEntry(entry: $entry);
			if (empty($ref) === false) {
				$loader = $loaders[(int)is_array($entry)];
				$merged = $this->withParent(merged: $merged, parent: $loader($ref));
			}
		}

		return $merged;
	}//end resolvedParents()

	/**
	 * Loads and resolves a parent of the given schema, refusing the schema itself.
	 *
	 * @param Schema       $schema  The child schema.
	 * @param array<mixed> $visited Visited schemas for circular reference detection.
	 *
	 * @return Closure(int|string): Schema
	 */
	private function parentLoader(Schema $schema, array $visited): Closure {
		return function (int|string $ref) use ($schema, $visited): Schema {
			$this->assertNotSelf(schema: $schema, ref: $ref);
			return $this->resolve(schema: ($this->loadSchema)($ref), visited: $visited);
		};
	}//end parentLoader()

	/**
	 * Loads the parent a `{"$ref": ...}` entry names, or null when it cannot be applied.
	 *
	 * The save reduces a schema to its delta against this parent, so the read has
	 * to merge it back. Such an entry was never required to resolve, and an import
	 * may reference a schema that does not exist yet, so a parent that is missing,
	 * loops, is the schema itself or is relaxed by the child leaves the schema
	 * readable instead of refusing the read.
	 *
	 * @param Schema  $child The child schema.
	 * @param Closure $load  Loads a parent; throws when it cannot.
	 *
	 * @return Closure(int|string): (Schema|null)
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	private function tolerantLoader(Schema $child, Closure $load): Closure {
		return function (int|string $ref) use ($child, $load): ?Schema {
			try {
				$parent = $load($ref);
				(new SchemaPropertyInheritance(schemaId: (string)$this->idOf(schema: $child)))->mergeProperties(
					parentProperties: $parent->getProperties(),
					childProperties: $child->getProperties()
				);
				return $parent;
			} catch (Exception $e) {
				return null;
			}
		};
	}//end tolerantLoader()

	/**
	 * Every oneOf or else anyOf option must exist and resolve; properties stay separate.
	 *
	 * @param Schema       $schema  The schema.
	 * @param array<mixed> $visited Visited schemas.
	 *
	 * @throws Exception If a referenced schema is not found.
	 *
	 * @return void
	 */
	private function assertAlternatives(Schema $schema, array $visited): void {
		$entries = $schema->getOneOf();
		if (empty($entries) === true) {
			$entries = ($schema->getAnyOf() ?? []);
		}

		foreach ($this->namedEntries(entries: $entries) as $ref) {
			$this->assertNotSelf(schema: $schema, ref: $ref);
			$this->resolve(schema: ($this->loadSchema)($ref), visited: $visited);
		}
	}//end assertAlternatives()

	/**
	 * The entries that are schema identifiers.
	 *
	 * JSON Schema allows an entry to be an inline schema object
	 * ({ "required": [...] }, { "properties": {...} }), not only a $ref to
	 * another schema. OpenRegister's composition model treats allOf/oneOf/anyOf
	 * as a list of schema IDENTIFIERS, so only string/int entries are references
	 * to load: an array entry is a constraint that stands on its own and must not
	 * be handed to the loader (which would fatal on the array). This unblocked
	 * openconnector's register import, silently broken by lti_deployment's
	 * `oneOf: [{required, not}, ...]` XOR constraint.
	 *
	 * @param array<mixed> $entries The composition entries.
	 *
	 * @return array<int|string>
	 */
	private function namedEntries(array $entries): array {
		return array_filter($entries, static fn (mixed $entry): bool => is_string($entry) === true || is_int($entry) === true);
	}//end namedEntries()

	/**
	 * Refuse an entry that points back at the schema itself.
	 *
	 * @param Schema     $schema The schema.
	 * @param int|string $ref    The entry.
	 *
	 * @throws Exception When the entry is the schema itself.
	 *
	 * @return void
	 */
	private function assertNotSelf(Schema $schema, int|string $ref): void {
		$currentId = $this->idOf(schema: $schema);
		if (in_array($ref, [$currentId, $schema->getId(), $schema->getUuid(), $schema->getSlug()], true) === true) {
			$keyword = 'anyOf';
			if (empty($schema->getAllOf()) === false) {
				$keyword = 'allOf';
			} elseif (empty($schema->getOneOf()) === false) {
				$keyword = 'oneOf';
			}

			throw new Exception("Schema '{$currentId}' cannot reference itself in {$keyword}");
		}
	}//end assertNotSelf()

	/**
	 * Reduce a schema to what it adds to the parents its `allOf` names.
	 *
	 * ## `allOf` is not only a parent list (openregister#2534)
	 *
	 * In JSON Schema an `allOf` entry is a SUBSCHEMA, and only some of them name
	 * another schema. The common non-naming case is a conditional:
	 *
	 *     "allOf": [ { "if": {...}, "then": {}, "else": { "required": [...] } } ]
	 *
	 * ParentReference decides what an entry IS, and a subschema that names no
	 * parent contributes nothing to a PARENT delta and is skipped.
	 *
	 * If a parent schema doesn't exist yet (e.g. during import Pass 1), the
	 * schema stays without delta extraction: the full properties are preserved
	 * and the delta is extracted during Pass 2 (update) when all schemas exist.
	 *
	 * `\Throwable`, not `Exception`: this net keeps an unresolvable parent from
	 * failing an import, and a TypeError from a composition shape it did not
	 * expect is the same situation. Delta extraction is an OPTIMISATION; nothing
	 * it can fail at justifies refusing to store the schema.
	 *
	 * @param Schema $schema The child schema.
	 *
	 * @return void
	 */
	private function extractAllOfDelta(Schema $schema): void {
		try {
			$parents = $this->mergedParents(allOf: $schema->getAllOf());
		} catch (\Throwable $e) {
			return;
		}

		$schema->setProperties(
			(new PropertyDelta())->between(parentProperties: $parents['properties'], childProperties: $schema->getProperties())
		);
		$schema->setRequired(array_values(array_diff($schema->getRequired(), $parents['required'])));
	}//end extractAllOfDelta()

	/**
	 * The properties and required fields of every parent an `allOf` names, merged.
	 *
	 * @param array<mixed> $allOf The `allOf` subschemas.
	 *
	 * @return array{properties: array<mixed>, required: array<mixed>}
	 */
	private function mergedParents(array $allOf): array {
		$references = array_map([new ParentReference(), 'fromEntry'], $allOf);
		$merged = self::NOTHING_MERGED;
		foreach (array_filter($references, static fn (mixed $ref): bool => $ref !== null) as $parentRef) {
			$parent = ($this->loadSchema)($parentRef);
			if ($parent->getAllOf() !== null) {
				$parent = $this->resolve(schema: $parent);
			}

			$merged = $this->withParent(merged: $merged, parent: $parent);
		}

		return $merged;
	}//end mergedParents()

	/**
	 * The merged parents so far with one more: its properties merged, its required fields added.
	 *
	 * @param array{properties: array<mixed>, required: array<mixed>} $merged The parents so far.
	 * @param Schema|null                                             $parent The resolved parent, if any.
	 *
	 * @return array{properties: array<mixed>, required: array<mixed>}
	 */
	private function withParent(array $merged, ?Schema $parent): array {
		if ($parent === null) {
			return $merged;
		}

		return [
			'properties' => (new PropertyMerge())->merge(parentProperties: $merged['properties'], childProperties: $parent->getProperties()),
			'required' => array_unique(array_merge($merged['required'], $parent->getRequired())),
		];
	}//end withParent()

	/**
	 * The identifier composition messages and loop detection use.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return mixed An integer id, a uuid, or 'unknown'.
	 */
	private function idOf(Schema $schema): mixed {
		return ($schema->getId() ?? $schema->getUuid() ?? 'unknown');
	}//end idOf()
}//end class
