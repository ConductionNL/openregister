<?php

/**
 * Integrity and file audit rows take their expiry from the object's retention.
 *
 * Object audit rows stopped expiring after a flat 30 days in or#2265: their
 * expiry now follows the retention of the object they describe, and `null`
 * keeps a row. Two other writers were not moved. Every row
 * `ReferentialIntegrityService::logIntegrityAction()` writes (set_null,
 * set_default, restrict_blocked, the per-object cascade_delete) and every
 * file audit row `FileAuditHandler` writes still carried `+30 days`, so the
 * evidence of why a reference was cleared, or which file was renamed on a
 * record under legal hold, was purged a month later (or#4101).
 *
 * The mapper under test is REAL down to the retention resolver; only its
 * `insert()` is replaced, to capture the row instead of touching a database.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Dto\DeletionAnalysis;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Service\AuditRetentionResolver;
use OCA\OpenRegister\Service\File\FileAuditHandler;
use OCA\OpenRegister\Service\Object\ReferentialIntegrityService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Object\ReferentialIntegrityService
 * @covers \OCA\OpenRegister\Service\File\FileAuditHandler
 * @covers \OCA\OpenRegister\Db\AuditTrailMapper
 * @uses \OCA\OpenRegister\Db\AuditTrail
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Dto\DeletionAnalysis
 * @uses \OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard
 * @uses \OCA\OpenRegister\Service\AuditRetentionResolver
 */
class IntegrityAndFileAuditExpiryTest extends TestCase {

	/**
	 * The rows the mapper was asked to insert.
	 *
	 * @var AuditTrail[]
	 */
	private array $inserted = [];

	/**
	 * The object mapper the integrity service looks the object up through.
	 *
	 * @var MagicMapper&MockObject
	 */
	private MagicMapper $objectMapper;

