<?php

/**
 * The ancestors and descendants of one object along its schema's parent property.
 *
 * The parent property is the one a schema already declares. `x-openregister-hierarchy`
 * names it when the tree also carries inherited access; `x-openregister-party`
 * names it as `parentProperty` when the tree is an organisation's group and
 * should hand nobody any access. Either way the edge points at the same schema,
 * so the whole tree lives in one magic table and a level is one query.
 *
 * Bounded three ways: the depth the schema declares, the depth the caller asks
 * for, and the instance's node cap. The answer says which bound stopped it, so a
 * caller can refuse a partial tree instead of reading it as the whole one.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Party\PartyDefinition;
use OCA\OpenRegister\Service\Rbac\HierarchyDescender;
use OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Walks one object's tree up and down, under the caller's RBAC.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The walk reads the declaration,
 * the table, the access filter and the instance cap; each is its own class.
 *
 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
 */
class ObjectTreeService {

	/**
	 * Read the objects below the root.
	 *
	 * @var string
	 */
	public const DIRECTION_DESCENDANTS = 'descendants';

	/**
	 * Read the objects above the root.
	 *
	 * @var string
	 */
	public const DIRECTION_ANCESTORS = 'ancestors';

	/**
	 * Read both.
	 *
	 * @var string
	 */
	public const DIRECTION_BOTH = 'both';

	/**
	 * The walk stopped at the depth bound with more below or above it.
	 *
	 * @var string
	 */
	public const TRUNCATED_DEPTH = 'depth';

	/**
	 * The walk stopped at the node cap.
	 *
	 * @var string
	 */
	public const TRUNCATED_CAP = 'cap';

	/**
	 * A level could not be read from the table.
	 *
	 * @var string
	 */
	public const TRUNCATED_UNREADABLE = 'unreadable';

	/**
	 * Constructor.
	 *
	 * @param HierarchyDescender     $descender      Reads one level of children from a magic table.
	 * @param HierarchyGrantExpander $hierarchies    Reads a schema's hierarchy declaration.
	 * @param MagicMapper            $objectMapper   Loads objects and filters them by read access.
	 * @param RegisterMapper         $registerMapper Resolves the root's register.
	 * @param SchemaMapper           $schemaMapper   Resolves the root's schema.
	 * @param RelationGraphService   $graphs         Holds the instance's node cap.
	 * @param LoggerInterface        $logger         The logger.
	 */
	public function __construct(
		private readonly HierarchyDescender $descender,
		private readonly HierarchyGrantExpander $hierarchies,
		private readonly MagicMapper $objectMapper,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly RelationGraphService $graphs,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The parent property a schema declares, with its depth bound, or null.
	 *
	 * `x-openregister-hierarchy` wins where both are declared, because it is the
	 * declaration access inheritance already reads.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array{property: string, maxDepth: int, source: string}|null The declaration.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) PartyDefinition::fromSchema() is the party model's one reader of the annotation.
	 *
	 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
	 */
	public function declarationFor(Schema $schema): ?array {
		$hierarchy = $this->hierarchies->declarationFor(schema: $schema);
		if ($hierarchy !== null) {
			return [
				'property' => $hierarchy['parent'],
				'maxDepth' => $hierarchy['maxDepth'],
				'source' => HierarchyGrantExpander::ANNOTATION,
			];
		}

		$party = PartyDefinition::fromSchema(schema: $schema);
		if ($party === null || $party->parentProperty() === null) {
			return null;
		}

		return [
			'property' => $party->parentProperty(),
			'maxDepth' => min($party->maxDepth(), HierarchyGrantExpander::DEPTH_CEILING),
			'source' => 'x-openregister-party',
		];
	}//end declarationFor()

