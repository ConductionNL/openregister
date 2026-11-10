<?php

/**
 * Golden outcomes of the hierarchy descent, pinned before its refactor.
 *
 * Every scenario records the complete `expand()` answer: which descendants a
 * grant reaches (allowed) and which it does not (refused: past the depth cap,
 * narrowed to nothing, past the descendant bound, behind an unreadable level,
 * not seeded, or already granted directly). The expected arrays were recorded
 * from the code as it stood before the descent state was moved into
 * HierarchyDescent, so any change to an allowed or refused outcome fails here.
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
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\HierarchyDescender;
use OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander;
use OCP\Constants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Pins allowed and refused descendants for a fixed set of trees.
 */
class HierarchyGrantExpanderGoldenTest extends TestCase {

	/**
	 * A descender double answering from a parent map.
	 *
	 * @param array<string, string>            $childToParent Child UUID => parent UUID.
	 * @param array<int, array<string, mixed>> $tables        The declarations to answer.
	 * @param string|null                      $failOn        A parent whose level throws, or null.
	 * @param boolean                          $tablesFail    Whether reading the declarations throws.
	 *
	 * @return HierarchyDescender The double.
	 */
	private function descender(array $childToParent, array $tables, ?string $failOn, bool $tablesFail): HierarchyDescender {
		$double = $this->getMockBuilder(HierarchyDescender::class)
			->disableOriginalConstructor()
			->onlyMethods(['hierarchicalTables', 'childrenOf'])
			->getMock();

		if ($tablesFail === true) {
			$double->method('hierarchicalTables')->willThrowException(new RuntimeException('tables'));
		} else {
			$double->method('hierarchicalTables')->willReturn($tables);
		}

		$double->method('childrenOf')->willReturnCallback(
			static function (string $table, string $parentColumn, array $parentUuids) use ($childToParent, $failOn): array {
				if ($failOn !== null && in_array($failOn, $parentUuids, true) === true) {
					throw new RuntimeException('level');
				}

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
	 * @param integer  $maxDepth The depth cap.
	 * @param string[] $verbs    The verbs that travel down.
	 * @param integer  $schemaId The schema id.
	 *
	 * @return array<string, mixed> The declaration.
	 */
	private static function table(int $maxDepth, array $verbs, int $schemaId=2): array {
		return [
			'table' => 'openregister_table_1_' . $schemaId,
			'parentColumn' => 'parent_case',
			'maxDepth' => $maxDepth,
			'verbs' => $verbs,
			'schemaId' => $schemaId,
		];
	}//end table()

	/**
	 * The scenarios and the answers the pre-refactor code gave.
	 *
	 * @return array<string, array<int, mixed>> Scenario name => arguments.
	 */
	public static function scenarios(): array {
		$read   = Constants::PERMISSION_READ;
		$update = Constants::PERMISSION_UPDATE;
		$chain  = ['child' => 'root', 'grandchild' => 'child', 'great' => 'grandchild'];

		$wide = [];
		for ($i = 0; $i <= HierarchyGrantExpander::MAX_DESCENDANTS; $i++) {
			$wide['c' . $i] = 'root';
		}

		return [
			'chain reaches every level' => [
				$chain, [self::table(5, [])], ['root' => $read], null, null, false,
				[
					'granted' => ['root' => $read, 'child' => $read, 'grandchild' => $read, 'great' => $read],
					'sources' => ['child' => 'root', 'grandchild' => 'root', 'great' => 'root'],
				],
			],
			'depth cap refuses below it' => [
				$chain, [self::table(2, [])], ['root' => $read], null, null, false,
				[
					'granted' => ['root' => $read, 'child' => $read, 'grandchild' => $read],
					'sources' => ['child' => 'root', 'grandchild' => 'root'],
				],
			],
			'verbs narrow the mask' => [
				$chain, [self::table(5, ['read'])], ['root' => ($read | $update)], null, null, false,
				[
					'granted' => ['root' => ($read | $update), 'child' => $read, 'grandchild' => $read, 'great' => $read],
					'sources' => ['child' => 'root', 'grandchild' => 'root', 'great' => 'root'],
				],
			],
			'narrowed to nothing is refused' => [
				$chain, [self::table(5, ['delete'])], ['root' => $read], null, null, false,
				[
					'granted' => ['root' => $read],
					'sources' => [],
				],
			],
			'direct grant survives' => [
				$chain, [self::table(5, ['read'])], ['root' => $read, 'child' => ($read | $update)], null, null, false,
				[
					'granted' => ['root' => $read, 'child' => ($read | $update), 'grandchild' => $read, 'great' => $read],
					'sources' => ['grandchild' => 'child', 'great' => 'child'],
				],
			],
			'cycle adds nothing' => [
				['a' => 'b', 'b' => 'a', 'c' => 'b'], [self::table(5, [])], ['a' => $read], null, null, false,
				[
					'granted' => ['a' => $read, 'b' => $read, 'c' => $read],
					'sources' => ['b' => 'a', 'c' => 'a'],
				],
			],
			'unseeded grant does not travel' => [
				['x1' => 'x', 'y1' => 'y'], [self::table(5, [])], ['x' => $read, 'y' => $update], ['x' => $read], null, false,
				[
					'granted' => ['x' => $read, 'y' => $update, 'x1' => $read],
					'sources' => ['x1' => 'x'],
				],
			],
			'empty seeds expand nothing' => [
				$chain, [self::table(5, [])], ['root' => $read], [], null, false,
				[
					'granted' => ['root' => $read],
					'sources' => [],
				],
			],
			'no grants expand nothing' => [
				$chain, [self::table(5, [])], [], null, null, false,
				[
					'granted' => [],
					'sources' => [],
				],
			],
			'unreadable level stops the descent' => [
				$chain, [self::table(5, [])], ['root' => $read], null, 'child', false,
				[
					'granted' => ['root' => $read, 'child' => $read],
					'sources' => ['child' => 'root'],
				],
			],
			'unreadable declarations keep direct grants' => [
				$chain, [self::table(5, [])], ['root' => $read], null, null, true,
				[
					'granted' => ['root' => $read],
					'sources' => [],
				],
			],
			'a nothing-mask in one hierarchy does not block the next' => [
				$chain, [self::table(1, ['delete'], 3), self::table(5, [], 4)], ['root' => $read], null, null, false,
				[
					'granted' => ['root' => $read, 'child' => $read, 'grandchild' => $read, 'great' => $read],
					'sources' => ['child' => 'root', 'grandchild' => 'root', 'great' => 'root'],
				],
			],
			'an inherited grant does not seed the next hierarchy' => [
				$chain, [self::table(1, [], 3), self::table(5, ['read'], 4)], ['root' => ($read | $update)], null, null, false,
				[
					'granted' => ['root' => ($read | $update), 'child' => ($read | $update)],
					'sources' => ['child' => 'root'],
				],
			],
			'descendant bound refuses past it' => [
				$wide, [self::table(5, [])], ['root' => $read], null, null, false,
				null,
			],
		];
	}//end scenarios()

	/**
	 * The full answer matches the pinned one.
	 *
	 * @param array<string, string>                                         $childToParent Parent map.
	 * @param array<int, array<string, mixed>>                              $tables        Declarations.
	 * @param array<string, int>                                            $granted       Direct grants.
	 * @param array<string, int>|null                                       $seeds         Travelling grants.
	 * @param string|null                                                   $failOn        A parent whose level throws.
	 * @param boolean                                                       $tablesFail    Whether the declarations throw.
	 * @param array{granted: array<string, int>, sources: array<string, string>}|null $expected The pinned answer, or null for the bound scenario.
	 *
	 * @return void
	 */
	#[DataProvider('scenarios')]
	public function testTheAnswerIsPinned(
		array $childToParent,
		array $tables,
		array $granted,
		?array $seeds,
		?string $failOn,
		bool $tablesFail,
		?array $expected
	): void {
		$expander = new HierarchyGrantExpander(
			descender: $this->descender($childToParent, $tables, $failOn, $tablesFail),
			logger: new NullLogger()
		);

		$result = $expander->expand($granted, $seeds);

		if ($expected === null) {
			// The bound: exactly MAX_DESCENDANTS children are granted, the next
			// one and everything after it are refused.
			$this->assertCount((HierarchyGrantExpander::MAX_DESCENDANTS + 1), $result['granted']);
			$this->assertCount(HierarchyGrantExpander::MAX_DESCENDANTS, $result['sources']);
			$this->assertArrayHasKey('c' . (HierarchyGrantExpander::MAX_DESCENDANTS - 1), $result['granted']);
			$this->assertArrayNotHasKey('c' . HierarchyGrantExpander::MAX_DESCENDANTS, $result['granted']);
			return;
		}

		$this->assertSame($expected, $result);
	}//end testTheAnswerIsPinned()
}//end class
