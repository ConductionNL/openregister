<?php

/**
 * OpenRegister PropertyMergeTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\SchemaMapper;

use OCA\OpenRegister\Db\SchemaMapper\PropertyMerge;
use PHPUnit\Framework\TestCase;

/**
 * A child schema's properties laid over its parent's.
 *
 * @covers \OCA\OpenRegister\Db\SchemaMapper\PropertyMerge
 */
final class PropertyMergeTest extends TestCase {
	/**
	 * Child keys win, parent-only properties stay, and a property's nested properties and items merge one level deep.
	 *
	 * @return void
	 */
	public function testTheChildIsLaidOverTheParentDeeply(): void {
		$parent = [
			'title'   => ['type' => 'string', 'maxLength' => 100, 'description' => 'Parent'],
			'address' => [
				'type'       => 'object',
				'properties' => ['street' => ['type' => 'string'], 'city' => ['type' => 'string']],
			],
			'tags'    => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['a']]],
			'kept'    => ['type' => 'integer'],
		];
		$child  = [
			'title'   => ['maxLength' => 50],
			'address' => ['properties' => ['city' => ['minLength' => 2], 'zip' => ['type' => 'string']]],
			'tags'    => ['items' => ['enum' => ['b']]],
			'added'   => ['type' => 'boolean'],
		];

		self::assertSame(
			[
				'title'   => ['type' => 'string', 'maxLength' => 50, 'description' => 'Parent'],
				'address' => [
					'type'       => 'object',
					'properties' => [
						'street' => ['type' => 'string'],
						// One level down a named sub-property is replaced, not merged.
						'city'   => ['minLength' => 2],
						'zip'    => ['type' => 'string'],
					],
				],
				'tags'    => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['b']]],
				'kept'    => ['type' => 'integer'],
				'added'   => ['type' => 'boolean'],
			],
			(new PropertyMerge())->merge($parent, $child)
		);
	}//end testTheChildIsLaidOverTheParentDeeply()

	/**
	 * A scalar on either side replaces rather than merges, and only properties/items nest.
	 *
	 * @return void
	 */
	public function testScalarsReplaceAndOnlyPropertiesAndItemsNest(): void {
		$merged = (new PropertyMerge())->merge(
			['a' => ['type' => 'string'], 'b' => 'legacy', 'c' => ['enum' => ['x', 'y'], 'properties' => 'odd']],
			['a' => 'replaced', 'b' => ['type' => 'integer'], 'c' => ['enum' => ['z'], 'properties' => ['p' => []]]]
		);

		self::assertSame('replaced', $merged['a']);
		self::assertSame(['type' => 'integer'], $merged['b']);
		self::assertSame(['enum' => ['z'], 'properties' => ['p' => []]], $merged['c']);
		self::assertSame([], (new PropertyMerge())->merge([], []));
	}//end testScalarsReplaceAndOnlyPropertiesAndItemsNest()
}//end class
