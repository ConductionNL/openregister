<?php

/**
 * Regression test for MagicMapper::getResolvedRegisterAndSchema().
 *
 * Guards against the stale-signature bug where the helper called
 * RegisterMapper::find()/SchemaMapper::find() with a `null` second positional
 * argument (the `bool $_rbac` parameter), which threw a TypeError that the
 * surrounding try/catch swallowed — leaving register/schema unresolved and
 * 500-ing the files-attached-to-object endpoint. The fix passes the named
 * `_rbac: false, _multitenancy: false` arguments (internal resolution).
 *
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\SettingsService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class MagicMapperResolveContextTest extends TestCase {

	private IDBConnection&MockObject $db;

	private SchemaMapper&MockObject $schemaMapper;

	private RegisterMapper&MockObject $registerMapper;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build a MagicMapper with the shared mock dependencies.
	 *
	 * @return MagicMapper
	 */
	private function makeMapper(): MagicMapper {
		return new MagicMapper(
			$this->db,
			$this->schemaMapper,
			$this->registerMapper,
			$this->createMock(IConfig::class),
			$this->createMock(IEventDispatcher::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IAppConfig::class),
			$this->logger,
			$this->createMock(SettingsService::class),
			$this->makeContainer()
		);
	}//end makeMapper()

	/**
	 * Build a DI container resolving the collaborators MagicMapper lazily fetches.
	 *
	 * @return ContainerInterface&MockObject
	 */
	private function makeContainer(): ContainerInterface {
		$dateTimeNormalizer = $this->createMock(DateTimeNormalizer::class);
		$conditionMatcher = $this->createMock(\OCA\OpenRegister\Service\ConditionMatcher::class);
		$schemaTypeConverter = $this->createMock(\OCA\OpenRegister\Service\Object\SchemaTypeConverter::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($dateTimeNormalizer, $conditionMatcher, $schemaTypeConverter) {
				if ($id === DateTimeNormalizer::class) {
					return $dateTimeNormalizer;
				}
				if ($id === \OCA\OpenRegister\Service\ConditionMatcher::class) {
					return $conditionMatcher;
				}
				if ($id === \OCA\OpenRegister\Service\Object\SchemaTypeConverter::class) {
					return $schemaTypeConverter;
				}
				return null;
			}
		);
		return $container;
	}//end makeContainer()

	/**
	 * Invoke the private getResolvedRegisterAndSchema() helper via reflection.
	 *
	 * @param MagicMapper $mapper The mapper under test.
	 * @param ObjectEntity $entity The object whose register/schema to resolve.
	 *
	 * @return array{0: ?Register, 1: ?Schema}
	 */
	private function resolve(MagicMapper $mapper, ObjectEntity $entity): array {
		$method = new \ReflectionMethod(MagicMapper::class, 'getResolvedRegisterAndSchema');
		$method->setAccessible(true);
		return $method->invoke($mapper, $entity, null, null);
	}//end resolve()

	/**
	 * The helper resolves register + schema from the entity using internal
	 * (rbac=false, multitenancy=false) lookups. With the pre-fix `null` second
	 * positional argument this threw a swallowed TypeError and returned nulls.
	 *
	 * @return void
	 */
	public function testResolvesRegisterAndSchemaWithInternalLookup(): void {
		$register = $this->createMock(Register::class);
		$schema = $this->createMock(Schema::class);

		// find() params are typed bool; passing null (the old bug) would throw
		// a TypeError on these mocks. Matching the booleans proves the fix.
		$this->registerMapper->expects($this->once())
			->method('find')
			->with(5, false, false)
			->willReturn($register);

		$this->schemaMapper->expects($this->once())
			->method('find')
			->with(7, [], false, false)
			->willReturn($schema);

		// No warning should be logged on the happy path.
		$this->logger->expects($this->never())->method('warning');

		$entity = new ObjectEntity();
		$entity->setRegister(5);
		$entity->setSchema(7);

		[$resolvedRegister, $resolvedSchema] = $this->resolve($this->makeMapper(), $entity);

		$this->assertSame($register, $resolvedRegister);
		$this->assertSame($schema, $resolvedSchema);
	}//end testResolvesRegisterAndSchemaWithInternalLookup()
}//end class
