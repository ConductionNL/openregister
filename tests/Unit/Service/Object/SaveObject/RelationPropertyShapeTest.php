<?php

/**
 * Unit tests for RelationPropertyShape.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Service\Object\SaveObject\RelationPropertyShape;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which property definitions are relations, and the UUID their values refer to.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\RelationPropertyShape
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\UuidReference
 */
class RelationPropertyShapeTest extends TestCase {

	/**
	 * Property definitions and whether each is a relation.
	 *
	 * @return array<string, array{0: array, 1: bool}>
	 */
	public static function shapes(): array {
		return [
			'ref' => [['$ref' => 'p'], true],
			'empty ref' => [['$ref' => ''], false],
			'uuid format' => [['format' => 'uuid'], true],
			'array of ref' => [['type' => 'array', 'items' => ['$ref' => 'p']], true],
			'array of uuid' => [['type' => 'array', 'items' => ['format' => 'uuid']], true],
			'array of strings' => [['type' => 'array', 'items' => ['type' => 'string']], false],
			'object with ref' => [['type' => 'object', '$ref' => 'p'], true],
			'object oneOf ref' => [['type' => 'object', 'items' => ['oneOf' => [[], ['$ref' => 'p']]]], true],
			'object oneOf without ref' => [['type' => 'object', 'items' => ['oneOf' => [['type' => 'string']]]], false],
			'array oneOf is not read' => [['type' => 'array', 'items' => ['oneOf' => [['$ref' => 'p']]]], false],
			'empty' => [[], false],
		];
	}//end shapes()

	/**
	 * Detect a relation.
	 *
	 * @param array $property The property definition.
	 * @param bool $expected Whether it is a relation.
	 *
	 * @return void
	 */
	#[DataProvider('shapes')]
	public function testIsRelation(array $property, bool $expected): void {
		$this->assertSame($expected, (new RelationPropertyShape($property))->isRelation());
	}//end testIsRelation()

	public function testUuidInReadsTheValueOnlyForARelation(): void {
		$this->assertSame('u-1', (new RelationPropertyShape(['$ref' => 'p']))->uuidIn(['id' => 'u-1']));
		$this->assertNull((new RelationPropertyShape(['type' => 'string']))->uuidIn('u-1'));
		$this->assertNull((new RelationPropertyShape(['format' => 'uuid']))->uuidIn(['x' => 'u-1']));
	}//end testUuidInReadsTheValueOnlyForARelation()
}//end class
