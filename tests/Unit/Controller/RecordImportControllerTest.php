<?php

/**
 * The record-import surface: an uploaded file's proposed mapping, and an import with the confirmed one.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\RecordImportController;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Import\RecordImport\ColumnMapper;
use OCA\OpenRegister\Service\Import\RecordImport\FailedRowExport;
use OCA\OpenRegister\Service\Import\RecordImport\RecordFileReader;
use OCA\OpenRegister\Service\Import\RecordImport\RecordImportRequest;
use OCA\OpenRegister\Service\Import\RecordImport\RecordImportService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The reader, mapper and failed-row export are real; the register, schema and import service are doubles.
 *
 * @covers \OCA\OpenRegister\Controller\RecordImportController
 * @uses   \OCA\OpenRegister\Service\Import\RecordImport\ColumnMapper
 * @uses   \OCA\OpenRegister\Service\Import\RecordImport\ConfirmedMapping
 * @uses   \OCA\OpenRegister\Service\Import\RecordImport\FailedRowExport
 * @uses   \OCA\OpenRegister\Service\Import\RecordImport\RecordFileReader
 * @uses   \OCA\OpenRegister\Service\Import\RecordImport\RecordImportRequest
 * @uses   \OCA\OpenRegister\Service\Import\RecordImport\UnconfirmedMappingException
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Register
 */
class RecordImportControllerTest extends TestCase {

	private const CSV = "reference;name;postcode\nA1;Alpha;1011AB\nA2;Beta;bad\n";

	/** @var list<string> */
	private array $files = [];

	private RecordImportService&MockObject $importer;

	private Register $register;

	private Schema $schema;

	protected function setUp(): void {
		$this->importer = $this->createMock(RecordImportService::class);
		$this->register = new Register();
		$this->register->setId(5);
		$this->schema = new Schema();
		$this->schema->setId(7);
		$this->schema->setProperties(['reference' => ['type' => 'string'], 'name' => ['type' => 'string']]);
	}

	protected function tearDown(): void {
		foreach ($this->files as $file) {
			@unlink($file);
		}
	}

	public function testTheMappingProposesAndListsTheUnmatchedColumnsWithoutImporting(): void {
		$this->importer->expects($this->never())->method('import');

		$response = $this->controller($this->request(file: $this->upload(self::CSV, 'people.csv')))->mapping('5', '7');

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('csv', $data['format']);
		$this->assertSame(['reference', 'name', 'postcode'], $data['columns']);
		$this->assertSame(['reference', 'name'], $data['properties']);
		$this->assertSame(['reference' => 'reference', 'name' => 'name'], $data['mapped']);
		$this->assertSame(['postcode'], $data['unmatched']);
	}

	public function testAnUndecidedColumnRefusesTheImport(): void {
		$this->importer->expects($this->never())->method('import');

		$response = $this->controller($this->request(
			file: $this->upload(self::CSV, 'people.csv'),
			params: ['decisions' => ['reference' => 'reference', 'name' => 'name']]
		))->import('5', '7');

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(['postcode'], $response->getData()['columns']);
	}

	public function testAConfirmedImportRunsWithTheMappingAndCarriesTheFailedRows(): void {
		$path = $this->upload(self::CSV, 'people.csv');
		$this->importer->expects($this->once())->method('import')
			->with($this->callback(function (RecordImportRequest $request) use ($path): bool {
				return $request->path === $path
					&& $request->format === RecordFileReader::CSV
					&& $request->register === $this->register
					&& $request->schema === $this->schema
					&& $request->mapping->map === ['reference' => 'reference', 'name' => 'name']
					&& $request->mapping->ignored === ['postcode']
					&& $request->mapping->identifier === 'reference';
			}))
			->willReturn(['createdCount' => 1, 'failedCount' => 1, 'failures' => [['line' => 3, 'reason' => 'invalid']]]);

		$response = $this->controller($this->request(
			file: $path,
			params: ['decisions' => json_encode(['reference' => 'reference', 'name' => 'name', 'postcode' => null]), 'identifier' => 'reference']
		))->import('5', '7');

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(1, $data['createdCount']);
		$this->assertSame("reference,name,postcode,reason\nA2,Beta,bad,invalid\n", $data['failedRows']);
		$this->assertSame(0, $data['failedRowsUnplaced']);
	}