	/**
	 * Build the real mapper with only insert() captured.
	 *
	 * @return AuditTrailMapper
	 */
	private function mapper(): AuditTrailMapper {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willThrowException(new \RuntimeException('no schema'));

		$resolverContainer = $this->createMock(ContainerInterface::class);
		$resolverContainer->method('get')->willReturnCallback(
			static function (string $id) use ($schemaMapper): object {
				if ($id === SchemaMapper::class) {
					return $schemaMapper;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);
		$resolver = new AuditRetentionResolver($resolverContainer, new NullLogger());

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($resolver): object {
				if ($id === AuditRetentionResolver::class) {
					return $resolver;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		$mapper = $this->getMockBuilder(AuditTrailMapper::class)
			->setConstructorArgs(
				[
					$this->createMock(IDBConnection::class),
					$container,
					$this->createMock(IUserSession::class),
					$this->createMock(IRequest::class),
					$this->createMock(LoggerInterface::class),
				]
			)
			->onlyMethods(['insert'])
			->getMock();
		$mapper->method('insert')->willReturnCallback(
			function (AuditTrail $row): AuditTrail {
				$this->inserted[] = $row;
				return $row;
			}
		);

		return $mapper;

	}//end mapper()

	/**
	 * The integrity service over the real mapper.
	 *
	 * @return ReferentialIntegrityService
	 */
	private function integrityService(): ReferentialIntegrityService {
		$this->objectMapper = $this->createMock(MagicMapper::class);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));

		return new ReferentialIntegrityService(
			$this->createMock(SchemaMapper::class),
			$this->createMock(RegisterMapper::class),
			$this->objectMapper,
			$this->mapper(),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IDBConnection::class),
			$cacheFactory,
			new ArchivalRetentionGuard($this->createMock(SchemaMapper::class), $this->createMock(LoggerInterface::class))
		);

	}//end integrityService()

	/**
	 * An object carrying the given retention block.
	 *
	 * @param array $retention The retention column.
	 *
	 * @return ObjectEntity
	 */
	private function object(array $retention): ObjectEntity {
		$object = new ObjectEntity();
		$object->setId(7);
		$object->setUuid('11111111-1111-4111-8111-111111111111');
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setRetention($retention);

		return $object;

	}//end object()

	/**
	 * The object mapper finds the given object for any uuid.
	 *
	 * @param ObjectEntity $object The object to find.
	 *
	 * @return void
	 */
	private function objectIsFound(ObjectEntity $object): void {
		$this->objectMapper->method('findAcrossAllSources')->willReturn(
			['object' => $object, 'register' => null, 'schema' => null]
		);

	}//end objectIsFound()

	/**
	 * A restrict block on an object under legal hold is kept indefinitely.
	 *
	 * @return void
	 */
	public function testARestrictBlockOnAnObjectUnderLegalHoldIsKept(): void {
		$service = $this->integrityService();
		$this->objectIsFound($this->object(['legalHold' => ['active' => true]]));

		$service->logRestrictBlock(
			objectUuid: '11111111-1111-4111-8111-111111111111',
			schemaId: '2',
			analysis: new DeletionAnalysis(deletable: false, blockers: [['schema' => 'child', 'property' => 'parent']]),
			userId: 'alice'
		);

		$this->assertCount(1, $this->inserted);
		$this->assertSame('referential_integrity.restrict_blocked', $this->inserted[0]->getAction());
		$this->assertNull($this->inserted[0]->getExpires(), 'a row under legal hold must not expire');
		$this->assertSame('legal-hold:indefinite', $this->inserted[0]->getRetentionPeriod());

	}//end testARestrictBlockOnAnObjectUnderLegalHoldIsKept()

	/**
	 * A set_null row follows the object's own ten-year retention, not 30 days.
	 *
	 * @return void
	 */
	public function testASetNullRowFollowsTheObjectsRetention(): void {
		$service = $this->integrityService();
		$this->objectIsFound($this->object(['bewaartermijn' => 'P10Y']));

		$service->applyDeletionActions(
			analysis: new DeletionAnalysis(
				deletable: true,
				nullifyTargets: [
					[
						'objectUuid' => '11111111-1111-4111-8111-111111111111',
						'property' => 'parent',
						'schema' => '2',
						'sourceUuid' => '22222222-2222-4222-8222-222222222222',
					],
				]
			),
			userId: 'alice',
			cascadeSource: '22222222-2222-4222-8222-222222222222'
		);

		$this->assertCount(1, $this->inserted);
		$this->assertSame('referential_integrity.set_null', $this->inserted[0]->getAction());
		$expires = $this->inserted[0]->getExpires();
		$this->assertNotNull($expires);
		$this->assertGreaterThan(new DateTime('+9 years'), $expires);
		$this->assertSame('object.bewaartermijn', $this->inserted[0]->getRetentionPeriod());

	}//end testASetNullRowFollowsTheObjectsRetention()

	/**
	 * When the object cannot be found the row is kept, never given 30 days.
	 *
	 * The failure being fixed is evidence disappearing, so an unknown retention
	 * errs toward keeping the row, as the object audit path does.
	 *
	 * @return void
	 */
	public function testARowWhoseObjectCannotBeFoundIsKept(): void {
		$service = $this->integrityService();
		$this->objectMapper->method('findAcrossAllSources')->willThrowException(new \RuntimeException('gone'));

		$service->logRestrictBlock(
			objectUuid: '11111111-1111-4111-8111-111111111111',
			schemaId: '2',
			analysis: new DeletionAnalysis(deletable: false, blockers: [['schema' => 'child', 'property' => 'parent']]),
			userId: 'alice'
		);

		$this->assertCount(1, $this->inserted);
		$this->assertNull($this->inserted[0]->getExpires());

	}//end testARowWhoseObjectCannotBeFoundIsKept()

	/**
	 * A file action on a record under legal hold is kept indefinitely.
	 *
	 * @return void
	 */
	public function testAFileActionOnARecordUnderLegalHoldIsKept(): void {
		$handler = new FileAuditHandler(
			$this->mapper(),
			$this->createMock(IUserSession::class),
			$this->createMock(IRequest::class),
			$this->createMock(LoggerInterface::class)
		);

		$handler->logFileAction(
			object: $this->object(['legalHold' => ['active' => true]]),
			fileId: 42,
			action: 'file.renamed',
			data: ['newName' => 'b.pdf']
		);

		$this->assertCount(1, $this->inserted);
		$this->assertNull($this->inserted[0]->getExpires(), 'a file row under legal hold must not expire');
		$this->assertSame('legal-hold:indefinite', $this->inserted[0]->getRetentionPeriod());

	}//end testAFileActionOnARecordUnderLegalHoldIsKept()

	/**
	 * A bulk download of a record kept ten years is kept ten years.
	 *
	 * @return void
	 */
	public function testABulkDownloadRowFollowsTheObjectsRetention(): void {
		$handler = new FileAuditHandler(
			$this->mapper(),
			$this->createMock(IUserSession::class),
			$this->createMock(IRequest::class),
			$this->createMock(LoggerInterface::class)
		);

		$handler->logBulkDownload(
			object: $this->object(['bewaartermijn' => 'P10Y']),
			fileIds: [1, 2],
			fileNames: ['a.pdf', 'b.pdf'],
			zipName: 'files.zip'
		);

		$this->assertCount(1, $this->inserted);
		$expires = $this->inserted[0]->getExpires();
		$this->assertNotNull($expires);
		$this->assertGreaterThan(new DateTime('+9 years'), $expires);

	}//end testABulkDownloadRowFollowsTheObjectsRetention()

}//end class
