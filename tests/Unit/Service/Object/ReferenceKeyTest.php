<?php

/**
 * One reading of `referenceKey`, for the validator and the service that acts on it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\ReferenceKey;
use PHPUnit\Framework\TestCase;

/**
 * The key a reference cites its target by.
 *
 * @covers \OCA\OpenRegister\Service\Object\ReferenceKey
 */
class ReferenceKeyTest extends TestCase {

	/**
	 * What a property declares, and the key it means.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string|null}>
	 */
	public static function properties(): array {
		return [
			'a plain uuid reference' => [['type' => 'string', 'referenceSchema' => 'person'], null],
			'the literal uuid is a uuid reference' => [['referenceKey' => 'uuid'], null],
			'an empty key is a uuid reference' => [['referenceKey' => ''], null],
			'a blank key is a uuid reference' => [['referenceKey' => '   '], null],
			'a key that is no string is ignored' => [['referenceKey' => 12], null],
			'a key on the property' => [['referenceKey' => 'code'], 'code'],
			'a key is trimmed' => [['referenceKey' => ' code '], 'code'],
			'a key on the items of an array of references' => [['type' => 'array', 'items' => ['referenceKey' => 'code']], 'code'],
			'the property wins over its items' => [['referenceKey' => 'a', 'items' => ['referenceKey' => 'b']], 'a'],
		];
	}//end properties()

	/**
	 * Every shape reads to one key, or to none.
	 *
	 * @dataProvider properties
	 *
	 * @param array<string, mixed> $property The property.
	 * @param string|null          $expected The key.
	 *
	 * @return void
	 */
	public function testKeyOf(array $property, ?string $expected): void {
		$this->assertSame($expected, (new ReferenceKey())->keyOf(property: $property));
	}//end testKeyOf()
}//end class
