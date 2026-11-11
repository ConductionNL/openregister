<?php

/**
 * Characterisation of MappingMapper::updateFromArray()'s version bump and write order, and of
 * the constructor's handling of a cache that cannot be built.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden version bump, write order and cache start-up of MappingMapper.
 *
 * @covers \OCA\OpenRegister\Db\MappingMapper
 */
final class MappingMapperUpdateVersionGoldenTest extends TestCase {

	/**
	 * What the mapper did, in order.
	 *
	 * @var list<string>
	 */
	private array $steps = [];

	/**
	 * The stored mapping find() answers.
	 *
	 * @var Mapping|null
	 */
	private ?Mapping $stored = null;

	/**
	 * The current version, the given one, and the version that is written.
	 *
	 * @return array<string, array{0: string|null, 1: string|null, 2: string}>
	 */
	public static function versions(): array {
		return [
			'patch bumped' => ['1.2.3', null, '1.2.4'],
			'patch bumped from an empty given version' => ['1.2.9', '', '1.2.10'],
			'non-numeric patch counts from zero' => ['1.2.x', null, '1.2.1'],
			'four parts keep the rest' => ['1.2.3.4', null, '1.2.4.4'],
			'no patch part starts over' => ['1.2', null, '0.0.1'],
			'no current version starts over' => [null, null, '0.0.1'],
			'empty current version starts over' => ['', null, '0.0.1'],
			'a given version wins' => ['1.2.3', '5.0.0', '5.0.0'],
		];
	}//end versions()

	/**
	 * The written version follows from the current one unless one is given.
	 *
	 * @param string|null $current  The stored version.
	 * @param string|null $given    The version in the update, if any.
	 * @param string      $expected The written version.
	 *
	 * @return void
	 *
	 * @dataProvider versions
	 */
	public function testVersionOnUpdate(?string $current, ?string $given, string $expected): void {
		$this->stored = new Mapping();
		$this->stored->setId(5);
		$this->stored->setUuid('m-uuid');
		$this->stored->setVersion($current);
		$data = ['name' => 'renamed', 'uuid' => 'other', 'organisation' => 'other-org', 'created' => new DateTime('2001-01-01')];
		if ($given !== null) {
			$data['version'] = $given;
		}

		$saved = $this->mapper()->updateFromArray(5, $data);

		$this->assertSame($expected, $saved->getVersion());
		$this->assertSame('renamed', $saved->getName());
		$this->assertSame('m-uuid', $saved->getUuid());
		$this->assertInstanceOf(DateTime::class, $saved->getUpdated());
		$this->assertSame(['rbac update mapping', 'find 5', 'organisation access 5', 'update 5'], $this->steps);
	}//end testVersionOnUpdate()

	/**
	 * A refused RBAC check stops the update before the row is read.
	 *
	 * @return void
	 */
	public function testRefusedUpdateReadsNothing(): void {
		try {
			$this->mapper(refuse: true)->updateFromArray(5, ['name' => 'x']);
			$this->fail('The update was let through.');
		} catch (RuntimeException $e) {
			$this->assertSame('refused', $e->getMessage());
		}

		$this->assertSame(['rbac update mapping'], $this->steps);
	}//end testRefusedUpdateReadsNothing()

	/**
	 * A cache that cannot be built is logged once as a warning, and the mapper still works.
	 *
	 * @return void
	 */
	public function testUnavailableCacheIsLogged(): void {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willThrowException(new Exception('no redis'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[MappingMapper] Failed to initialize distributed cache',
			['error' => 'no redis']
		);

		$this->assertInstanceOf(MappingMapper::class, new MappingMapper($this->createMock(IDBConnection::class), $this->tenancy(), $factory, $logger));
	}//end testUnavailableCacheIsLogged()

	/**
	 * A mapper whose guards, read and write are recorded.
	 *
	 * @param bool $refuse Whether the RBAC check refuses.
	 *
	 * @return MappingMapper
	 */
	private function mapper(bool $refuse=false): MappingMapper {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($this->createMock(ICache::class));
		$mapper = $this->getMockBuilder(MappingMapper::class)
			->setConstructorArgs([$this->createMock(IDBConnection::class), $this->tenancy(), $factory, $this->createMock(LoggerInterface::class)])
			->onlyMethods(['verifyRbacPermission', 'find', 'verifyOrganisationAccess', 'update'])
			->getMock();
		$mapper->method('verifyRbacPermission')->willReturnCallback(
			function (string $action, string $entityType) use ($refuse): void {
				$this->steps[] = 'rbac ' . $action . ' ' . $entityType;
				if ($refuse === true) {
					throw new RuntimeException('refused');
				}
			}
		);
		$mapper->method('find')->willReturnCallback(
			function (int|string $id): Mapping {
				$this->steps[] = 'find ' . $id;
				return ($this->stored ?? new Mapping());
			}
		);
		$mapper->method('verifyOrganisationAccess')->willReturnCallback(
			function (Mapping $entity): void {
				$this->steps[] = 'organisation access ' . $entity->getId();
			}
		);
		$mapper->method('update')->willReturnCallback(
			function (Mapping $entity): Mapping {
				$this->steps[] = 'update ' . $entity->getId();
				return $entity;
			}
		);

		return $mapper;
	}//end mapper()

	/**
	 * Tenancy collaborators.
	 *
	 * @return TenancyServices
	 */
	private function tenancy(): TenancyServices {
		return new TenancyServices(
			$this->createMock(OrganisationMapper::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class)
		);
	}//end tenancy()
}//end class
