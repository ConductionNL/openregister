<?php

/**
 * LogServiceAccessGoldenTest
 *
 * Pins what the object history and the audit export allow and refuse, so a
 * restructuring of LogService cannot widen or narrow either: which object a
 * history request resolves to (or refuses), that every lookup runs without
 * RBAC and multitenancy filters exactly as before, that the history query
 * only runs once the object resolved, and the exact rows an export renders,
 * explicit-reveal masking included.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\LogService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Golden outcomes of LogService's object lookup and export.
 */
class LogServiceAccessGoldenTest extends TestCase {

	/**
	 * @var AuditTrailMapperInterface&MockObject
	 */
	private AuditTrailMapperInterface&MockObject $auditTrailMapper;

	/**
	 * @var MagicMapperInterface&MockObject
	 */
	private MagicMapperInterface&MockObject $objectMapper;

	/**
	 * @var RegisterMapperInterface&MockObject
	 */
	private RegisterMapperInterface&MockObject $registerMapper;

	/**
	 * @var SchemaMapperInterface&MockObject
	 */
	private SchemaMapperInterface&MockObject $schemaMapper;

	/**
	 * Every lookup call made, as [method, args].
	 *
	 * @var array<int, array{0: string, 1: array}>
	 */
	private array $calls = [];

	/**
	 * @var LogService
	 */
	private LogService $service;

	/**
	 * Wire the service on interface doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->auditTrailMapper = $this->createMock(AuditTrailMapperInterface::class);
		$this->objectMapper = $this->createMock(MagicMapperInterface::class);
		$this->registerMapper = $this->createMock(RegisterMapperInterface::class);
		$this->schemaMapper = $this->createMock(SchemaMapperInterface::class);
		$this->calls = [];
		$this->service = new LogService(
			$this->auditTrailMapper,
			$this->objectMapper,
			$this->registerMapper,
			$this->schemaMapper
		);
	}//end setUp()

	/**
	 * An object with a uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		return $object;
	}//end object()

	/**
	 * Register and schema resolve; record every scope lookup.
	 *
	 * @return void
	 */
	private function scopeResolves(): void {
		$this->registerMapper->method('find')->willReturnCallback(function (...$args): Register {
			$this->calls[] = ['register', $args];
			return new Register();
		});
		$this->schemaMapper->method('find')->willReturnCallback(function (...$args): Schema {
			$this->calls[] = ['schema', $args];
			return new Schema();
		});
	}//end scopeResolves()

