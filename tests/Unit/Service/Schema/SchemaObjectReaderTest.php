<?php

declare(strict_types=1);

/**
 * SchemaObjectReader Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schema
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Schema;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\Schema\SchemaObjectReader;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Covers the schema-migration read helper extracted from SchemaMigrationController.
 */
class SchemaObjectReaderTest extends TestCase {
	private RegisterMapper $registerMapper;
	private IRequest $request;

	protected function setUp(): void {
		parent::setUp();
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->request = $this->createMock(IRequest::class);
	}

	private function reader(?MagicMapper $objects): SchemaObjectReader {
		return new SchemaObjectReader($this->registerMapper, $this->request, $objects);
	}

	public function testCanReadValuesReflectsObjectMapperPresence(): void {
		$this->assertFalse($this->reader(null)->canReadValues());
		$this->assertTrue($this->reader($this->createMock(MagicMapper::class))->canReadValues());
	}

	public function testResolveRegisterIdHonoursExplicitOverride(): void {
		$this->request->method('getParam')->with('registerId')->willReturn('7');

		$this->assertSame(7, $this->reader(null)->resolveRegisterId(42));
	}

	public function testResolveRegisterIdFindsRegisterContainingSchema(): void {
		$this->request->method('getParam')->willReturn(null);
		$register = new Register();
		$register->setId(9);
		$register->setSchemas([1, 42, 3]);
		$this->registerMapper->method('findAll')->willReturn([$register]);

		$this->assertSame(9, $this->reader(null)->resolveRegisterId(42));
	}

	public function testResolveRegisterIdReturnsNullWhenNoRegisterMatches(): void {
		$this->request->method('getParam')->willReturn(null);
		$register = new Register();
		$register->setId(1);
		$register->setSchemas([1, 2]);
		$this->registerMapper->method('findAll')->willReturn([$register]);

		$this->assertNull($this->reader(null)->resolveRegisterId(42));
	}

	public function testStoredValuesEmptyWhenNoObjectMapper(): void {
		$this->assertSame([], $this->reader(null)->storedValues(42, 'name'));
	}

	public function testStoredValuesEmptyWhenRegisterUnresolved(): void {
		$this->request->method('getParam')->willReturn(null);
		$this->registerMapper->method('findAll')->willReturn([]);
		$objects = $this->createMock(MagicMapper::class);

		$this->assertSame([], $this->reader($objects)->storedValues(42, 'name'));
	}

	public function testStoredValuesCollectsPropertyValues(): void {
		$this->request->method('getParam')->willReturn('5');
		$objects = $this->createMock(MagicMapper::class);
		$a = $this->createMock(ObjectEntity::class);
		$a->method('getObject')->willReturn(['name' => 'Alpha']);
		$b = $this->createMock(ObjectEntity::class);
		$b->method('getObject')->willReturn(['other' => 'x']);
		$objects->method('searchObjects')->willReturn([$a, $b]);

		$this->assertSame(['Alpha', null], $this->reader($objects)->storedValues(5, 'name'));
	}

	public function testStoredValuesEmptyWhenSearchThrows(): void {
		$this->request->method('getParam')->willReturn('5');
		$objects = $this->createMock(MagicMapper::class);
		$objects->method('searchObjects')->willThrowException(new \RuntimeException('boom'));

		$this->assertSame([], $this->reader($objects)->storedValues(5, 'name'));
	}
}
