<?php

/**
 * Unit tests for the names of a generated GraphQL schema.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\GraphQL
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\GraphQL;

use OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator\GraphQLNames;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator\GraphQLNames
 */
class GraphQLNamesTest extends TestCase {

	public static function plurals(): array {
		return [
			'dutch -en' => ['zaken', 'zak'],
			'-en on a short word stays' => ['pen', 'pen'],
			'four letters ending in -en' => ['eten', 'eten'],
			'-ies' => ['categories', 'category'],
			'-ies on the shortest word' => ['ies', 'y'],
			'-es' => ['boxes', 'box'],
			'-es on a short word only loses the s' => ['eens', 'een'],
			'four letters ending in -es' => ['does', 'doe'],
			'-ss stays' => ['glass', 'glass'],
			'-s' => ['bus', 'bu'],
			'no suffix' => ['data', 'data'],
		];
	}//end plurals()

	#[DataProvider('plurals')]
	public function testSingularize(string $plural, string $singular): void {
		$this->assertSame($singular, (new GraphQLNames())->singularize($plural));
	}//end testSingularize()

	public function testTypeNameIsPascalCaseAndStartsWithALetter(): void {
		$names = new GraphQLNames();

		$this->assertSame('FirstSecondThird', $names->toTypeName('first_second-third'));
		$this->assertSame('Type9lives', $names->toTypeName('9lives'));
	}//end testTypeNameIsPascalCaseAndStartsWithALetter()

	public function testASecondSchemaGetsItsIdAddedAndTheFirstKeepsItsName(): void {
		$names = new GraphQLNames();

		$this->assertSame('Zaak', $names->toTypeName('zaak', 1));
		$this->assertSame('Zaak2', $names->toTypeName('zaak', 2));
		$this->assertSame('Zaak', $names->toTypeName('zaak', 1));
	}//end testASecondSchemaGetsItsIdAddedAndTheFirstKeepsItsName()

	public function testATypeNameWithoutASchemaIsNeitherClaimedNorChanged(): void {
		$names = new GraphQLNames();

		$this->assertSame('Zaak', $names->toTypeName('zaak'));
		$this->assertSame('Zaak', $names->toTypeName('zaak', 1));
		$this->assertSame('Zaak', $names->toTypeName('zaak'));
	}//end testATypeNameWithoutASchemaIsNeitherClaimedNorChanged()

	public function testFieldNameIsCamelCaseAndValid(): void {
		$names = new GraphQLNames();

		$this->assertSame('firstSecondThird', $names->toFieldName('first_second-third'));
		$this->assertSame('_1start', $names->toFieldName('1start'));
		$this->assertSame('same', $names->toFieldName('sa.me!'));
	}//end testFieldNameIsCamelCaseAndValid()
}//end class
