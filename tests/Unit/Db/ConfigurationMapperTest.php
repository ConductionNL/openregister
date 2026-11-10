<?php

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCA\OpenRegister\Service\Registers\EntityEventPublisher;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ConfigurationMapper
 *
 * Tests construction with mocked dependencies. Most methods in this mapper
 * are DB-heavy (findAll, find, insert, update, delete) and require integration tests.
 * We verify the mapper can be instantiated and its table name is correct.
 */
class ConfigurationMapperTest extends TestCase {
	private IDBConnection&MockObject $db;
	private OrganisationMapper&MockObject $organisationMapper;
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;
	private IEventDispatcher&MockObject $eventDispatcher;
	private ConfigurationMapper $mapper;

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->organisationMapper = $this->createMock(OrganisationMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);

		$this->mapper = new ConfigurationMapper(
			$this->db,
			new TenancyServices($this->organisationMapper, $this->userSession, $this->groupManager),
			new EntityEventPublisher(dispatcher: $this->eventDispatcher)
		);
	}

	// -------------------------------------------------------------------------
	// Construction
	// -------------------------------------------------------------------------

	public function testGetTableNameReturnsCorrectValue(): void {
		$this->assertStringContainsString('openregister_configurations', $this->mapper->getTableName());
	}
}
