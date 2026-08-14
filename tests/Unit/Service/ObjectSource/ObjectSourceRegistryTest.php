<?php

/**
 * Unit tests for ObjectSourceRegistry.
 *
 * Covers:
 *  - addProvider() indexes providers by id and get() resolves them
 *  - duplicate id is rejected; first registration wins (warning logged)
 *  - withProviders() replaces the entire registered set (test seam)
 *  - list / listIds return expected shapes
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/object-source-providers/tasks.md#task-6.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Minimal stub object-source provider for registry tests.
 */
class _DummyObjectSource implements ObjectSourceProvider {

	public function __construct(
		private string $id,
		private bool $enabled = true,
	) {
	}//end __construct()

	public function getId(): string {
		return $this->id;
	}//end getId()

	public function isEnabled(): bool {
		return $this->enabled;
	}//end isEnabled()

	public function find(Register $register, Schema $schema, string $id, array $config = []): ?ObjectEntity {
		return null;
	}//end find()

	public function findAll(Register $register, Schema $schema, array $query = [], array $config = []): array {
		return [];
	}//end findAll()

	public function count(Register $register, Schema $schema, array $query = [], array $config = []): int {
		return 0;
	}//end count()
}//end class

/**
 * Test class for ObjectSourceRegistry.
 */
class ObjectSourceRegistryTest extends TestCase {

	/**
	 * addProvider() indexes by id and get() resolves it.
	 *
	 * @return void
	 */
	public function testAddAndResolve(): void {
		$registry = new ObjectSourceRegistry(new NullLogger());
		$this->assertTrue($registry->addProvider(new _DummyObjectSource('caldav-vtodo')));
		$this->assertSame('caldav-vtodo', $registry->get('caldav-vtodo')?->getId());
		$this->assertNull($registry->get('unknown'));
		$this->assertSame(['caldav-vtodo'], $registry->listIds());
		$this->assertCount(1, $registry->list());
	}//end testAddAndResolve()

	/**
	 * Duplicate id: first registration wins, second is rejected.
	 *
	 * @return void
	 */
	public function testDuplicateIdFirstWins(): void {
		$registry = new ObjectSourceRegistry(new NullLogger());
		$first = new _DummyObjectSource('caldav-vtodo', true);
		$second = new _DummyObjectSource('caldav-vtodo', false);

		$this->assertTrue($registry->addProvider($first));
		$this->assertFalse($registry->addProvider($second));
		$this->assertSame($first, $registry->get('caldav-vtodo'));
	}//end testDuplicateIdFirstWins()

	/**
	 * withProviders() replaces the entire registered set.
	 *
	 * @return void
	 */
	public function testWithProvidersReplacesSet(): void {
		$registry = new ObjectSourceRegistry(new NullLogger());
		$registry->addProvider(new _DummyObjectSource('a'));
		$registry->withProviders([new _DummyObjectSource('b'), new _DummyObjectSource('c')]);

		$this->assertNull($registry->get('a'));
		$this->assertSame(['b', 'c'], $registry->listIds());
	}//end testWithProvidersReplacesSet()
}//end class
