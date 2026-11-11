<?php

/**
 * Unit tests for MigrationValueCaster.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/schema-migration/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Schema;

use OCA\OpenRegister\Service\Schema\CastTarget;
use OCA\OpenRegister\Service\Schema\MigrationValueCaster;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Each supported type casts what it should and refuses what it should not.
 *
 * @covers \OCA\OpenRegister\Service\Schema\MigrationValueCaster
 */
class MigrationValueCasterTest extends TestCase {

	/**
	 * @return array<string, array{0: mixed, 1: string, 2: string|null, 3: mixed}>
	 */
	public static function casts(): array {
		return [
			'string' => [12, 'string', null, '12'],
			'integer' => ['7', 'integer', null, 7],
			'number' => [true, 'number', null, 1.0],
			'boolean' => ['off', 'boolean', null, false],
			'date' => ['2024-03-05T10:00:00+02:00', 'date', null, '2024-03-05T08:00:00+00:00'],
			'date with format' => ['05/03/2024 10:00 +01:00', 'date', 'd/m/Y H:i P', '2024-03-05T10:00:00+01:00'],
		];
	}//end casts()

	/**
	 * @param mixed       $value    The value.
	 * @param string      $toType   Target type.
	 * @param string|null $format   Date format.
	 * @param mixed       $expected Expected result.
	 *
	 * @return void
	 */
	#[DataProvider('casts')]
	public function testCasts(mixed $value, string $toType, ?string $format, mixed $expected): void {
		$this->assertSame($expected, (new MigrationValueCaster())->cast($value, new CastTarget($toType, $format)));
	}//end testCasts()

	/**
	 * @return array<string, array{0: mixed, 1: string, 2: string}>
	 */
	public static function refusals(): array {
		return [
			'unsupported type' => ['1', 'float', 'Unsupported cast target type "float".'],
			'integer from word' => ['x', 'integer', 'Cannot cast "x" to integer.'],
			'number from array' => [[], 'number', 'Cannot cast "array" to number.'],
			'boolean from two' => [2, 'boolean', 'Cannot cast "2" to boolean.'],
			'date from array' => [[], 'date', 'Cannot cast non-string value to date.'],
		];
	}//end refusals()

	/**
	 * @param mixed  $value   The value.
	 * @param string $toType  Target type.
	 * @param string $message Expected message.
	 *
	 * @return void
	 */
	#[DataProvider('refusals')]
	public function testRefusals(mixed $value, string $toType, string $message): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage($message);
		(new MigrationValueCaster())->cast($value, new CastTarget($toType, null));
	}//end testRefusals()

	/**
	 * @return void
	 */
	public function testTypesListsEverySupportedTarget(): void {
		$this->assertSame(['string', 'integer', 'number', 'boolean', 'date'], MigrationValueCaster::TYPES);
	}//end testTypesListsEverySupportedTarget()
}//end class
