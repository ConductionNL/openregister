<?php

/**
 * Writes inside a system operation leave an audit row, in the transaction of the write.
 *
 * Pins that a single save inside a system operation opens the save
 * transaction and writes its audit row even when silent, that a bulk chunk
 * written inside one writes its audit rows whatever the caller asked and
 * rolls back with them when they fail, and that outside a system operation
 * nothing changes.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Service\Object\BulkSaveReporting;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSaveCall;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSaveFlags;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

final class SystemWriteAuditTest extends TestCase {

	private function saveHandler(): SaveObject {
		$handler = (new ReflectionClass(SaveObject::class))->newInstanceWithoutConstructor();
		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->method('getRetentionSettingsOnly')->willReturn(['auditTrailsEnabled' => true]);
		(new ReflectionClass(SaveObject::class))->getProperty('settingsService')->setValue($handler, $settings);

		return $handler;
	}//end saveHandler()

	private static function call(): ObjectSaveCall {
		$call = new ObjectSaveCall();
		$call->flags = new ObjectSaveFlags();
		$call->flags->persist = true;

		return $call;
	}//end call()

	public function testASilentSaveInsideASystemOperationIsAuditedInItsTransaction(): void {
		$handler = $this->saveHandler();
		$class = new ReflectionClass(SaveObject::class);
		$writesAudit = $class->getMethod('writesAuditRow');
		$isSystemWrite = $class->getMethod('isSystemWrite');

		$this->assertFalse($writesAudit->invoke($handler, true));
		$this->assertFalse($isSystemWrite->invoke($handler, self::call()));

		SystemOperationContext::run(function () use ($handler, $writesAudit, $isSystemWrite): void {
			$this->assertTrue($writesAudit->invoke($handler, true));
			$this->assertTrue($isSystemWrite->invoke($handler, self::call()));
		});
	}//end testASilentSaveInsideASystemOperationIsAuditedInItsTransaction()

	private function bulkHandler(AuditTrailMapperInterface $audit): SaveObjects {
		$handler = (new ReflectionClass(SaveObjects::class))->newInstanceWithoutConstructor();
		(new ReflectionClass(SaveObjects::class))->getProperty('reporting')->setValue(
			$handler,
			new BulkSaveReporting(logger: $this->createMock(LoggerInterface::class), auditTrailMapper: $audit, db: $this->createMock(IDBConnection::class))
		);

		return $handler;
	}//end bulkHandler()

	public function testABulkChunkInsideASystemOperationIsAuditedEvenWhenTheCallerOptedOut(): void {
		$handler = $this->bulkHandler(audit: $this->createMock(AuditTrailMapperInterface::class));
		$writesAudit = (new ReflectionClass(SaveObjects::class))->getMethod('writesChunkAudit');

		$this->assertFalse($writesAudit->invoke($handler, false));
		$this->assertTrue(SystemOperationContext::run(static fn (): bool => $writesAudit->invoke($handler, false)));
	}//end testABulkChunkInsideASystemOperationIsAuditedEvenWhenTheCallerOptedOut()

	public function testAFailingAuditRowInsideASystemOperationFailsTheChunk(): void {
		$audit = $this->createMock(AuditTrailMapperInterface::class);
		$audit->method('insertAuditTrails')->willThrowException(new RuntimeException('audit store down'));
		$handler = $this->bulkHandler(audit: $audit);
		$insert = (new ReflectionClass(SaveObjects::class))->getMethod('insertAuditEntries');

		$insert->invoke($handler, [['entry']]);

		$this->expectException(RuntimeException::class);
		SystemOperationContext::run(static function () use ($insert, $handler): void {
			$insert->invoke($handler, [['entry']]);
		});
	}//end testAFailingAuditRowInsideASystemOperationFailsTheChunk()
}//end class
