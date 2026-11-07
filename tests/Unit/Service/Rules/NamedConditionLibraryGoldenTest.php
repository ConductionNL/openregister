<?php

/**
 * Golden test for NamedConditionLibrary.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

use OCA\OpenRegister\Service\Rules\NamedConditionLibrary;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for every public method of NamedConditionLibrary.
 *
 * Fixed declarations and nodes; the expected values were recorded from the
 * code as it stood before the refactor.
 *
 * @covers \OCA\OpenRegister\Service\Rules\NamedConditionLibrary
 */
class NamedConditionLibraryGoldenTest extends TestCase {

	/**
	 * The library under test.
	 *
	 * @var NamedConditionLibrary
	 */
	private NamedConditionLibrary $library;

	protected function setUp(): void {
		$this->library = new NamedConditionLibrary();
	}//end setUp()

	private static function ref(string $name): array {
		return [NamedConditionLibrary::REF => $name];
	}//end ref()

	/**
	 * A chain c0 → c1 → … → c{length}, every link declared.
	 *
	 * @param int $length The number of links.
	 *
	 * @return array<string, mixed>
	 */
	private static function chain(int $length): array {
		$annotation = [];
		for ($i = 0; $i < $length; $i++) {
			$annotation['c' . $i] = ['expression' => self::ref('c' . ($i + 1))];
		}

		$annotation['c' . $length] = ['expression' => ['==' => [1, 1]]];
		return $annotation;
	}//end chain()

	public function testLibraryFrom(): void {
		$this->assertSame([], $this->library->libraryFrom(null));
		$this->assertSame([], $this->library->libraryFrom([]));
		$this->assertSame(
			[
				'adult' => ['expression' => ['>=' => [['var' => 'age'], 18]], 'description' => 'Of age'],
				'blank' => ['expression' => null, 'description' => ''],
				'7' => ['expression' => true, 'description' => '42'],
			],
			$this->library->libraryFrom(
				[
					'adult' => ['expression' => ['>=' => [['var' => 'age'], 18]], 'description' => 'Of age'],
					'' => ['expression' => true],
					'scalar' => 'nope',
					'noExpression' => ['description' => 'missing'],
					'blank' => ['expression' => null],
					7 => ['expression' => true, 'description' => 42],
				]
			)
		);
	}//end testLibraryFrom()

	public function testReferencesIn(): void {
		$cases = [
			[null, []],
			['x', []],
			[[], []],
			[self::ref('a'), ['a']],
			[[NamedConditionLibrary::REF => 5, 'and' => [self::ref('b')]], ['5']],
			[['and' => [self::ref('a'), ['or' => [self::ref('b'), self::ref('c')]]]], ['a', 'b', 'c']],
			[['not' => self::ref('a')], ['a']],
			[['!' => [self::ref('a')]], ['a']],
			[['if' => [self::ref('a'), self::ref('b'), ['and' => [self::ref('c')]]]], ['a', 'b', 'c']],
			[['==' => [self::ref('a'), 1]], []],
			[['and' => 'x', 'or' => [self::ref('z')]], ['z']],
			[['and' => [self::ref('a')], 'or' => [self::ref('a')]], ['a', 'a']],
			[['not' => ['and' => [self::ref('q')]]], ['q']],
			[[0 => self::ref('a')], []],
		];
		foreach ($cases as $index => [$node, $expected]) {
			$this->assertSame($expected, $this->library->referencesIn($node), 'case ' . $index);
		}
	}//end testReferencesIn()

	public function testRefusalFor(): void {
		$cases = [
			'null library, no users' => [null, [], null],
			'clean library and users' => [
				['a' => ['expression' => self::ref('b')], 'b' => ['expression' => true]],
				['rule1' => self::ref('a'), 'rule2' => ['and' => [self::ref('b')]]],
				null,
			],
			'undeclared inside library' => [
				['a' => ['expression' => ['and' => [self::ref('b'), self::ref('x')]]], 'b' => ['expression' => self::ref('y')]],
				['rule1' => self::ref('nope')],
				'named condition "a" references "x", which is not declared',
			],
			'undeclared by a user' => [
				['a' => ['expression' => true]],
				['rule1' => self::ref('a'), 9 => ['or' => [self::ref('ghost')]]],
				'"9" references named condition "ghost", which is not declared',
			],
			'self cycle' => [
				['a' => ['expression' => self::ref('a')]],
				[],
				'named conditions form a cycle: a → a',
			],
			'user check runs before the cycle check' => [
				['a' => ['expression' => self::ref('b')], 'b' => ['expression' => ['not' => self::ref('c')]], 'c' => ['expression' => self::ref('b')]],
				['rule' => self::ref('ghost')],
				'"rule" references named condition "ghost", which is not declared',
			],
			'cycle reached from a' => [
				['a' => ['expression' => self::ref('b')], 'b' => ['expression' => ['not' => self::ref('c')]], 'c' => ['expression' => self::ref('b')]],
				[],
				'named conditions form a cycle: a → b → c → b',
			],
			'depth five is allowed' => [self::chain(5), [], null],
			'depth six is refused' => [
				self::chain(6),
				[],
				'named condition "c0" composes deeper than the administered depth of 5',
			],
			'depth seven names the first' => [
				self::chain(7),
				[],
				'named condition "c0" composes deeper than the administered depth of 5',
			],
			'dropped declaration is undeclared' => [
				['a' => ['expression' => self::ref('b')], 'b' => ['description' => 'no expression']],
				[],
				'named condition "a" references "b", which is not declared',
			],
		];
		foreach ($cases as $name => [$annotation, $users, $expected]) {
			$this->assertSame($expected, $this->library->refusalFor($annotation, $users), $name);
		}

		$this->assertNull($this->library->refusalFor(['a' => ['expression' => true]]));
	}//end testRefusalFor()

	public function testUsage(): void {
		$this->assertSame([], $this->library->usage([]));
		$this->assertSame(
			[
				'a' => ['rule1', '3'],
				'b' => ['rule1', 'rule2'],
				'c' => ['rule2'],
			],
			$this->library->usage(
				[
					'rule1' => ['and' => [self::ref('b'), self::ref('a'), self::ref('b')]],
					'rule2' => ['or' => [self::ref('c'), ['not' => self::ref('b')]]],
					3 => self::ref('a'),
					'rule4' => ['==' => [1, 1]],
				]
			)
		);
	}//end testUsage()
}//end class
