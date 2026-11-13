<?php

/**
 * RegisterModelService — a register's schemas and the links between them, as nodes and edges.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\SaveObject\RelationCascadeHandler;

/**
 * Builds the model of one register from schema definitions only.
 *
 * Edges come from a property's `$ref` (one) or `items.$ref` (many), with
 * `inversedBy` as the inverse label, and from the schema's declared
 * `x-openregister-relations` entries. A ref into another register adds that
 * schema as an external node; a ref that resolves to nothing is kept as a
 * dangling edge so the broken link shows. No object is read.
 *
 * @spec openspec/changes/modelling-schema-diagram/specs/schema-diagram/spec.md
 */
class RegisterModelService {

	/**
	 * The configuration key of declared relations.
	 *
	 * @var string
	 */
	private const RELATIONS_KEY = 'x-openregister-relations';

	/**
	 * Constructor.
	 *
	 * @param RegisterMapperInterface $registers The register's schemas, read as `registers#schemas` reads them.
	 * @param SchemaMapperInterface   $schemas   External schemas, read with the caller's rights.
	 * @param RelationCascadeHandler  $refs      The resolver the relation code uses for `$ref` values.
	 */
	public function __construct(
		private readonly RegisterMapperInterface $registers,
		private readonly SchemaMapperInterface $schemas,
		private readonly RelationCascadeHandler $refs,
	) {
	}//end __construct()

	/**
	 * The register's model.
	 *
	 * @param Register $register The register, already read with the caller's rights.
	 *
	 * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
	 *
	 * @spec openspec/changes/modelling-schema-diagram/specs/schema-diagram/spec.md
	 */
	public function model(Register $register): array {
		$nodes = [];
		$own = [];
		foreach ($this->registers->getSchemasByRegisterId((int)$register->getId()) as $schema) {
			$id = (string)$schema->getId();
			$own[$id] = $schema;
			$nodes[$id] = self::node(schema: $schema, external: false);
		}

		$edges = [];
		foreach ($own as $id => $schema) {
			// A numeric id key is an int again here; the model speaks of ids as strings.
			$id = (string)$id;
			foreach ($this->propertyEdges(from: $id, schema: $schema) as $edge) {
				$edges[] = $edge;
			}

			foreach ($this->declaredEdges(from: $id, schema: $schema, edges: $edges) as $edge) {
				$edges[] = $edge;
			}
		}

		$edges = self::withoutInverseTwins(edges: $edges);
		foreach ($edges as $edge) {
			$to = $edge['to'];
			if ($to !== null && isset($nodes[$to]) === false) {
				$nodes[$to] = $this->externalNode(schemaId: $to);
			}
		}

		return ['nodes' => array_values($nodes), 'edges' => $edges];
	}//end model()

	/**
	 * The edges of a schema's `$ref` and `items.$ref` properties.
	 *
	 * @param string $from   The schema id.
	 * @param Schema $schema The schema.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function propertyEdges(string $from, Schema $schema): array {
		$edges = [];
		foreach (($schema->getProperties() ?? []) as $name => $property) {
			if (is_array($property) === false) {
				continue;
			}

			[$ref, $cardinality] = self::refOf(property: $property);
			if ($ref === null) {
				continue;
			}

			$edges[] = $this->edge(
				from: $from,
				target: $ref,
				label: ['property' => (string)$name, 'cardinality' => $cardinality, 'inverse' => self::text(value: ($property['inversedBy'] ?? null))],
				source: 'property'
			);
		}

		return $edges;
	}//end propertyEdges()

	/**
	 * The edges of a schema's declared relations, leaving out one a `$ref` property already draws.
	 *
	 * @param string                     $from   The schema id.
	 * @param Schema                     $schema The schema.
	 * @param list<array<string, mixed>> $edges  The edges drawn so far.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function declaredEdges(string $from, Schema $schema, array $edges): array {
		$declared = (($schema->getConfiguration() ?? [])[self::RELATIONS_KEY] ?? []);
		if (is_array($declared) === false) {
			return [];
		}

		$added = [];
		foreach ($declared as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$name = self::text(value: ($entry['name'] ?? null));
			$target = self::text(value: ($entry['target'] ?? null));
			if ($name === null || $target === null) {
				continue;
			}

			$cardinality = 'one';
			if (($entry['cardinality'] ?? null) === 'many') {
				$cardinality = 'many';
			}

			$edge = $this->edge(
				from: $from,
				target: $target,
				label: ['property' => $name, 'cardinality' => $cardinality, 'inverse' => self::text(value: ($entry['inverseOf'] ?? null))],
				source: 'declared'
			);
			if (self::drawn(edge: $edge, edges: $edges) === false) {
				$added[] = $edge;
			}
		}

		return $added;
	}//end declaredEdges()

	/**
	 * One edge; a target that does not resolve leaves it dangling.
	 *
	 * @param string                                                         $from   The schema id.
	 * @param string                                                         $target The reference as declared.
	 * @param array{property: string, cardinality: string, inverse: ?string} $label  The property, one or many, and the inverse.
	 * @param string                                                         $source `property` or `declared`.
	 *
	 * @return array<string, mixed>
	 */
	private function edge(string $from, string $target, array $label, string $source): array {
		$to = $this->refs->resolveSchemaReference(reference: $target, scope: RelationCascadeHandler::SYSTEM_SCOPE);

		return [
			'from' => $from,
			'to' => $to,
			'property' => $label['property'],
			'cardinality' => $label['cardinality'],
			'inverse' => $label['inverse'],
			'source' => $source,
			'target' => $target,
			'dangling' => ($to === null),
		];
	}//end edge()

