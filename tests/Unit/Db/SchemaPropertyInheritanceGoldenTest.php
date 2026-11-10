<?php

/**
 * Golden test for merging a child schema's properties over its resolved parent.
 *
 * Records the merged properties, or the exception and its message, for metadata
 * overrides, nested properties and items, every constraint kind a child may
 * tighten but not relax, and removed constraints. Re-record with UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\SchemaPropertyInheritance;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\SchemaPropertyInheritance
 */
class SchemaPropertyInheritanceGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/schema-property-inheritance-golden.json';

	private static function cases(): array {
		$base = [
			'name' => ['type' => 'string', 'title' => 'Name', 'minLength' => 2, 'maxLength' => 50, 'format' => 'email', 'pattern' => '^a', 'enum' => ['a', 'b', 'c']],
			'age' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 120, 'multipleOf' => 1],
			'tags' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 10], 'minItems' => 1, 'maxItems' => 5],
			'address' => [
				'type' => 'object',
				'properties' => ['street' => ['type' => 'string', 'maxLength' => 80], 'city' => ['type' => 'string']],
				'x-meta' => ['a' => 1, 'b' => ['c' => 2]],
			],
			'flag' => 'yes',
			'empty' => '',
			'nullish' => ['type' => 'string', 'format' => null, 'pattern' => null],
			'multi' => ['type' => ['string', 'null']],
			'counted' => ['minProperties' => 1, 'maxProperties' => 4, 'minimum' => 'low'],
		];
		$cases = [
			'new property' => [$base, ['extra' => ['type' => 'string']]],
			'metadata override' => [$base, ['name' => ['title' => 'Full name', 'description' => 'd', 'x-order' => 3, 'default' => 'z']]],
			'tighten everything' => [$base, ['name' => ['minLength' => 3, 'maxLength' => 40, 'enum' => ['a', 'b']], 'age' => ['minimum' => 18, 'maximum' => 99]]],
			'same type' => [$base, ['name' => ['type' => 'string']]],
			'change type' => [$base, ['name' => ['type' => 'number']]],
			'array type subset' => [$base, ['multi' => ['type' => ['string']]]],
			'array type superset' => [$base, ['multi' => ['type' => ['string', 'integer']]]],
			'array to scalar type' => [$base, ['multi' => ['type' => 'string']]],
			'format change' => [$base, ['name' => ['format' => 'uri']]],
			'format from null' => [$base, ['nullish' => ['format' => 'uri', 'pattern' => '^b']]],
			'format same' => [$base, ['name' => ['format' => 'email', 'pattern' => '^a']]],
			'pattern change' => [$base, ['name' => ['pattern' => '^b']]],
			'enum widen' => [$base, ['name' => ['enum' => ['a', 'z']]]],
			'enum scalar' => [$base, ['name' => ['enum' => 'a']]],
			'minimum decrease' => [$base, ['age' => ['minimum' => -1]]],
			'minLength decrease' => [$base, ['name' => ['minLength' => 1]]],
			'minItems decrease' => [$base, ['tags' => ['minItems' => 0]]],
			'minProperties decrease' => [$base, ['counted' => ['minProperties' => 0]]],
			'maximum increase' => [$base, ['age' => ['maximum' => 121]]],
			'maxLength increase' => [$base, ['name' => ['maxLength' => 51]]],
			'maxItems increase' => [$base, ['tags' => ['maxItems' => 6]]],
			'maxProperties increase' => [$base, ['counted' => ['maxProperties' => 9]]],
			'non numeric minimum' => [$base, ['counted' => ['minimum' => 'lower']]],
			'const and multipleOf free' => [$base, ['age' => ['const' => 5, 'multipleOf' => 3, 'required' => true]]],
			'nested tighten' => [$base, ['address' => ['properties' => ['street' => ['maxLength' => 60], 'zip' => ['type' => 'string']]]]],
			'nested relax' => [$base, ['address' => ['properties' => ['street' => ['maxLength' => 100]]]]],
			'nested null parent' => [['p' => ['properties' => ['a' => null, 'b' => ['type' => 'string']]]], ['p' => ['properties' => ['a' => ['type' => 'x'], 'b' => ['type' => 'string']]]]],
			'items tighten' => [$base, ['tags' => ['items' => ['maxLength' => 8]]]],
			'items relax' => [$base, ['tags' => ['items' => ['maxLength' => 12]]]],
			'items scalar child' => [$base, ['tags' => ['items' => 'string']]],
			'other array merges deep' => [$base, ['address' => ['x-meta' => ['b' => ['d' => 3], 'e' => 4]]]],
			'other array to scalar' => [$base, ['address' => ['x-meta' => 'flat']]],
			'deep other relax' => [['p' => ['x-rule' => ['inner' => ['maximum' => 3]]]], ['p' => ['x-rule' => ['inner' => ['maximum' => 4]]]]],
			'scalar replacement' => [$base, ['flag' => 'no']],
			'scalar removed' => [$base, ['flag' => '']],
			'scalar to array' => [$base, ['flag' => ['type' => 'string']]],
			'empty parent scalar' => [$base, ['empty' => '']],
			'array replaced by scalar' => [$base, ['age' => 'n/a']],
			'array replaced by empty' => [$base, ['age' => []]],
			'null parent property' => [['n' => null], ['n' => ['type' => 'string']]],
			'null child key' => [$base, ['name' => ['type' => null, 'title' => null]]],
			'array metadata replaced' => [['p' => ['ui:options' => ['a' => 0, 'b' => 2], 'x-order' => ['k' => 1]]], ['p' => ['ui:options' => ['a' => 1], 'x-order' => ['j' => 2]]]],
			'parent key null' => [['p' => ['type' => null, 'maximum' => 2]], ['p' => ['type' => 'string']]],
		];

		return $cases;
	}//end cases()

	public function testMergeMatchesGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => [$parent, $child]) {
			try {
				$actual[$name] = ['merged' => $this->merge(parent: $parent, child: $child)];
			} catch (\Throwable $e) {
				// A type error names the method it was raised in, so only its class is recorded.
				$message = $e instanceof \TypeError ? '' : $e->getMessage();
				$actual[$name] = ['exception' => $e::class, 'message' => $message];
			}
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testMergeMatchesGolden()

	private function merge(array $parent, array $child): array {
		return (new SchemaPropertyInheritance(schemaId: 'child-7'))->mergeProperties(parentProperties: $parent, childProperties: $child);
	}//end merge()
}//end class
