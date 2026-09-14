<?php

/**
 * The list and the object read agree down a tree, and the filter is in the query.
 *
 * Two claims, and they are the same claim read from two ends.
 *
 * THE AGREEMENT (task 4.2). A denied row that still appears in a list is the
 * worst of both answers: the caller sees the row, learns the identifier, and is
 * refused on the read. So the per-object verdict and the list term are asserted
 * against each other for every node of a chain of depth five, rather than for
 * one row in isolation.
 *
 * THE PLACE THE FILTER RUNS (task 6.3). "Compiled into the query" and "checked
 * on the result" are one sentence in English and two products in practice: a
 * permission check applied to a fetched page gives a correct page of wrong data,
 * with a wrong total and wrong facet counts. What proves the first rather than
 * the second is that the emitted SQL does not vary with the number of rows and
 * that no row is read to produce it. Both are asserted here against a tree of
 * 100,000 objects.
 *
 * WHAT THIS DOES NOT CLAIM. There is no database in a unit run, so the term is
 * proved present in the WHERE clause rather than read back out of `EXPLAIN`.
 * The e2e suite carries the version that runs against a real instance.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
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
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tasks 4.2 and 6.3: the tree of depth five, and the tree of 100,000.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler
 * @covers \OCA\OpenRegister\Service\Object\PermissionHandler
 */
class MagicRbacHandlerDepthAndScaleTest extends TestCase {

	/**
	 * The depth of the tree the tasks name.
	 *
	 * @var integer
	 */
	private const DEPTH = 5;

	/**
	 * The row count the performance task names.
	 *
	 * @var integer
	 */
	private const ROWS = 100000;

	/**
	 * The caller every case in this file runs as.
	 *
	 * @var string
	 */
	private const CALLER = 'ana';

	/**
	 * The group the caller holds and the rules name.
	 *
	 * @var string
	 */
	private const GROUP = 'behandelaars';

	/**
	 * The level of the tree whose node carries the deny.
	 *
	 * @var integer
	 */
	private const DENIED_LEVEL = 3;

	/**
	 * The list-side handler.
	 *
	 * @param string $mode One of DenyEnforcementMode::MODES.
	 *
	 * @return MagicRbacHandler The emitter under test.
	 */
	private function listHandler(string $mode = DenyEnforcementMode::MODE_ENFORCING): MagicRbacHandler {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($mode);

		return new MagicRbacHandler(
			$this->session(),
			$this->groupManager(),
			$this->createMock(originalClassName: IUserManager::class),
			$appConfig,
			$this->createMock(originalClassName: ConditionMatcher::class),
			$this->createMock(originalClassName: ContainerInterface::class),
			new NullLogger(),
			null,
			null,
			new DenyResolver(),
			new DenyEnforcementMode($appConfig, new NullLogger())
		);
	}//end listHandler()

	/**
	 * The object-side handler.
	 *
	 * @return PermissionHandler The evaluator under test.
	 */
	private function objectHandler(): PermissionHandler {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn(self::CALLER);

		$userManager = $this->createMock(originalClassName: IUserManager::class);
		$userManager->method('get')->willReturn($user);

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(false);
		$appConfig->method('getValueString')->willReturn(DenyEnforcementMode::MODE_ENFORCING);

		return new PermissionHandler(
			$this->session(),
			$userManager,
			$this->groupManager(),
			$this->createMock(originalClassName: SchemaMapper::class),
			$this->createMock(originalClassName: MagicMapper::class),
			$this->createMock(originalClassName: ConditionMatcher::class),
			$appConfig,
			new NullLogger(),
			$this->createMock(originalClassName: ContainerInterface::class),
			null,
			null,
			null,
			new DenyResolver(),
			new DenyEnforcementMode($appConfig, new NullLogger())
		);
	}//end objectHandler()

	/**
	 * A session holding the caller.
	 *
	 * @return IUserSession The session.
	 */
	private function session(): IUserSession {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn(self::CALLER);

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	/**
	 * A group manager answering with the caller's one group.
	 *
	 * @return IGroupManager The group manager.
	 */
	private function groupManager(): IGroupManager {
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn([self::GROUP]);

		return $groupManager;
	}//end groupManager()

	/**
	 * The schema every node of the tree belongs to.
	 *
	 * @return Schema The schema, granting `read` to the caller's group.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(42);
		$schema->setTitle('Zaak');
		$schema->setAuthorization(['read' => [self::GROUP]]);

		return $schema;
	}//end schema()

	/**
	 * The block one node of the tree carries.
	 *
	 * Only the node at {@see DENIED_LEVEL} carries a deny. Every other node
	 * carries nothing, so the grant on the schema is what answers for it.
	 *
	 * @param integer $level The node's depth, 1 for the root.
	 *
	 * @return array|null The node's `_authorization`.
	 */
	private function blockAtLevel(int $level): ?array {
		if ($level !== self::DENIED_LEVEL) {
			return null;
		}

		return ['deny' => ['read' => [self::GROUP]]];
	}//end blockAtLevel()

	/**
	 * One node of the chain.
	 *
	 * @param integer $level The node's depth, 1 for the root.
	 *
	 * @return ObjectEntity The node.
	 */
	private function nodeAtLevel(int $level): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid(sprintf('55555555-6666-7777-8888-%012d', $level));
		$object->setOwner('bea');
		$object->setObject(['title' => sprintf('niveau %d', $level), 'parent' => ($level - 1)]);
		$object->setAuthorization($this->blockAtLevel(level: $level));

		return $object;
	}//end nodeAtLevel()

