<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\SaveObjects\BulkRelationScanner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins RelationDetectionTrait::isSchemaDeclaredReference() on a table of property definitions.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObjects\BulkRelationScanner
 */
class SchemaDeclaredReferenceTest extends TestCase {

	/**
	 * @return array<string, array{?array<string, mixed>, bool}>
	 */
	public static function definitions(): array {
		return [
			'unknown'          => [null, false],
			'empty'            => [[], false],
			'object'           => [['type' => 'object'], true],
			'text uuid'        => [['type' => 'text', 'format' => 'uuid'], true],
			'text uri'         => [['type' => 'text', 'format' => 'uri'], true],
			'text url'         => [['type' => 'text', 'format' => 'url'], true],
			'text email'       => [['type' => 'text', 'format' => 'email'], false],
			'text no format'   => [['type' => 'text'], false],
			'string uuid'      => [['type' => 'string', 'format' => 'uuid'], false],
			'ref'              => [['type' => 'string', '$ref' => '#/x'], true],
			'inversedBy'       => [['inversedBy' => 'parent'], true],
			'null ref'         => [['$ref' => null], false],
			'array of objects' => [['type' => 'array', 'items' => ['type' => 'object']], false],
		];
	}//end definitions()

	/**
	 * @dataProvider definitions
	 */
	public function testDeclaredReference(?array $definition, bool $expected): void {
		$object = (new ReflectionClass(BulkRelationScanner::class))->newInstanceWithoutConstructor();
		$method = new ReflectionMethod($object, 'isSchemaDeclaredReference');
		$method->setAccessible(true);
		$this->assertSame($expected, $method->invoke($object, $definition));
	}//end testDeclaredReference()
}//end class
