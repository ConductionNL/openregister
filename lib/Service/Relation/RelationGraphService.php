<?php

/**
 * Answers what a record is linked to, within a bounded depth.
 *
 * GLPI draws an impact graph and exports it; the corpus says the question
 * "what is this case linked to" is one people ask before they act. The bound
 * is not a detail: an unbounded walk over a municipal register is a query that
 * never returns, and one that silently returns half an answer is worse than
 * one that refuses. So the depth is declared per request within an
 * administered maximum, the result count is capped, and the answer says which
 * of the two stopped it.
 *
 * Edges come from both places a relation can live: the `$ref` relations
 * already recorded on each object, and the stored rows for the links no
 * property can hold. A graph that showed only one of the two would be a
 * different graph depending on which surface wrote the link.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectRelation;
use OCA\OpenRegister\Db\ObjectRelationMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Walks the relation graph outward from one object.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * Reason: a breadth-first walk bounded twice, over two edge sources, with a
 * node shape per source. The branches are the two bounds and the two sources,
 * and every one of them is load-bearing: dropping a branch is dropping a
 * bound or half the graph.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
class RelationGraphService implements RelationGraphServiceInterface {
	/**
	 * The app whose settings hold the administered maximum.
	 *
	 * @var string
	 */
	public const APP_ID = 'openregister';

	/**
	 * The setting naming the deepest walk an instance permits.
	 *
	 * @var string
	 */
	public const MAX_DEPTH_KEY = 'relationGraphMaxDepth';

	/**
	 * The default ceiling. Three steps is far enough to answer "what is this
	 * case linked to" and near enough that the answer arrives.
	 *
	 * @var int
	 */
	public const MAX_DEPTH_DEFAULT = 3;

	/**
	 * The setting naming the largest answer an instance permits.
	 *
	 * @var string
	 */
	public const MAX_NODES_KEY = 'relationGraphMaxNodes';

	/**
	 * The default node cap.
	 *
	 * @var int
	 */
	public const MAX_NODES_DEFAULT = 250;

	/**
	 * The answer stopped because the requested depth was reached.
	 *
	 * @var string
	 */
	public const TRUNCATED_DEPTH = 'depth';

	/**
	 * The answer stopped because the node cap was reached.
	 *
	 * @var string
	 */
	public const TRUNCATED_CAP = 'cap';

	/**
	 * Constructor.
	 *
	 * @param MagicMapperInterface $objectEntityMapper Mapper for object entities.
	 * @param ObjectRelationMapperInterface $relationMapper Mapper for stored relation rows.
	 * @param SchemaMapperInterface $schemaMapper Mapper for schemas.
	 * @param RelationTypeResolver $relationTypes Resolves what a relation is called.
	 * @param IAppConfig $appConfig Instance settings.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function __construct(
		private readonly MagicMapperInterface $objectEntityMapper,
		private readonly ObjectRelationMapperInterface $relationMapper,
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly RelationTypeResolver $relationTypes,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The deepest walk this instance permits.
	 *
	 * @return int The administered maximum.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function maximumDepth(): int {
		$configured = $this->appConfig->getValueInt(self::APP_ID, self::MAX_DEPTH_KEY, self::MAX_DEPTH_DEFAULT);

		return max(1, $configured);
	}//end maximumDepth()

	/**
	 * The largest answer this instance permits.
	 *
	 * @return int The node cap.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function maximumNodes(): int {
		$configured = $this->appConfig->getValueInt(self::APP_ID, self::MAX_NODES_KEY, self::MAX_NODES_DEFAULT);

		return max(1, $configured);
	}//end maximumNodes()

	/**
	 * The objects reachable from a root, with the typed edges between them.
	 *
	 * A request above the administered maximum is BOUNDED at the maximum and
	 * marked truncated rather than refused: refusing sends the caller back to
	 * guess a number, and answering silently at a smaller depth is the shape
	 * of wrongness this whole method exists to avoid. The answer always says
	 * what it is.
	 *
	 * @param string $rootUuid The object to walk out from.
	 * @param int $depth The depth asked for.
	 * @param string $language The BCP-47 tag to resolve labels in.
	 *
	 * @return array{root: string, depth: int, requestedDepth: int, maxDepth: int,
	 *     nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>,
	 *     truncated: bool, truncatedBy: string|null}
	 *     The graph.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function graph(string $rootUuid, int $depth = 1, string $language = 'nl'): array {
		$walk = new GraphWalk();
		$walk->maxDepth = $this->maximumDepth();
		$walk->requestedDepth = max(1, $depth);
		$walk->depth = min($walk->requestedDepth, $walk->maxDepth);
		$walk->language = $language;
		$this->startAt(walk: $walk, rootUuid: $rootUuid);
		$this->walkLevels(walk: $walk);

		// A walk that still had an UNVISITED neighbour when the requested depth
		// ran out is truncated by that depth, even within the administered
		// maximum. A non-empty frontier alone is not enough: the last level
		// almost always sits in it, and a flag that is always true tells a
		// reader nothing.
		if ($walk->truncatedBy === null && $this->hasMore(frontier: $walk->frontier, visited: $walk->visited, language: $language) === true) {
			$walk->truncatedBy = self::TRUNCATED_DEPTH;
		}

		return $this->graphResult(walk: $walk);
	}//end graph()

	/**
	 * Start a walk at its root: the node cap, a depth cut when more was asked
	 * than is allowed, and the root as the only reached node.
	 *
	 * @param GraphWalk $walk     The walk, with its depths set.
	 * @param string    $rootUuid The root UUID.
	 *
	 * @return void
	 */
	private function startAt(GraphWalk $walk, string $rootUuid): void {
		$walk->maxNodes = $this->maximumNodes();
		if ($walk->requestedDepth > $walk->maxDepth) {
			$walk->truncatedBy = self::TRUNCATED_DEPTH;
		}

		$walk->root = $rootUuid;
		$walk->frontier = [$rootUuid];
		$walk->visited = [$rootUuid => true];
		$walk->nodes[$rootUuid] = $this->node(uuid: $rootUuid, object: $this->loadObject(uuid: $rootUuid), distance: 0);
	}//end startAt()

	/**
	 * Walk level by level: at most the walk's depth, and no further once the node cap stopped it.
	 *
	 * @param GraphWalk $walk The walk.
	 *
	 * @return void
	 */
	private function walkLevels(GraphWalk $walk): void {
		for ($walk->level = 0; $walk->level < $walk->depth; $walk->level++) {
			if ($walk->frontier === [] || $walk->truncatedBy === self::TRUNCATED_CAP) {
				break;
			}

			$walk->next = [];
			foreach ($this->edgesFrom(uuids: $walk->frontier, language: $walk->language) as $edge) {
				if ($this->followEdge(walk: $walk, edge: $edge) === false) {
					break;
				}
			}

			$walk->frontier = $walk->next;
		}
	}//end walkLevels()

	/**
	 * The graph answer of a finished walk.
	 *
	 * @param GraphWalk $walk The finished walk.
	 *
	 * @return array{root: string, depth: int, requestedDepth: int, maxDepth: int,
	 *     nodes: list<array>, edges: array, truncated: bool, truncatedBy: string|null}
	 */
	private function graphResult(GraphWalk $walk): array {
		return [
			'root' => $walk->root,
			'depth' => $walk->depth,
			'requestedDepth' => $walk->requestedDepth,
			'maxDepth' => $walk->maxDepth,
			'nodes' => array_values($walk->nodes),
			'edges' => $walk->edges,
			'truncated' => ($walk->truncatedBy !== null),
			'truncatedBy' => $walk->truncatedBy,
		];
	}//end graphResult()

	/**
	 * Walk one edge: record it once, and reach its other end unless that is visited or the node cap is hit.
	 *
	 * Hitting the cap marks the walk truncated by the cap, not the depth:
	 * naming which bound stopped the walk is the difference between "there is
	 * no more" and "there is more and you were not shown it".
	 *
	 * @param GraphWalk $walk The walk, on one level.
	 * @param array     $edge The edge.
	 *
	 * @return bool False when the node cap stops the level.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function followEdge(GraphWalk $walk, array $edge): bool {
		$key = $edge['from'].'|'.$edge['to'].'|'.((string)($edge['label'] ?? '')).'|'.$edge['direction'];
		if (isset($walk->seenEdges[$key]) === true) {
			return true;
		}

		$walk->seenEdges[$key] = true;
		$walk->edges[] = $edge;

		$other = self::otherEnd(edge: $edge);

		if ($other === '' || isset($walk->visited[$other]) === true) {
			return true;
		}

		if (count($walk->nodes) >= $walk->maxNodes) {
			$walk->truncatedBy = self::TRUNCATED_CAP;
			return false;
		}

		$walk->visited[$other] = true;
		$this->reachNode(walk: $walk, edge: $edge);
		return true;
	}//end followEdge()

	/**
	 * Add the node an edge reaches; an external address is a leaf that keeps its own title.
	 *
	 * An external address is not an object: loading it would fail, and
	 * passing it through node() would throw its title away and draw an
	 * anonymous node. Only objects are walked out from on the next level.
	 *
	 * @param GraphWalk $walk The walk, on one level.
	 * @param array     $edge The edge reaching the node.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function reachNode(GraphWalk $walk, array $edge): void {
		$other = self::otherEnd(edge: $edge);

		if (($edge['kind'] ?? null) === ObjectRelation::KIND_EXTERNAL) {
			$walk->nodes[$other] = [
				'uuid' => $other,
				'title' => ($edge['targetTitle'] ?? $other),
				'register' => null,
				'schema' => null,
				'distance' => ($walk->level + 1),
				'resolved' => true,
				'external' => true,
			];
			return;
		}

		$walk->nodes[$other] = $this->node(
			uuid: $other,
			object: $this->loadObject(uuid: $other),
			distance: ($walk->level + 1)
		);
		$walk->next[] = $other;
	}//end reachNode()

	/**
	 * The end of an edge the walk has not come from: its source for an incoming edge, else its target.
	 *
	 * @param array $edge The edge.
	 *
	 * @return string The other end.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private static function otherEnd(array $edge): string {
		if ($edge['direction'] === RelationTypeResolver::DIRECTION_INCOMING) {
			return $edge['from'];
		}

		return $edge['to'];
	}//end otherEnd()

	/**
	 * Whether the level the walk stopped on leads anywhere it has not been.
	 *
	 * One extra edge read, deliberately, so `truncated` means "there is more
	 * and you were not shown it" rather than "the walk ended", which is true
	 * of every walk.
	 *
	 * @param array<int, string> $frontier The level the walk stopped on.
	 * @param array<string, bool> $visited Every node already in the answer.
	 * @param string $language The BCP-47 tag.
	 *
	 * @return boolean True when an unvisited neighbour exists.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function hasMore(array $frontier, array $visited, string $language): bool {
		if ($frontier === []) {
			return false;
		}

		foreach ($this->edgesFrom(uuids: $frontier, language: $language) as $edge) {
			$other = $edge['to'];
			if ($edge['direction'] === RelationTypeResolver::DIRECTION_INCOMING) {
				$other = $edge['from'];
			}

			if ($other !== '' && isset($visited[$other]) === false) {
				return true;
			}
		}

		return false;
	}//end hasMore()

	/**
	 * The same graph as rows a spreadsheet can open.
	 *
	 * One row per edge, because an edge list is what every graph tool reads and
	 * what a person checking "does this case really depend on that one" scans.
	 * The truncation marker rides along as a trailing row rather than a header
	 * comment, so it survives a paste into a sheet.
	 *
	 * @param string $rootUuid The object to walk out from.
	 * @param int $depth The depth asked for.
	 * @param string $language The BCP-47 tag.
	 *
	 * @return array{rows: array<int, array<int, string>>, graph: array<string, mixed>}
	 *     The export rows, header first, and the graph they came from.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function export(string $rootUuid, int $depth = 1, string $language = 'nl'): array {
		$graph = $this->graph(rootUuid: $rootUuid, depth: $depth, language: $language);

		return ['rows' => $this->rowsOf(graph: $graph), 'graph' => $graph];
	}//end export()

	/**
	 * The export rows of a graph, header first.
	 *
	 * Separate from export() so a caller that narrows the graph first (the
	 * controller drops the nodes of schemas whose purpose was not named) writes
	 * the rows of what it kept, not of the whole walk.
	 *
	 * @param array<string, mixed> $graph The graph, as graph() answers it or narrowed.
	 *
	 * @return array<int, array<int, string>> The rows.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function rowsOf(array $graph): array {
		$titles = [];
		foreach (($graph['nodes'] ?? []) as $node) {
			$titles[(string)$node['uuid']] = (string)($node['title'] ?? $node['uuid']);
		}

		$rows = [['from', 'fromTitle', 'relation', 'direction', 'to', 'toTitle', 'origin', 'distance']];
		foreach (($graph['edges'] ?? []) as $edge) {
			$rows[] = [
				(string)$edge['from'],
				($titles[(string)$edge['from']] ?? ''),
				(string)($edge['label'] ?? ''),
				(string)$edge['direction'],
				(string)$edge['to'],
				($titles[(string)$edge['to']] ?? (string)($edge['targetUrl'] ?? '')),
				(string)($edge['origin'] ?? ''),
				(string)($edge['distance'] ?? ''),
			];
		}

		if (($graph['truncated'] ?? false) === true) {
			$rows[] = ['truncated', (string)($graph['truncatedBy'] ?? ''), '', '', '', '', '', ''];
		}

		return $rows;
	}//end rowsOf()

	/**
	 * Every edge touching a level of the walk, from both edge sources.
	 *
	 * @param array<int, string> $uuids The objects at this level.
	 * @param string $language The BCP-47 tag.
	 *
	 * @return array<int, array<string, mixed>> The edges.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function edgesFrom(array $uuids, string $language): array {
		$edges = [];
		foreach ($uuids as $uuid) {
			$edges = array_merge($edges, $this->propertyEdges(uuid: $uuid, language: $language));
		}

		// Source two: the stored rows for the links no property can hold. One
		// query for the whole level, not one per node.
		foreach ($this->relationMapper->findTouching(uuids: $uuids) as $stored) {
			$edges[] = self::storedEdge(stored: $stored, uuids: $uuids);
		}

		return $edges;
	}//end edgesFrom()

	/**
	 * The edges of the `$ref` relations already recorded on one object.
	 *
	 * @param string $uuid     The object's uuid.
	 * @param string $language The language labels are read in.
	 *
	 * @return array<int, array<string, mixed>> The outgoing property edges; none when the object cannot be loaded.
	 */
	private function propertyEdges(string $uuid, string $language): array {
		$object = $this->loadObject(uuid: $uuid);
		if ($object === null) {
			return [];
		}

		$schema = $this->schemaOf(schemaId: $object->getSchema());
		$edges  = [];
		foreach ($this->linksOf(relations: ($object->getRelations() ?? []), uuid: $uuid) as [$path, $target]) {
			$descriptor = $this->relationTypes->descriptorFor(schema: $schema, property: $path, language: $language);
			$row        = $this->relationTypes->row(descriptor: $descriptor, direction: RelationTypeResolver::DIRECTION_OUTGOING, path: $path);
			$edges[]    = ['from' => $uuid, 'to' => $target] + self::outgoingDetails(row: $row);
		}

		return $edges;
	}//end propertyEdges()

	/**
	 * The relation path and target of each link an object records, without links to itself.
	 *
	 * @param array  $relations The object's recorded relations, by path.
	 * @param string $uuid      The object's uuid.
	 *
	 * @return array<int, array{0: string, 1: string}> Each link as its path and target uuid.
	 */
	private function linksOf(array $relations, string $uuid): array {
		$links = [];
		foreach ($relations as $path => $value) {
			foreach ($this->targetsOf(value: $value) as $target) {
				if (is_string($path) === true && $target !== $uuid) {
					$links[] = [$path, $target];
				}
			}
		}

		return $links;
	}//end linksOf()

	/**
	 * The direction, label, type and kind fields of an outgoing property edge.
	 *
	 * @param array<string, mixed> $row The relation type row of the edge's path.
	 *
	 * @return array<string, mixed>
	 */
	private static function outgoingDetails(array $row): array {
		return [
			'direction' => RelationTypeResolver::DIRECTION_OUTGOING,
			'label' => ($row['displayLabel'] ?? null),
			'inverseLabel' => ($row['inverseLabel'] ?? null),
			'type' => ($row['type'] ?? null),
			'property' => ($row['property'] ?? null),
			'origin' => 'property',
			'kind' => ObjectRelation::KIND_OBJECT,
		];
	}//end outgoingDetails()

	/**
	 * One edge from a stored relation row, outgoing when its source is on this level.
	 *
	 * @param ObjectRelation     $stored The stored row.
	 * @param array<int, string> $uuids  The uuids of this level.
	 *
	 * @return array<string, mixed> The edge.
	 */
	private static function storedEdge(ObjectRelation $stored, array $uuids): array {
		$source    = (string)$stored->getSourceUuid();
		$direction = RelationTypeResolver::DIRECTION_INCOMING;
		if (in_array($source, $uuids, true) === true) {
			$direction = RelationTypeResolver::DIRECTION_OUTGOING;
		}

		return ['from' => $source, 'to' => self::storedTarget(stored: $stored), 'direction' => $direction] + self::storedDetails(stored: $stored);
	}//end storedEdge()

	/**
	 * The other end of a stored row: its target uuid, or its address when it has none.
	 *
	 * @param ObjectRelation $stored The stored row.
	 *
	 * @return string
	 */
	private static function storedTarget(ObjectRelation $stored): string {
		$target = (string)($stored->getTargetUuid() ?? '');
		if ($target === '') {
			return (string)$stored->getTargetUrl();
		}

		return $target;
	}//end storedTarget()

	/**
	 * The label, type, origin and target fields of an edge from a stored row.
	 *
	 * @param ObjectRelation $stored The stored row.
	 *
	 * @return array<string, mixed>
	 */
	private static function storedDetails(ObjectRelation $stored): array {
		return [
			'label' => ($stored->getLabel() ?? $stored->getRelationType()),
			'inverseLabel' => $stored->getInverseLabel(),
			'type' => $stored->getRelationType(),
			'property' => null,
			'origin' => $stored->getOrigin(),
			'kind' => $stored->getKind(),
			'targetUrl' => $stored->getTargetUrl(),
			'targetTitle' => $stored->getTargetTitle(),
		];
	}//end storedDetails()

	/**
	 * The uuids a stored relation value holds.
	 *
	 * @param mixed $value One entry of an object's relation map.
	 *
	 * @return array<int, string> The uuids.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function targetsOf(mixed $value): array {
		if (is_string($value) === true && $value !== '') {
			return [$value];
		}

		if (is_array($value) === false) {
			return [];
		}

		$targets = [];
		foreach ($value as $entry) {
			if (is_string($entry) === true && $entry !== '') {
				$targets[] = $entry;
			}
		}

		return $targets;
	}//end targetsOf()

	/**
	 * One node of the answer.
	 *
	 * A node whose object cannot be loaded still appears, with its uuid and
	 * nothing else. Dropping it would silently remove the edge that names it,
	 * and "the link is not there" and "you may not read what it points at" are
	 * not the same answer.
	 *
	 * @param string $uuid The object's uuid.
	 * @param ObjectEntity|null $object The object, when it loaded.
	 * @param int $distance How many steps from the root.
	 *
	 * @return array<string, mixed> The node.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function node(string $uuid, ?ObjectEntity $object, int $distance): array {
		if ($object === null) {
			return [
				'uuid' => $uuid,
				'title' => null,
				'register' => null,
				'schema' => null,
				'distance' => $distance,
				'resolved' => false,
				'external' => false,
			];
		}

		return [
			'uuid' => $uuid,
			'title' => ($object->getName() ?? $uuid),
			'register' => $object->getRegister(),
			'schema' => $object->getSchema(),
			'distance' => $distance,
			'resolved' => true,
			'external' => false,
		];
	}//end node()

	/**
	 * Load one object by uuid, or null when it cannot be read.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity|null The object.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function loadObject(string $uuid): ?ObjectEntity {
		try {
			return $this->objectEntityMapper->find(identifier: $uuid);
		} catch (\Exception $e) {
			$this->logger->debug(
				message: '[RelationGraphService] A graph node could not be loaded',
				context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $uuid]
			);

			return null;
		}
	}//end loadObject()

	/**
	 * The schema an object belongs to, for label resolution.
	 *
	 * @param mixed $schemaId The schema id.
	 *
	 * @return \OCA\OpenRegister\Db\Schema|null The schema.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function schemaOf(mixed $schemaId): ?\OCA\OpenRegister\Db\Schema {
		if (is_numeric($schemaId) === false) {
			return null;
		}

		try {
			return $this->schemaMapper->find((int)$schemaId, _rbac: false, _multitenancy: false);
		} catch (\Exception $e) {
			return null;
		}
	}//end schemaOf()
}//end class
