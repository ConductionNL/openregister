<?php

/**
 * A grant on a parent reaches its children (ledger row Q13.23).
 *
 * Somebody is invited to an object and cannot open the objects hanging under
 * it, so those get invited separately and the two invitations drift: the child
 * stays open to a person taken off the parent a year earlier. The register's
 * note on the consuming app says it plainly: `deelzaak|parentCase|subCase` over
 * dossiq's own guard returned 0, so nothing anywhere read the parent.
 *
 * WHY THE EXPANSION LIVES HERE, ON THE GRANT SET, AND NOT AT EACH DECISION.
 * {@see ObjectGrantResolver} is the ONE funnel every path takes: the per-object
 * check calls `isGranted()`, the two list emitters call `quotedGrantedUuids()`,
 * and both read the same map. Expanding the map itself is therefore the only
 * placement where a list and an object read CANNOT disagree — which is design
 * D-3's whole worry, and a worry worth having: the two are compiled by
 * different code into different languages, and a rule added to one of them has
 * gone missing from the other before.
 *
 * WHY IT IS A BOUNDED DESCENT AND NOT ONE RECURSIVE CTE. The spec's wording is
 * "a single recursive query", and this is `maxDepth` queries per hierarchical
 * schema per request rather than one. The intent behind that wording is
 * openregister ADR-009: NOT A WALK PER OBJECT IN A LIST. A descent by LEVEL
 * satisfies that exactly, because its cost is the depth of the tree and not the
 * size of the list, and a grant set is the handful of objects one person was
 * invited to. What it buys is portability: `WITH RECURSIVE` differs across the
 * four backends this app supports and cannot be exercised on all of them from
 * here, and an authorization resolver that is subtly wrong on one backend is a
 * disclosure on that backend. The honest trade is written down rather than
 * hidden behind the word "recursive".
 *
 * WHAT IT REFUSES TO DO. It never widens a verb (D-2): a descendant inherits
 * the ancestor's bitmask and nothing more, narrowed further when the schema
 * declares `inheritedVerbs`. It never overwrites a DIRECT grant: a grant
 * written on the object itself wins, because the existing most-specific-wins
 * resolution is what the spec keeps. A cycle and an over-deep chain both stop
 * with no grant added and a logged refusal (D-4), which is fail-closed: the
 * direction that hides an object is recoverable by asking, and the other one is
 * not.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Support\PermissionBit;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Expands a grant set down every declared object hierarchy.
 *
 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
 */
class HierarchyGrantExpander {

	/**
	 * The schema annotation that declares the parent edge.
	 *
	 * @var string
	 */
	public const ANNOTATION = 'x-openregister-hierarchy';

	/**
	 * The depth used when a declaration names none.
	 *
	 * @var integer
	 */
	public const DEFAULT_MAX_DEPTH = 5;

	/**
	 * The deepest a declaration may ask to go.
	 *
	 * A cap on the cap. `maxDepth` is authored per schema and the descent runs
	 * on every request that holds a grant, so an author who types 500 would
	 * otherwise buy five hundred queries per request for a tree nobody has.
	 *
	 * @var integer
	 */
	public const DEPTH_CEILING = 20;

	/**
	 * How many descendants one expansion may collect before it stops.
	 *
	 * Reached only by a tree far larger than the grant model is for. Stopping
	 * is fail-closed in the same direction as everything else here: the
	 * descendants past the bound are NOT granted, and the refusal is logged
	 * with the count so it can be read rather than guessed at.
	 *
	 * @var integer
	 */
	public const MAX_DESCENDANTS = 10000;

