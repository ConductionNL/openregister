<?php

/**
 * Unit tests for ProcessingLogService — AVG per-access read logging.
 *
 * Verifies the genuinely-missing read-logging delta:
 *   - read logging triggers ONLY for schemas opting in via
 *     `x-openregister-processing.logReads: true`;
 *   - the buffered entry carries the correct shape (action, actor,
 *     channel, references, attributed activity, subject identifier);
 *   - unresolvable attribution falls back to the flagged fallback
 *     activity rather than being dropped;
 *   - retired/archived activities are not attributable;
 *   - list reads collapse to one entry with objectCount;
 *   - logging is fail-soft (a mapper error never propagates).
 *
 * Pure-logic test: all collaborators are mocked, no Nextcloud runtime.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ProcessingLogEntry;
use OCA\OpenRegister\Db\ProcessingLogMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapper;
use OCA\OpenRegister\Service\ProcessingLogService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\ProcessingLogService
 */
class ProcessingLogServiceTest extends TestCase {

	/**
	 * @var ProcessingLogMapper&MockObject
	 */
	private $logMapper;

	/**
	 * @var VerwerkingsactiviteitMapper&MockObject
	 */
	private $vrwMapper;

	/**
	 * @var SchemaMapper&MockObject
	 */
	private $schemaMapper;

	/**
	 * @var RegisterMapper&MockObject
	 */
	private $registerMapper;

	/**
	 * @var IUserSession&MockObject
	 */
	private $userSession;

	private ProcessingLogService $service;

