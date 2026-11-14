<?php

/**
 * OpenRegister ReferenceNormaliserTest
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

use Exception;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\ReferenceNormaliser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * A `$ref` given as an object or array is reduced to its id, at any depth.
 */
#[CoversClass(ReferenceNormaliser::class)]
#[UsesClass(Schema::class)]
final class ReferenceNormaliserTest extends TestCase {
	/**
	 * Normalise a schema with these properties and return them.
	 *
	 * @param array<string,mixed> $properties The properties.
	 *
	 * @return array<string,mixed>
	 */
	private static function normalised(array $properties): array {
		$schema = new Schema();
		$schema->setProperties($properties);
		(new ReferenceNormaliser())->normalise(schema: $schema);

		return $schema->getProperties();
	}//end normalised()

	public function testAnArrayOrObjectReferenceBecomesItsIdAndAScalarStays(): void {
		$object = new \stdClass();
		$object->id = 'org-uuid';

		self::assertSame(
			[
				'owner' => ['type' => 'object', '$ref' => 12],
				'organisation' => ['type' => 'object', '$ref' => 'org-uuid'],
				'kind' => ['type' => 'object', '$ref' => '#/components/schemas/kind'],
				'name' => ['type' => 'string'],
				'plain' => 'not a property array',
			],
			self::normalised(properties: [
				'owner' => ['type' => 'object', '$ref' => ['id' => 12, 'title' => 'Person']],
				'organisation' => ['type' => 'object', '$ref' => $object],
				'kind' => ['type' => 'object', '$ref' => '#/components/schemas/kind'],
				'name' => ['type' => 'string'],
				'plain' => 'not a property array',
			])
		);
	}//end testAnArrayOrObjectReferenceBecomesItsIdAndAScalarStays()

	public function testNestedItemsAndPropertiesAreNormalisedToo(): void {
		$properties = self::normalised(properties: [
			'members' => ['type' => 'array', 'items' => ['member' => ['$ref' => ['id' => 'person']]]],
			'address' => ['type' => 'object', 'properties' => ['city' => ['$ref' => ['id' => 5]]]],
		]);

		self::assertSame('person', $properties['members']['items']['member']['$ref']);
		self::assertSame(5, $properties['address']['properties']['city']['$ref']);
	}//end testNestedItemsAndPropertiesAreNormalisedToo()

	public function testAReferenceWithoutAnIdIsRefusedNamingTheProperty(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage("Schema property 'owner' has a \$ref that is not a string or empty: {\"title\":\"Person\"}");

		self::normalised(properties: ['owner' => ['$ref' => ['title' => 'Person']]]);
	}//end testAReferenceWithoutAnIdIsRefusedNamingTheProperty()
}//end class
