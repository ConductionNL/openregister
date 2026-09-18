<?php

/**
 * A grant on a parent reaches its children, and never more than it carried.
 *
 * Ledger row Q13.23. Every test here is a way this could be wrong while still
 * looking like inheritance works, and each one of them is a disclosure or a
 * lock-out rather than a cosmetic defect:
 *
 *  - the verb GROWING on the way down, which hands everybody who may read a
 *    root the right to edit everything under it. This is the half of the
 *    competitor's measurement that is easiest to drop, and the reason it is
 *    written twice: "read on the root read the grandchild AND WAS REFUSED A
 *    WRITE";
 *  - a cycle resolving, which is either a request that never returns or a
 *    grant assembled out of a loop nobody authored;
 *  - the depth cap not being enforced, so a chain of two hundred is walked on
 *    every request that holds a grant;
 *  - an inherited grant OVERWRITING a direct one, which silently narrows
 *    somebody's real invitation to whatever their ancestor carries;
 *  - the provenance going missing, which leaves an administrator looking at
 *    access they cannot explain and cannot remove.
 *
 * The descender is doubled, on purpose. What is under test is the DECISION —
 * the verb rule, the cycle rule, the cap and the provenance — and running it
 * against a live magic table would test the query instead and pass on a broken
 * verb rule as readily as on a correct one.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
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

namespace Unit\Service\Rbac;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rbac\HierarchyDescender;
use OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander;
use OCP\Constants;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the inheritance rule, the cap, the cycle and the provenance.
 */
class HierarchyGrantExpanderTest extends TestCase {

	private LoggerInterface $logger;

	/**
	 * Set up the logger double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A descender double answering from a parent map.
	 *
	 * `onlyMethods` rather than `addMethods`, deliberately: a double that can
	 * invent a method the real class lacks passes while production 500s on the
	 * call, and this suite would be green over an expander calling a descender
	 * API that does not exist.
	 *
	 * @param array<string, string> $childToParent Child UUID => parent UUID.
	 * @param array<int, array<string, mixed>> $tables The declarations to answer.
	 *
	 * @return HierarchyDescender The double.
	 */
	private function descender(array $childToParent, array $tables): HierarchyDescender {
		$double = $this->getMockBuilder(HierarchyDescender::class)
			->disableOriginalConstructor()
			->onlyMethods(['hierarchicalTables', 'childrenOf'])
			->getMock();

		$double->method('hierarchicalTables')->willReturn($tables);
		$double->method('childrenOf')->willReturnCallback(
			static function (string $table, string $parentColumn, array $parentUuids) use ($childToParent): array {
				$children = [];
				foreach ($childToParent as $child => $parent) {
					if (in_array($parent, $parentUuids, true) === true) {
						$children[$child] = $parent;
					}
				}

				return $children;
			}
		);

		return $double;
	}//end descender()

	/**
	 * One declaration, as the descender resolves it.
	 *
	 * @param integer $maxDepth The depth cap.
	 * @param string[] $verbs The verbs that travel down.
	 *
	 * @return array<int, array<string, mixed>> The declaration list.
	 */
	private function table(int $maxDepth = 5, array $verbs = []): array {
		return [
			[
				'table' => 'openregister_table_1_2',
				'parentColumn' => 'parent_case',
				'maxDepth' => $maxDepth,
				'verbs' => $verbs,
				'schemaId' => 2,
			],
		];
	}//end table()

	/**
	 * A schema carrying one configuration block.
	 *
	 * @param array<string, mixed>|null $hierarchy The annotation, or null.
	 *
	 * @return Schema The schema.
	 */
	private function schema(?array $hierarchy): Schema {
		$schema = $this->getMockBuilder(Schema::class)
			->disableOriginalConstructor()
			->onlyMethods(['getConfiguration'])
			->getMock();
		$schema->method('getConfiguration')->willReturn(
			$hierarchy === null ? [] : [HierarchyGrantExpander::ANNOTATION => $hierarchy]
		);

		return $schema;
	}//end schema()