	public function testACleanImportCarriesNoFailedRowsAndAnEmptyIdentifierCreatesOnly(): void {
		$this->importer->expects($this->once())->method('import')
			->with($this->callback(static fn (RecordImportRequest $request): bool => $request->mapping->identifier === null))
			->willReturn(['createdCount' => 2, 'failedCount' => 0, 'failures' => []]);

		$response = $this->controller($this->request(
			file: $this->upload(self::CSV, 'people.csv'),
			params: ['decisions' => ['reference' => 'reference', 'name' => 'name', 'postcode' => null], 'identifier' => '  ']
		))->import('5', '7');

		$this->assertSame(200, $response->getStatus());
		$this->assertArrayNotHasKey('failedRows', $response->getData());
	}

	public function testDecisionsThatAreNotAColumnMapAreRefused(): void {
		$this->importer->expects($this->never())->method('import');

		$controller = $this->controller($this->request(file: $this->upload(self::CSV, 'people.csv'), params: ['decisions' => 'not json']));
		$this->assertSame(400, $controller->import('5', '7')->getStatus());

		$controller = $this->controller($this->request(file: $this->upload(self::CSV, 'people.csv'), params: ['decisions' => ['name' => 42]]));
		$this->assertSame(400, $controller->import('5', '7')->getStatus());
	}

	public function testAMissingOrFailedUploadIsRefused(): void {
		$this->assertSame(400, $this->controller($this->request(file: null))->mapping('5', '7')->getStatus());

		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->willReturn(['name' => 'a.csv', 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_PARTIAL]);
		$this->assertSame(400, $this->controller($request)->mapping('5', '7')->getStatus());
	}

	public function testAFileThatIsNotARecordFileIsRefused(): void {
		$response = $this->controller($this->request(file: $this->upload('x', 'notes.pdf')))->mapping('5', '7');

		$this->assertSame(400, $response->getStatus());
		$this->assertStringContainsString('not a record file', $response->getData()['error']);
	}

	public function testAnUnknownRegisterOrSchemaIs404(): void {
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willThrowException(new DoesNotExistException('gone'));

		$controller = new RecordImportController(
			'openregister',
			$this->request(file: $this->upload(self::CSV, 'people.csv')),
			$registers,
			$this->createMock(SchemaMapperInterface::class),
			new RecordFileReader(),
			new ColumnMapper(),
			$this->importer,
			new FailedRowExport(new RecordFileReader())
		);

		$this->assertSame(404, $controller->mapping('9', '7')->getStatus());
		$this->assertSame(404, $controller->import('9', '7')->getStatus());
	}

	public function testBothActionsAreForAdministratorsOnlyAndKeepTheRequestToken(): void {
		foreach (['mapping', 'import'] as $action) {
			$method = new ReflectionMethod(RecordImportController::class, $action);
			$this->assertSame([], $method->getAttributes(NoAdminRequired::class), $action);
			$this->assertSame([], $method->getAttributes(PublicPage::class), $action);
			$this->assertSame([], $method->getAttributes(NoCSRFRequired::class), $action);
			$this->assertStringNotContainsString('@NoAdminRequired', (string)$method->getDocComment(), $action);
		}
	}

	private function controller(IRequest $request): RecordImportController {
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->with('5')->willReturn($this->register);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->with('7')->willReturn($this->schema);

		return new RecordImportController(
			'openregister',
			$request,
			$registers,
			$schemas,
			new RecordFileReader(),
			new ColumnMapper(),
			$this->importer,
			new FailedRowExport(new RecordFileReader())
		);
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function request(?string $file, array $params = []): IRequest {
		$request = $this->createMock(IRequest::class);
		$upload = null;
		if ($file !== null) {
			$upload = ['name' => basename($file), 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)];
		}
		$request->method('getUploadedFile')->with('file')->willReturn($upload);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));

		return $request;
	}

	private function upload(string $content, string $name): string {
		$dir = sys_get_temp_dir() . '/ric-' . bin2hex(random_bytes(4));
		mkdir($dir);
		$path = $dir . '/' . $name;
		file_put_contents($path, $content);
		$this->files[] = $path;
		return $path;
	}
}
