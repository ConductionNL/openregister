<?php

/**
 * Behaviour tests for ExampleSetFragmentShape: what a fragment may declare, and
 * every refusal of its shape.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;


use OCA\OpenRegister\Service\ExampleSet\ExampleSetException;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetFragmentShape;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour of the fragment shape check: what a fragment file may declare.
 */
class ExampleSetFragmentShapeBehaviourTest extends TestCase {

	private static function shape(string $app='alpha'): ExampleSetFragmentShape {
		return new ExampleSetFragmentShape(app: $app, file: 'alpha/demo.json');
	}//end shape()

	private static function object(array $override=[]): array {
		return array_merge(['key' => 'o1', 'register' => 'r', 'schema' => 's', 'data' => ['title' => 'T']], $override);
	}//end object()

	private function expectRefusal(string $message): void {
		$this->expectException(ExampleSetException::class);
		$this->expectExceptionMessage($message);
	}//end expectRefusal()

	public function testAWellFormedFragmentIsShapedWithEveryPart(): void {
		$shaped = self::shape()->shape(
			[
				'@dir' => '/apps/alpha',
				'objects' => [self::object(['files' => [['path' => 'docs/a.pdf']]])],
				'groups' => [['gid' => 'g'], 'junk'],
				'tasks' => [['key' => 't1', 'data' => ['title' => 'Bel']]],
			]
		);

		$this->assertSame('alpha', $shaped['app']);
		$this->assertSame('alpha/demo.json', $shaped['file']);
		$this->assertSame('/apps/alpha', $shaped['dir']);
		$this->assertSame('o1', $shaped['objects'][0]['key']);
		$this->assertSame([['gid' => 'g']], $shaped['groups']);
		$this->assertSame([['key' => 't1', 'data' => ['title' => 'Bel']]], $shaped['tasks']);
		$this->assertSame([], $shaped['views']);
		$this->assertSame([], $shaped['archival']);
	}

	public function testAnObjectWithoutARegisterIsRefused(): void {
		$this->expectRefusal("alpha/demo.json: object 0 needs a key, a register, a schema, data, and files with a relative path inside the fragment's folder.");

		self::shape()->shape(['objects' => [self::object(['register' => ''])]]);
	}

	public function testAnObjectWithoutAKeyIsRefused(): void {
		$this->expectRefusal('object 0 needs');

		self::shape()->shape(['objects' => [self::object(['key' => ''])]]);
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function unsafeFiles(): array {
		return [
			'not an object' => ['docs/a.pdf'],
			'no path' => [['name' => 'a']],
			'absolute' => [['path' => '/etc/passwd']],
			'parent' => [['path' => 'docs/../../x']],
			'only parent' => [['path' => '..']],
			'backslash' => [['path' => 'docs\\a.pdf']],
			'nul byte' => [['path' => "a\0b"]],
		];
	}//end unsafeFiles()

	/**
	 * @dataProvider unsafeFiles
	 */
	public function testAnObjectWithAnUnsafeFileIsRefused(mixed $file): void {
		$this->expectRefusal('object 0 needs');

		self::shape()->shape(['objects' => [self::object(['files' => [$file]])]]);
	}

	public function testAFileNameThatOnlyContainsDotsIsSafe(): void {
		$shaped = self::shape()->shape(['objects' => [self::object(['files' => [['path' => 'docs/..hidden/a..b.pdf']]])]]);

		$this->assertCount(1, $shaped['objects']);
	}

	public function testAChangeNamesWhatAndHow(): void {
		$shaped = self::shape()->shape(
			[
				'changes' => [
					['key' => 'c1', 'register' => 'r', 'schema' => 's', 'where' => ['a' => 1], 'set' => ['b' => 2]],
					['key' => 'c2', 'register' => 'r', 'schema' => 's', 'object' => 'o9', 'append' => ['tags' => ['x']]],
				],
			]
		);

		$this->assertSame(['c1', 'c2'], array_column($shaped['changes'], 'key'));
	}

	/**
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function incompleteChanges(): array {
		return [
			'no target' => [['set' => ['b' => 2]]],
			'no operation' => [['where' => ['a' => 1]]],
			'scalar operation' => [['object' => 'o9', 'set' => 'b']],
		];
	}//end incompleteChanges()

	/**
	 * @dataProvider incompleteChanges
	 */
	public function testAChangeWithoutTargetOrOperationIsRefused(array $change): void {
		$this->expectRefusal('alpha/demo.json: change 0 needs a key, a register, a schema, a where or an object, and a set or an append.');

		self::shape()->shape(['changes' => [['key' => 'c1', 'register' => 'r', 'schema' => 's'] + $change]]);
	}

	public function testAKeyUsedTwiceAcrossObjectsAndChangesIsRefused(): void {
		$this->expectRefusal('alpha/demo.json: the key "o1" is used twice.');

		self::shape()->shape(['objects' => [self::object(), self::object()]]);
	}

	public function testAKeyedRowUsedTwiceIsRefused(): void {
		$this->expectRefusal('alpha/demo.json: view key "v" is used twice.');

		self::shape()->shape(['views' => [['key' => 'v', 'data' => []], ['key' => 'v', 'data' => []]]]);
	}

	/**
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function badKeyedRows(): array {
		return [
			'no key' => [['data' => []]],
			'empty key' => [['key' => '', 'data' => []]],
			'numeric key' => [['key' => 5, 'data' => []]],
			'no data' => [['key' => 'w']],
			'scalar data' => [['key' => 'w', 'data' => 'x']],
		];
	}//end badKeyedRows()

	/**
	 * @dataProvider badKeyedRows
	 */
	public function testAKeyedRowNeedsAKeyAndData(array $row): void {
		$this->expectRefusal('alpha/demo.json: watcher 0 needs a key and its fields (data).');

		self::shape()->shape(['watchers' => [$row]]);
	}

	public function testOnlyOpenRegistersFragmentMayDeclareArchival(): void {
		$this->expectRefusal("alpha/demo.json: archival is OpenRegister's own setting; only OpenRegister's fragment may write it.");

		self::shape()->shape(['archival' => [['key' => 'a', 'data' => []]]]);
	}

	public function testOpenRegistersFragmentDeclaresArchival(): void {
		$shaped = self::shape(app: 'openregister')->shape(['archival' => [['key' => 'a', 'data' => ['years' => 7]]]]);

		$this->assertSame([['key' => 'a', 'data' => ['years' => 7]]], $shaped['archival']);
	}

	public function testAServiceAccountIsShapedWithDefaults(): void {
		$shaped = self::shape()->shape(['serviceAccounts' => [['key' => 'bot', 'uid' => 'alpha.bot-1', 'groups' => ['g', 3]]]]);

		$this->assertSame([['key' => 'bot', 'uid' => 'alpha.bot-1', 'displayName' => 'alpha.bot-1', 'groups' => ['g', '3']]], $shaped['serviceAccounts']);
	}

	/**
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function badAccounts(): array {
		return [
			'no key' => [['uid' => 'bot']],
			'upper case uid' => [['key' => 'bot', 'uid' => 'Bot']],
			'no uid' => [['key' => 'bot']],
		];
	}//end badAccounts()

	/**
	 * @dataProvider badAccounts
	 */
	public function testAServiceAccountNeedsAKeyAndASafeUid(array $account): void {
		$this->expectRefusal('alpha/demo.json: service account 0 needs a key and an account id of lower-case letters, digits, dots, dashes or underscores.');

		self::shape()->shape(['serviceAccounts' => [$account]]);
	}
}//end class