	/**
	 * The tree around one object.
	 *
	 * The root is always in the answer at level 0: the caller has already been
	 * allowed to read it. Ancestors carry negative levels, nearest first as -1.
	 * A node the caller may not read is left out, and so is everything reached
	 * only through it.
	 *
	 * @param ObjectEntity $root      The object to walk from, already read under the caller's RBAC.
	 * @param string       $direction One of the DIRECTION_ constants.
	 * @param int|null     $depth     The depth asked for; null means the declared bound.
	 * @param string[]     $fields    Object properties to copy onto each node.
	 * @param bool         $_rbac     Whether to apply the caller's read access to every node.
	 *
	 * @return array{root: string, register: int, schema: int, parentProperty: string|null,
	 *     direction: string, depth: int, requestedDepth: int|null, maxDepth: int,
	 *     nodes: array<int, array<string, mixed>>, truncated: bool, truncatedBy: string|null}
	 *     The tree.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `_rbac` follows ObjectService's own read signature.
	 *
	 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
	 */
	public function tree(
		ObjectEntity $root,
		string $direction = self::DIRECTION_DESCENDANTS,
		?int $depth = null,
		array $fields = [],
		bool $_rbac = true
	): array {
		$registerId = (int)$root->getRegister();
		$schemaId = (int)$root->getSchema();
		$rootUuid = (string)$root->getUuid();

		$answer = [
			'root' => $rootUuid,
			'register' => $registerId,
			'schema' => $schemaId,
			'parentProperty' => null,
			'direction' => $direction,
			'depth' => 0,
			'requestedDepth' => $depth,
			'maxDepth' => 0,
			'nodes' => [$this->node(object: $root, level: 0, parent: null, fields: $fields)],
			'truncated' => false,
			'truncatedBy' => null,
		];

		$context = $this->context(registerId: $registerId, schemaId: $schemaId);
		if ($context === null) {
			return $answer;
		}

		$declaration = $this->declarationFor(schema: $context['schema']);
		if ($declaration === null) {
			return $answer;
		}

		$effective = $declaration['maxDepth'];
		if ($depth !== null) {
			$effective = min(max(1, $depth), $declaration['maxDepth']);
		}

		$answer['parentProperty'] = $declaration['property'];
		$answer['maxDepth'] = $declaration['maxDepth'];
		$answer['depth'] = $effective;

		$walk = [
			'register' => $context['register'],
			'schema' => $context['schema'],
			'property' => $declaration['property'],
			'depth' => $effective,
			'fields' => $fields,
			'rbac' => $_rbac,
		];

		$ancestors = ['nodes' => [], 'truncatedBy' => null];
		if ($direction === self::DIRECTION_ANCESTORS || $direction === self::DIRECTION_BOTH) {
			$ancestors = $this->ancestors(root: $root, walk: $walk);
		}

		$descendants = ['nodes' => [], 'truncatedBy' => null];
		if ($direction === self::DIRECTION_DESCENDANTS || $direction === self::DIRECTION_BOTH) {
			$descendants = $this->descendants(rootUuid: $rootUuid, walk: $walk);
		}

		$answer['nodes'] = array_merge(
			array_reverse($ancestors['nodes']),
			$answer['nodes'],
			$descendants['nodes']
		);
		$answer['truncatedBy'] = ($descendants['truncatedBy'] ?? $ancestors['truncatedBy']);
		$answer['truncated'] = ($answer['truncatedBy'] !== null);

		return $answer;
	}//end tree()

	/**
	 * The register and schema of the root, or null when either does not resolve.
	 *
	 * @param int $registerId The register id.
	 * @param int $schemaId   The schema id.
	 *
	 * @return array{register: Register, schema: Schema}|null The pair.
	 */
	private function context(int $registerId, int $schemaId): ?array {
		try {
			// Metadata only: which table and which declaration. Access to the
			// rows is decided per node below.
			return [
				'register' => $this->registerMapper->find(id: $registerId, _rbac: false, _multitenancy: false),
				'schema' => $this->schemaMapper->find(id: $schemaId, _rbac: false, _multitenancy: false),
			];
		} catch (Throwable $e) {
			return null;
		}
	}//end context()

