<?php

/**
 * Unit tests for the guard that strips nested identities under cascading properties.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Form
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Form\NestedIdentityGuard;
use PHPUnit\Framework\TestCase;

/**
 * A nested `id` / `@self.id` under a cascading property never survives; other data is untouched.
 *
 * @covers \OCA\OpenRegister\Service\Form\NestedIdentityGuard
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class NestedIdentityGuardTest extends TestCase {

	/**
	 * A schema with the given property definitions.
	 *
	 * @param array<string, mixed> $properties The property definitions.
	 *
	 * @return Schema The schema.
	 */
	private function schema(array $properties): Schema {
		$schema = new Schema();
		$schema->setProperties($properties);

		return $schema;
	}//end schema()

	/**
	 * A `$ref` property loses its nested `id` and `@self.id`, at every depth.
	 *
	 * @return void
	 */
	public function testARefPropertyLosesEveryNestedIdentity(): void {
		$schema = $this->schema(['contact' => ['type' => 'object', '$ref' => 'person']]);
		$object = [
			'id'      => 'top-stays',
			'contact' => [
				'id'      => 'existing-uuid',
				'name'    => 'Jan',
				'@self'   => ['id' => 'self-uuid', 'schema' => 'person'],
				'address' => ['id' => 'deep-uuid', 'street' => 'Dorpsstraat'],
			],
		];

		$this->assertSame(
			[
				'id'      => 'top-stays',
				'contact' => [
					'name'    => 'Jan',
					'@self'   => ['schema' => 'person'],
					'address' => ['street' => 'Dorpsstraat'],
				],
			],
			(new NestedIdentityGuard())->strip(object: $object, schema: $schema)
		);
	}//end testARefPropertyLosesEveryNestedIdentity()

	/**
	 * An array whose `items` cascade (`inversedBy`) loses the identity of each element.
	 *
	 * @return void
	 */
	public function testAListWhoseItemsCascadeLosesEachElementsIdentity(): void {
		$schema = $this->schema(['members' => ['type' => 'array', 'items' => ['type' => 'object', 'inversedBy' => 'group']]]);
		$object = ['members' => [['id' => 'a', 'role' => 'chair'], ['@self' => ['id' => 'b'], 'role' => 'member']]];

		$this->assertSame(
			['members' => [['role' => 'chair'], ['@self' => [], 'role' => 'member']]],
			(new NestedIdentityGuard())->strip(object: $object, schema: $schema)
		);
	}//end testAListWhoseItemsCascadeLosesEachElementsIdentity()

	/**
	 * An `inversedBy` on the property itself also cascades.
	 *
	 * @return void
	 */
	public function testAnInversedByOnThePropertyCascades(): void {
		$schema = $this->schema(['owner' => ['type' => 'object', 'inversedBy' => 'things']]);

		$this->assertSame(
			['owner' => ['name' => 'x']],
			(new NestedIdentityGuard())->strip(object: ['owner' => ['id' => 'o-1', 'name' => 'x']], schema: $schema)
		);
	}//end testAnInversedByOnThePropertyCascades()

	/**
	 * Data under a property that does not cascade keeps its identity keys.
	 *
	 * @return void
	 */
	public function testANonCascadingPropertyIsLeftAlone(): void {
		$schema = $this->schema(
			[
				'meta'  => ['type' => 'object'],
				'tags'  => ['type' => 'array', 'items' => ['type' => 'string']],
			]
		);
		$object = ['meta' => ['id' => 'keep', '@self' => ['id' => 'keep-too']], 'tags' => ['a', 'b']];

		$this->assertSame($object, (new NestedIdentityGuard())->strip(object: $object, schema: $schema));
	}//end testANonCascadingPropertyIsLeftAlone()

	/**
	 * A scalar value under a cascading property, a property missing from the
	 * object, and a non-array definition are passed through unchanged.
	 *
	 * @return void
	 */
	public function testScalarsMissingValuesAndOddDefinitionsPassThrough(): void {
		$schema = $this->schema(
			[
				'ref'     => ['$ref' => 'person'],
				'absent'  => ['$ref' => 'person'],
				'strange' => 'not-a-definition',
			]
		);
		$object = ['ref' => 'uuid-as-string', 'strange' => ['id' => 'kept']];

		$this->assertSame($object, (new NestedIdentityGuard())->strip(object: $object, schema: $schema));
	}//end testScalarsMissingValuesAndOddDefinitionsPassThrough()
}//end class
