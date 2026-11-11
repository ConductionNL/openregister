<?php

/**
 * Golden test: which reads ProcessingLogService records, and exactly what it records.
 *
 * Pins, on the code as it was before the A21 refactor, when a read is logged
 * (opted in) and when it is not (no annotation, reads off, legacy shorthand,
 * non-entity list anchor, empty list), the full serialised entry and the order
 * its changed fields are set in, the schema-over-register merge, attribution and its
 * fallback, the list identifier cap, fail-soft capture and flush.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ProcessingLogEntry;
use OCA\OpenRegister\Db\ProcessingLogMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapper;
use OCA\OpenRegister\Service\ProcessingLogService;
use OCA\OpenRegister\Service\ProcessingRead;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\MagicMapperInterface;

/**
 * @covers \OCA\OpenRegister\Service\ProcessingLogService
 */
final class ProcessingLogServiceGoldenTest extends TestCase {

	/**
	 * Schema configuration by schema id.
	 *
	 * @var array<string, array|null>
	 */
	private array $schemaConfigs = [];

	/**
	 * Register configuration by register id.
	 *
	 * @var array<string, array|null>
	 */
	private array $registerConfigs = [];

	/**
	 * Activities by reference.
	 *
	 * @var array<string, Verwerkingsactiviteit>
	 */
	private array $activities = [];

	/**
	 * Fallback activities inserted.
	 *
	 * @var list<Verwerkingsactiviteit>
	 */
	private array $inserted = [];

	/**
	 * Entries handed to insertBatch.
	 *
	 * @var list<ProcessingLogEntry>
	 */
	private array $flushed = [];

	/**
	 * Debug and error log messages.
	 *
	 * @var list<string>
	 */
	private array $logs = [];

	/**
	 * Whether insertBatch throws.
	 *
	 * @var bool
	 */
	private bool $flushFails = false;

	/**
	 * Whether a user is logged in.
	 *
	 * @var bool
	 */
	private bool $loggedIn = true;

