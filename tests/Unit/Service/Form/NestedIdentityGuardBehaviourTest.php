<?php

/**
 * A form submission cannot pick the id of an object it creates through a
 * cascading property: the guard strips `id` and `@self.id` at every depth of
 * a `$ref` or `inversedBy` property, on the property or its items, and leaves
 * every other property alone.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Form
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Form\NestedIdentityGuard;
use PHPUnit\Framework\TestCase;

/**
 * NestedIdentityGuard::strip().
 */
class NestedIdentityGuardBehaviourTest extends TestCase {

	private function schema(array $properties): Schema {
		$schema = new Schema();
		$schema->setProperties($properties);
		return $schema;
	}//end schema()

	public function testARefPropertyLosesItsIdAndSelfIdAtEveryDepth(): void {
		$schema = $this->schema(['address' => ['type' => 'object', '$ref' => 'address']]);
		$object = [
			'id' => 'top-stays',
			'address' => [
				'id' => 'chosen',
				'@self' => ['id' => 'chosen', 'register' => 'r'],
				'street' => 'Main',
				'owner' => ['id' => 'deep', 'name' => 'n', '@self' => ['id' => 'deep2']],
			],
		];

		$this->assertSame(
			[
				'id' => 'top-stays',
				'address' => [
					'@self' => ['register' => 'r'],
					'street' => 'Main',
					'owner' => ['name' => 'n', '@self' => []],
				],
			],
			(new NestedIdentityGuard())->strip($object, $schema)
		);
	}//end testARefPropertyLosesItsIdAndSelfIdAtEveryDepth()

	public function testAnArrayWhoseItemsCascadeLosesTheIdsOfEachItem(): void {
		$schema = $this->schema(
			[
				'lines' => ['type' => 'array', 'items' => ['$ref' => 'line']],
				'children' => ['type' => 'array', 'items' => ['inversedBy' => 'parent']],
			]
		);
		$object = [
			'lines' => [['id' => 'a', 'qty' => 1], ['id' => 'b', 'qty' => 2]],
			'children' => [['id' => 'c', '@self' => 'not-an-array']],
		];

		$this->assertSame(
			['lines' => [['qty' => 1], ['qty' => 2]], 'children' => [['@self' => 'not-an-array']]],
			(new NestedIdentityGuard())->strip($object, $schema)
		);
	}//end testAnArrayWhoseItemsCascadeLosesTheIdsOfEachItem()

	public function testAnInversedByPropertyCascades(): void {
		$schema = $this->schema(['parent' => ['type' => 'object', 'inversedBy' => 'children']]);

		$this->assertSame(
			['parent' => ['name' => 'p']],
			(new NestedIdentityGuard())->strip(['parent' => ['id' => 'x', 'name' => 'p']], $schema)
		);
	}//end testAnInversedByPropertyCascades()

	public function testNonCascadingScalarAndMissingPropertiesAreLeftAlone(): void {
		$schema = $this->schema(
			[
				'meta' => ['type' => 'object'],
				'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
				'ref' => ['$ref' => 'x'],
				'absent' => ['$ref' => 'y'],
				'weird' => 'not-a-definition',
			]
		);
		$object = [
			'meta' => ['id' => 'keep'],
			'tags' => [['id' => 'keep-too']],
			'ref' => 'uuid-string',
			'weird' => ['id' => 'keep-three'],
		];

		$this->assertSame($object, (new NestedIdentityGuard())->strip($object, $schema));
	}//end testNonCascadingScalarAndMissingPropertiesAreLeftAlone()
}//end class