	/**
	 * Whether an edge with the same start, end and name is already drawn.
	 *
	 * @param array<string, mixed>       $edge  The edge.
	 * @param list<array<string, mixed>> $edges The edges drawn so far.
	 *
	 * @return bool
	 */
	private static function drawn(array $edge, array $edges): bool {
		foreach ($edges as $other) {
			if ($other['from'] === $edge['from'] && $other['property'] === $edge['property']
				&& ($other['to'] === $edge['to'] || $other['target'] === $edge['target'])
			) {
				return true;
			}
		}

		return false;
	}//end drawn()

	/**
	 * The edges, without the second half of each inverse pair.
	 *
	 * A link declared on both ends (`a.x` inversedBy `y`, `b.y` inversedBy `x`)
	 * is one line with two labels, so the second edge is left out.
	 *
	 * @param list<array<string, mixed>> $edges The edges.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function withoutInverseTwins(array $edges): array {
		$kept = [];
		foreach ($edges as $edge) {
			$twin = false;
			foreach ($kept as $earlier) {
				if ($edge['to'] !== null && $earlier['from'] === $edge['to'] && $earlier['to'] === $edge['from']
					&& $earlier['inverse'] === $edge['property'] && $edge['inverse'] === $earlier['property']
				) {
					$twin = true;
					break;
				}
			}

			if ($twin === false) {
				$kept[] = $edge;
			}
		}

		return $kept;
	}//end withoutInverseTwins()

	/**
	 * The reference of a property and whether it links one or many.
	 *
	 * @param array<string, mixed> $property The property definition.
	 *
	 * @return array{0: string|null, 1: string} The reference (null when none) and `one` or `many`.
	 */
	private static function refOf(array $property): array {
		$ref = self::text(value: ($property['$ref'] ?? null));
		if ($ref !== null) {
			return [$ref, 'one'];
		}

		$items = $property['items'] ?? null;
		if (is_array($items) === true && self::text(value: ($items['$ref'] ?? null)) !== null) {
			return [$items['$ref'], 'many'];
		}

		return [null, 'one'];
	}//end refOf()

	/**
	 * A non-empty string, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private static function text(mixed $value): ?string {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;
	}//end text()

	/**
	 * A node of the model.
	 *
	 * @param Schema $schema   The schema.
	 * @param bool   $external Whether it lies outside the register.
	 *
	 * @return array<string, mixed>
	 */
	private static function node(Schema $schema, bool $external): array {
		$properties = [];
		foreach (($schema->getProperties() ?? []) as $name => $property) {
			$type = null;
			if (is_array($property) === true) {
				$type = self::text(value: ($property['type'] ?? null));
			}

			$properties[] = ['name' => (string)$name, 'type' => $type];
		}

		return [
			'id' => (string)$schema->getId(),
			'slug' => $schema->getSlug(),
			'title' => $schema->getTitle(),
			'external' => $external,
			'readable' => true,
			'properties' => $properties,
		];
	}//end node()

	/**
	 * A schema outside the register: drawn in full when the caller may read it, nameless when not.
	 *
	 * @param string $schemaId The schema id.
	 *
	 * @return array<string, mixed>
	 */
	private function externalNode(string $schemaId): array {
		try {
			return self::node(schema: $this->schemas->find($schemaId), external: true);
		} catch (\Throwable $e) {
			return [
				'id' => $schemaId,
				'slug' => null,
				'title' => null,
				'external' => true,
				'readable' => false,
				'properties' => [],
			];
		}
	}//end externalNode()
}//end class
