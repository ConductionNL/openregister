<?php

/**
 * The property read question a filter has to ask.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Query
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Query;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Query\PropertyReadGuard;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * `PropertyReadGuard`.
 *
 * @covers \OCA\OpenRegister\Service\Query\PropertyReadGuard
 * @uses \OCA\OpenRegister\Db\Schema
 */
class PropertyReadGuardTest extends TestCase {

	/**
	 * A schema, with or without a property that carries an authorization block.
	 *
	 * @param bool $governed Whether a property carries authorization.
	 *
	 * @return Schema The schema.
	 */
	private function schema(bool $governed): Schema {
		$schema = new Schema();
		$schema->setId(3);
		$properties = ['name' => ['type' => 'string']];
		if ($governed === true) {
			$properties['salary'] = ['type' => 'number', 'authorization' => ['read' => ['hr']]];
		}

		$schema->setProperties($properties);

		return $schema;
	}//end schema()

	/**
	 * A schema without property authorization asks nobody and admits.
	 *
	 * @return void
	 */
	public function testASchemaWithoutPropertyAuthorizationAdmitsEveryProperty(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$guard = new PropertyReadGuard(container: $container, logger: new NullLogger());

		$this->assertTrue($guard->mayRead(schema: $this->schema(governed: false), property: 'name'));
	}//end testASchemaWithoutPropertyAuthorizationAdmitsEveryProperty()

	/**
	 * The property read rule decides, asked with an empty object.
	 *
	 * @return void
	 */
	public function testTheReadRuleDecidesWithAnEmptyObject(): void {
		$schema = $this->schema(governed: true);
		$rbac   = $this->createMock(PropertyRbacHandler::class);
		$rbac->expects($this->exactly(2))->method('canReadProperty')
			->with($schema, $this->anything(), [])
			->willReturnCallback(static fn (Schema $s, string $property): bool => $property !== 'salary');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(PropertyRbacHandler::class)->willReturn($rbac);

		$guard = new PropertyReadGuard(container: $container, logger: new NullLogger());

		$this->assertTrue($guard->mayRead(schema: $schema, property: 'name'));
		$this->assertFalse($guard->mayRead(schema: $schema, property: 'salary'));
	}//end testTheReadRuleDecidesWithAnEmptyObject()

	/**
	 * Fails closed: no container, or a handler that throws, withholds the property.
	 *
	 * @return void
	 */
	public function testItFailsClosed(): void {
		$schema = $this->schema(governed: true);

		$this->assertFalse(
			(new PropertyReadGuard(container: null, logger: new NullLogger()))->mayRead(schema: $schema, property: 'salary')
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('no handler'));

		$this->assertFalse(
			(new PropertyReadGuard(container: $container, logger: new NullLogger()))->mayRead(schema: $schema, property: 'salary')
		);
	}//end testItFailsClosed()
}//end class
