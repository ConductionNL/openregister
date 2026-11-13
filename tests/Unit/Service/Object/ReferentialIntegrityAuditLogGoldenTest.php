<?php

/**
 * Golden test: the restrict-block audit row, the retained-record partition and the audit fail-soft.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\RefusedDeletion;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Dto\DeletionAnalysis;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Service\Object\IntegrityCascadeControls;
use OCA\OpenRegister\Service\Object\IntegrityRelationIndex;
use OCA\OpenRegister\Service\Object\IntegrityStorage;
use OCA\OpenRegister\Service\Object\ReferentialIntegrityService;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\MagicMapperInterface;

/**
 * @covers \OCA\OpenRegister\Service\Object\ReferentialIntegrityService
 * @uses \OCA\OpenRegister\Db\AuditTrail
 * @uses \OCA\OpenRegister\Db\SchemaCatalog
 * @uses \OCA\OpenRegister\Dto\DeletionAnalysis
 * @uses \OCA\OpenRegister\Dto\DeletionEffects
 * @uses \OCA\OpenRegister\Service\Object\IntegrityAuditEntry
 * @uses \OCA\OpenRegister\Service\Object\IntegrityCascadeControls
 * @uses \OCA\OpenRegister\Service\Object\IntegrityRelationIndex
 * @uses \OCA\OpenRegister\Service\Object\IntegrityStorage
 * @uses \OCA\OpenRegister\Service\Object\RefusedDeletion
 * @uses \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses \OCA\OpenRegister\Kernel\Contracts\ListQuery
 */
class ReferentialIntegrityAuditLogGoldenTest extends TestCase {

	private array $inserted = [];

	private array $logs = [];

	public function testARestrictBlockIsAuditedWithItsFirstBlocker(): void {
		$analysis = new DeletionAnalysis(
			deletable: false,
			blockers: [
				['schema' => 7, 'property' => 'owner'],
				['schema' => 7, 'property' => 'case'],
				['property' => 'other'],
			]
		);

		$this->service()->logRestrictBlock(new RefusedDeletion(objectUuid: 'obj-1', schemaId: '12', userId: 'anna'), $analysis);

		$this->assertCount(1, $this->inserted);
		$row = $this->inserted[0];
		$this->assertSame('referential_integrity.restrict_blocked', $row->getAction());
		$this->assertSame('obj-1', $row->getObjectUuid());
		$this->assertSame('anna', $row->getUser());
		$this->assertSame(12, $row->getSchema());
		$this->assertNull($row->getRegister());
		$this->assertSame(
			['blockerCount' => 3, 'blockerSchema' => 7, 'blockerProperty' => 'owner', 'reason' => 'RESTRICT constraint prevents deletion'],
			$row->getChanged()
		);
	}//end testARestrictBlockIsAuditedWithItsFirstBlocker()

	public function testARestrictBlockWithoutBlockersNamesNothing(): void {
		$this->service()->logRestrictBlock(
			new RefusedDeletion(objectUuid: 'obj-2', schemaId: null, userId: 'bob'),
			new DeletionAnalysis(deletable: false)
		);

		$this->assertSame(
			['blockerCount' => 0, 'blockerSchema' => 'unknown', 'blockerProperty' => 'unknown', 'reason' => 'RESTRICT constraint prevents deletion'],
			$this->inserted[0]->getChanged()
		);
		$this->assertNull($this->inserted[0]->getSchema());
	}//end testARestrictBlockWithoutBlockersNamesNothing()

	public function testAFailedAuditInsertIsLoggedNotThrown(): void {
		$this->service(insertFails: true)->logRestrictBlock(
			new RefusedDeletion(objectUuid: 'obj-3', schemaId: '5', userId: 'anna'),
			new DeletionAnalysis(deletable: false)
		);

		$this->assertSame(
			[[
				'warning',
				'[ReferentialIntegrity] Failed to create audit trail entry for integrity action',
				['action' => 'referential_integrity.restrict_blocked', 'objectUuid' => 'obj-3', 'error' => 'insert failed'],
			]],
			$this->logs
		);
	}//end testAFailedAuditInsertIsLoggedNotThrown()

	public function testRetainedTargetsAreReportedOnceAndLogged(): void {
		$targets = [
			['objectUuid' => 'keep-1', 'schema' => '77'],
			['objectUuid' => '', 'schema' => '77'],
			['objectUuid' => 'go-1', 'schema' => '10'],
			['objectUuid' => 'keep-1', 'schema' => '77', 'property' => 'second'],
			['schema' => '77'],
		];

		$partition = new ReflectionMethod(ReferentialIntegrityService::class, 'partitionRetainedTargets');
		[$proceed, $retained] = $partition->invoke($this->service(), $targets);

		$this->assertSame([$targets[1], $targets[2], $targets[4]], $proceed);
		$this->assertSame([['uuid' => 'keep-1', 'ground' => 'archival']], $retained);
		$this->assertSame(
			[['info', '[ReferentialIntegrity] Kept a retained record out of a cascade', ['uuid' => 'keep-1', 'schema' => '77', 'ground' => 'archival']]],
			$this->logs
		);
	}//end testRetainedTargetsAreReportedOnceAndLogged()

	private function service(bool $insertFails = false): ReferentialIntegrityService {
		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('insert')->willReturnCallback(function (AuditTrail $row) use ($insertFails): AuditTrail {
			if ($insertFails === true) {
				throw new RuntimeException('insert failed');
			}

			$this->inserted[] = $row;
			return $row;
		});

		$guard = $this->createMock(ArchivalRetentionGuard::class);
		$guard->method('cascadeRefusal')->willReturnCallback(
			static fn (string $uuid, $schema): ?array => ((string) $schema === '77' ? ['uuid' => $uuid, 'ground' => 'archival'] : null)
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function ($message, $context = []) use ($level): void {
				unset($context['file'], $context['line']);
				$this->logs[] = [$level, $message, $context];
			});
		}

		return new ReferentialIntegrityService(
			new IntegrityRelationIndex(catalog: new SchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $this->createMock(SchemaMapper::class), tables: $this->createMock(MagicMapperInterface::class)), cacheFactory: $this->createMock(ICacheFactory::class)),
			new IntegrityStorage($this->createMock(MagicMapper::class), $this->createMock(IDBConnection::class)),
			new IntegrityCascadeControls($audit, $guard),
			$logger
		);
	}//end service()
}//end class
