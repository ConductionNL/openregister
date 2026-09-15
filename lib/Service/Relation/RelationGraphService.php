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

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectRelation;
use OCA\OpenRegister\Db\ObjectRelationMapper;
use OCA\OpenRegister\Db\SchemaMapper;
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
class RelationGraphService {
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
	 * @param MagicMapper $objectEntityMapper Mapper for object entities.
	 * @param ObjectRelationMapper $relationMapper Mapper for stored relation rows.
	 * @param SchemaMapper $schemaMapper Mapper for schemas.
	 * @param RelationTypeResolver $relationTypes Resolves what a relation is called.
	 * @param IAppConfig $appConfig Instance settings.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function __construct(
		private readonly MagicMapper $objectEntityMapper,
		private readonly ObjectRelationMapper $relationMapper,
		private readonly SchemaMapper $schemaMapper,
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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) A bounded breadth-first walk over two edge sources.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      The same walk; the branches are the two bounds and the two sources.
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Splitting the walk across methods would hide the bound from its own loop.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function graph(string $rootUuid, int $depth = 1, string $language = 'nl'): array {
		$maxDepth = $this->maximumDepth();
		$maxNodes = $this->maximumNodes();
		$requested = max(1, $depth);
		$effective = min($requested, $maxDepth);

		$truncatedBy = null;
		if ($requested > $maxDepth) {
			$truncatedBy = self::TRUNCATED_DEPTH;
		}

		$nodes = [];
		$edges = [];
		$seenEdges = [];
		$frontier = [$rootUuid];
		$visited = [$rootUuid => true];

		$root = $this->loadObject(uuid: $rootUuid);
		$nodes[$rootUuid] = $this->node(uuid: $rootUuid, object: $root, distance: 0);

		for ($level = 0; $level < $effective; $level++) {
			if ($frontier === [] || $truncatedBy === self::TRUNCATED_CAP) {
				break;
			}

			$next = [];
			foreach ($this->edgesFrom(uuids: $frontier, language: $language) as $edge) {
				$key = $edge['from'].'|'.$edge['to'].'|'.((string)($edge['label'] ?? '')).'|'.$edge['direction'];
				if (isset($seenEdges[$key]) === true) {
					continue;
				}

				$seenEdges[$key] = true;
				$edges[] = $edge;

				$other = $edge['to'];
				if ($edge['direction'] === RelationTypeResolver::DIRECTION_INCOMING) {
					$other = $edge['from'];
				}

				if ($other === '' || isset($visited[$other]) === true) {
					continue;
				}

				if (count($nodes) >= $maxNodes) {
					// The cap, not the depth. Naming which one stopped the walk
					// is the difference between "there is no more" and "there
					// is more and you were not shown it".
					$truncatedBy = self::TRUNCATED_CAP;
					break;
				}

				$visited[$other] = true;

				// An external address is a leaf, and it is not an object: it
				// carries its own title on the row and loading it would fail.
				// Passing it through node() would have thrown that title away
				// and drawn an anonymous node, which is exactly what a graph
				// of "what is this case linked to" must not do.
				if (($edge['kind'] ?? null) === ObjectRelation::KIND_EXTERNAL) {
					$nodes[$other] = [
						'uuid' => $other,
						'title' => ($edge['targetTitle'] ?? $other),
						'register' => null,
						'schema' => null,
						'distance' => ($level + 1),
						'resolved' => true,
						'external' => true,
					];
					continue;
				}

				$nodes[$other] = $this->node(
					uuid: $other,
					object: $this->loadObject(uuid: $other),
					distance: ($level + 1)
				);
				$next[] = $other;
			}//end foreach

			$frontier = $next;
		}//end for

		// A walk that still had somewhere to go when the requested depth ran
		// out is truncated by that depth, even when the request was within the
		// administered maximum.
		//
		// "Somewhere to go" is an unvisited neighbour, not a non-empty
		// frontier. The last level's nodes almost always sit in the frontier
		// whether or not they lead anywhere, so marking on the frontier alone
		// would make `truncated` true on nearly every complete answer, and a
		// flag that is always true tells a reader nothing.
		if ($truncatedBy === null && $this->hasMore(frontier: $frontier, visited: $visited, language: $language) === true) {
			$truncatedBy = self::TRUNCATED_DEPTH;
		}

		return [
			'root' => $rootUuid,
			'depth' => $effective,
			'requestedDepth' => $requested,
			'maxDepth' => $maxDepth,
			'nodes' => array_values($nodes),
			'edges' => $edges,
			'truncated' => ($truncatedBy !== null),
			'truncatedBy' => $truncatedBy,
		];
	}//end graph()

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
		$titles = [];
		foreach ($graph['nodes'] as $node) {
			$titles[(string)$node['uuid']] = (string)($node['title'] ?? $node['uuid']);
		}

		$rows = [['from', 'fromTitle', 'relation', 'direction', 'to', 'toTitle', 'origin', 'distance']];
		foreach ($graph['edges'] as $edge) {
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

		if ($graph['truncated'] === true) {
			$rows[] = ['truncated', (string)$graph['truncatedBy'], '', '', '', '', '', ''];
		}

		return ['rows' => $rows, 'graph' => $graph];
	}//end export()

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

		// Source one: the `$ref` relations already recorded on each object.
		foreach ($uuids as $uuid) {
			$object = $this->loadObject(uuid: $uuid);
			if ($object === null) {
				continue;
			}

			$schema = $this->schemaOf(schemaId: $object->getSchema());
			foreach (($object->getRelations() ?? []) as $path => $value) {
				foreach ($this->targetsOf(value: $value) as $target) {
					if (is_string($path) === false || $target === $uuid) {
						continue;
					}

					$descriptor = $this->relationTypes->descriptorFor(
						schema: $schema,
						property: $path,
						language: $language
					);
					$row = $this->relationTypes->row(
						descriptor: $descriptor,
						direction: RelationTypeResolver::DIRECTION_OUTGOING,
						path: $path
					);

					$edges[] = [
						'from' => $uuid,
						'to' => $target,
						'direction' => RelationTypeResolver::DIRECTION_OUTGOING,
						'label' => ($row['displayLabel'] ?? null),
						'inverseLabel' => ($row['inverseLabel'] ?? null),
						'type' => ($row['type'] ?? null),
						'property' => ($row['property'] ?? null),
						'origin' => 'property',
						'kind' => ObjectRelation::KIND_OBJECT,
					];
				}
			}
		}//end foreach

		// Source two: the stored rows for the links no property can hold. One
		// query for the whole level, not one per node.
		foreach ($this->relationMapper->findTouching(uuids: $uuids) as $stored) {
			$source = (string)$stored->getSourceUuid();
			$outgoing = in_array($source, $uuids, true);
			$direction = RelationTypeResolver::DIRECTION_INCOMING;
			if ($outgoing === true) {
				$direction = RelationTypeResolver::DIRECTION_OUTGOING;
			}

			$target = (string)($stored->getTargetUuid() ?? '');
			$other = $target;
			if ($target === '') {
				$other = (string)$stored->getTargetUrl();
			}

			$edges[] = [
				'from' => $source,
				'to' => $other,
				'direction' => $direction,
				'label' => ($stored->getLabel() ?? $stored->getRelationType()),
				'inverseLabel' => $stored->getInverseLabel(),
				'type' => $stored->getRelationType(),
				'property' => null,
				'origin' => $stored->getOrigin(),
				'kind' => $stored->getKind(),
				'targetUrl' => $stored->getTargetUrl(),
				'targetTitle' => $stored->getTargetTitle(),
			];
		}//end foreach

		return $edges;
	}//end edgesFrom()

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