	/**
	 * Build the service under test over recording collaborators.
	 *
	 * @return ProcessingLogService
	 */
	private function service(): ProcessingLogService {
		$logMapper = $this->createMock(ProcessingLogMapper::class);
		$logMapper->method('insertBatch')->willReturnCallback(function (array $entries): int {
			if ($this->flushFails === true) {
				throw new RuntimeException('db down');
			}

			$this->flushed = array_merge($this->flushed, $entries);
			return count($entries);
		});

		$vrwMapper = $this->createMock(VerwerkingsactiviteitMapper::class);
		$vrwMapper->method('resolveReference')->willReturnCallback(fn (string $reference) => ($this->activities[$reference] ?? null));
		$vrwMapper->method('findByCode')->willReturnCallback(fn (string $code) => ($this->activities['code:' . $code] ?? null));
		$vrwMapper->method('insert')->willReturnCallback(function ($activity) {
			$activity->setUuid('fallback-uuid');
			$this->inserted[] = $activity;
			return $activity;
		});

		$schemaMapper = $this->createMock(SchemaMapperInterface::class);
		$schemaMapper->method('find')->willReturnCallback(function ($id) {
			$schema = $this->createMock(Schema::class);
			$schema->method('getConfiguration')->willReturn($this->schemaConfigs[(string) $id] ?? null);
			return $schema;
		});
		$registerMapper = $this->createMock(RegisterMapperInterface::class);
		$registerMapper->method('find')->willReturnCallback(function ($id) {
			$register = $this->createMock(Register::class);
			$register->method('getConfiguration')->willReturn($this->registerConfigs[(string) $id] ?? []);
			return $register;
		});

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('jan');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn () => ($this->loggedIn === true ? $user : null));

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function ($message, array $context = []) use ($level) {
				$this->logs[] = $level . ':' . $message . ':' . json_encode($context);
			});
		}

		return new ProcessingLogService($logMapper, $vrwMapper, new SchemaCatalog(registers: $registerMapper, schemas: $schemaMapper, tables: $this->createMock(MagicMapperInterface::class)), $session, $logger);
	}//end service()

	/**
	 * An object in schema 5 / register 3.
	 *
	 * @param array<string, mixed> $data The payload.
	 * @param string               $uuid The uuid.
	 *
	 * @return ObjectEntity
	 */
	private function object(array $data = [], string $uuid = 'obj-1'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setSchema('5');
		$object->setRegister('3');
		$object->setOrganisation('org-1');
		$object->setObject($data);
		return $object;
	}//end object()

	/**
	 * A published activity.
	 *
	 * @param string $uuid   The uuid.
	 * @param string $status The status.
	 *
	 * @return Verwerkingsactiviteit
	 */
	private function activity(string $uuid, string $status = 'published'): Verwerkingsactiviteit {
		$activity = new Verwerkingsactiviteit();
		$activity->setUuid($uuid);
		$activity->setStatus($status);
		return $activity;
	}//end activity()

	/**
	 * The serialised entries flushed, without the unset id/uuid/created.
	 *
	 * @param ProcessingLogService $service The service.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function flushedRows(ProcessingLogService $service): array {
		$service->flush();
		return array_map(static function (ProcessingLogEntry $entry): array {
			$row = $entry->jsonSerialize();
			unset($row['id'], $row['uuid'], $row['created']);
			$row['_order'] = array_keys($entry->getUpdatedFields());
			return $row;
		}, $this->flushed);
	}//end flushedRows()

	/**
	 * An opted-in single read records the full entry, fields set in this order.
	 *
	 * @return void
	 */
	public function testAnOptedInReadRecordsTheFullEntry(): void {
		$this->schemaConfigs['5'] = ['x-openregister-processing' => [
			'logReads' => true,
			'attribution' => ['default' => 'ref-default', 'export' => 'ref-export'],
			'subjectIdFields' => ['KVK' => 'kvk', 'BSN' => 'bsn'],
		]];
		$this->activities['ref-export'] = $this->activity('act-export');
		$service = $this->service();
		$service->recordRead($this->object(['kvk' => '', 'bsn' => 123456789]), new ProcessingRead('export', null, 'actor-x'));
		$this->assertSame(1, $service->pendingCount());
		$this->assertSame([[
			'activityId' => 'act-export',
			'action' => 'export',
			'actor' => 'actor-x',
			'channel' => 'ui',
			'registerId' => '3',
			'schemaId' => '5',
			'objectUuid' => 'obj-1',
			'subjectIdType' => 'BSN',
			'subjectIdValue' => '123456789',
			'objectCount' => 1,
			'objectUuids' => null,
			'subjectIds' => null,
			'queryParams' => null,
			'confidential' => false,
			'organisationId' => 'org-1',
			'_order' => ['activityId', 'action', 'actor', 'registerId', 'schemaId', 'objectUuid', 'subjectIdType', 'subjectIdValue', 'organisationId'],
		]], $this->flushedRows($service));
		$this->assertSame(0, $service->pendingCount());
		$this->assertSame([], $this->inserted);
	}//end testAnOptedInReadRecordsTheFullEntry()

	/**
	 * No annotation, reads off, or only the legacy shorthand: nothing is recorded.
	 *
	 * @return void
	 */
	public function testReadsThatAreNotOptedInAreNotRecorded(): void {
		$configs = [
			null,
			[],
			['x-openregister-processing' => ['logReads' => false]],
			['x-openregister-processing' => ['logReads' => 'yes']],
			['x-openregister-processing-activity' => 'ref-legacy'],
			['x-openregister-processing' => 'not-an-array'],
		];
		foreach ($configs as $config) {
			$this->schemaConfigs['5'] = $config;
			$service = $this->service();
			$service->recordRead($this->object(), new ProcessingRead());
			$service->recordReadList([$this->object()], new ProcessingRead());
			$this->assertSame(0, $service->pendingCount(), json_encode($config));
		}

		$this->assertSame(0, $this->service()->flush());
	}//end testReadsThatAreNotOptedInAreNotRecorded()

	/**
	 * A register opt-in applies when the schema has none; the schema overrides it per key.
	 *
	 * @return void
	 */
	public function testTheSchemaOverridesTheRegisterPerKey(): void {
		$this->registerConfigs['3'] = ['x-openregister-processing' => [
			'logReads' => true,
			'attribution' => ['default' => 'ref-reg', 'read' => 'ref-reg-read'],
			'subjectIdFields' => ['BSN' => 'bsn'],
		]];
		$this->activities['ref-reg-read'] = $this->activity('act-reg-read');
		$this->activities['ref-schema-read'] = $this->activity('act-schema-read');
		$service = $this->service();
		$service->recordRead($this->object(['bsn' => '1']), new ProcessingRead());
		$this->schemaConfigs['5'] = ['x-openregister-processing-activity' => 'ref-schema-read'];
		$service->recordRead($this->object(['bsn' => '1']), new ProcessingRead());
		$this->schemaConfigs['5'] = ['x-openregister-processing' => ['logReads' => true, 'attribution' => ['read' => 'ref-schema-read']]];
		$service->recordRead($this->object(['bsn' => '1']), new ProcessingRead());
		$rows = $this->flushedRows($service);
		$this->assertSame(['act-reg-read', 'act-schema-read'], array_column($rows, 'activityId'));
		$this->assertSame(['BSN', null], array_column($rows, 'subjectIdType'));
	}//end testTheSchemaOverridesTheRegisterPerKey()

	/**
	 * An unresolvable or retired attribution falls back, creating the fallback once per miss.
	 *
	 * @return void
	 */
	public function testUnattributableReadsFallBack(): void {
		$this->schemaConfigs['5'] = ['x-openregister-processing' => ['logReads' => true, 'attribution' => ['default' => 'ref-retired']]];
		$this->activities['ref-retired'] = $this->activity('act-retired', 'retired');
		$this->loggedIn = false;
		$service = $this->service();
		$service->recordRead($this->object(), new ProcessingRead());
		$this->activities['code:niet-geclassificeerde-verwerking'] = $this->activity('existing-fallback');
		$this->schemaConfigs['5']['x-openregister-processing']['attribution'] = ['default' => 'ref-missing'];
		$service->recordRead($this->object(), new ProcessingRead());
		$rows = $this->flushedRows($service);
		$this->assertSame(['fallback-uuid', 'existing-fallback'], array_column($rows, 'activityId'));
		$this->assertSame(['system', 'system'], array_column($rows, 'actor'));
		$this->assertSame(['public', 'public'], array_column($rows, 'channel'));
		$this->assertCount(1, $this->inserted);
		$this->assertSame('niet-geclassificeerde-verwerking', $this->inserted[0]->getCode());
		$this->assertSame('public_task', $this->inserted[0]->getLegalBasis());
		$this->assertSame('org-1', $this->inserted[0]->getOrganisationId());
	}//end testUnattributableReadsFallBack()

	/**
	 * A list read collapses to one entry with identifiers, capped at 100 objects.
	 *
	 * @return void
	 */
	public function testAListReadCollapsesToOneEntry(): void {
		$this->schemaConfigs['5'] = ['x-openregister-processing' => ['logReads' => true, 'attribution' => ['default' => 'ref'], 'subjectIdFields' => ['BSN' => 'bsn']]];
		$this->activities['ref'] = $this->activity('act');
		$service = $this->service();
		$service->recordReadList([], new ProcessingRead('read'));
		$service->recordReadList(['k' => $this->object(['bsn' => '1'], 'a'), 'l' => $this->object([], 'b')], new ProcessingRead('search', 'api', null, ['q' => 'x']));
		$big = [];
		for ($i = 0; $i < 101; $i++) {
			$big[] = $this->object([], 'o' . $i);
		}

		$service->recordReadList($big, new ProcessingRead());
		$service->recordReadList(['not-an-entity'], new ProcessingRead());
		$rows = $this->flushedRows($service);
		$this->assertCount(2, $rows);
		$this->assertSame([
			'activityId' => 'act',
			'action' => 'search',
			'actor' => 'jan',
			'channel' => 'api',
			'registerId' => '3',
			'schemaId' => '5',
			'objectUuid' => null,
			'subjectIdType' => null,
			'subjectIdValue' => null,
			'objectCount' => 2,
			'objectUuids' => ['a', 'b'],
			'subjectIds' => [['type' => 'BSN', 'value' => '1']],
			'queryParams' => ['q' => 'x'],
			'confidential' => false,
			'organisationId' => 'org-1',
			'_order' => ['activityId', 'action', 'actor', 'channel', 'registerId', 'schemaId', 'objectCount', 'organisationId', 'queryParams', 'objectUuids', 'subjectIds'],
		], $rows[0]);
		$this->assertSame(101, $rows[1]['objectCount']);
		$this->assertNull($rows[1]['objectUuids']);
		$this->assertSame(['activityId', 'actor', 'registerId', 'schemaId', 'objectCount', 'organisationId', 'queryParams'], $rows[1]['_order']);
	}//end testAListReadCollapsesToOneEntry()

	/**
	 * Capture and flush are fail-soft: a failure logs, buffers nothing new, and keeps pending entries.
	 *
	 * @return void
	 */
	public function testCaptureAndFlushAreFailSoft(): void {
		$this->schemaConfigs['5'] = ['x-openregister-processing' => ['logReads' => true, 'subjectIdFields' => ['BSN' => ['not', 'a', 'key']]]];
		$service = $this->service();
		$service->recordRead($this->object(['bsn' => '1']), new ProcessingRead());
		$this->assertSame(0, $service->pendingCount());
		$this->assertCount(1, $this->inserted, 'the fallback is created before the subject is read');
		$this->assertStringStartsWith('debug:[AVG] processing-log capture skipped:', $this->logs[0]);

		$this->schemaConfigs['5'] = ['x-openregister-processing' => ['logReads' => true]];
		$service->recordRead($this->object(), new ProcessingRead());
		$this->flushFails = true;
		$this->assertSame(0, $service->flush());
		$this->assertSame(1, $service->pendingCount());
		$this->assertSame('error:[AVG] processing-log flush failed; entries retained for retry:{"exception":"db down","pending":1}', $this->logs[1]);
		$this->flushFails = false;
		$this->assertSame(1, $service->flush());
		$this->assertSame(0, $service->flush());
	}//end testCaptureAndFlushAreFailSoft()

	/**
	 * A list capture failure is logged as a list capture failure.
	 *
	 * @return void
	 */
	public function testAListCaptureFailureIsLogged(): void {
		$this->schemaConfigs['5'] = ['x-openregister-processing' => ['logReads' => true, 'subjectIdFields' => ['BSN' => ['bad']]]];
		$service = $this->service();
		$service->recordReadList([$this->object(['bsn' => '1'])], new ProcessingRead());
		$this->assertSame(0, $service->pendingCount());
		$this->assertStringStartsWith('debug:[AVG] processing-log list capture skipped:', $this->logs[0]);
	}//end testAListCaptureFailureIsLogged()
}//end class
