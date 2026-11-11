<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\SaveObject\RelationCascadeHandler;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkRelationScanner;
use OCA\OpenRegister\Service\Object\UtilityHandler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins the four predicates that recognise a UUID-shaped value (plain, without
 * dashes, or behind a word prefix), each with its own extra rules, on one table.
 *
 * @covers \OCA\OpenRegister\Service\Object\UtilityHandler
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\RelationCascadeHandler
 * @covers \OCA\OpenRegister\Service\Object\SaveObjects\BulkRelationScanner
 */
class UuidShapePredicatesTest extends TestCase {
	private const UUID = '0f8fad5b-d9cb-469f-a165-70867728950e';

	/**
	 * The value, then the expected answer of: UtilityHandler::isUuid, RelationCascadeHandler::looksLikeObjectReference,
	 * RelationCascadeHandler::isReference, BulkRelationScanner::matchesReferencePattern.
	 *
	 * @return array<string, array{string, bool, bool, bool, bool}>
	 */
	public static function values(): array {
		$hex = '0f8fad5bd9cb469fa16570867728950e';
		return [
			'uuid'               => [self::UUID, true, true, true, true],
			'uuid upper'         => [strtoupper(self::UUID), true, true, true, true],
			'hex32'              => [$hex, true, true, true, true],
			'prefixed uuid'      => ['id-' . self::UUID, true, true, true, true],
			'prefixed hex32'     => ['obj-' . $hex, true, true, true, true],
			'upper prefix'       => ['ID-' . self::UUID, true, true, true, true],
			'digit prefix'       => ['a1-' . self::UUID, false, false, false, false],
			'two prefixes'       => ['a-b-' . self::UUID, false, false, false, false],
			'uuid newline'       => [self::UUID . "\n", true, true, true, true],
			'uuid short'         => [substr(self::UUID, 1), false, false, false, false],
			'hex31'              => [substr($hex, 1), false, false, false, false],
			'hex33'              => [$hex . 'a', false, false, false, false],
			'non-hex'            => ['0f8fad5b-d9cb-469f-a165-70867728950g', false, false, false, false],
			'numeric'            => ['42', false, true, true, false],
			'zero'               => ['0', false, true, false, false],
			'objects url'        => ['https://x.example/objects/1', false, true, true, true],
			'api path'           => ['/api/thing', false, false, true, false],
			'plain url'          => ['https://x.example/a', false, false, false, true],
			'empty'              => ['', false, false, false, false],
			'word'               => ['hello', false, false, false, false],
		];
	}//end values()

	/**
	 * @dataProvider values
	 */
	public function testPredicates(string $value, bool $util, bool $looks, bool $ref, bool $trait): void {
		$this->assertSame(
			[$util, $looks, $ref, $trait],
			[
				$this->call(UtilityHandler::class, 'isUuid', $value),
				$this->call(RelationCascadeHandler::class, 'looksLikeObjectReference', $value),
				$this->call(RelationCascadeHandler::class, 'isReference', $value),
				$this->call(BulkRelationScanner::class, 'matchesReferencePattern', $value),
			]
		);
	}//end testPredicates()

	private function call(string $class, string $method, string $value): bool {
		$object = (new ReflectionClass($class))->newInstanceWithoutConstructor();
		$reflection = new ReflectionMethod($object, $method);
		$reflection->setAccessible(true);
		return $reflection->invoke($object, $value);
	}//end call()
}//end class
