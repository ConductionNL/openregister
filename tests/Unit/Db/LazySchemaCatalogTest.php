<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\LazySchemaCatalog;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The lazy schema catalogue builds a mapper only when its accessor is called,
 * and hands out the container's instance.
 *
 * @spec exclude ADR-011 constructor aggregate, container plumbing with no behaviour of its own
 */
class LazySchemaCatalogTest extends TestCase {

	/**
	 * Constructing it asks the container for nothing.
	 *
	 * @return void
	 */
	public function testConstructingItBuildsNoMapper(): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$this->assertInstanceOf(expected: SchemaCatalogInterface::class, actual: new LazySchemaCatalog(container: $container));
	}//end testConstructingItBuildsNoMapper()

	/**
	 * Each accessor answers the container's mapper for its contract, and only that one.
	 *
	 * @return void
	 */
	public function testEachAccessorResolvesOnlyItsOwnMapper(): void {
		$registers = $this->createMock(originalClassName: RegisterMapperInterface::class);
		$schemas   = $this->createMock(originalClassName: SchemaMapperInterface::class);
		$tables    = $this->createMock(originalClassName: MagicMapperInterface::class);
		$asked     = [];
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(callback:
			static function (string $id) use (&$asked, $registers, $schemas, $tables) {
				$asked[] = $id;
				return [
					RegisterMapperInterface::class => $registers,
					SchemaMapperInterface::class   => $schemas,
					MagicMapperInterface::class    => $tables,
				][$id];
			}
		);
		$catalog = new LazySchemaCatalog(container: $container);

		$this->assertSame(expected: $schemas, actual: $catalog->schemas());
		$this->assertSame(expected: [SchemaMapperInterface::class], actual: $asked);
		$this->assertSame(expected: $registers, actual: $catalog->registers());
		$this->assertSame(expected: $tables, actual: $catalog->tables());
		$this->assertSame(expected: [SchemaMapperInterface::class, RegisterMapperInterface::class, MagicMapperInterface::class], actual: $asked);
	}//end testEachAccessorResolvesOnlyItsOwnMapper()
}//end class