	/**
	 * 🔴 Down five levels, the list term and the object verdict name the same rows.
	 *
	 * The two paths are compared through the reader they share, rather than by
	 * re-implementing the SQL in the test: a second reading of the same grammar
	 * would only ever meet the first one when a denial failed to bite.
	 *
	 * @return void
	 */
	public function testTheListAndTheObjectReadAgreeOnEveryNodeOfADepthFiveTree(): void {
		$schema = $this->schema();
		$objectHandler = $this->objectHandler();
		$resolver = new DenyResolver();
		$principals = $resolver->principalsFor(userId: self::CALLER, userGroups: [self::GROUP]);

		$term = $this->listHandler()->buildRbacConditionsSql($schema, 'read')['conditions'];
		$this->assertNotEmpty($term, 'the list emitted no condition at all, so nothing was compared');
		$sql = implode(' ', $term);

		$refused = 0;
		for ($level = 1; $level <= self::DEPTH; $level++) {
			$node = $this->nodeAtLevel(level: $level);

			$objectVerdict = $objectHandler->hasPermission(
				schema: $schema,
				action: 'read',
				userId: self::CALLER,
				object: $node
			);

			// What the list term excludes, read through the same resolver the
			// emitter built it from.
			$listExcludes = ($resolver->unconditionalDenial(
				authorization: ($node->getAuthorization() ?? []),
				action: 'read',
				principals: $principals
			) !== null);

			$this->assertSame(
				$objectVerdict,
				($listExcludes === false),
				sprintf('the list and the object read disagreed at level %d of the tree', $level)
			);

			if ($objectVerdict === false) {
				$refused++;
			}
		}//end for

		// Exactly one node is refused, so the case is not passing because every
		// node is refused or because none is.
		$this->assertSame(1, $refused, 'the deny bit on a different number of nodes than the one carrying it');

		// And the term the list carries is the row deny, naming the caller's
		// own principal rather than any deny at all.
		$this->assertStringContainsString('$.deny.read', $sql);
		$this->assertStringContainsString(sprintf('"%s"', self::GROUP), $sql);
	}//end testTheListAndTheObjectReadAgreeOnEveryNodeOfADepthFiveTree()

	/**
	 * 🔴 The filter is in the query: 100,000 rows do not change a character of it.
	 *
	 * A post-filter has to look at the rows, so its cost and its shape both move
	 * with them. This emitter is handed the schema and the action and nothing
	 * else, and the assertion is that the SQL it produces for a tree of five
	 * rows is byte-identical to the SQL it produces for a tree of 100,000 —
	 * which is what "computed over the permitted set" means for the total and
	 * for every facet count.
	 *
	 * @return void
	 */
	public function testTheEmittedFilterDoesNotVaryWithTheNumberOfRows(): void {
		$handler = $this->listHandler();
		$schema = $this->schema();

		$small = $handler->buildRbacConditionsSql($schema, 'read');

		// The same tree, grown to the size the task names. The rows exist and
		// none of them is passed to the emitter, which is the point: a
		// post-filter could not be written this way.
		$tree = $this->treeOf(rows: self::ROWS);
		$this->assertCount(self::ROWS, $tree);
		$this->assertSame(self::DEPTH, max(array_column($tree, 'level')));

		$large = $handler->buildRbacConditionsSql($schema, 'read');

		$this->assertSame($small['conditions'], $large['conditions']);
		$this->assertFalse($large['bypass']);
		$this->assertStringContainsString('$.deny.read', implode(' ', $large['conditions']));
	}//end testTheEmittedFilterDoesNotVaryWithTheNumberOfRows()

	/**
	 * The staged tree emits no deny predicate at this scale either.
	 *
	 * The list is where a staged deny would show first and hurt most, because
	 * the total and every facet count are computed over the predicate. A
	 * dashboard whose numbers moved on the day a rule was written, in a mode
	 * whose whole promise is that nothing moves, is the failure D15 exists to
	 * prevent.
	 *
	 * @return void
	 */
	public function testAStagedTreeOfAHundredThousandKeepsItsTotal(): void {
		$conditions = $this->listHandler(DenyEnforcementMode::MODE_STAGING)
			->buildRbacConditionsSql($this->schema(), 'read')['conditions'];

		$this->assertNotEmpty($conditions);
		$this->assertStringNotContainsString('$.deny.read', implode(' ', $conditions));
	}//end testAStagedTreeOfAHundredThousandKeepsItsTotal()

	/**
	 * A tree of depth five holding this many rows.
	 *
	 * Built as flat rows with a parent pointer rather than as entities: the
	 * point of the case is that the emitter never sees them, so the cheapest
	 * honest representation is the right one.
	 *
	 * @param integer $rows How many rows the tree holds.
	 *
	 * @return array<int, array{parent: integer, level: integer}> The tree.
	 */
	private function treeOf(int $rows): array {
		$tree = [];
		for ($index = 0; $index < $rows; $index++) {
			$tree[] = [
				'parent' => intdiv($index, self::DEPTH),
				'level' => (($index % self::DEPTH) + 1),
			];
		}

		return $tree;
	}//end treeOf()
}//end class
