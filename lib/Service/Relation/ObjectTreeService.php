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

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Rbac\HierarchyLevel;
use OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Walks one object's tree up and down, under the caller's RBAC.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The walk reads the declaration,
 * the table, the access filter and the instance cap; each is its own class.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Two directions, three bounds and a
 * fail-closed answer for each unreadable level or ancestor; each is a short method, and
 * splitting them would scatter one walk's bounds over several classes.
 *
 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
 */
class ObjectTreeService implements ObjectTreeServiceInterface {

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
	 * Decides where the walk stops.
	 *
	 * @var ObjectTreeBounds
	 */
	private readonly ObjectTreeBounds $bounds;

	/**
	 * Constructor.
	 *
	 * @param ObjectTreeSources      $sources  Reads children, the hierarchy declaration and the node cap.
	 * @param SchemaCatalogInterface $catalog  Loads objects, filters them by read access, resolves register and schema.
	 * @param RenderObject           $renderer Strips what the caller may not read from the properties a node carries.
	 * @param LoggerInterface        $logger   The logger.
	 */
	public function __construct(
		private readonly ObjectTreeSources $sources,
		private readonly SchemaCatalogInterface $catalog,
		private readonly RenderObject $renderer,
		private readonly LoggerInterface $logger,
	) {
		$this->bounds = new ObjectTreeBounds();
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
	 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
	 */
	public function declarationFor(Schema $schema): ?array {
		$hierarchy = $this->sources->hierarchies->declarationFor(schema: $schema);
		if ($hierarchy !== null) {
			return [
				'property' => $hierarchy['parent'],
				'maxDepth' => $hierarchy['maxDepth'],
				'source' => HierarchyGrantExpander::ANNOTATION,
			];
		}

		return $this->partyDeclaration(schema: $schema);
	}//end declarationFor()

	/**
	 * The parent property the party annotation declares, with its depth bound, or null.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array{property: string, maxDepth: int, source: string, nameProperty: string|null}|null The declaration.
	 */
	private function partyDeclaration(Schema $schema): array|null {
		$party = $this->sources->parties->parentOf(schema: $schema);
		if ($party === null) {
			return null;
		}

		return [
			'property' => $party['property'],
			'maxDepth' => min($party['maxDepth'], HierarchyGrantExpander::DEPTH_CEILING),
			'source' => 'x-openregister-party',
			'nameProperty' => ($party['nameProperty'] ?? null),
		];
	}//end partyDeclaration()

	/**
	 * The tree around one object.
	 *
	 * The root is always in the answer at level 0: the caller has already been
	 * allowed to read it. Ancestors carry negative levels, nearest first as -1.
	 * A node the caller may not read is left out, and so is everything reached
	 * only through it.
	 *
	 * @param ObjectTreeQuery $query The root, the direction, the depth and the fields asked for.
	 * @param bool            $_rbac Whether to apply the caller's read access to every node.
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
	public function tree(ObjectTreeQuery $query, bool $_rbac = true): array {
		$root    = $query->root;
		$walk    = $this->treeWalk(root: $root, depth: $query->depth);
		$asked   = ['fields' => $query->fields, 'rbac' => $_rbac, 'nameProperty' => ($walk['nameProperty'] ?? null)];
		$answer  = self::rootAnswer(query: $query);
		$answer['nodes'] = [$this->rootNode(root: $root, walk: $asked)];
		if ($walk === null) {
			return $answer;
		}

		return $this->withBranches(answer: self::withDeclaration(answer: $answer, walk: $walk), walk: $walk + $asked + ['root' => $root]);
	}//end tree()

	/**
	 * The tree answer with no nodes yet and no declaration applied yet.
	 *
	 * @param ObjectTreeQuery $query The root, and the direction and depth the caller asked for.
	 *
	 * @return array<string, mixed> The answer.
	 *
	 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
	 */
	private static function rootAnswer(ObjectTreeQuery $query): array {
		$root = $query->root;
		return [
			'root' => (string)$root->getUuid(),
			'register' => (int)$root->getRegister(),
			'schema' => (int)$root->getSchema(),
			'parentProperty' => null,
			'direction' => $query->direction,
			'depth' => 0,
			'requestedDepth' => $query->depth,
			'maxDepth' => 0,
			'nodes' => [],
			'truncated' => false,
			'truncatedBy' => null,
		];
	}//end rootAnswer()

	/**
	 * The root's node, at level 0, as the caller may read it.
	 *
	 * @param ObjectEntity $root The object to walk from.
	 * @param array        $walk `fields` and `rbac`.
	 *
	 * @return array<string, mixed> The node.
	 */
	private function rootNode(ObjectEntity $root, array $walk): array {
		$rootUuid = (string)$root->getUuid();
		$redacted = $this->redacted(objects: [$rootUuid => $root], walk: $walk);

		return self::node(object: ($redacted[$rootUuid] ?? $rootUuid), fields: $walk['fields'], nameProperty: ($walk['nameProperty'] ?? null));
	}//end rootNode()

	/**
	 * The answer with the declaration the walk applies.
	 *
	 * @param array<string, mixed> $answer The answer with the root node.
	 * @param array<string, mixed> $walk   The walk.
	 *
	 * @return array<string, mixed> The answer.
	 */
	private static function withDeclaration(array $answer, array $walk): array {
		$answer['parentProperty'] = $walk['property'];
		$answer['maxDepth']       = $walk['maxDepth'];
		$answer['depth']          = $walk['depth'];

		return $answer;
	}//end withDeclaration()

	/**
	 * The register, schema, parent property and depths to walk from the root; null when there is no declaration.
	 *
	 * @param ObjectEntity $root  The object to walk from.
	 * @param int|null     $depth The depth asked for; null means the declared bound.
	 *
	 * @return array{register: Register, schema: Schema, property: string, depth: int, maxDepth: int, nameProperty: string|null}|null The walk.
	 *
	 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
	 */
	private function treeWalk(ObjectEntity $root, int|null $depth): array|null {
		$context = $this->context(registerId: (int)$root->getRegister(), schemaId: (int)$root->getSchema());
		if ($context === null) {
			return null;
		}

		$declaration = $this->declarationFor(schema: $context['schema']);
		if ($declaration === null) {
			return null;
		}

		return $context + [
			'property' => $declaration['property'],
			'depth' => $this->bounds->effectiveDepth(asked: $depth, declared: $declaration['maxDepth']),
			'maxDepth' => $declaration['maxDepth'],
			'nameProperty' => ($declaration['nameProperty'] ?? null),
		];
	}//end treeWalk()

	/**
	 * The answer with the ancestors and descendants its direction asks for, and whether a walk was cut short.
	 *
	 * @param array $answer The answer with the root node.
	 * @param array $walk   The walk, with `fields`, `rbac` and the `root` entity.
	 *
	 * @return array<string, mixed> The answer.
	 *
	 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
	 */
	private function withBranches(array $answer, array $walk): array {
		$ancestors   = $this->ancestorBranch(direction: $answer['direction'], walk: $walk);
		$descendants = $this->descendantBranch(direction: $answer['direction'], walk: $walk);

		$answer['nodes'] = array_merge(
			array_reverse($ancestors['nodes']),
			$answer['nodes'],
			$descendants['nodes']
		);
		$answer['truncatedBy'] = ($descendants['truncatedBy'] ?? $ancestors['truncatedBy']);
		$answer['truncated'] = ($answer['truncatedBy'] !== null);

		return $answer;
	}//end withBranches()

	/**
	 * The ancestors when the direction asks for them; an empty branch otherwise.
	 *
	 * @param string               $direction One of the DIRECTION_ constants.
	 * @param array<string, mixed> $walk      The walk, with the `root` entity.
	 *
	 * @return array{nodes: array<int, array<string, mixed>>, truncatedBy: string|null} The branch.
	 */
	private function ancestorBranch(string $direction, array $walk): array {
		if ($direction === self::DIRECTION_ANCESTORS || $direction === self::DIRECTION_BOTH) {
			return $this->ancestors(root: $walk['root'], walk: $walk);
		}

		return ['nodes' => [], 'truncatedBy' => null];
	}//end ancestorBranch()

	/**
	 * The descendants when the direction asks for them; an empty branch otherwise.
	 *
	 * @param string               $direction One of the DIRECTION_ constants.
	 * @param array<string, mixed> $walk      The walk, with the `root` entity.
	 *
	 * @return array{nodes: array<int, array<string, mixed>>, truncatedBy: string|null} The branch.
	 */
	private function descendantBranch(string $direction, array $walk): array {
		if ($direction === self::DIRECTION_DESCENDANTS || $direction === self::DIRECTION_BOTH) {
			return $this->descendants(rootUuid: (string)$walk['root']->getUuid(), walk: $walk);
		}

		return ['nodes' => [], 'truncatedBy' => null];
	}//end descendantBranch()

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
				'register' => $this->catalog->registers()->find(id: $registerId, _rbac: false, _multitenancy: false),
				'schema' => $this->catalog->schemas()->find(id: $schemaId, _rbac: false, _multitenancy: false),
			];
		} catch (Throwable $e) {
			return null;
		}
	}//end context()

	/**
	 * The descendants below the root, level by level, within the depth and the cap.
	 *
	 * @param string               $rootUuid The root.
	 * @param array<string, mixed> $walk     The register, schema, property, depth, fields and rbac flag.
	 *
	 * @return array{nodes: array<int, array<string, mixed>>, truncatedBy: string|null} The nodes.
	 */
	private function descendants(string $rootUuid, array $walk): array {
		$walk['table']  = MagicMapperInterface::TABLE_PREFIX.(int)$walk['register']->getId().'_'.(int)$walk['schema']->getId();
		$walk['column'] = $this->sources->descender->columnFor(property: $walk['property']);
		$walk['cap']    = $this->sources->graphs->maximumNodes();

		$run = new TreeDescentRun(walk: $walk, rootUuid: $rootUuid);
		for ($level = 1; $level <= $walk['depth']; $level++) {
			$run->level = $level;
			$stop       = $this->descendLevel(run: $run);
			if ($stop !== null) {
				return ['nodes' => $run->nodes, 'truncatedBy' => $stop['truncatedBy']];
			}
		}

		return ['nodes' => $run->nodes, 'truncatedBy' => $this->truncationBelow(run: $run)];
	}//end descendants()

	/**
	 * Read one level below the frontier and add it to the run, or say why the walk ends here.
	 *
	 * @param TreeDescentRun $run The descent, at the level being read.
	 *
	 * @return array{done: bool, truncatedBy: string|null}|null Why the walk ends, or null when it goes on.
	 */
	private function descendLevel(TreeDescentRun $run): array|null {
		// What the cap still lets in, the root counted: the read below is
		// bounded by it, so a level far wider than the cap is never fetched.
		$read = $this->readableBelow(run: $run);
		$stop = $this->bounds->levelStop(read: $read);
		if ($stop !== null) {
			return $stop;
		}

		$this->withLevel(run: $run, children: $read['children']);
		return null;
	}//end descendLevel()

	/**
	 * The readable children below the frontier, bounded by what the cap still lets in.
	 *
	 * @param TreeDescentRun $run The descent.
	 *
	 * @return array{children: array<string, string>, overflow: bool}|null The children, or null when the level cannot be read.
	 */
	private function readableBelow(TreeDescentRun $run): array|null {
		return $this->readableChildren(
			read: [
				'parents' => $run->frontier,
				'budget' => ($run->walk['cap'] - count($run->nodes) - 1),
				'seen' => $run->seen,
				'walk' => $run->walk,
			]
		);
	}//end readableBelow()

	/**
	 * Add one level of children to the run; they become the next frontier.
	 *
	 * @param TreeDescentRun        $run      The descent, at the level of the children.
	 * @param array<string, string> $children Child uuid => parent uuid.
	 *
	 * @return void
	 */
	private function withLevel(TreeDescentRun $run, array $children): void {
		$objects = $this->redacted(objects: $this->load(walk: $run->walk, uuids: array_keys($children)), walk: $run->walk);
		$run->frontier = [];
		foreach ($children as $child => $parent) {
			$node           = self::node(
				object: ($objects[$child] ?? $child),
				fields: $run->walk['fields'],
				nameProperty: ($run->walk['nameProperty'] ?? null)
			);
			$node['level']  = $run->level;
			$node['parent'] = $parent;
			$run->seen[$child] = true;
			$run->frontier[]   = $child;
			$run->nodes[]      = $node;
		}
	}//end withLevel()

	/**
	 * Why a walk that reached its depth bound stopped: a probe one level further down.
	 *
	 * @param TreeDescentRun $run The descent, after its last level.
	 *
	 * @return string|null The truncation, or null when there is nothing below.
	 */
	private function truncationBelow(TreeDescentRun $run): string|null {
		$read = $this->readableBelow(run: $run);
		if ($read === null) {
			return self::TRUNCATED_UNREADABLE;
		}

		// An overflowing probe still answers from the rows it did read: one
		// readable child among them is enough to say the depth stopped the walk.
		if ($read['children'] !== []) {
			return self::TRUNCATED_DEPTH;
		}

		// An exhausted cap leaves a budget of zero: the probe then reads one row,
		// finds it, and slices it away. Unread children below a full answer mean
		// the cap stopped the walk, not that there is nothing below.
		if ($read['overflow'] === true) {
			return self::TRUNCATED_CAP;
		}

		return null;
	}//end truncationBelow()

	/**
	 * The children of a level the caller may read and has not met yet, or null when the level cannot be read.
	 *
	 * The read is bounded by the budget: one row more than the cap still lets
	 * in is asked for, and getting it means the level is wider than the cap
	 * (`overflow`); the access check then runs on the rows that fit only, so
	 * the work stays bounded too. A cycle in the data shows up here as a child
	 * already seen, and is dropped. Null covers both a level that could not be
	 * read and one whose read access could not be established: neither may be
	 * answered as "nothing below".
	 *
	 * @param array<string, mixed> $read `parents` (the level to read below), `budget` (the children the cap still
	 *                                   admits), `seen` (the uuids already in the answer) and `walk` (with the
	 *                                   magic `table` and the parent `column`).
	 *
	 * @return array{children: array<string, string>, overflow: bool}|null Child uuid => parent uuid, and whether the level is wider than the budget.
	 */
	private function readableChildren(array $read): ?array {
		$budget = max(0, $read['budget']);
		$rows   = $this->childRows(read: $read, limit: ($budget + 1));
		if ($rows === null) {
			return null;
		}

		$bounded  = $this->bounds->withinBudget(rows: $rows, read: $read);
		$readable = $this->readableOnly(walk: $read['walk'], uuids: $bounded['children']);
		if ($readable === null) {
			return null;
		}

		return ['children' => $readable, 'overflow' => $bounded['overflow']];
	}//end readableChildren()

	/**
	 * One bounded read of the children of a level; empty without parents, null when it fails.
	 *
	 * @param array<string, mixed> $read  `parents` (the level to read below) and `walk` (with `table` and `column`).
	 * @param int                  $limit The most rows to read.
	 *
	 * @return array<string, string>|null Child uuid => parent uuid.
	 */
	private function childRows(array $read, int $limit): array|null {
		$parents = $read['parents'];
		$table   = $read['walk']['table'];
		$column  = $read['walk']['column'];
		if ($parents === []) {
			return [];
		}

		try {
			$level = new HierarchyLevel(table: $table, parentColumn: $column, limit: $limit);
			return $this->sources->descender->childrenOf(level: $level, parentUuids: $parents);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ObjectTreeService] A level of the tree could not be read',
				context: ['file' => __FILE__, 'line' => __LINE__, 'table' => $table, 'column' => $column, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end childRows()

	/**
	 * The ancestors of the root, nearest first.
	 *
	 * The walk stops at the first ancestor the caller may not read: a level
	 * above a hidden one would carry the wrong level number. A node names the
	 * uuid of its own parent only when that parent is one the caller may read:
	 * the topmost node of a walk that stopped at a hidden ancestor, or at one
	 * that does not exist, carries no parent at all.
	 *
	 * @param ObjectEntity         $root The root.
	 * @param array<string, mixed> $walk The walk.
	 *
	 * @return array{nodes: array<int, array<string, mixed>>, truncatedBy: string|null} The nodes, nearest first.
	 */
	private function ancestors(ObjectEntity $root, array $walk): array {
		$chain = $this->ancestorChain(root: $root, walk: $walk);
		$shown = $this->redacted(objects: $chain['chain'], walk: $walk);
		$uuids = array_keys($chain['chain']);
		$nodes = [];
		foreach ($uuids as $index => $uuid) {
			$node           = self::node(object: ($shown[$uuid] ?? $uuid), fields: $walk['fields'], nameProperty: ($walk['nameProperty'] ?? null));
			$node['level']  = -($index + 1);
			$node['parent'] = ($uuids[($index + 1)] ?? $chain['topParent']);
			$nodes[]        = $node;
		}

		return ['nodes' => $nodes, 'truncatedBy' => $chain['truncatedBy']];
	}//end ancestors()

	/**
	 * The readable ancestors of the root, nearest first, and why the climb stopped.
	 *
	 * One step past the depth bound: it only finds out whether there is
	 * more above, and which readable uuid the topmost node then names.
	 *
	 * @param ObjectEntity         $root The root.
	 * @param array<string, mixed> $walk The walk.
	 *
	 * @return array{chain: array<string, ObjectEntity>, truncatedBy: string|null, topParent: string|null} The climb.
	 */
	private function ancestorChain(ObjectEntity $root, array $walk): array {
		$chain    = [];
		$rootUuid = (string)$root->getUuid();
		$current  = $root;
		for ($level = 1; $level <= ($walk['depth'] + 1); $level++) {
			$parentUuid = $this->bounds->reference(value: ($current->getObject()[$walk['property']] ?? null));
			$step       = $this->parentStep(parentUuid: $this->unvisited(uuid: $parentUuid, visited: $chain + [$rootUuid => $root]), walk: $walk);
			if ($step['object'] === null || $level > $walk['depth']) {
				return $this->bounds->climbEnd(chain: $chain, step: $step);
			}

			$current = $step['object'];
			$chain[(string)$current->getUuid()] = $current;
		}

		return ['chain' => $chain, 'truncatedBy' => null, 'topParent' => null];
	}//end ancestorChain()

	/**
	 * A parent uuid the climb has not visited yet, or null when it has (a cycle) or there is none.
	 *
	 * @param string|null                 $uuid    The parent uuid.
	 * @param array<string, ObjectEntity> $visited The root and the ancestors so far, by uuid.
	 *
	 * @return string|null The parent uuid to step to.
	 */
	private function unvisited(?string $uuid, array $visited): ?string {
		if ($uuid !== null && isset($visited[$uuid]) === true) {
			return null;
		}

		return $uuid;
	}//end unvisited()

	/**
	 * The parent of one object when it exists and is readable.
	 *
	 * Tells "there is none" (or it is a cycle) and "the caller may not read it"
	 * from "read access could not be established": the last one is an
	 * incomplete answer, and the tree says so.
	 *
	 * @param string|null          $parentUuid The parent reference, null when there is none or it is already in the answer.
	 * @param array<string, mixed> $walk       The walk.
	 *
	 * @return array{object: ObjectEntity|null, truncatedBy: string|null} The parent, or why there is none to show.
	 */
	private function parentStep(?string $parentUuid, array $walk): array {
		if ($parentUuid === null) {
			return ['object' => null, 'truncatedBy' => null];
		}

		$readable = $this->readableOnly(walk: $walk, uuids: [$parentUuid => '']);
		if ($readable === null) {
			return ['object' => null, 'truncatedBy' => self::TRUNCATED_UNREADABLE];
		}

		if ($readable === []) {
			return ['object' => null, 'truncatedBy' => null];
		}

		return ['object' => ($this->load(walk: $walk, uuids: [$parentUuid])[$parentUuid] ?? null), 'truncatedBy' => null];
	}//end parentStep()

	/**
	 * The entries of a uuid-keyed map the caller may read.
	 *
	 * @param array<string, mixed>  $walk  The walk.
	 * @param array<string, string> $uuids Uuid => anything.
	 *
	 * @return array<string, string>|null The readable entries, in their order; null when read access could not be established.
	 */
	private function readableOnly(array $walk, array $uuids): ?array {
		if ($uuids === [] || $walk['rbac'] === false) {
			return $uuids;
		}

		$readable = $this->catalog->tables()->readableUuidsOrNull(
			registerId: (int)$walk['register']->getId(),
			schemaId: (int)$walk['schema']->getId(),
			uuids: array_map('strval', array_keys($uuids))
		);
		if ($readable === null) {
			return null;
		}

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
		return $this->catalog->tables()->findObjectsByUuidsInRegisterSchema(
			register: $walk['register'],
			schema: $walk['schema'],
			uuids: array_map('strval', $uuids)
		);
	}//end load()

	/**
	 * The objects as the caller may read them: write-only properties, properties
	 * the caller's group may not read and encrypted values handled the way every
	 * other read of an object does.
	 *
	 * Only a request that names `fields` has properties to carry, and only an
	 * object without a name of its own is called by the declared party name
	 * property, so only then is anything done: a node's name never shows a
	 * property the caller may not read. The entities are copied first: the
	 * originals are what the walk reads the parent property from, and are
	 * never written back.
	 *
	 * @param array<string, ObjectEntity> $objects Uuid => object, as the mapper loaded them.
	 * @param array<string, mixed>        $walk    The walk: `fields` (the properties the request names),
	 *                                             `nameProperty` (the declared party name property, if any)
	 *                                             and `rbac` (whether the read is the caller's own, so
	 *                                             property read access applies).
	 *
	 * @return array<string, ObjectEntity> Uuid => the redacted copy.
	 */
	private function redacted(array $objects, array $walk): array {
		if ($objects === []) {
			return $objects;
		}

		$namedByProperty = (($walk['nameProperty'] ?? null) !== null) && self::anyUnnamed(objects: $objects);
		if ($walk['fields'] === [] && $namedByProperty === false) {
			return $objects;
		}

		$copies = array_map(static fn (ObjectEntity $object): ObjectEntity => clone $object, $objects);
		$this->renderer->redactWriteOnlyFromRows(rows: $copies, _rbac: $walk['rbac']);

		return $copies;
	}//end redacted()

	/**
	 * Whether any of the objects has no name of its own, so its node is called by the declared party name property.
	 *
	 * @param array<string, ObjectEntity> $objects Uuid => object.
	 *
	 * @return bool True when at least one object has no own name.
	 *
	 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md#requirement-an-objects-tree-along-its-declared-parent-property-can-be-read-req-otr-001
	 */
	private static function anyUnnamed(array $objects): bool {
		foreach ($objects as $object) {
			if (self::ownName(object: $object) === null) {
				return true;
			}
		}

		return false;
	}//end anyUnnamed()

	/**
	 * The object's own name, null when it has none (empty, or only its uuid).
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return string|null The own name.
	 */
	private static function ownName(ObjectEntity $object): ?string {
		$own = $object->getName();
		if (is_string($own) === true && $own !== '' && $own !== (string)$object->getUuid()) {
			return $own;
		}

		return null;
	}//end ownName()

	/**
	 * One node of the answer, placed as the root (level 0, no parent).
	 *
	 * A node below or above the root has its `level` (negative above, positive
	 * below) and `parent` uuid set by the walk that reached it.
	 *
	 * @param ObjectEntity|string $object       The object as the caller may read it, or only its uuid when it did not load.
	 * @param string[]            $fields       Properties to copy.
	 * @param string|null         $nameProperty The property the party declaration names its parties by, if any.
	 *
	 * @return array<string, mixed> The node.
	 *
	 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md#requirement-an-objects-tree-along-its-declared-parent-property-can-be-read-req-otr-001
	 */
	private static function node(ObjectEntity|string $object, array $fields, ?string $nameProperty = null): array {
		$loaded = null;
		$uuid   = $object;
		if ($object instanceof ObjectEntity) {
			$loaded = $object;
			$uuid   = (string)$object->getUuid();
		}

		return [
			'uuid' => $uuid,
			'name' => self::nameOf(loaded: $loaded, uuid: $uuid, nameProperty: $nameProperty),
			'level' => 0,
			'parent' => null,
			'properties' => self::propertiesOf(data: ($loaded?->getObject() ?? []), fields: $fields),
		];
	}//end node()

	/**
	 * A node's name: the object's own, else the value of the declared party name property, else its uuid.
	 *
	 * An object whose schema names no name field is called by its uuid; for a
	 * party, the declaration says which property holds its name
	 * (`x-openregister-party.nameProperty`), so a tree of organisations shows
	 * their names rather than a column of uuids.
	 *
	 * @param ObjectEntity|null $loaded       The object, null when it did not load.
	 * @param string            $uuid         Its uuid.
	 * @param string|null       $nameProperty The declared party name property.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md#requirement-an-objects-tree-along-its-declared-parent-property-can-be-read-req-otr-001
	 */
	private static function nameOf(?ObjectEntity $loaded, string $uuid, ?string $nameProperty): string {
		$own = null;
		if ($loaded !== null) {
			$own = self::ownName(object: $loaded);
		}

		if ($own !== null) {
			return $own;
		}

		$declared = null;
		if ($loaded !== null && $nameProperty !== null) {
			$declared = ($loaded->getObject()[$nameProperty] ?? null);
		}

		if (is_string($declared) === true && trim($declared) !== '') {
			return $declared;
		}

		return $uuid;
	}//end nameOf()

	/**
	 * The properties a node carries: each field asked for, null where the object has none.
	 *
	 * @param array<string, mixed> $data   The object's data as the caller may read it.
	 * @param string[]             $fields Properties to copy.
	 *
	 * @return array<string, mixed> The properties.
	 */
	private static function propertiesOf(array $data, array $fields): array {
		$properties = [];
		foreach ($fields as $field) {
			$properties[$field] = ($data[$field] ?? null);
		}

		return $properties;
	}//end propertiesOf()

}//end class