	/**
	 * Build the service with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logMapper = $this->createMock(ProcessingLogMapper::class);
		$this->vrwMapper = $this->createMock(VerwerkingsactiviteitMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('jan');
		$this->userSession->method('getUser')->willReturn($user);

		$this->service = new ProcessingLogService(
			logMapper: $this->logMapper,
			vrwMapper: $this->vrwMapper,
			schemaMapper: $this->schemaMapper,
			registerMapper: $this->registerMapper,
			userSession: $this->userSession,
			logger: new NullLogger()
		);

	}//end setUp()

	/**
	 * Build an ObjectEntity stub.
	 *
	 * @param array<string, mixed> $data Object payload.
	 *
	 * @return ObjectEntity
	 */
	private function makeObject(array $data = []): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-uuid-1');
		$object->setSchema('5');
		$object->setRegister('3');
		$object->setOrganisation('org-1');
		$object->setObject($data);
		return $object;
	}//end makeObject()

	/**
	 * Stub the schema config returned by SchemaMapper::find().
	 *
	 * @param array<string, mixed>|null $config Schema configuration.
	 *
	 * @return void
	 */
	private function stubSchemaConfig(?array $config): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('getConfiguration')->willReturn($config);
		$this->schemaMapper->method('find')->willReturn($schema);

		$register = $this->createMock(Register::class);
		$register->method('getConfiguration')->willReturn([]);
		$this->registerMapper->method('find')->willReturn($register);

	}//end stubSchemaConfig()

	/**
	 * Stub a resolvable activity.
	 *
	 * @param string $uuid Activity uuid.
	 * @param string $status Lifecycle status.
	 *
	 * @return void
	 */
	private function stubResolvableActivity(string $uuid, string $status = 'published'): void {
		$activity = new Verwerkingsactiviteit();
		$activity->setUuid($uuid);
		$activity->setStatus($status);
		$this->vrwMapper->method('resolveReference')->willReturn($activity);

	}//end stubResolvableActivity()

	/**
	 * Opted-in schema produces one buffered entry with the right shape.
	 *
	 * @return void
	 */
	public function testReadOnOptedInSchemaIsBuffered(): void {
		$this->stubSchemaConfig(
			[
				'x-openregister-processing' => [
					'logReads' => true,
					'attribution' => ['default' => 'act-omgevingsvergunning'],
					'subjectIdFields' => ['BSN' => 'bsn'],
				],
			]
		);
		$this->stubResolvableActivity('act-uuid-1');

		$object = $this->makeObject(['bsn' => '123456789']);
		$this->service->logRead(object: $object, action: 'read');

		$this->assertSame(1, $this->service->pendingCount());

		// Flush asserts the entry shape via the mapper.
		$this->logMapper->expects($this->once())
			->method('insertBatch')
			->willReturnCallback(
				function (array $entries): int {
					$this->assertCount(1, $entries);
					/*
					 * @var ProcessingLogEntry $entry
					 */
					$entry = $entries[0];
					$this->assertSame('read', $entry->getAction());
					$this->assertSame('jan', $entry->getActor());
					$this->assertSame('act-uuid-1', $entry->getActivityId());
					$this->assertSame('obj-uuid-1', $entry->getObjectUuid());
					$this->assertSame('BSN', $entry->getSubjectIdType());
					$this->assertSame('123456789', $entry->getSubjectIdValue());
					$this->assertSame('org-1', $entry->getOrganisationId());
					$this->assertSame(1, $entry->getObjectCount());
					return 1;
				}
			);

		$this->assertSame(1, $this->service->flush());
		$this->assertSame(0, $this->service->pendingCount());

	}//end testReadOnOptedInSchemaIsBuffered()

	/**
	 * A schema without the opt-in produces no entry.
	 *
	 * @return void
	 */
	public function testNoLoggingWithoutOptIn(): void {
		$this->stubSchemaConfig(
			[
				'x-openregister-processing' => ['logReads' => false, 'attribution' => ['default' => 'a']],
			]
		);

		$this->service->logRead(object: $this->makeObject(['bsn' => '1']));

		$this->assertSame(0, $this->service->pendingCount());
		$this->logMapper->expects($this->never())->method('insertBatch');
		$this->assertSame(0, $this->service->flush());

	}//end testNoLoggingWithoutOptIn()

	/**
	 * No annotation at all → no entry.
	 *
	 * @return void
	 */
	public function testNoAnnotationNoEntry(): void {
		$this->stubSchemaConfig([]);
		$this->service->logRead(object: $this->makeObject());
		$this->assertSame(0, $this->service->pendingCount());

	}//end testNoAnnotationNoEntry()

	/**
	 * Unresolvable attribution falls back to the flagged fallback
	 * activity, never dropped.
	 *
	 * @return void
	 */
	public function testUnresolvedAttributionFallsBackAndIsNotDropped(): void {
		$this->stubSchemaConfig(
			[
				'x-openregister-processing' => [
					'logReads' => true,
					'attribution' => ['default' => 'dangling-ref'],
				],
			]
		);
		// resolveReference returns null (dangling) for the default ref.
		$this->vrwMapper->method('resolveReference')->willReturn(null);
		// No existing fallback → it is seeded; insert returns it with a uuid.
		$this->vrwMapper->method('findByCode')->willReturn(null);
		$seeded = new Verwerkingsactiviteit();
		$seeded->setUuid('fallback-uuid');
		$this->vrwMapper->method('insert')->willReturn($seeded);

		$this->service->logRead(object: $this->makeObject());

		$this->assertSame(1, $this->service->pendingCount());

		$this->logMapper->method('insertBatch')->willReturnCallback(
			function (array $entries): int {
				$this->assertSame('fallback-uuid', $entries[0]->getActivityId());
				return 1;
			}
		);
		$this->assertSame(1, $this->service->flush());

	}//end testUnresolvedAttributionFallsBackAndIsNotDropped()

	/**
	 * A retired activity is not attributable — falls back.
	 *
	 * @return void
	 */
	public function testRetiredActivityFallsBack(): void {
		$this->stubSchemaConfig(
			[
				'x-openregister-processing' => ['logReads' => true, 'attribution' => ['default' => 'retired-act']],
			]
		);
		$this->stubResolvableActivity('retired-uuid', status: 'retired');
		$fallback = new Verwerkingsactiviteit();
		$fallback->setUuid('fallback-uuid');
		$this->vrwMapper->method('findByCode')->willReturn($fallback);

		$this->service->logRead(object: $this->makeObject());

		$this->logMapper->method('insertBatch')->willReturnCallback(
			function (array $entries): int {
				$this->assertSame('fallback-uuid', $entries[0]->getActivityId());
				return 1;
			}
		);
		$this->assertSame(1, $this->service->flush());

	}//end testRetiredActivityFallsBack()

	/**
	 * Per-operation override: export uses the export attribution.
	 *
	 * @return void
	 */
	public function testPerOperationExportAttribution(): void {
		$this->stubSchemaConfig(
			[
				'x-openregister-processing' => [
					'logReads' => true,
					'attribution' => ['default' => 'act-a', 'export' => 'act-b'],
				],
			]
		);
		$exportActivity = new Verwerkingsactiviteit();
		$exportActivity->setUuid('export-uuid');
		$exportActivity->setStatus('published');
		$this->vrwMapper->expects($this->once())
			->method('resolveReference')
			->with(reference: 'act-b')
			->willReturn($exportActivity);

		$this->service->logRead(object: $this->makeObject(), action: 'export');

		$this->logMapper->method('insertBatch')->willReturnCallback(
			function (array $entries): int {
				$this->assertSame('export', $entries[0]->getAction());
				$this->assertSame('export-uuid', $entries[0]->getActivityId());
				return 1;
			}
		);
		$this->assertSame(1, $this->service->flush());

	}//end testPerOperationExportAttribution()

	/**
	 * A list read collapses to a single entry carrying objectCount.
	 *
	 * @return void
	 */
	public function testListReadCollapsesToOneEntry(): void {
		$this->stubSchemaConfig(
			[
				'x-openregister-processing' => ['logReads' => true, 'attribution' => ['default' => 'act']],
			]
		);
		$this->stubResolvableActivity('act-uuid');

		$objects = [];
		for ($i = 0; $i < 50; $i++) {
			$objects[] = $this->makeObject(['bsn' => (string)$i]);
		}

		$this->service->logReadList(objects: $objects, action: 'read');

		$this->assertSame(1, $this->service->pendingCount());
		$this->logMapper->method('insertBatch')->willReturnCallback(
			function (array $entries): int {
				$this->assertCount(1, $entries);
				$this->assertSame(50, $entries[0]->getObjectCount());
				return 1;
			}
		);
		$this->assertSame(1, $this->service->flush());

	}//end testListReadCollapsesToOneEntry()

	/**
	 * A failing flush is fail-soft: it returns 0 and retains the buffer,
	 * never throwing into the read path.
	 *
	 * @return void
	 */
	public function testFlushIsFailSoft(): void {
		$this->stubSchemaConfig(
			[
				'x-openregister-processing' => ['logReads' => true, 'attribution' => ['default' => 'act']],
			]
		);
		$this->stubResolvableActivity('act-uuid');

		$this->service->logRead(object: $this->makeObject());
		$this->logMapper->method('insertBatch')->willThrowException(new \RuntimeException('db down'));

		// Must NOT throw.
		$this->assertSame(0, $this->service->flush());
		// Buffer retained for retry.
		$this->assertSame(1, $this->service->pendingCount());

	}//end testFlushIsFailSoft()

	/**
	 * A broken schema lookup is fail-soft: logRead swallows the error.
	 *
	 * @return void
	 */
	public function testCaptureIsFailSoftOnSchemaError(): void {
		$this->schemaMapper->method('find')->willThrowException(new \RuntimeException('boom'));
		$register = $this->createMock(Register::class);
		$register->method('getConfiguration')->willReturn([]);
		$this->registerMapper->method('find')->willReturn($register);

		// Must NOT throw.
		$this->service->logRead(object: $this->makeObject());
		$this->assertSame(0, $this->service->pendingCount());

	}//end testCaptureIsFailSoftOnSchemaError()

	/**
	 * Legacy single-string annotation does not enable read logging
	 * (reads default off) — back-compat preserved.
	 *
	 * @return void
	 */
	public function testLegacyAnnotationDoesNotEnableReads(): void {
		$this->stubSchemaConfig(
			[
				'x-openregister-processing-activity' => 'some-activity',
			]
		);

		$this->service->logRead(object: $this->makeObject());
		$this->assertSame(0, $this->service->pendingCount());

	}//end testLegacyAnnotationDoesNotEnableReads()
}//end class