	/**
	 * Read on the root reaches the child and the grandchild.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function testAGrantOnTheRootReachesTheGrandchild(): void {
		$expander = new HierarchyGrantExpander(
			descender: $this->descender(
				['child' => 'root', 'grandchild' => 'child'],
				$this->table()
			),
			logger: $this->logger
		);

		$result = $expander->expand(['root' => Constants::PERMISSION_READ]);

		$this->assertArrayHasKey('child', $result['granted']);
		$this->assertArrayHasKey('grandchild', $result['granted']);
		$this->assertSame(Constants::PERMISSION_READ, $result['granted']['grandchild']);
	}//end testAGrantOnTheRootReachesTheGrandchild()

	/**
	 * 🔴 The verb does not grow on the way down.
	 *
	 * The assertion the whole row turns on. Read on the root is read on the
	 * child; it is not update, and nothing below the root may carry a bit the
	 * root's own grant does not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function testTheVerbDoesNotGrowOnTheWayDown(): void {
		$expander = new HierarchyGrantExpander(
			descender: $this->descender(
				['child' => 'root', 'grandchild' => 'child'],
				$this->table()
			),
			logger: $this->logger
		);

		$result = $expander->expand(['root' => Constants::PERMISSION_READ]);

		foreach (['child', 'grandchild'] as $uuid) {
			$mask = $result['granted'][$uuid];
			$this->assertSame(
				Constants::PERMISSION_READ,
				($mask & Constants::PERMISSION_READ),
				'read travels down'
			);
			$this->assertSame(0, ($mask & Constants::PERMISSION_UPDATE), 'update does not');
			$this->assertSame(0, ($mask & Constants::PERMISSION_DELETE), 'nor does delete');
		}
	}//end testTheVerbDoesNotGrowOnTheWayDown()

	/**
	 * A schema may narrow further than the ancestor's grant.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function testInheritedVerbsNarrowTheMask(): void {
		$expander = new HierarchyGrantExpander(
			descender: $this->descender(
				['child' => 'root'],
				$this->table(verbs: ['read'])
			),
			logger: $this->logger
		);

		$result = $expander->expand(
			['root' => (Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE)]
		);

		$this->assertSame(Constants::PERMISSION_READ, $result['granted']['child']);
		$this->assertSame(
			(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE),
			$result['granted']['root'],
			'the root keeps everything it was actually given'
		);
	}//end testInheritedVerbsNarrowTheMask()

	/**
	 * 🔴 A direct grant on a descendant is never overwritten.
	 *
	 * The existing most-specific-wins resolution is what the spec keeps. An
	 * expansion that wrote over it would silently narrow a real invitation to
	 * whatever the ancestor happens to carry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function testADirectGrantOnAChildSurvives(): void {
		$expander = new HierarchyGrantExpander(
			descender: $this->descender(['child' => 'root'], $this->table(verbs: ['read'])),
			logger: $this->logger
		);

		$result = $expander->expand(
			[
				'root' => Constants::PERMISSION_READ,
				'child' => (Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE),
			]
		);

		$this->assertSame(
			(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE),
			$result['granted']['child']
		);
		$this->assertArrayNotHasKey(
			'child',
			$result['sources'],
			'a direct grant is not reported as inherited'
		);
	}//end testADirectGrantOnAChildSurvives()

	/**
	 * A cycle terminates and grants nothing extra.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function testACycleTerminatesAndGrantsNothing(): void {
		$expander = new HierarchyGrantExpander(
			descender: $this->descender(
				['a' => 'b', 'b' => 'a'],
				$this->table()
			),
			logger: $this->logger
		);

		$result = $expander->expand(['outsider' => Constants::PERMISSION_READ]);

		$this->assertSame(['outsider' => Constants::PERMISSION_READ], $result['granted']);
		$this->assertSame([], $result['sources']);
	}//end testACycleTerminatesAndGrantsNothing()

	/**
	 * A cycle reached FROM a grant stops at the loop.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function testACycleUnderAGrantStopsAtTheLoop(): void {
		$expander = new HierarchyGrantExpander(
			descender: $this->descender(
				['a' => 'root', 'b' => 'a', 'a2' => 'b'],
				$this->table()
			),
			logger: $this->logger
		);

		// `a2` is a second object whose parent chain rejoins; the walk must
		// still be finite and must not revisit.
		$result = $expander->expand(['root' => Constants::PERMISSION_READ]);

		$this->assertArrayHasKey('a', $result['granted']);
		$this->assertArrayHasKey('b', $result['granted']);
		$this->assertArrayHasKey('a2', $result['granted']);
	}//end testACycleUnderAGrantStopsAtTheLoop()

	/**
	 * 🔴 A chain longer than the cap stops at the cap.
	 *
	 * Asserted as a REFUSAL, not skipped. A cap that was not enforced would
	 * make the sixth object readable and this test would be the only thing
	 * that could tell.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function testTheDepthCapIsEnforced(): void {
		$chain = [
			'l1' => 'root',
			'l2' => 'l1',
			'l3' => 'l2',
			'l4' => 'l3',
			'l5' => 'l4',
			'l6' => 'l5',
		];

		$expander = new HierarchyGrantExpander(
			descender: $this->descender($chain, $this->table(maxDepth: 3)),
			logger: $this->logger
		);

		$result = $expander->expand(['root' => Constants::PERMISSION_READ]);

		$this->assertArrayHasKey('l3', $result['granted'], 'three levels down is inside the cap');
		$this->assertArrayNotHasKey('l4', $result['granted'], 'four is not');
		$this->assertArrayNotHasKey('l6', $result['granted']);
	}//end testTheDepthCapIsEnforced()

	/**
	 * The provenance names the ancestor the grant was written on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function testTheProvenanceNamesTheRoot(): void {
		$expander = new HierarchyGrantExpander(
			descender: $this->descender(
				['child' => 'root', 'grandchild' => 'child'],
				$this->table()
			),
			logger: $this->logger
		);

		$result = $expander->expand(['root' => Constants::PERMISSION_READ]);

		$this->assertSame('root', $result['sources']['child']);
		$this->assertSame(
			'root',
			$result['sources']['grandchild'],
			'the grandchild names the object the grant is ON, not its own parent'
		);
	}//end testTheProvenanceNamesTheRoot()

	/**
	 * An object in another tree is never reached.
	 *
	 * The control. Without it an expander that granted everything it found
	 * would satisfy every test above.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function testAnotherTreeIsNotReached(): void {
		$expander = new HierarchyGrantExpander(
			descender: $this->descender(
				['child' => 'root', 'stranger' => 'other-root'],
				$this->table()
			),
			logger: $this->logger
		);

		$result = $expander->expand(['root' => Constants::PERMISSION_READ]);

		$this->assertArrayHasKey('child', $result['granted']);
		$this->assertArrayNotHasKey('stranger', $result['granted']);
		$this->assertArrayNotHasKey('other-root', $result['granted']);
	}//end testAnotherTreeIsNotReached()

	/**
	 * A caller with no grant at all inherits nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function testNoGrantInheritsNothing(): void {
		$expander = new HierarchyGrantExpander(
			descender: $this->descender(['child' => 'root'], $this->table()),
			logger: $this->logger
		);

		$this->assertSame(
			['granted' => [], 'sources' => []],
			$expander->expand([])
		);
	}//end testNoGrantInheritsNothing()

	/**
	 * A schema narrowing every verb away grants no child rather than an empty one.
	 *
	 * A recorded grant of zero would put the object in the list and refuse
	 * every action on it, which reads as a broken object rather than as one
	 * nobody was given.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function testAFullyNarrowedInheritanceGrantsNothing(): void {
		$expander = new HierarchyGrantExpander(
			descender: $this->descender(['child' => 'root'], $this->table(verbs: ['update'])),
			logger: $this->logger
		);

		$result = $expander->expand(['root' => Constants::PERMISSION_READ]);

		$this->assertArrayNotHasKey('child', $result['granted']);
		$this->assertArrayNotHasKey('child', $result['sources']);
	}//end testAFullyNarrowedInheritanceGrantsNothing()

	/**
	 * Both spellings of the parent key are read.
	 *
	 * `parent` is this spec's word and `parentField` is what the consuming app
	 * shipped first. An annotation whose key is not the one the reader looks
	 * for is DROPPED IN SILENCE, so the app would declare an edge, this
	 * resolver would report no inheritance, and nothing would say why.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function testBothSpellingsOfTheParentKeyAreRead(): void {
		$expander = new HierarchyGrantExpander(
			descender: $this->descender([], []),
			logger: $this->logger
		);

		$canonical = $expander->declarationFor($this->schema(['parent' => 'parentCase']));
		$alias = $expander->declarationFor($this->schema(['parentField' => 'parentCase']));

		$this->assertSame('parentCase', $canonical['parent']);
		$this->assertSame('parentCase', $alias['parent']);

		$both = $expander->declarationFor(
			$this->schema(['parent' => 'realParent', 'parentField' => 'oldParent'])
		);
		$this->assertSame('realParent', $both['parent'], 'the canonical key wins');
	}//end testBothSpellingsOfTheParentKeyAreRead()

	/**
	 * A schema with no annotation, or an empty parent, declares nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function testAnUndeclaredHierarchyIsNull(): void {
		$expander = new HierarchyGrantExpander(
			descender: $this->descender([], []),
			logger: $this->logger
		);

		$this->assertNull($expander->declarationFor($this->schema(null)));
		$this->assertNull($expander->declarationFor($this->schema(['maxDepth' => 5])));
		$this->assertNull($expander->declarationFor($this->schema(['parent' => '   '])));
	}//end testAnUndeclaredHierarchyIsNull()

	/**
	 * An authored depth is capped, and a nonsensical one falls back.
	 *
	 * `maxDepth` is authored per schema and the descent runs on every request
	 * that holds a grant, so an author who types 500 would otherwise buy five
	 * hundred queries per request.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function testTheAuthoredDepthIsBounded(): void {
		$expander = new HierarchyGrantExpander(
			descender: $this->descender([], []),
			logger: $this->logger
		);

		$this->assertSame(
			HierarchyGrantExpander::DEPTH_CEILING,
			$expander->declarationFor($this->schema(['parent' => 'p', 'maxDepth' => 500]))['maxDepth']
		);
		$this->assertSame(
			HierarchyGrantExpander::DEFAULT_MAX_DEPTH,
			$expander->declarationFor($this->schema(['parent' => 'p', 'maxDepth' => 0]))['maxDepth']
		);
		$this->assertSame(
			3,
			$expander->declarationFor($this->schema(['parent' => 'p', 'maxDepth' => 3]))['maxDepth']
		);
	}//end testTheAuthoredDepthIsBounded()

	/**
	 * A narrowing names verbs, and an unknown verb narrows to nothing.
	 *
	 * An extension verb has no core bit, so it cannot travel down a grant at
	 * all. Treating it as "no narrowing" would be the widening direction.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function testAnUnknownVerbNarrowsToNothing(): void {
		$expander = new HierarchyGrantExpander(
			descender: $this->descender([], []),
			logger: $this->logger
		);

		$this->assertSame(
			0,
			$expander->narrow(mask: Constants::PERMISSION_READ, verbs: ['besluit_nemen'])
		);
		$this->assertSame(
			Constants::PERMISSION_READ,
			$expander->narrow(mask: Constants::PERMISSION_READ, verbs: []),
			'no narrowing declared leaves the mask alone'
		);
	}//end testAnUnknownVerbNarrowsToNothing()
}//end class
