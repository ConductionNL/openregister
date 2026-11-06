<?php

/**
 * Unit tests for ImportPreviewService — the preview, and the write that
 * applies it.
 *
 * WHAT THESE TESTS HOLD. That a preview decides every row and writes nothing,
 * that it counts what it decided against the declared policy, that a row
 * matching two objects is refused naming both, that a commit applies exactly
 * the decisions the preview kept, and that a commit naming a different file
 * is refused with nothing written.
 *
 * The mappers are doubled and the file is real: the reader parses an actual
 * CSV from disk, so a change that breaks parsing reddens here rather than
 * passing against a hand-built row array.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Import
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Import;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ImportPreview;
use OCA\OpenRegister\Db\ImportPreviewMapper;
use OCA\OpenRegister\Db\ImportPreviewRow;
use OCA\OpenRegister\Db\ImportPreviewRowMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Import\ConflictPolicy;
use OCA\OpenRegister\Service\Import\ImportPreviewRefusedException;
use OCA\OpenRegister\Service\Import\ImportPreviewService;
use OCA\OpenRegister\Service\Import\MatchResolver;
use OCA\OpenRegister\Service\Import\SourceRowReader;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\MigrationPack\MappingEngine;
use OCA\OpenRegister\Service\MigrationPackService;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\ObjectService;
use Opis\JsonSchema\ValidationResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ImportPreviewServiceTest extends TestCase {

	/**
	 * The preview record the doubled mapper keeps.
	 *
	 * @var ImportPreview|null
	 */
	private ?ImportPreview $preview = null;

	/**
	 * The decisions the doubled row mapper keeps.
	 *
	 * @var array<int, ImportPreviewRow>
	 */
	private array $rows = [];

	/**
	 * The write path, which most cases expect never to be called.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * The match lookup.
	 *
	 * @var MatchResolver&MockObject
	 */
	private MatchResolver $matchResolver;

	/**
	 * The service under test.
	 *
	 * @var ImportPreviewService
	 */
	private ImportPreviewService $service;

	/**
	 * Files written by a test, removed afterwards.
	 *
	 * @var array<int, string>
	 */
	private array $files = [];

	protected function setUp(): void {
		parent::setUp();

		$this->rows = [];
		$this->preview = null;

		$previewMapper = $this->createMock(ImportPreviewMapper::class);
		$previewMapper->method('createFromArray')->willReturnCallback(
			function (array $data): ImportPreview {
				$preview = new ImportPreview();
				foreach ($data as $key => $value) {
					$method = 'set'.ucfirst($key);

					try {
						$preview->$method($value);
					} catch (\Exception $exception) {
						// Unknown field, as the real mapper ignores.
					}
				}

				$preview->setId(1);
				$preview->setUuid('preview-uuid');
				$this->preview = $preview;

				return $preview;
			}
		);
		$previewMapper->method('persist')->willReturnArgument(0);

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
		$rowMapper->method('deleteByPreview')->willReturnCallback(
			function (): void {
				$this->rows = [];
			}
		);
		$rowMapper->method('findByPreview')->willReturnCallback(
			function (int $previewId, ?string $decision = null): array {
				if ($decision === null) {
					return $this->rows;
				}

				return array_values(
					array_filter(
						$this->rows,
						static fn (ImportPreviewRow $row): bool => $row->getDecision() === $decision
					)
				);
			}
		);
		$rowMapper->method('findPendingWrites')->willReturnCallback(
			function (): array {
				return array_values(
					array_filter(
						$this->rows,
						static fn (ImportPreviewRow $row): bool => $row->writes() === true
							&& $row->getAppliedAt() === null
					)
				);
			}
		);

		$register = new Register();
		$register->setId(1);
		$register->setSlug('migratie');

		$schema = new Schema();
		$schema->setId(2);
		$schema->setSlug('persoon');
		$schema->setProperties(['naam' => ['type' => 'string'], 'bsn' => ['type' => 'string']]);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		$importService = $this->createMock(ImportService::class);
		$importService->method('transformCsvRowToObject')->willReturnCallback(
			static function (array $rowData) use ($register, $schema): array {
				$object = $rowData;
				$object['@self'] = ['register' => $register->getId(), 'schema' => $schema->getId()];

				return $object;
			}
		);

		$validateObject = $this->createMock(ValidateObject::class);
		$validateObject->method('validateObject')->willReturn(new ValidationResult(null));

		$this->objectService = $this->createMock(ObjectService::class);
		$this->matchResolver = $this->createMock(MatchResolver::class);

		$this->service = new ImportPreviewService(
			$previewMapper,
			$rowMapper,
			new SourceRowReader(),
			$this->matchResolver,
			$this->createMock(MappingEngine::class),
			$this->createMock(MigrationPackService::class),
			$importService,
			$this->objectService,
			$validateObject,
			$registerMapper,
			$schemaMapper,
			new NullLogger()
		);
	}

	protected function tearDown(): void {
		foreach ($this->files as $file) {
			if (is_file($file) === true) {
				unlink($file);
			}
		}

		$this->files = [];
		parent::tearDown();
	}

	/**
	 * Write a CSV fixture and hand back its path.
	 *
	 * @param string $contents The file contents.
	 *
	 * @return string The path.
	 */
	private function csv(string $contents): string {
		$path = tempnam(sys_get_temp_dir(), 'or-import-test-').'.csv';
		file_put_contents($path, $contents);
		$this->files[] = $path;

		return $path;
	}

	/**
	 * The parameters a preview takes, with the policy under test.
	 *
	 * @param string $path The source file.
	 * @param string $policy The conflict policy.
	 *
	 * @return array<string, mixed> The parameters.
	 */
	private function params(string $path, string $policy): array {
		return [
			'register' => 'migratie',
			'schema' => 'persoon',
			'filePath' => $path,
			'sourceName' => 'personen.csv',
			'policy' => $policy,
			'matchKey' => ['bsn'],
		];
	}

	/**
	 * The decision the preview reached for one source row.
	 *
	 * @param int $rowNumber The row number.
	 *
	 * @return ImportPreviewRow The decision.
	 */
	private function decisionFor(int $rowNumber): ImportPreviewRow {
		foreach ($this->rows as $row) {
			if ($row->getRowNumber() === $rowNumber) {
				return $row;
			}
		}

		$this->fail('No decision was recorded for row '.$rowNumber);
	}

	public function testAMigrationIsInspectedBeforeItLands(): void {
		// Two of the three rows already exist; the third does not.
		$this->matchResolver->method('resolve')->willReturnCallback(
			static function (array $object): array {
				// A spreadsheet hands a numeric column back as an int, which
				// is exactly why MatchResolver stringifies before it filters.
				return match ((string)($object['bsn'] ?? '')) {
					'111' => ['object-111'],
					'222' => ['object-222'],
					default => [],
				};
			}
		);

		// The preview writes nothing. Not "writes nothing it should not":
		// nothing at all reaches the write path.
		$this->objectService->expects($this->never())->method('saveObjects');
		$this->objectService->expects($this->never())->method('saveObject');

		$path = $this->csv("bsn,naam\n111,Jansen\n222,De Vries\n333,Yilmaz\n");

		$preview = $this->service->preview($this->params($path, ConflictPolicy::UPSERT));

		$this->assertSame(ImportPreview::STATE_PREVIEWED, $preview->getState());
		$this->assertSame(3, $preview->getTotal());
		$this->assertSame(2, $preview->getToUpdate());
		$this->assertSame(1, $preview->getToCreate());
		$this->assertSame(0, $preview->getToSkip());
		$this->assertSame(0, $preview->getToRefuse());
	}

	public function testAFirstMigrationRefusesAnUnexpectedMatch(): void {
		$this->matchResolver->method('resolve')->willReturnCallback(
			static function (array $object): array {
				if ((string)($object['bsn'] ?? '') === '111') {
					return ['object-111'];
				}

				return [];
			}
		);

		$this->objectService->expects($this->never())->method('saveObjects');

		$path = $this->csv("bsn,naam\n111,Jansen\n333,Yilmaz\n");

		$preview = $this->service->preview($this->params($path, ConflictPolicy::CREATE_ONLY));

		$this->assertSame(1, $preview->getToRefuse());
		$this->assertSame(1, $preview->getToCreate());

		$refused = $this->decisionFor(2);
		$this->assertSame(ImportPreviewRow::DECISION_REFUSE, $refused->getDecision());
		$this->assertStringContainsString('object-111', (string)$refused->getReason());
	}

	public function testAnAmbiguousRowIsRefusedNamingBothObjects(): void {
		$this->matchResolver->method('resolve')->willReturn(['object-a', 'object-b']);

		$path = $this->csv("bsn,naam\n111,Jansen\n");

		$preview = $this->service->preview($this->params($path, ConflictPolicy::UPSERT));

		$this->assertSame(1, $preview->getToRefuse());

		$refused = $this->decisionFor(2);
		$this->assertSame(ImportPreviewRow::DECISION_REFUSE, $refused->getDecision());
		$this->assertStringContainsString('object-a', (string)$refused->getReason());
		$this->assertStringContainsString('object-b', (string)$refused->getReason());
		$this->assertSame(['object-a', 'object-b'], $refused->getCandidates());
		$this->assertNull($refused->getTargetUuid());
	}

	public function testTheReportNamesEveryRefusalAndItsReason(): void {
		$this->matchResolver->method('resolve')->willReturn(['object-a', 'object-b']);

		$path = $this->csv("bsn,naam\n111,Jansen\n");

		$report = $this->service->preview($this->params($path, ConflictPolicy::UPSERT))->getReport();

		$this->assertSame(ConflictPolicy::UPSERT, $report['policy']);
		$this->assertCount(1, $report['refusals']);
		$this->assertSame(2, $report['refusals'][0]['row']);
		$this->assertStringContainsString('more than one object', $report['refusals'][0]['reason']);
	}

	public function testTheWriteAppliesTheDecisionsThePreviewMade(): void {
		$this->matchResolver->method('resolve')->willReturnCallback(
			static function (array $object): array {
				if ((string)($object['bsn'] ?? '') === '111') {
					return ['object-111'];
				}

				return [];
			}
		);

		$path = $this->csv("bsn,naam\n111,Jansen\n333,Yilmaz\n");
		$preview = $this->service->preview($this->params($path, ConflictPolicy::UPSERT));

		$written = [];
		$this->objectService->expects($this->once())
			->method('saveObjects')
			->willReturnCallback(
				static function (array $objects) use (&$written): array {
					$written = $objects;

					return ['saved' => [], 'updated' => []];
				}
			);

		$committed = $this->service->commit(
			$preview,
			hash_file('sha256', $path)
		);

		$this->assertSame(ImportPreview::STATE_COMMITTED, $committed->getState());
		$this->assertSame(2, $committed->getApplied());
		$this->assertCount(2, $written);

		// The update carries the object the preview matched, so the write
		// updates that one and not whatever a second lookup would have found.
		$this->assertSame('object-111', $written[0]['@self']['id']);

		// The create now carries a uuid of its OWN, minted at decision time so
		// the row-by-row retry in writeBatch() cannot duplicate a row an earlier
		// committed chunk already wrote. This used to assert the create carried
		// NO id, which is what made that retry mint a fresh uuid every time.
		// What the assertion was really guarding — that a create does not target
		// the object the preview matched — is asserted directly instead.
		$this->assertArrayHasKey('id', $written[1]['@self']);
		$this->assertNotSame('object-111', $written[1]['@self']['id']);
	}

	public function testAChangedFileIsRefusedAndNothingIsWritten(): void {
		$this->matchResolver->method('resolve')->willReturn([]);

		$path = $this->csv("bsn,naam\n111,Jansen\n");
		$preview = $this->service->preview($this->params($path, ConflictPolicy::UPSERT));

		$this->objectService->expects($this->never())->method('saveObjects');
		$this->objectService->expects($this->never())->method('saveObject');

		$changed = $this->csv("bsn,naam\n111,Jansen\n999,Someone Else\n");

		$this->expectException(ImportPreviewRefusedException::class);
		$this->expectExceptionMessageMatches('/changed since it was previewed/');

		$this->service->commit($preview, hash_file('sha256', $changed));
	}

	/**
	 * A commit that names no file at all is the same failure as a commit
	 * naming the wrong one: it cannot say the decisions still describe what
	 * is being written.
	 *
	 * @return void
	 */
	public function testACommitThatNamesNoFileIsRefused(): void {
		$this->matchResolver->method('resolve')->willReturn([]);

		$path = $this->csv("bsn,naam\n111,Jansen\n");
		$preview = $this->service->preview($this->params($path, ConflictPolicy::UPSERT));

		$this->objectService->expects($this->never())->method('saveObjects');

		$this->expectException(ImportPreviewRefusedException::class);

		$this->service->commit($preview, null);
	}

	/**
	 * Committing twice writes once. The idempotence key is the stamp a real
	 * write leaves, not the decision, so the second commit finds nothing to do.
	 *
	 * @return void
	 */
	public function testARecommitDoesNotWriteTwice(): void {
		$this->matchResolver->method('resolve')->willReturn([]);

		$path = $this->csv("bsn,naam\n111,Jansen\n");
		$preview = $this->service->preview($this->params($path, ConflictPolicy::UPSERT));

		$this->objectService->expects($this->once())
			->method('saveObjects')
			->willReturn(['saved' => [], 'updated' => []]);

		$hash = hash_file('sha256', $path);
		$this->service->commit($preview, $hash);

		// The preview is committed now, so a second commit is refused on state
		// before it reaches the rows; and the rows are stamped, so even a
		// preview forced back to previewed would find no pending write.
		$this->assertSame([], array_filter(
			$this->rows,
			static fn (ImportPreviewRow $row): bool => $row->writes() === true && $row->getAppliedAt() === null
		));

		$this->expectException(ImportPreviewRefusedException::class);
		$this->service->commit($preview, $hash);
	}

	public function testACreateRowCarriesAStableUuidSoARetryCannotDuplicateIt(): void {
		// writeBatch() hands the batch to saveObjects() and, on a throw, walks
		// the batch row by row on the stated assumption that a throw means
		// nothing was written. Underneath, MagicBulkHandler::bulkUpsert() commits
		// each CHUNK in its own transaction and rolls back only the failing one,
		// so when a later chunk fails the earlier ones are already durable.
		//
		// A CREATE payload carried no id, so saveObject() minted a fresh uuid and
		// the retry re-created every committed row as a duplicate — reported as
		// succeeded, because for those rows the retry genuinely worked. UPDATE
		// rows were never affected: their targetUuid makes the retry idempotent.
		// Stamping the uuid at decision time gives CREATE rows the same property,
		// and it is stored on the payload, so a later commit() re-run of an
		// unstamped row upserts rather than duplicating too.
		$this->matchResolver->method('resolve')->willReturn([]);

		$path = $this->csv("bsn,naam\n111,Jansen\n");

		$this->service->preview($this->params($path, ConflictPolicy::CREATE_ONLY));

		$row = $this->decisionFor(2);
		$this->assertSame(ImportPreviewRow::DECISION_CREATE, $row->getDecision());

		$payload = ($row->getPayload() ?? []);
		$this->assertArrayHasKey('@self', $payload, 'a create payload must carry @self');
		$this->assertArrayHasKey('id', $payload['@self'], 'a create payload must carry a stable uuid');
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
			(string)$payload['@self']['id']
		);
	}//end testACreateRowCarriesAStableUuidSoARetryCannotDuplicateIt()

	public function testUpdateOnlySkipsRowsThatMatchNothing(): void {
		$this->matchResolver->method('resolve')->willReturn([]);

		$path = $this->csv("bsn,naam\n111,Jansen\n333,Yilmaz\n");

		$preview = $this->service->preview($this->params($path, ConflictPolicy::UPDATE_ONLY));

		$this->assertSame(2, $preview->getToSkip());
		$this->assertSame(0, $preview->getToCreate());
		$this->assertSame(0, $preview->getToUpdate());
		$this->assertStringContainsString('update-only', (string)$this->decisionFor(2)->getReason());
	}
}