	/**
	 * The in-table lookup answers per includeDeleted flag.
	 *
	 * @param mixed $withDeleted Object or exception for includeDeleted=true.
	 * @param mixed $liveOnly    Object or exception for includeDeleted=false.
	 *
	 * @return void
	 */
	private function tableAnswers(mixed $withDeleted, mixed $liveOnly): void {
		$this->objectMapper->method('findInRegisterSchemaTable')->willReturnCallback(
			function (...$args) use ($withDeleted, $liveOnly): ObjectEntity {
				$this->calls[] = ['table', [$args[0], $args[3], $args[4], $args[5]]];
				$answer = $liveOnly;
				if ($args[5] === true) {
					$answer = $withDeleted;
				}

				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);
	}//end tableAnswers()

	/**
	 * The history query returns the filters it was asked with.
	 *
	 * @return void
	 */
	private function historyEchoesFilters(): void {
		$this->auditTrailMapper->method('findAll')->willReturnCallback(
			fn (...$args): array => [['limit' => $args[0], 'offset' => $args[1], 'filters' => $args[2], 'sort' => $args[3], 'search' => $args[4]]]
		);
	}//end historyEchoesFilters()

	/**
	 * ALLOWED: an object of the register+schema answers with its own history, filters kept.
	 *
	 * @return void
	 */
	public function testObjectInScopeAnswersWithItsHistory(): void {
		$this->scopeResolves();
		$this->tableAnswers($this->object('u-1'), new RuntimeException('not asked'));
		$this->historyEchoesFilters();

		$logs = $this->service->getLogs('reg', 'sch', 'slug-1', ['filters' => ['action' => 'update'], 'limit' => 5, 'search' => 'x']);

		$this->assertSame(
			[['limit' => 5, 'offset' => 0, 'filters' => ['action' => 'update', 'object_uuid' => 'u-1'], 'sort' => ['created' => 'DESC'], 'search' => 'x']],
			$logs
		);
		$this->assertSame(
			[
				['register', ['reg', false, false]],
				['schema', ['sch', [], false, false]],
				['table', ['slug-1', false, false, true]],
			],
			$this->calls
		);
	}//end testObjectInScopeAnswersWithItsHistory()

	/**
	 * REFUSED: an identifier the register+schema does not hold is not found, and no history is read.
	 *
	 * @return void
	 */
	public function testObjectOutsideScopeIsRefusedBeforeAnyHistoryRead(): void {
		$this->scopeResolves();
		$this->tableAnswers(new DoesNotExistException('none'), new RuntimeException('not asked'));
		$this->auditTrailMapper->expects($this->never())->method('findAll');
		$this->objectMapper->expects($this->never())->method('findAcrossAllSources');

		$this->expectException(DoesNotExistException::class);
		$this->service->getLogs('reg', 'sch', 'elsewhere');
	}//end testObjectOutsideScopeIsRefusedBeforeAnyHistoryRead()

	/**
	 * ALLOWED: a slug a deleted and one live object share resolves to the live one.
	 *
	 * @return void
	 */
	public function testSharedSlugResolvesToTheSingleLiveHolder(): void {
		$this->scopeResolves();
		$this->tableAnswers(new MultipleObjectsReturnedException('two'), $this->object('live'));
		$this->historyEchoesFilters();

		$count = $this->service->count('reg', 'sch', 'shared');

		$this->assertSame(1, $count);
		$this->assertSame(
			[['table', ['shared', false, false, true]], ['table', ['shared', false, false, false]]],
			array_values(array_filter($this->calls, fn ($c) => $c[0] === 'table'))
		);
	}//end testSharedSlugResolvesToTheSingleLiveHolder()

	/**
	 * REFUSED: a slug held only by deleted objects stays ambiguous (the original exception).
	 *
	 * @return void
	 */
	public function testSlugHeldOnlyByDeletedObjectsIsRefusedAsAmbiguous(): void {
		$this->scopeResolves();
		$original = new MultipleObjectsReturnedException('ambiguous-original');
		$this->tableAnswers($original, new DoesNotExistException('no live'));
		$this->auditTrailMapper->expects($this->never())->method('findAll');

		try {
			$this->service->getLogs('reg', 'sch', 'shared');
			$this->fail('An ambiguous slug must be refused');
		} catch (MultipleObjectsReturnedException $e) {
			$this->assertSame($original, $e);
		}
	}//end testSlugHeldOnlyByDeletedObjectsIsRefusedAsAmbiguous()

	/**
	 * REFUSED: a slug two live objects hold is refused as ambiguous.
	 *
	 * @return void
	 */
	public function testSlugHeldByTwoLiveObjectsIsRefused(): void {
		$this->scopeResolves();
		$live = new MultipleObjectsReturnedException('live-ambiguous');
		$this->tableAnswers(new MultipleObjectsReturnedException('with-deleted'), $live);
		$this->auditTrailMapper->expects($this->never())->method('findAll');

		try {
			$this->service->count('reg', 'sch', 'shared');
			$this->fail('An ambiguous slug must be refused');
		} catch (MultipleObjectsReturnedException $e) {
			$this->assertSame($live, $e);
		}
	}//end testSlugHeldByTwoLiveObjectsIsRefused()

	/**
	 * ALLOWED: a register that no longer resolves falls back to the cross-table lookup, deleted included.
	 *
	 * @return void
	 */
	public function testMissingRegisterFallsBackToCrossTableLookup(): void {
		$this->registerMapper->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->objectMapper->expects($this->never())->method('findInRegisterSchemaTable');
		$this->objectMapper->expects($this->once())->method('findAcrossAllSources')
			->willReturnCallback(function (...$args): array {
				$this->assertSame(['old-1', true, false, false, null], $args);
				return ['object' => $this->object('u-old')];
			});
		$this->historyEchoesFilters();

		$logs = $this->service->getLogs('gone', 'sch', 'old-1');

		$this->assertSame(['object_uuid' => 'u-old'], $logs[0]['filters']);
	}//end testMissingRegisterFallsBackToCrossTableLookup()

	/**
	 * ALLOWED: a schema that no longer resolves falls back the same way.
	 *
	 * @return void
	 */
	public function testMissingSchemaFallsBackToCrossTableLookup(): void {
		$this->registerMapper->method('find')->willReturn(new Register());
		$this->schemaMapper->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->objectMapper->expects($this->once())->method('findAcrossAllSources')
			->willReturn(['object' => $this->object('u-s')]);
		$this->historyEchoesFilters();

		$this->assertSame(1, $this->service->count('reg', 'gone', 'x'));
	}//end testMissingSchemaFallsBackToCrossTableLookup()

	/**
	 * REFUSED: any other failure resolving the scope propagates; no fallback widens the lookup.
	 *
	 * @return void
	 */
	public function testOtherScopeFailurePropagatesWithoutFallback(): void {
		$this->registerMapper->method('find')->willThrowException(new RuntimeException('db down'));
		$this->objectMapper->expects($this->never())->method('findAcrossAllSources');
		$this->objectMapper->expects($this->never())->method('findInRegisterSchemaTable');
		$this->auditTrailMapper->expects($this->never())->method('findAll');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('db down');
		$this->service->getLogs('reg', 'sch', 'x');
	}//end testOtherScopeFailurePropagatesWithoutFallback()

	/**
	 * Rows for the export tests: one on a readable schema, one on an unreadable one.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function exportRowsFixture(): array {
		$this->schemaMapper->method('find')->willReturnCallback(function ($id): Schema {
			if ((string)$id === '7') {
				return new Schema();
			}

			throw new DoesNotExistException('unreadable');
		});
		return [
			[
				'id' => 1, 'uuid' => 'a', 'action' => 'create', 'object' => 10, 'register' => 3, 'schema' => 7,
				'user' => 'alice', 'userName' => 'Alice', 'created' => '2026-01-01', 'size' => 12,
				'hash' => 'h1', 'previousHash' => 'h0', 'changed' => ['name' => ['old' => 'x', 'new' => 'y']],
				'session' => 's', 'request' => 'r', 'ipAddress' => '10.0.0.1', 'version' => '1.0',
			],
			[
				'id' => 2, 'uuid' => 'b', 'action' => 'update', 'object' => 11, 'register' => 3, 'schema' => 99,
				'user' => 'bob', 'created' => '2026-01-02', 'changed' => ['secret' => ['old' => 'p', 'new' => 'q']],
			],
		];
	}//end exportRowsFixture()

	/**
	 * Export content in every format; only the generation timestamp is ignored.
	 *
	 * @return void
	 */
	public function testExportRowsRendersEveryFormatExactly(): void {
		$rows = $this->exportRowsFixture();
		$results = [];
		foreach (['csv', 'JSON', 'xml', 'txt'] as $format) {
			$file = $this->service->exportRows($format, $rows, ['includeMetadata' => true]);
			$this->assertMatchesRegularExpression('/^audit_trails_\d{4}-\d\d-\d\d_\d\d-\d\d-\d\d\.' . strtolower($format) . '$/', $file['filename']);
			$content = (string)$file['content'];
			if ($format === 'txt') {
				$content = preg_replace('/^Audit Trail Export - Generated on .*\n/', 'HEADER' . "\n", $content);
			}

			$results[$format] = [$file['contentType'], md5($content), strlen($content)];
		}

		$this->assertSame(
			[
				'csv' => ['text/csv', '8b7ea9e4d056f41485f5663eb4a4373f', 330],
				'JSON' => ['application/json', '40aaa91e7d91a73b44fe319932da8ac6', 935],
				'xml' => ['application/xml', '157c7e668ef0bc1cd33daa0fdf0c5482', 775],
				'txt' => ['text/plain', '06369b0769a22bca088bc6929a4a76cc', 596],
			],
			$results
		);
	}//end testExportRowsRendersEveryFormatExactly()

	/**
	 * Masking: the readable schema's change stays, the unreadable schema's change is masked.
	 *
	 * @return void
	 */
	public function testExportMasksChangesOfAnUnreadableSchema(): void {
		$rows = $this->exportRowsFixture();
		$data = json_decode((string)$this->service->exportRows('json', $rows)['content'], true);

		$this->assertSame('{"name":{"old":"x","new":"y"}}', $data[0]['changes']);
		$this->assertStringNotContainsString('"p"', $data[1]['changes']);
		$this->assertStringNotContainsString('"q"', $data[1]['changes']);
		$this->assertArrayNotHasKey('session', $data[0]);
		$this->assertSame(
			['id', 'uuid', 'action', 'object', 'register', 'schema', 'user', 'userName', 'created', 'size', 'hash', 'previousHash', 'changes'],
			array_keys($data[0])
		);
	}//end testExportMasksChangesOfAnUnreadableSchema()

	/**
	 * Without changes, an empty CSV and an unknown format.
	 *
	 * @return void
	 */
	public function testExportEdgeCases(): void {
		$rows = $this->exportRowsFixture();
		$data = json_decode((string)$this->service->exportRows('json', $rows, ['includeChanges' => false])['content'], true);
		$this->assertArrayNotHasKey('changes', $data[1]);

		$empty = $this->service->exportRows('csv', []);
		$this->assertSame(['', 'text/csv'], [$empty['content'], $empty['contentType']]);
		$this->assertSame("<?xml version=\"1.0\"?>\n<auditTrails/>\n", $this->service->exportRows('xml', [])['content']);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unsupported export format: PDF');
		$this->service->exportRows('PDF', $rows);
	}//end testExportEdgeCases()

	/**
	 * exportLogs selects every matching row newest first, then renders them.
	 *
	 * @return void
	 */
	public function testExportLogsQueriesWithFiltersAndSearch(): void {
		$this->auditTrailMapper->expects($this->once())->method('findAll')
			->with(null, null, ['action' => 'delete'], ['created' => 'DESC'], 'needle')
			->willReturn([]);

		$file = $this->service->exportLogs('json', ['filters' => ['action' => 'delete'], 'search' => 'needle']);

		$this->assertSame('[]', $file['content']);
	}//end testExportLogsQueriesWithFiltersAndSearch()
}//end class
