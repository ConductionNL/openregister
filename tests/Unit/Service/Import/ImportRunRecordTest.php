<?php

/**
 * Unit tests for the import run as a durable record: the cause of its writes,
 * the written object on each row, the submitted row kept, and the retry lineage.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Import
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Import;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use InvalidArgumentException;
use OCA\OpenRegister\Db\ImportPreview;
use OCA\OpenRegister\Db\ImportPreviewMapper;
use OCA\OpenRegister\Db\ImportPreviewRow;
use OCA\OpenRegister\Db\ImportPreviewRowMapper;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Import\ConflictPolicy;
use OCA\OpenRegister\Service\Import\ImportCommit;
use OCA\OpenRegister\Service\Import\ImportPreviewMatching;
use OCA\OpenRegister\Service\Import\ImportPreviewService;
use OCA\OpenRegister\Service\Import\ImportPreviewStore;
use OCA\OpenRegister\Service\Import\ImportPreviewTargets;
use OCA\OpenRegister\Service\Import\MatchQuery;
use OCA\OpenRegister\Service\Import\MatchResolver;
use OCA\OpenRegister\Service\Import\SourceRowReader;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\MigrationPack\MappingEngine;
use OCA\OpenRegister\Service\MigrationPackService;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WriteCause;
use OCP\AppFramework\Db\DoesNotExistException;
use Opis\JsonSchema\ValidationResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ImportRunRecordTest extends TestCase {

	private array $rows = [];

	private ObjectService $objectService;

	private MatchResolver $matchResolver;

	private ImportPreviewMapper $previewMapper;

	private ImportPreviewService $service;

	private array $files = [];

	protected function setUp(): void {
		parent::setUp();
		WriteCause::reset();

		$this->rows = [];

		$this->previewMapper = $this->createMock(ImportPreviewMapper::class);
		$this->previewMapper->method('createFromArray')->willReturnCallback(
			static function (array $data): ImportPreview {
				$preview = new ImportPreview();
				foreach ($data as $key => $value) {
					$method = 'set'.ucfirst($key);
					$preview->$method($value);
				}

				$preview->setId(7);
				$preview->setUuid('run-7');

				return $preview;
			}
		);
		$this->previewMapper->method('persist')->willReturnArgument(0);

		$rowMapper = $this->createMock(ImportPreviewRowMapper::class);
		$rowMapper->method('persist')->willReturnCallback(
			function (ImportPreviewRow $row): ImportPreviewRow {
				if ($row->getId() === null) {
					$row->setId(count($this->rows) + 1);
					$this->rows[] = $row;
				}

				return $row;
			}
		);
		$rowMapper->method('findByPreview')->willReturnCallback(fn (): array => $this->rows);
		$rowMapper->method('findPendingWrites')->willReturnCallback(
			fn (): array => array_values(
				array_filter(
					$this->rows,
					static fn (ImportPreviewRow $row): bool => $row->writes() === true && $row->getAppliedAt() === null
				)
			)
		);

		$register = new Register();
		$register->setId(1);
		$schema = new Schema();
		$schema->setId(2);
		$schema->setProperties(['bsn' => ['type' => 'string'], 'naam' => ['type' => 'string']]);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		$importService = $this->createMock(ImportService::class);
		$importService->method('transformCsvRow')->willReturnCallback(
			static fn (array $rowData): array => $rowData + ['@self' => ['register' => 1, 'schema' => 2]]
		);

		$validateObject = $this->createMock(ValidateObject::class);
		$validateObject->method('validateObject')->willReturn(new ValidationResult(null));

		$this->objectService = $this->createMock(ObjectService::class);
		$this->matchResolver = $this->createMock(MatchResolver::class);
		$this->matchResolver->method('matchesOf')->willReturnCallback(
			static fn (MatchQuery $query): array => match ((string)($query->object['bsn'] ?? '')) {
				'111' => ['object-111'],
				default => [],
			}
		);

		$this->service = new ImportPreviewService(
			new ImportPreviewStore($this->previewMapper, $rowMapper, new SourceRowReader(), new NullLogger()),
			new ImportPreviewMatching(
				$this->matchResolver,
				$this->createMock(MappingEngine::class),
				$this->createMock(MigrationPackService::class),
				$importService
			),
			new ImportPreviewTargets(
				new SchemaCatalog($registerMapper, $schemaMapper, $this->createMock(MagicMapperInterface::class)),
				$validateObject,
				$this->objectService
			)
		);
	}

	protected function tearDown(): void {
		foreach ($this->files as $file) {
			if (is_file($file) === true) {
				unlink($file);
			}
		}

		WriteCause::reset();
		parent::tearDown();
	}

	private function csv(string $contents): string {
		$path = tempnam(sys_get_temp_dir(), 'or-import-run-test-').'.csv';
		file_put_contents($path, $contents);
		$this->files[] = $path;

		return $path;
	}

	private function params(string $path, string $policy, array $extra = []): array {
		return $extra + [
			'register' => 1,
			'schema' => 2,
			'filePath' => $path,
			'sourceName' => 'personen.csv',
			'policy' => $policy,
			'matchKey' => ['bsn'],
		];
	}

	private function rowAt(int $sourceRow): ImportPreviewRow {
		foreach ($this->rows as $row) {
			if ($row->getSourceRow() === $sourceRow) {
				return $row;
			}
		}

		$this->fail('No row '.$sourceRow);
	}

	public function testEveryWriteOfACommitIsCausedByThatRun(): void {
		$path = $this->csv("bsn,naam\n333,Yilmaz\n");
		$preview = $this->service->preview($this->params($path, ConflictPolicy::UPSERT));

		$seen = null;
		$this->objectService->expects($this->once())->method('saveObjects')->willReturnCallback(
			static function () use (&$seen): array {
				$seen = WriteCause::current();

				return ['saved' => [], 'updated' => []];
			}
		);

		$this->service->commit($preview, new ImportCommit(hash_file('sha256', $path)));

		$this->assertSame(['cause' => WriteCause::IMPORT, 'run' => 'run-7'], $seen);
		// The frame is popped once the commit is done: a later write is somebody's again.
		$this->assertSame(['cause' => WriteCause::PERSON, 'run' => null], WriteCause::current());
	}

	public function testTheFramePopsWhenTheCommitThrows(): void {
		$path = $this->csv("bsn,naam\n333,Yilmaz\n");
		$preview = $this->service->preview($this->params($path, ConflictPolicy::UPSERT));

		$this->previewMapper->method('persist')->willReturnCallback(
			static function (ImportPreview $preview): ImportPreview {
				if ($preview->getState() === ImportPreview::STATE_COMMITTED) {
					throw new \RuntimeException('database gone');
				}

				return $preview;
			}
		);
		$this->objectService->method('saveObjects')->willReturn(['saved' => [], 'updated' => []]);

		try {
			$this->service->commit($preview, new ImportCommit(hash_file('sha256', $path)));
			$this->fail('The commit should have thrown');
		} catch (\RuntimeException $exception) {
			$this->assertSame('database gone', $exception->getMessage());
		}

		$this->assertSame(WriteCause::PERSON, WriteCause::current()['cause']);
	}

	public function testAWrittenRowNamesTheObjectItCreatedAndUpdated(): void {
		$path = $this->csv("bsn,naam\n111,Jansen\n333,Yilmaz\n");
		$preview = $this->service->preview($this->params($path, ConflictPolicy::UPSERT));
		$this->objectService->method('saveObjects')->willReturn(['saved' => [], 'updated' => []]);

		$this->service->commit($preview, new ImportCommit(hash_file('sha256', $path)));

		$updated = $this->rowAt(2);
		$this->assertSame('object-111', $updated->getTargetUuid());
		$this->assertSame(ImportPreviewRow::OUTCOME_WRITTEN, $updated->getOutcome());

		$created = $this->rowAt(3);
		// The created object's uuid, the one minted on its payload at preview time.
		$this->assertSame($created->getPayload()['@self']['id'], $created->getTargetUuid());
		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string)$created->getTargetUuid());
		$this->assertSame(ImportPreviewRow::OUTCOME_WRITTEN, $created->getOutcome());
		$this->assertFalse($created->failed());
	}

	public function testAFailedWriteIsMarkedFailedAndCountsAsAFailedRow(): void {
		$path = $this->csv("bsn,naam\n333,Yilmaz\n");
		$preview = $this->service->preview($this->params($path, ConflictPolicy::UPSERT));
		$this->objectService->method('saveObjects')->willThrowException(new \RuntimeException('bulk down'));
		$this->objectService->method('saveObject')->willThrowException(new \RuntimeException('row refused'));

		$this->service->commit($preview, new ImportCommit(hash_file('sha256', $path)));

		$row = $this->rowAt(2);
		$this->assertSame(ImportPreviewRow::OUTCOME_FAILED, $row->getOutcome());
		$this->assertSame('The write failed: row refused', $row->getReason());
		$this->assertTrue($row->failed());
		$this->assertNull($row->getTargetUuid());
	}

	public function testEachRowKeepsTheShapeItWasSubmittedIn(): void {
		$path = $this->csv("bsn,naam\n111,Jansen\n");
		$this->service->preview($this->params($path, ConflictPolicy::CREATE_ONLY));

		$row = $this->rowAt(2);
		$this->assertSame(ImportPreviewRow::DECISION_REFUSE, $row->getDecision());
		// As the reader handed it over: a spreadsheet gives a numeric cell back as a number.
		$this->assertSame(['bsn' => 111, 'naam' => 'Jansen'], $row->getSource());
		$this->assertTrue($row->failed());
	}

	public function testARetryNamesTheOriginalRunAsItsCause(): void {
		$original = new ImportPreview();
		$original->setUuid('run-1');
		$this->previewMapper->expects($this->once())->method('findByUuid')->with('run-1')->willReturn($original);

		$path = $this->csv("bsn,naam\n333,Yilmaz\n");
		$retry = $this->service->createPreview($this->params($path, ConflictPolicy::UPSERT, ['causeRun' => 'run-1']));

		$this->assertSame('run-1', $retry->getCauseRun());
		$this->assertSame('run-7', $retry->getUuid());
		$this->assertSame('run-1', $retry->jsonSerialize()['causeRun']);
	}

	public function testARetryNamingNoRunIsRefused(): void {
		$this->previewMapper->method('findByUuid')->willThrowException(new DoesNotExistException('none'));
		$this->previewMapper->expects($this->never())->method('createFromArray');

		$path = $this->csv("bsn,naam\n333,Yilmaz\n");

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The import run "run-404" a retry names does not exist.');
		$this->service->createPreview($this->params($path, ConflictPolicy::UPSERT, ['causeRun' => 'run-404']));
	}

	public function testAFirstLoadNamesNoCause(): void {
		$this->previewMapper->expects($this->never())->method('findByUuid');

		$path = $this->csv("bsn,naam\n333,Yilmaz\n");
		$run = $this->service->createPreview($this->params($path, ConflictPolicy::UPSERT));

		$this->assertNull($run->getCauseRun());
		$this->assertFalse($run->jsonSerialize()['rowsPruned']);
	}
}
