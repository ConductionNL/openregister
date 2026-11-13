<?php

/**
 * OpenRegister PropertyDeltaTest
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

use OCA\OpenRegister\Db\SchemaMapper\PropertyDelta;
use PHPUnit\Framework\TestCase;

/**
 * What a child schema adds to or changes in its parent's properties.
 *
 * @covers \OCA\OpenRegister\Db\SchemaMapper\PropertyDelta
 */
final class PropertyDeltaTest extends TestCase {
	/**
	 * Only new or changed properties and keys remain, recursively through nested properties.
	 *
	 * @return void
	 */
	public function testOnlyNewOrChangedPartsRemain(): void {
		$parent = [
			'title'   => ['type' => 'string', 'maxLength' => 100],
			'same'    => ['type' => 'integer'],
			'address' => [
				'type'       => 'object',
				'properties' => ['street' => ['type' => 'string'], 'city' => ['type' => 'string']],
			],
		];
		$child  = [
			'title'   => ['type' => 'string', 'maxLength' => 50],
			'same'    => ['type' => 'integer'],
			'address' => [
				'type'       => 'object',
				'properties' => ['street' => ['type' => 'string'], 'city' => ['type' => 'string', 'minLength' => 2]],
			],
			'new'     => ['type' => 'boolean'],
		];

		self::assertSame(
			[
				'title'   => ['maxLength' => 50],
				'address' => ['properties' => ['city' => ['minLength' => 2]]],
				'new'     => ['type' => 'boolean'],
			],
			(new PropertyDelta())->between($parent, $child)
		);
	}//end testOnlyNewOrChangedPartsRemain()

	/**
	 * A scalar change is kept whole; an identical child has no delta; a null parent value counts as absent.
	 *
	 * @return void
	 */
	public function testScalarsIdenticalAndNullParents(): void {
		$delta = new PropertyDelta();

		self::assertSame(['a' => 'x'], $delta->between(['a' => ['type' => 'string']], ['a' => 'x']));
		self::assertSame([], $delta->between(['a' => ['type' => 'string']], ['a' => ['type' => 'string']]));
		self::assertSame(['a' => null], $delta->between(['a' => null], ['a' => null]));
		self::assertSame(
			['o' => ['properties' => ['p' => 1]]],
			$delta->between(['o' => ['properties' => 'scalar']], ['o' => ['properties' => ['p' => 1]]])
		);
	}//end testScalarsIdenticalAndNullParents()
}//end class