	/**
	 * Constructor.
	 *
	 * @param HierarchyDescender $descender Reads one level of children.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly HierarchyDescender $descender,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The hierarchy a schema declares, normalised, or null when it declares none.
	 *
	 * TWO SPELLINGS OF THE PARENT KEY ARE ACCEPTED, and that is deliberate
	 * rather than sloppy. This spec writes `parent`; dossiq shipped
	 * `parentField` in its own change before this one existed, and an
	 * annotation whose key is not the one the reader looks for is DROPPED IN
	 * SILENCE: dossiq would declare an edge, this resolver would report no
	 * inheritance, and nothing anywhere would say the declaration was never
	 * read. `parent` is canonical and wins where both are present.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array{parent: string, maxDepth: int, verbs: string[]}|null The declaration.
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function declarationFor(Schema $schema): ?array {
		$configuration = ($schema->getConfiguration() ?? []);
		$block = ($configuration[self::ANNOTATION] ?? null);
		if (is_array($block) === false) {
			return null;
		}

		$parent = trim((string)($block['parent'] ?? ($block['parentField'] ?? '')));
		if ($parent === '') {
			return null;
		}

		$declared = (int)($block['maxDepth'] ?? self::DEFAULT_MAX_DEPTH);
		if ($declared < 1) {
			$declared = self::DEFAULT_MAX_DEPTH;
		}

		$verbs = [];
		$declaredVerbs = ($block['inheritedVerbs'] ?? null);
		if (is_array($declaredVerbs) === true) {
			foreach ($declaredVerbs as $verb) {
				$verb = trim((string)$verb);
				if ($verb !== '') {
					$verbs[] = $verb;
				}
			}
		}

		return [
			'parent' => $parent,
			'maxDepth' => min($declared, self::DEPTH_CEILING),
			'verbs' => $verbs,
		];
	}//end declarationFor()

	/**
	 * Expand a grant set with every descendant it reaches.
	 *
	 * @param array<string, int> $granted Object UUID => core permission bitmask.
	 * @param array<string, int>|null $seeds The grants that TRAVEL; null means all of them.
	 *
	 * @return array{granted: array<string, int>, sources: array<string, string>}
	 *         The expanded map, and where each inherited entry came from.
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md
	 */
	public function expand(array $granted, ?array $seeds = null): array {
		if (empty($granted) === true) {
			return ['granted' => $granted, 'sources' => []];
		}

		// A grant marked as not inheritable still admits the object it was
		// written on, and simply does not seed the descent (ledger row 13.41).
		// `null` means every grant travels, which is what every caller written
		// before the flag existed meant.
		$seeds = ($seeds ?? $granted);
		if (empty($seeds) === true) {
			return ['granted' => $granted, 'sources' => []];
		}

		$sources = [];

		try {
			$hierarchies = $this->descender->hierarchicalTables();
		} catch (Throwable $e) {
			// No expansion rather than no grants: the caller's DIRECT grants
			// are unaffected by this failing, and withdrawing them would lock
			// people out of objects they were plainly invited to.
			$this->logger->error(
				message: '[HierarchyGrantExpander] Could not read the hierarchical schemas; no grant is inherited this request',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'exception' => $e->getMessage(),
				]
			);
			return ['granted' => $granted, 'sources' => []];
		}

		foreach ($hierarchies as $hierarchy) {
			$this->expandOne(
				hierarchy: $hierarchy,
				granted: $granted,
				sources: $sources,
				seeds: $seeds
			);
		}

