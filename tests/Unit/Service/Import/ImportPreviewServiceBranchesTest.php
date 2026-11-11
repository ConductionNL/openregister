<?php

/**
 * Characterisation tests for the branches of ImportPreviewService that the
 * scenario tests do not reach: every reason createPreview refuses, every
 * reason a row is refused while deciding, the row-by-row fallback when a
 * batch write throws, and the failed state a throwing commit leaves.
 *
 * Each assertion pins the exact outcome and message, so a refactor that
 * reorders a check or rewords a refusal reddens here.
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

use InvalidArgumentException;
use OCA\OpenRegister\Service\Import\ImportCommit;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\ImportPreview;
use OCA\OpenRegister\Db\ImportPreviewMapper;
use OCA\OpenRegister\Db\ImportPreviewRow;
use OCA\OpenRegister\Db\ImportPreviewRowMapper;
use OCA\OpenRegister\Db\MigrationPack;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Import\ConflictPolicy;
use OCA\OpenRegister\Service\Import\ImportPreviewMatching;
use OCA\OpenRegister\Service\Import\ImportPreviewRefusedException;
use OCA\OpenRegister\Service\Import\ImportPreviewService;
use OCA\OpenRegister\Service\Import\ImportPreviewStore;
use OCA\OpenRegister\Service\Import\ImportPreviewTargets;
use OCA\OpenRegister\Service\Import\MatchLookupFailedException;
use OCA\OpenRegister\Service\Import\MatchResolver;
use OCA\OpenRegister\Service\Import\SourceRowReader;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\MigrationPack\MappingEngine;
use OCA\OpenRegister\Service\MigrationPackService;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use Opis\JsonSchema\ValidationResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;

final class ImportPreviewServiceBranchesTest extends TestCase {

	/**
	 * The decisions the doubled row mapper keeps.
	 *
	 * @var array<int, ImportPreviewRow>
	 */
	private array $rows = [];

	/**
	 * The data each createFromArray call received.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $created = null;

	/**
	 * The write path.
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
	 * The mapping engine.
	 *
	 * @var MappingEngine&MockObject
	 */
	private MappingEngine $mappingEngine;

	/**
	 * The saved-mapping lookup.
	 *
	 * @var MigrationPackService&MockObject
	 */
	private MigrationPackService $packService;

	/**
	 * The row transform.
	 *
	 * @var ImportService&MockObject
	 */
	private ImportService $importService;

	/**
	 * The schema validation.
	 *
	 * @var ValidateObject&MockObject
	 */
	private ValidateObject $validateObject;

	/**
	 * The register lookup.
	 *
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper $registerMapper;

	/**
	 * The schema lookup.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper $schemaMapper;

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
		$this->created = null;

		$previewMapper = $this->createMock(ImportPreviewMapper::class);
		$previewMapper->method('createFromArray')->willReturnCallback(
			function (array $data): ImportPreview {
				$this->created = $data;
				$preview = new ImportPreview();
				foreach ($data as $key => $value) {
					$method = 'set'.ucfirst($key);
					$preview->$method($value);
				}

				$preview->setId(1);

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
			function (int $previewId, ListQuery $query): array {
				$decision = ($query->filters['decision'] ?? null);
				return array_values(
					array_filter(
						$this->rows,
						static fn (ImportPreviewRow $row): bool => $decision === null || $row->getDecision() === $decision
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
		$schema = new Schema();
		$schema->setId(2);
		$schema->setProperties(['naam' => ['type' => 'string'], 'bsn' => ['type' => 'string']]);

		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->registerMapper->method('find')->willReturn($register);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->importService = $this->createMock(ImportService::class);
		$this->validateObject = $this->createMock(ValidateObject::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->matchResolver = $this->createMock(MatchResolver::class);
		$this->mappingEngine = $this->createMock(MappingEngine::class);
		$this->packService = $this->createMock(MigrationPackService::class);

		$this->service = new ImportPreviewService(
			new ImportPreviewStore($previewMapper, $rowMapper, new SourceRowReader(), new NullLogger()),
			new ImportPreviewMatching($this->matchResolver, $this->mappingEngine, $this->packService, $this->importService),
			new ImportPreviewTargets(
				new SchemaCatalog($this->registerMapper, $this->schemaMapper, $this->createMock(MagicMapperInterface::class)),
				$this->validateObject,
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

		$this->files = [];
		parent::tearDown();
	}

	private function file(string $contents, string $extension = '.csv'): string {
		$path = tempnam(sys_get_temp_dir(), 'or-import-branch-').$extension;
		file_put_contents($path, $contents);
		$this->files[] = $path;

		return $path;
	}

	/**
	 * The preview parameters, with overrides.
	 *
	 * @param string $path The source file.
	 * @param array<string, mixed> $extra Overrides.
	 *
	 * @return array<string, mixed> The parameters.
	 */
	private function params(string $path, array $extra = []): array {
		return array_merge(
			[
				'register' => 'migratie',
				'schema' => 'persoon',
				'filePath' => $path,
				'sourceName' => 'personen.csv',
				'policy' => ConflictPolicy::UPSERT,
				'matchKey' => ['bsn'],
			],
			$extra
		);
	}

	private function transformAsIs(): void {
		$this->importService->method('transformCsvRow')->willReturnCallback(
			static fn (array $rowData): array => $rowData
		);
	}

	private function valid(): void {
		$this->validateObject->method('validateObject')->willReturn(new ValidationResult(null));
	}

	private function pack(array $definition): void {
		$pack = $this->createMock(MigrationPack::class);
		$pack->method('getDefinitionArray')->willReturn($definition);
		$this->packService->method('findByPackSlug')->willReturn($pack);
	}

	public function testAMissingFileIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A source file is required to preview an import.');

		$this->service->createPreview(['filePath' => '']);
	}

	public function testANonexistentFileIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A source file is required to preview an import.');

		$this->service->createPreview(['filePath' => '/nonexistent/or-import-'.uniqid()]);
	}

	public function testAnUndeterminableFormatIsRefused(): void {
		$path = $this->file('x', '.bin');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(
			'The source format could not be determined from "upload.bin". Pass format as one of: csv, excel, json.'
		);

		$this->service->createPreview(['filePath' => $path, 'sourceName' => 'upload.bin']);
	}

	public function testTheSourceNameDefaultsToTheFileNameAndTheFormatToItsExtension(): void {
		$path = $this->file("bsn\n1\n");
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		$this->service->createPreview(
			['filePath' => $path, 'register' => 1, 'schema' => 2, 'packSlug' => '', 'matchKey' => 'bsn, naam'],
			$user
		);

		$this->assertSame(basename($path), $this->created['sourceName']);
		$this->assertSame('csv', $this->created['sourceFormat']);
		$this->assertNull($this->created['packSlug']);
		$this->assertSame(['bsn', 'naam'], $this->created['matchKey']);
		$this->assertSame(1, $this->created['registerId']);
		$this->assertSame(2, $this->created['schemaId']);
		$this->assertSame(hash_file('sha256', $path), $this->created['sourceHash']);
		$this->assertSame(ImportPreview::STATE_PENDING, $this->created['state']);
		$this->assertSame('alice', $this->created['createdBy']);
	}

	public function testAMissingRegisterIsRefused(): void {
		$path = $this->file("bsn\n1\n");

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A register is required to preview an import.');

		$this->service->createPreview(['filePath' => $path, 'schema' => 2]);
	}

	public function testAnUnknownSchemaIsRefused(): void {
		$path = $this->file("bsn\n1\n");
		// Rebuild a service whose schema lookup throws.
		$throwing = $this->createMock(SchemaMapper::class);
		$throwing->method('find')->willThrowException(new DoesNotExistException('nope'));
		$service = new ImportPreviewService(
			new ImportPreviewStore(
				$this->createMock(ImportPreviewMapper::class),
				$this->createMock(ImportPreviewRowMapper::class),
				new SourceRowReader(),
				new NullLogger()
			),
			new ImportPreviewMatching($this->matchResolver, $this->mappingEngine, $this->packService, $this->importService),
			new ImportPreviewTargets(
				new SchemaCatalog($this->registerMapper, $throwing, $this->createMock(MagicMapperInterface::class)),
				$this->validateObject,
				$this->objectService
			)
		);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Schema "zaak" was not found.');

		$service->createPreview(['filePath' => $path, 'register' => 1, 'schema' => 'zaak']);
	}

	public function testAnUnknownPackIsRefusedBeforeAPreviewExists(): void {
		$path = $this->file("bsn\n1\n");
		$this->packService->method('findByPackSlug')->willThrowException(new DoesNotExistException('nope'));

		try {
			$this->service->createPreview($this->params($path, ['packSlug' => 'zaken-v1']));
			$this->fail('An unknown pack must be refused.');
		} catch (InvalidArgumentException $exception) {
			$this->assertSame('Column mapping "zaken-v1" was not found.', $exception->getMessage());
		}

		$this->assertNull($this->created);
	}

	public function testAPackOntoUnknownPropertiesIsRefused(): void {
		$path = $this->file("bsn\n1\n");
		$this->pack(['fieldMappings' => [['source' => 'a', 'target' => 'nope']]]);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Column mapping "zaken-v1" maps onto a property the schema does not have: nope.');

		$this->service->createPreview($this->params($path, ['packSlug' => 'zaken-v1']));
	}

	public function testAPackOntoSeveralUnknownPropertiesNamesThemAll(): void {
		$path = $this->file("bsn\n1\n");
		$this->pack(['fieldMappings' => [['target' => 'x'], ['target' => 'y']]]);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Column mapping "zaken-v1" maps onto properties the schema does not have: x, y.');

		$this->service->createPreview($this->params($path, ['packSlug' => 'zaken-v1']));
	}

	public function testAMappingErrorRefusesTheRowNamingEachColumn(): void {
		$path = $this->file("bsn,naam\n1,a\n");
		$this->pack(['fieldMappings' => [['target' => 'bsn']]]);
		$this->mappingEngine->method('mapRow')->willReturn(
			[
				'data' => [],
				'errors' => [
					['source' => 'bsn', 'message' => 'not a number'],
					['source' => 'naam', 'message' => 'too long'],
				],
			]
		);
		$this->importService->expects($this->never())->method('transformCsvRow');

		$preview = $this->service->preview($this->params($path, ['packSlug' => 'zaken-v1']));

		$this->assertSame(1, $preview->getToRefuse());
		$this->assertSame(
			'The column mapping refused this row. source column "bsn": not a number; source column "naam": too long',
			$this->rows[0]->getReason()
		);
		$this->assertSame([], $this->rows[0]->getPayload());
	}

	public function testAMappedRowIsTransformedFromTheMappedData(): void {
		$path = $this->file("bsn,naam\n1,a\n");
		$this->pack(['fieldMappings' => [['target' => 'bsn']]]);
		$this->mappingEngine->method('mapRow')->willReturn(['data' => ['bsn' => 'mapped'], 'errors' => []]);
		$this->transformAsIs();
		$this->valid();
		$this->matchResolver->method('matchesOf')->willReturn(['object-1']);

		$this->service->preview($this->params($path, ['packSlug' => 'zaken-v1']));

		$this->assertSame(ImportPreviewRow::DECISION_UPDATE, $this->rows[0]->getDecision());
		$this->assertSame(['bsn' => 'mapped', '@self' => ['id' => 'object-1']], $this->rows[0]->getPayload());
		$this->assertSame('object-1', $this->rows[0]->getTargetUuid());
		$this->assertSame(['object-1'], $this->rows[0]->getCandidates());
	}

	public function testATransformThatThrowsRefusesTheRow(): void {
		$path = $this->file("bsn\n1\n");
		$this->importService->method('transformCsvRow')->willThrowException(new RuntimeException('bad date'));

		$this->service->preview($this->params($path));

		$this->assertSame(ImportPreviewRow::DECISION_REFUSE, $this->rows[0]->getDecision());
		$this->assertSame('The row could not be mapped onto the schema: bad date', $this->rows[0]->getReason());
		$this->assertSame([], $this->rows[0]->getPayload());
	}

	public function testAnInvalidRowIsRefusedKeepingItsPayload(): void {
		$path = $this->file("bsn\n1\n");
		$this->transformAsIs();
		$result = $this->createMock(ValidationResult::class);
		$result->method('isValid')->willReturn(false);
		$this->validateObject->method('validateObject')->willReturn($result);
		$this->validateObject->method('generateErrorMessage')->willReturn('bsn must be 9 digits');
		$this->matchResolver->expects($this->never())->method('matchesOf');

		$this->service->preview($this->params($path));

		$this->assertSame(
			'The row does not validate against the schema: bsn must be 9 digits',
			$this->rows[0]->getReason()
		);
		$this->assertSame(['bsn' => 1], $this->rows[0]->getPayload());
		$this->assertSame(2, $this->rows[0]->getSourceRow());
	}

	public function testAFailedLookupRefusesTheRowKeepingItsPayload(): void {
		$path = $this->file("bsn\n1\n");
		$this->transformAsIs();
		$this->valid();
		$this->matchResolver->method('matchesOf')->willThrowException(
			new MatchLookupFailedException('The match key could not be resolved: db down')
		);

		$preview = $this->service->preview($this->params($path));

		$this->assertSame(1, $preview->getToRefuse());
		$this->assertSame('The match key could not be resolved: db down', $this->rows[0]->getReason());
		$this->assertSame(['bsn' => 1], $this->rows[0]->getPayload());
		$this->assertSame([], $this->rows[0]->getCandidates());
		$this->assertNull($this->rows[0]->getTargetUuid());
	}

	public function testASkippedRowCarriesNoNewId(): void {
		$path = $this->file("bsn\n1\n");
		$this->transformAsIs();
		$this->valid();
		$this->matchResolver->method('matchesOf')->willReturn([]);

		$this->service->preview($this->params($path, ['policy' => ConflictPolicy::UPDATE_ONLY]));

		$this->assertSame(ImportPreviewRow::DECISION_SKIP, $this->rows[0]->getDecision());
		$this->assertSame(['bsn' => 1], $this->rows[0]->getPayload());
	}

	public function testAThrowingBatchIsWrittenRowByRow(): void {
		$path = $this->file("bsn\n1\n2\n");
		$this->transformAsIs();
		$this->valid();
		$this->matchResolver->method('matchesOf')->willReturn([]);
		$preview = $this->service->preview($this->params($path));

		$user = $this->createMock(IUser::class);
		$second = $this->rows[1]->getPayload()['@self']['id'];
		$this->objectService->expects($this->once())->method('saveObjects')->willThrowException(new RuntimeException('chunk'));
		$seen = [];
		$this->objectService->expects($this->exactly(2))->method('saveObject')->willReturnCallback(
			static function (array|ObjectEntity $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true) use (&$seen, $second): ObjectEntity {
				$rest = [$options->extend, $options->target->register, $options->target->schema, $options->uuid, $_rbac, $_multitenancy, $options->policy->silent, $options->policy->validation, $options->uploadedFiles, $options->actor->currentUser, $options->policy->failIfExists, $options->actor->unowned, $options->policy->dedupOverride, $options->actor->owner];
				// Captured, not asserted: writeRow() catches every Throwable.
				$seen[] = ['id' => $object['@self']['id'], 'validation' => $rest[7], 'user' => $rest[9]];
				if ($object['@self']['id'] === $second) {
					throw new RuntimeException('locked');
				}

				return new ObjectEntity();
			}
		);

		$committed = $this->service->commit($preview, new ImportCommit(hash_file('sha256', $path), $user));

		$this->assertSame(ImportPreview::STATE_COMMITTED, $committed->getState());
		$this->assertCount(2, $seen);
		$this->assertFalse($seen[0]['validation']);
		$this->assertSame($user, $seen[0]['user']);
		$this->assertSame($second, $seen[1]['id']);
		$this->assertSame(1, $committed->getApplied());
		$this->assertSame(1, $committed->getFailed());
		$this->assertSame('The write failed: locked', $this->rows[1]->getReason());
		$this->assertNotNull($this->rows[0]->getAppliedAt());
		$this->assertNotNull($this->rows[1]->getAppliedAt());
		$this->assertSame(1, $committed->getReport()['applied']);
		$this->assertSame(1, $committed->getReport()['failed']);
	}

	public function testABulkRefusalWithoutAnErrorFallsBackToValidationFailed(): void {
		$path = $this->file("bsn\n1\n");
		$this->transformAsIs();
		$this->valid();
		$this->matchResolver->method('matchesOf')->willReturn([]);
		$preview = $this->service->preview($this->params($path));
		$id = $this->rows[0]->getPayload()['@self']['id'];

		$this->objectService->method('saveObjects')->willReturn(['invalid' => [['@self' => ['id' => $id]]]]);

		$this->service->commit($preview, new ImportCommit(hash_file('sha256', $path)));

		$this->assertSame('The write failed: Validation failed', $this->rows[0]->getReason());
		$this->assertSame(0, $preview->getApplied());
		$this->assertSame(1, $preview->getFailed());
	}

	public function testACommitInTheWrongStateIsRefused(): void {
		$preview = new ImportPreview();
		$preview->setState(ImportPreview::STATE_PENDING);

		$this->expectException(ImportPreviewRefusedException::class);
		$this->expectExceptionMessage(
			'This import is in state "pending". Only a completed preview can be committed.'
		);

		$this->service->commit($preview, new ImportCommit('abc'));
	}

	public function testAnEmptyHashIsRefused(): void {
		$preview = new ImportPreview();
		$preview->setState(ImportPreview::STATE_PREVIEWED);

		$this->expectException(ImportPreviewRefusedException::class);
		$this->expectExceptionMessage(
			'A commit must name the source file it is committing. Send the file again, or pass its sha256 as sourceHash.'
		);

		$this->service->commit($preview, new ImportCommit(''));
	}

	public function testAThrowingCommitLeavesThePreviewFailed(): void {
		$path = $this->file("bsn\n1\n");
		$this->transformAsIs();
		$this->valid();
		$this->matchResolver->method('matchesOf')->willReturn([]);
		$preview = $this->service->preview($this->params($path));

		$this->objectService->method('saveObjects')->willReturn(['saved' => []]);
		$preview->setId(null);
		$broken = $this->createMock(ImportPreviewRowMapper::class);
		$broken->method('findPendingWrites')->willThrowException(new RuntimeException('db gone'));
		$service = new ImportPreviewService(
			new ImportPreviewStore(
				$this->createMock(ImportPreviewMapper::class),
				$broken,
				new SourceRowReader(),
				new NullLogger()
			),
			new ImportPreviewMatching($this->matchResolver, $this->mappingEngine, $this->packService, $this->importService),
			new ImportPreviewTargets(
				new SchemaCatalog($this->registerMapper, $this->schemaMapper, $this->createMock(MagicMapperInterface::class)),
				$this->validateObject,
				$this->objectService
			)
		);

		try {
			$service->commit($preview, new ImportCommit(hash_file('sha256', $path)));
			$this->fail('The commit must rethrow.');
		} catch (RuntimeException $exception) {
			$this->assertSame('db gone', $exception->getMessage());
		}

		$this->assertSame(ImportPreview::STATE_FAILED, $preview->getState());
		$this->assertSame(['error' => 'db gone'], $preview->getReport());
	}

	public function testAnUnreadableSourceLeavesThePreviewFailed(): void {
		$path = $this->file('{"not": "a list"}', '.json');

		try {
			$this->service->preview($this->params($path, ['sourceName' => 'x.json']));
			$this->fail('An unreadable source must rethrow.');
		} catch (InvalidArgumentException $exception) {
			$this->assertStringStartsWith('The source file holds no list of objects.', $exception->getMessage());
		}
	}
}