	/**
	 * The descendants of the root, breadth first.
	 *
	 * @param string               $rootUuid The root.
	 * @param array<string, mixed> $walk     The register, schema, property, depth, fields and rbac flag.
	 *
	 * @return array{nodes: array<int, array<string, mixed>>, truncatedBy: string|null} The nodes.
	 */
	private function descendants(string $rootUuid, array $walk): array {
		$table = MagicMapper::TABLE_PREFIX . (int)$walk['register']->getId() . '_' . (int)$walk['schema']->getId();
		$column = $this->descender->columnFor(property: $walk['property']);
		$cap = $this->graphs->maximumNodes();

		$nodes = [];
		$seen = [$rootUuid => true];
		$frontier = [$rootUuid];

		for ($level = 1; $level <= $walk['depth']; $level++) {
			$children = $this->readableChildren(table: $table, column: $column, parents: $frontier, seen: $seen, walk: $walk);
			if ($children === null) {
				return ['nodes' => $nodes, 'truncatedBy' => self::TRUNCATED_UNREADABLE];
			}

			if ($children === []) {
				return ['nodes' => $nodes, 'truncatedBy' => null];
			}

			if ((count($nodes) + count($children) + 1) > $cap) {
				return ['nodes' => $nodes, 'truncatedBy' => self::TRUNCATED_CAP];
			}

			$objects = $this->load(walk: $walk, uuids: array_keys($children));
			$frontier = [];
			foreach ($children as $child => $parent) {
				$seen[$child] = true;
				$frontier[] = $child;
				$nodes[] = $this->node(object: ($objects[$child] ?? null), level: $level, parent: $parent, fields: $walk['fields'], uuid: $child);
			}
		}

		$more = $this->readableChildren(table: $table, column: $column, parents: $frontier, seen: $seen, walk: $walk);
		if ($more === null) {
			return ['nodes' => $nodes, 'truncatedBy' => self::TRUNCATED_UNREADABLE];
		}

		if ($more !== []) {
			return ['nodes' => $nodes, 'truncatedBy' => self::TRUNCATED_DEPTH];
		}

		return ['nodes' => $nodes, 'truncatedBy' => null];
	}//end descendants()