		return ['granted' => $granted, 'sources' => $sources];
	}//end expand()

	/**
	 * Descend one schema's hierarchy, adding what it reaches.
	 *
	 * @param array{table: string, parentColumn: string, maxDepth: int, verbs: string[], schemaId: int} $hierarchy One declaration, resolved.
	 * @param array<string, int> $granted The grant map, modified in place.
	 * @param array<string, string> $sources The provenance map, modified in place.
	 * @param array<string, int> $seeds The grants that travel.
	 *
	 * @return void
	 */
	private function expandOne(array $hierarchy, array &$granted, array &$sources, array $seeds): void {
		// The frontier starts at every direct grant THAT TRAVELS. A descendant
		// reached on a later level is expanded too, which is what makes the
		// grandchild work, but only ever as the descendant of the root it came
		// from.
		$frontier = [];
		foreach ($seeds as $uuid => $mask) {
			$frontier[$uuid] = ['mask' => $mask, 'root' => $uuid];
		}

		// Everything already granted is `seen`, travelling or not: a
		// non-inheritable grant on an object still means the object is decided,
		// and re-deciding it from an ancestor would put the very grant the flag
		// was written to stop straight back.
		$seen = $frontier;
		foreach (array_keys($granted) as $uuid) {
			if (isset($seen[$uuid]) === false) {
				$seen[$uuid] = ['mask' => $granted[$uuid], 'root' => $uuid];
			}
		}

		$added = 0;

		for ($depth = 0; $depth < $hierarchy['maxDepth']; $depth++) {
			if (empty($frontier) === true) {
				return;
			}

			$children = $this->childrenOrNull(hierarchy: $hierarchy, frontier: $frontier, depth: $depth);
			if ($children === null) {
				return;
			}

			$next = $this->absorbLevel(
				hierarchy: $hierarchy,
				children: $children,
				frontier: $frontier,
				seen: $seen,
				granted: $granted,
				sources: $sources,
				added: $added
			);

			// The descendant bound was passed. Nothing below this point is
			// inherited, and stopping here is the whole point of the bound.
			if ($next === null) {
				return;
			}

			$frontier = $next;
		}//end for
	}//end expandOne()

	/**
	 * One level of children, or null when the level could not be read.
	 *
	 * A level that cannot be read stops the descent rather than skipping to the
	 * next one: the objects below it would otherwise be reached from nowhere.
	 *
	 * @param array{table: string, parentColumn: string, maxDepth: int, verbs: string[], schemaId: int} $hierarchy One declaration, resolved.
	 * @param array<string, array{mask: int, root: string}> $frontier The current frontier.
	 * @param integer $depth Which level this is, for the log.
	 *
	 * @return array<string, string>|null Child uuid => parent uuid, or null.
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	private function childrenOrNull(array $hierarchy, array $frontier, int $depth): ?array {
		try {
			return $this->descender->childrenOf(
				table: $hierarchy['table'],
				parentColumn: $hierarchy['parentColumn'],
				parentUuids: array_keys($frontier)
			);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[HierarchyGrantExpander] A level of the hierarchy could not be read; the descent stops here',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'schemaId' => $hierarchy['schemaId'],
					'depth' => $depth,
					'exception' => $e->getMessage(),
				]
			);
			return null;
		}
	}//end childrenOrNull()

	/**
	 * Absorb one level of children, returning the next frontier.
	 *
	 * @param array{table: string, parentColumn: string, maxDepth: int, verbs: string[], schemaId: int} $hierarchy One declaration, resolved.
	 * @param array<string, string> $children Child uuid => parent uuid.
	 * @param array<string, array{mask: int, root: string}> $frontier The current frontier.
	 * @param array<string, array{mask: int, root: string}> $seen Everything decided so far, modified in place.
	 * @param array<string, int> $granted The grant map, modified in place.
	 * @param array<string, string> $sources The provenance map, modified in place.
	 * @param integer $added How many descendants the descent has taken, modified in place.
	 *
	 * @return array<string, array{mask: int, root: string}>|null The next frontier, or null when the bound was passed.
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	private function absorbLevel(
		array $hierarchy,
		array $children,
		array $frontier,
		array &$seen,
		array &$granted,
		array &$sources,
		int &$added
	): ?array {
		$next = [];

		foreach ($children as $childUuid => $parentUuid) {
			$childUuid = (string)$childUuid;
			$parentUuid = (string)$parentUuid;

			if (isset($seen[$childUuid]) === true) {
				$this->logRevisit(hierarchy: $hierarchy, childUuid: $childUuid);
				continue;
			}

			$added++;
			if ($added > self::MAX_DESCENDANTS) {
				$this->logger->warning(
					message: '[HierarchyGrantExpander] The descent passed its descendant bound; nothing below this point is inherited',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'schemaId' => $hierarchy['schemaId'],
						'bound' => self::MAX_DESCENDANTS,
					]
				);
				return null;
			}

			$from = ($frontier[$parentUuid] ?? null);
			if ($from === null) {
				continue;
			}

			$mask = $this->narrow(mask: $from['mask'], verbs: $hierarchy['verbs']);
			$seen[$childUuid] = ['mask' => $mask, 'root' => $from['root']];
			$next[$childUuid] = ['mask' => $mask, 'root' => $from['root']];

			// 🔴 A DIRECT GRANT IS NEVER OVERWRITTEN. The inherited one is the
			// weaker claim by construction, and the spec keeps the existing
			// most-specific-wins resolution: a person given `update` on the
			// child keeps it even where the root grants only `read`.
			//
			// A mask of 0 is skipped for the mirror reason: the schema narrowed
			// every verb away, and recording a grant of nothing would put the
			// object in the list and refuse every action on it, which reads as
			// a broken object.
			if (array_key_exists($childUuid, $granted) === true || $mask === 0) {
				continue;
			}

			$granted[$childUuid] = $mask;
			$sources[$childUuid] = $from['root'];
		}//end foreach

		return $next;
	}//end absorbLevel()

	/**
	 * Note that the parent chain returned to an object already resolved.
	 *
	 * A cycle, or a diamond. Either way this object has already been decided
	 * and re-deciding it is how a walk never ends. A CYCLE ADDS NOTHING: the
	 * object keeps whatever grant it already had, which for an object nobody
	 * was invited to is none at all.
	 *
	 * @param array{table: string, parentColumn: string, maxDepth: int, verbs: string[], schemaId: int} $hierarchy One declaration, resolved.
	 * @param string $childUuid The object reached a second time.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	private function logRevisit(array $hierarchy, string $childUuid): void {
		$this->logger->info(
			message: '[HierarchyGrantExpander] The parent chain returns to an object already resolved; that branch grants nothing further',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'schemaId' => $hierarchy['schemaId'],
				'object' => $childUuid,
				'reason' => 'cycle-or-revisit',
			]
		);
	}//end logRevisit()

	/**
	 * The ancestor's bitmask, narrowed by what the schema lets travel down.
	 *
	 * The verb NEVER GROWS (D-2), which costs nothing to enforce here because
	 * the mask is copied rather than recomputed. What this adds is the
	 * narrowing: a schema that declares `inheritedVerbs: ["read"]` sends read
	 * down a chain whose root also carries update, and the update stays at the
	 * root. A schema declaring none sends the ancestor's mask unchanged.
	 *
	 * @param integer $mask The ancestor's bitmask.
	 * @param string[] $verbs The verbs the schema lets travel down, or [].
	 *
	 * @return integer The narrowed bitmask.
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function narrow(int $mask, array $verbs): int {
		if (empty($verbs) === true) {
			return $mask;
		}

		$allowed = 0;
		foreach ($verbs as $verb) {
			$bit = PermissionBit::forAction(action: $verb);
			if ($bit !== null) {
				$allowed |= $bit;
			}
		}

		return ($mask & $allowed);
	}//end narrow()
}//end class
