<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Schema;

use OCA\OpenRegister\Service\Schema\SchemaChangeSet;
use OCA\OpenRegister\Service\Schema\SchemaDiffService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for SchemaDiffService::diff() and nextVersion().
 *
 * Each case diffs a fixed pair of definitions (with optional declared renames);
 * the recording holds the full change list, the classification, the bump and
 * the next version from 1.4.2. Compared with fixtures/schema-diff-golden.json;
 * set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Service\Schema\SchemaDiffService
 * @covers \OCA\OpenRegister\Service\Schema\SchemaDiffRun
 * @uses   \OCA\OpenRegister\Service\Schema\SchemaChangeSet
 */
class SchemaDiffServiceGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/schema-diff-golden.json';

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, string>}>
	 */
	public static function cases(): array {
		$str = ['type' => 'string'];
		$int = ['type' => 'integer'];

		return [
			'identical' => [self::def(['a' => $str], ['a']), self::def(['a' => $str], ['a']), []],
			'both empty' => [[], [], []],
			'properties not an array' => [['properties' => 'x', 'required' => 'y'], ['properties' => ['a' => $str]], []],
			'required with non strings' => [self::def(['a' => $str]), self::def(['a' => $str, 'b' => $str], ['a', 7, null, 'b']), []],
			'int required entry, numeric name' => [self::def([], [7]), self::def(['7' => $str]), []],
			'added optional' => [self::def(['a' => $str]), self::def(['a' => $str, 'b' => $str]), []],
			'added required no default' => [self::def(['a' => $str]), self::def(['a' => $str, 'b' => $str], ['b']), []],
			'added required with default' => [self::def(['a' => $str]), self::def(['a' => $str, 'b' => $str + ['default' => 'x']], ['b']), []],
			'added scalar definition' => [self::def([]), self::def(['b' => 'string']), []],
			'removed' => [self::def(['a' => $str, 'b' => $int], ['b']), self::def(['a' => $str]), []],
			'declared rename' => [self::def(['a' => $str]), self::def(['b' => $str]), ['a' => 'b']],
			'declared rename with required' => [self::def(['a' => $str], ['a']), self::def(['b' => $str], ['b']), ['a' => 'b']],
			'rename source missing' => [self::def(['x' => $str]), self::def(['b' => $str]), ['a' => 'b']],
			'rename target missing' => [self::def(['a' => $str]), self::def(['y' => $str]), ['a' => 'b']],
			'rename plus extra add' => [self::def(['a' => $str]), self::def(['b' => $str, 'c' => $int]), ['a' => 'b']],
			'type changed' => [self::def(['a' => $str]), self::def(['a' => $int]), []],
			'type list reordered' => [self::def(['a' => ['type' => ['string', 'null']]]), self::def(['a' => ['type' => ['null', 'string']]]), []],
			'type list widened' => [self::def(['a' => ['type' => 'string']]), self::def(['a' => ['type' => ['null', 'string']]]), []],
			'type dropped' => [self::def(['a' => $str]), self::def(['a' => []]), []],
			'scalar old definition' => [self::def(['a' => 'string']), self::def(['a' => $str]), []],
			'min bound introduced' => [self::def(['a' => $str]), self::def(['a' => $str + ['minLength' => 3]]), []],
			'max bound removed' => [self::def(['a' => $str + ['maxLength' => 9]]), self::def(['a' => $str]), []],
			'min raised' => [self::def(['a' => $int + ['minimum' => 1]]), self::def(['a' => $int + ['minimum' => 2]]), []],
			'min lowered' => [self::def(['a' => $int + ['minimum' => 2]]), self::def(['a' => $int + ['minimum' => 1]]), []],
			'max raised' => [self::def(['a' => $int + ['maximum' => 1]]), self::def(['a' => $int + ['maximum' => 5]]), []],
			'max lowered' => [self::def(['a' => $int + ['maximum' => 5]]), self::def(['a' => $int + ['maximum' => 1]]), []],
			'bound int to float same' => [self::def(['a' => $int + ['maximum' => 5]]), self::def(['a' => $int + ['maximum' => 5.0]]), []],
			'bound equal' => [self::def(['a' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 3]]), self::def(['a' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 3]]), []],
			'many bounds' => [
				self::def(['a' => ['type' => 'object', 'minProperties' => 1, 'maxProperties' => 4, 'minItems' => 2]]),
				self::def(['a' => ['type' => 'object', 'minProperties' => 0, 'maxProperties' => 3, 'maxItems' => 2]]),
				[],
			],
			'enum introduced' => [self::def(['a' => $str]), self::def(['a' => $str + ['enum' => ['x', 'y']]]), []],
			'enum dropped' => [self::def(['a' => $str + ['enum' => ['x']]]), self::def(['a' => $str]), []],
			'enum narrowed' => [self::def(['a' => $str + ['enum' => ['x', 'y']]]), self::def(['a' => $str + ['enum' => ['x']]]), []],
			'enum widened' => [self::def(['a' => $str + ['enum' => ['x']]]), self::def(['a' => $str + ['enum' => ['x', 'y']]]), []],
			'enum reordered' => [self::def(['a' => $str + ['enum' => ['x', 'y']]]), self::def(['a' => $str + ['enum' => ['y', 'x']]]), []],
			'enum swapped' => [self::def(['a' => $str + ['enum' => ['x']]]), self::def(['a' => $str + ['enum' => ['y']]]), []],
			'enum scalar to list' => [self::def(['a' => $str + ['enum' => 'x']]), self::def(['a' => $str + ['enum' => ['x']]]), []],
			'enum list to scalar' => [self::def(['a' => $str + ['enum' => ['x']]]), self::def(['a' => $str + ['enum' => 'x']]), []],
			'pattern introduced' => [self::def(['a' => $str]), self::def(['a' => $str + ['pattern' => '^a']]), []],
			'pattern removed' => [self::def(['a' => $str + ['pattern' => '^a']]), self::def(['a' => $str]), []],
			'pattern changed' => [self::def(['a' => $str + ['pattern' => '^a']]), self::def(['a' => $str + ['pattern' => '^b']]), []],
			'format changed' => [self::def(['a' => $str + ['format' => 'date']]), self::def(['a' => $str + ['format' => 'email']]), []],
			'format same' => [self::def(['a' => $str + ['format' => 'date']]), self::def(['a' => $str + ['format' => 'date']]), []],
			'format introduced' => [self::def(['a' => $str]), self::def(['a' => $str + ['format' => 'date']]), []],
			'format removed' => [self::def(['a' => $str + ['format' => 'date']]), self::def(['a' => $str]), []],
			'pattern and format both null' => [self::def(['a' => $str + ['pattern' => null, 'format' => null]]), self::def(['a' => $str + ['pattern' => null, 'format' => null]]), []],
			'pattern null to absent' => [self::def(['a' => $str + ['pattern' => null]]), self::def(['a' => $str]), []],
			'bound null to absent' => [self::def(['a' => $str + ['minLength' => null]]), self::def(['a' => $str]), []],
			'bound absent to null' => [self::def(['a' => $str]), self::def(['a' => $str + ['maxLength' => null]]), []],
			'bound both null' => [self::def(['a' => $str + ['maxLength' => null]]), self::def(['a' => $str + ['maxLength' => null]]), []],
			'bound numeric string equal value' => [self::def(['a' => $int + ['minimum' => 5]]), self::def(['a' => $int + ['minimum' => '5']]), []],
			'bound numeric string raised' => [self::def(['a' => $int + ['minimum' => '2']]), self::def(['a' => $int + ['minimum' => '3']]), []],
			'every keyword on one property' => [
				self::def(['a' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 9, 'maximum' => 4, 'enum' => ['x', 'y'], 'pattern' => '^a']]),
				self::def(['a' => ['type' => 'integer', 'minLength' => 2, 'minItems' => 1, 'maxLength' => 10, 'enum' => ['x'], 'pattern' => '^b', 'format' => 'int32']]),
				[],
			],
			'every keyword relaxed on two properties' => [
				self::def(['a' => ['type' => ['null', 'string'], 'minimum' => 3, 'maxItems' => 2, 'enum' => ['x'], 'format' => 'date'], 'b' => ['type' => 'string', 'pattern' => '^x']]),
				self::def(['a' => ['type' => ['string', 'null'], 'minimum' => 1, 'maxItems' => 6, 'enum' => ['x', 'z']], 'b' => ['type' => 'string']]),
				[],
			],
			'newly required no default' =>[self::def(['a' => $str]), self::def(['a' => $str], ['a']), []],
			'newly required with default' => [self::def(['a' => $str]), self::def(['a' => $str + ['default' => '']], ['a']), []],
			'newly required unknown property' => [self::def(['a' => $str]), self::def(['a' => $str], ['zz']), []],
			'required removed' => [self::def(['a' => $str], ['a']), self::def(['a' => $str]), []],
			'required removed with property' => [self::def(['a' => $str], ['a']), self::def([]), []],
			'only relaxed' => [self::def(['a' => $str + ['maxLength' => 2]], ['a']), self::def(['a' => $str + ['maxLength' => 5]]), []],
			'mixed everything' => [
				self::def(['a' => $str + ['minLength' => 1], 'b' => $int, 'c' => $str + ['enum' => ['p', 'q']], 'd' => $str, 'e' => $str], ['a', 'd']),
				self::def(['a' => $str + ['minLength' => 4], 'c' => $str + ['enum' => ['p', 'q', 'r']], 'd' => $str, 'e2' => $str, 'f' => $int, 'g' => $str], ['f', 'b', 'c']),
				['e' => 'e2'],
			],
		];
	}//end cases()

	/**
	 * @param array<string, mixed> $properties
	 * @param array<int, mixed>    $required
	 *
	 * @return array<string, mixed>
	 */
	private static function def(array $properties, array $required=[]): array {
		return ['properties' => $properties, 'required' => $required];
	}//end def()

	/**
	 * @param array<string, mixed>  $old
	 * @param array<string, mixed>  $new
	 * @param array<string, string> $renames
	 */
	#[DataProvider('cases')]
	public function testDiffMatchesGolden(array $old, array $new, array $renames): void {
		$service = new SchemaDiffService();
		$set     = $service->diff($old, $new, $renames);
		$actual  = [
			'changes'        => $set->getChanges(),
			'classification' => $set->getClassification(),
			'bump'           => $set->getBump(),
			'breaking'       => $set->isBreaking(),
			'next'           => $service->nextVersion('1.4.2', $set),
		];
		$actual  = json_decode(json_encode($actual, JSON_PRESERVE_ZERO_FRACTION), true);
		$name    = (string)$this->dataName();

		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end testDiffMatchesGolden()
	/**
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function typeErrorCases(): array {
		$str = ['type' => 'string'];

		return [
			'numeric name in both' => [self::def(['7' => $str]), self::def(['7' => ['type' => 'integer']])],
			'enum scalar to scalar' => [self::def(['a' => $str + ['enum' => 'x']]), self::def(['a' => $str + ['enum' => 'y']])],
			'enum scalar to absent' => [self::def(['a' => $str + ['enum' => 'x']]), self::def(['a' => $str])],
		];
	}//end typeErrorCases()

	/**
	 * @param array<string, mixed> $old
	 * @param array<string, mixed> $new
	 */
	#[DataProvider('typeErrorCases')]
	public function testDiffRaisesTypeError(array $old, array $new): void {
		$this->expectException(\TypeError::class);
		(new SchemaDiffService())->diff($old, $new);
	}//end testDiffRaisesTypeError()
}//end class