	/**
	 * The children of a level the caller may read and has not met yet, or null when the level cannot be read.
	 *
	 * A cycle in the data shows up here as a child already seen, and is dropped.
	 *
	 * @param string               $table   The magic table.
	 * @param string               $column  The parent column.
	 * @param string[]             $parents The level to read below.
	 * @param array<string, bool>  $seen    Every uuid already in the answer.
	 * @param array<string, mixed> $walk    The walk.
	 *
	 * @return array<string, string>|null Child uuid => parent uuid.
	 */
	private function readableChildren(string $table, string $column, array $parents, array $seen, array $walk): ?array {
		if ($parents === []) {
			return [];
		}

		try {
			$children = $this->descender->childrenOf(table: $table, parentColumn: $column, parentUuids: $parents);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ObjectTreeService] A level of the tree could not be read',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'table' => $table,
					'column' => $column,
					'exception' => $e->getMessage(),
				]
			);
			return null;
		}

		$children = array_diff_key($children, $seen);

		return $this->readableOnly(walk: $walk, uuids: $children);
	}//end readableChildren()

	/**
	 * The ancestors of the root, nearest first.
	 *
	 * The walk stops at the first ancestor the caller may not read: a level
	 * above a hidden one would carry the wrong level number.
	 *
	 * @param ObjectEntity         $root The root.
	 * @param array<string, mixed> $walk The walk.
	 *
	 * @return array{nodes: array<int, array<string, mixed>>, truncatedBy: string|null} The nodes, nearest first.
	 */
	private function ancestors(ObjectEntity $root, array $walk): array {
		$nodes = [];
		$seen = [(string)$root->getUuid() => true];
		$current = $root;

		for ($level = 1; $level <= $walk['depth']; $level++) {
			$parent = $this->readableParent(object: $current, seen: $seen, walk: $walk);
			if ($parent === null) {
				return ['nodes' => $nodes, 'truncatedBy' => null];
			}

			$uuid = (string)$parent->getUuid();
			$seen[$uuid] = true;
			$grandparent = self::reference(value: ($parent->getObject()[$walk['property']] ?? null));
			$nodes[] = $this->node(object: $parent, level: -$level, parent: $grandparent, fields: $walk['fields']);
			$current = $parent;
		}

		if ($this->readableParent(object: $current, seen: $seen, walk: $walk) !== null) {
			return ['nodes' => $nodes, 'truncatedBy' => self::TRUNCATED_DEPTH];
		}

		return ['nodes' => $nodes, 'truncatedBy' => null];
	}//end ancestors()

	/**
	 * The parent of one object when it exists, is new to the walk and is readable.
	 *
	 * @param ObjectEntity         $object The object.
	 * @param array<string, bool>  $seen   Every uuid already in the answer.
	 * @param array<string, mixed> $walk   The walk.
	 *
	 * @return ObjectEntity|null The parent.
	 */
	private function readableParent(ObjectEntity $object, array $seen, array $walk): ?ObjectEntity {
		$parentUuid = self::reference(value: ($object->getObject()[$walk['property']] ?? null));
		if ($parentUuid === null || isset($seen[$parentUuid]) === true) {
			return null;
		}

		if ($this->readableOnly(walk: $walk, uuids: [$parentUuid => '']) === []) {
			return null;
		}

		return ($this->load(walk: $walk, uuids: [$parentUuid])[$parentUuid] ?? null);
	}//end readableParent()

	/**
	 * The entries of a uuid-keyed map the caller may read.
	 *
	 * @param array<string, mixed>  $walk  The walk.
	 * @param array<string, string> $uuids Uuid => anything.
	 *
	 * @return array<string, string> The readable entries, in their order.
	 */
	private function readableOnly(array $walk, array $uuids): array {
		if ($uuids === [] || $walk['rbac'] === false) {
			return $uuids;
		}

		$readable = $this->objectMapper->filterReadableUuids(
			registerId: (int)$walk['register']->getId(),
			schemaId: (int)$walk['schema']->getId(),
			uuids: array_map('strval', array_keys($uuids))
		);

		return array_intersect_key($uuids, array_flip($readable));
	}//end readableOnly()

	/**
	 * Load objects of the walk's table by uuid.
	 *
	 * @param array<string, mixed> $walk  The walk.
	 * @param string[]             $uuids The uuids.
	 *
	 * @return array<string, ObjectEntity> Uuid => object.
	 */
	private function load(array $walk, array $uuids): array {
		return $this->objectMapper->findObjectsByUuidsInRegisterSchema(
			register: $walk['register'],
			schema: $walk['schema'],
			uuids: array_map('strval', $uuids)
		);
	}//end load()

	/**
	 * One node of the answer.
	 *
	 * @param ObjectEntity|null $object The object, when it loaded.
	 * @param int               $level  0 for the root, negative above, positive below.
	 * @param string|null       $parent The parent uuid.
	 * @param string[]          $fields Properties to copy.
	 * @param string|null       $uuid   The uuid when the object did not load.
	 *
	 * @return array<string, mixed> The node.
	 */
	private function node(?ObjectEntity $object, int $level, ?string $parent, array $fields, ?string $uuid = null): array {
		$uuid = (string)($object?->getUuid() ?? $uuid);
		$data = ($object?->getObject() ?? []);

		$properties = [];
		foreach ($fields as $field) {
			$properties[$field] = ($data[$field] ?? null);
		}

		return [
			'uuid' => $uuid,
			'name' => ($object?->getName() ?? $uuid),
			'level' => $level,
			'parent' => $parent,
			'properties' => $properties,
		];
	}//end node()

	/**
	 * The uuid a parent property holds, or null.
	 *
	 * @param mixed $value The stored value: a uuid, or a reference object carrying one.
	 *
	 * @return string|null The uuid.
	 */
	private static function reference(mixed $value): ?string {
		if (is_array($value) === true) {
			$value = ($value['uuid'] ?? ($value['id'] ?? ($value['value'] ?? null)));
		}

		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end reference()
}//end class
