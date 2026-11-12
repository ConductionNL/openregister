<?php

/**
 * Unit tests for target resolution, progress checkpoints and staged-source cleanup of the import preview.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Import;

use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\ImportPreview;
use OCA\OpenRegister\Db\ImportPreviewMapper;
use OCA\OpenRegister\Db\ImportPreviewRowMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Import\ConflictPolicy;
use OCA\OpenRegister\Service\Import\ImportPreviewMatching;
use OCA\OpenRegister\Service\Import\ImportPreviewService;
use OCA\OpenRegister\Service\Import\ImportPreviewStore;
use OCA\OpenRegister\Service\Import\ImportPreviewTargets;
use OCA\OpenRegister\Service\Import\MatchResolver;
use OCA\OpenRegister\Service\Import\SourceRowReader;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\MigrationPack\MappingEngine;
use OCA\OpenRegister\Service\MigrationPackService;
use OCA\OpenRegister\Service\Object\ObjectValidatorInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use Opis\JsonSchema\ValidationResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Register and schema references, the every-50-rows checkpoint and the staged copy's removal.
 *
 * @covers \OCA\OpenRegister\Service\Import\ImportPreviewService
 */
class ImportPreviewServiceSourceTest extends TestCase {

	/**
	 * The preview mapper, mocked.
	 *
	 * @var ImportPreviewMapper&MockObject
	 */
	private ImportPreviewMapper&MockObject $previewMapper;

	/**
	 * The register mapper, mocked.
	 *
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registerMapper;

	/**
	 * The schema mapper, mocked.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * What createFromArray was handed.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $created = null;

	/**
	 * The states of every persist call, in order.
	 *
	 * @var list<array{0: string|null, 1: int|null, 2: string|null}>
	 */
	private array $persisted = [];

	/**
	 * Files to remove after a test.
	 *
	 * @var list<string>
	 */
	private array $files = [];

	/**
	 * The service under test.
	 *
	 * @var ImportPreviewService
	 */
	private ImportPreviewService $service;

	/**
	 * Build the service over mocked boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->previewMapper = $this->createMock(ImportPreviewMapper::class);
		$this->previewMapper->method('createFromArray')->willReturnCallback(
			function (array $data): ImportPreview {
				$this->created = $data;
				return new ImportPreview();
			}
		);
		$this->previewMapper->method('persist')->willReturnCallback(
			function (ImportPreview $preview): ImportPreview {
				$this->persisted[] = [$preview->getState(), $preview->getProcessed(), $preview->getSourcePath()];
				return $preview;
			}
		);

		$rowMapper = $this->createMock(ImportPreviewRowMapper::class);
		$rowMapper->method('persist')->willReturnArgument(0);
		$rowMapper->method('findByPreview')->willReturn([]);

		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper   = $this->createMock(SchemaMapper::class);

		$importService = $this->createMock(ImportService::class);
		$importService->method('transformCsvRow')->willReturnCallback(static fn (array $row): array => $row);
		$validator = $this->createMock(ObjectValidatorInterface::class);
		$validator->method('validateObject')->willReturn(new ValidationResult(null));
		$matchResolver = $this->createMock(MatchResolver::class);
		$matchResolver->method('matchesOf')->willReturn([]);

		$this->service = new ImportPreviewService(
			new ImportPreviewStore($this->previewMapper, $rowMapper, new SourceRowReader(), new NullLogger()),
			new ImportPreviewMatching(
				$matchResolver,
				$this->createMock(MappingEngine::class),
				$this->createMock(MigrationPackService::class),
				$importService
			),
			new ImportPreviewTargets(
				new SchemaCatalog($this->registerMapper, $this->schemaMapper, $this->createMock(MagicMapperInterface::class)),
				$validator,
				$this->createMock(ObjectOperationsInterface::class)
			)
		);

	}//end setUp()

	/**
	 * Remove the files a test wrote.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->files as $file) {
			if (is_file($file) === true) {
				unlink($file);
			}
		}

	}//end tearDown()

	/**
	 * Write a CSV source with a header and the given number of rows.
	 *
	 * @param int    $rows   The row count.
	 * @param string $prefix The file name prefix.
	 *
	 * @return string The path.
	 */
	private function csv(int $rows, string $prefix = 'or-preview-src-'): string {
		$path  = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(4)) . '.csv';
		$lines = ['bsn'];
		for ($i = 1; $i <= $rows; $i++) {
			$lines[] = (string)$i;
		}

		file_put_contents($path, implode("\n", $lines) . "\n");
		$this->files[] = $path;

		return $path;

	}//end csv()

	/**
	 * A pending preview over a source.
	 *
	 * @param string $path The source path.
	 *
	 * @return ImportPreview
	 */
	private function pending(string $path): ImportPreview {
		$register = new Register();
		$register->setId(1);
		$this->registerMapper->method('find')->willReturn($register);
		$schema = new Schema();
		$schema->setId(2);
		$schema->setProperties(['bsn' => ['type' => 'string']]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$preview = new ImportPreview();
		$preview->setId(7);
		$preview->setRegisterId(1);
		$preview->setSchemaId(2);
		$preview->setPolicy(ConflictPolicy::CREATE_ONLY);
		$preview->setMatchKey([]);
		$preview->setSourcePath($path);
		$preview->setSourceFormat('csv');

		return $preview;

	}//end pending()

	/**
	 * Register and schema entities passed in are used as they are, without a lookup.
	 *
	 * @return void
	 */
	public function testEntitiesPassedInAreUsedWithoutALookup(): void {
		$register = new Register();
		$register->setId(11);
		$schema = new Schema();
		$schema->setId(22);
		$this->registerMapper->expects($this->never())->method('find');
		$this->schemaMapper->expects($this->never())->method('find');

		$this->service->createPreview(['filePath' => $this->csv(1), 'register' => $register, 'schema' => $schema]);

		$this->assertSame(11, $this->created['registerId']);
		$this->assertSame(22, $this->created['schemaId']);

	}//end testEntitiesPassedInAreUsedWithoutALookup()

	/**
	 * An unknown register is refused by name.
	 *
	 * @return void
	 */
	public function testAnUnknownRegisterIsRefused(): void {
		$this->registerMapper->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->previewMapper->expects($this->never())->method('createFromArray');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Register "migratie" was not found.');

		$this->service->createPreview(['filePath' => $this->csv(1), 'register' => 'migratie', 'schema' => 'p']);

	}//end testAnUnknownRegisterIsRefused()

	/**
	 * An empty schema reference is refused.
	 *
	 * @return void
	 */
	public function testAnEmptySchemaIsRefused(): void {
		$this->registerMapper->method('find')->willReturn(new Register());
		$this->schemaMapper->expects($this->never())->method('find');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A schema is required to preview an import.');

		$this->service->createPreview(['filePath' => $this->csv(1), 'register' => 'r', 'schema' => '']);

	}//end testAnEmptySchemaIsRefused()

	/**
	 * Progress is checkpointed every fifty rows, not on every row.
	 *
	 * @return void
	 */
	public function testProgressIsCheckpointedEveryFiftyRows(): void {
		$preview = $this->pending($this->csv(101));

		$this->service->runPreview($preview);

		$checkpoints = array_values(
			array_filter(
				$this->persisted,
				static fn (array $p): bool => $p[0] === ImportPreview::STATE_PREVIEWING
			)
		);
		$this->assertSame([0, 0, 50, 100], array_column($checkpoints, 1), 'start, reset, then rows 50 and 100');
		$this->assertSame(ImportPreview::STATE_PREVIEWED, $preview->getState());
		$this->assertSame(101, $preview->getProcessed());

	}//end testProgressIsCheckpointedEveryFiftyRows()

	/**
	 * A staged copy of the source is deleted after the preview and forgotten.
	 *
	 * @return void
	 */
	public function testAStagedSourceIsRemovedAfterThePreview(): void {
		$path    = $this->csv(2, SourceRowReader::STAGE_PREFIX);
		$preview = $this->pending($path);

		$this->service->runPreview($preview);

		$this->assertFileDoesNotExist($path);
		$this->assertNull($preview->getSourcePath());
		$last = end($this->persisted);
		$this->assertSame([ImportPreview::STATE_PREVIEWED, 2, null], $last, 'the forgotten path is persisted');

	}//end testAStagedSourceIsRemovedAfterThePreview()

	/**
	 * A source the caller owns (not staged) is left where it is.
	 *
	 * @return void
	 */
	public function testAnUnstagedSourceIsKept(): void {
		$path    = $this->csv(2);
		$preview = $this->pending($path);

		$this->service->runPreview($preview);

		$this->assertFileExists($path);
		$this->assertSame($path, $preview->getSourcePath());

	}//end testAnUnstagedSourceIsKept()

	/**
	 * A staged path whose file is already gone is still forgotten.
	 *
	 * @return void
	 */
	public function testAStagedPathWithoutAFileIsForgotten(): void {
		$path = $this->csv(1, SourceRowReader::STAGE_PREFIX);
		$preview = $this->pending($path);
		unlink($path);

		try {
			$this->service->runPreview($preview);
			$this->fail('reading a missing source succeeded');
		} catch (\Throwable $e) {
			$this->assertSame(ImportPreview::STATE_FAILED, $preview->getState());
		}

		$this->assertNull($preview->getSourcePath());

	}//end testAStagedPathWithoutAFileIsForgotten()
}//end class
