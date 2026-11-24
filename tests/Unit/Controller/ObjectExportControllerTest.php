<?php

/**
 * ObjectExportController unit tests.
 *
 * The export endpoint moved out of ObjectsController unchanged; these are its
 * tests, moved with it: the format dispatch, the public path, the export-right
 * refusal and the audit and run recording.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\ObjectExportController;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectExportRequest;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ObjectExportController.
 *
 * @package Unit\Controller
 */
class ObjectExportControllerTest extends TestCase {

	private ObjectExportController $controller;
	private IRequest&MockObject $request;
	private ContainerInterface&MockObject $container;
	private RegisterMapper&MockObject $registerMapper;
	private SchemaMapper&MockObject $schemaMapper;
	private ObjectService&MockObject $objectService;
	private IUserSession&MockObject $userSession;
	private ExportService&MockObject $exportService;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->exportService = $this->createMock(ExportService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new ObjectExportController(
			'openregister',
			$this->request,
			$this->userSession,
			$this->exportService,
			$this->container,
			$this->logger
		);
	}

	private function setupRegularUser(): void {
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);
	}

	/**
	 * Let the export verb through for a test that is about something else.
	 *
	 * `export()` resolves `ExportRightService` from the container and REFUSES
	 * when it gets anything else, so a test that does not program the container
	 * is asserting a 503 rather than an export. That refusal is asserted on
	 * purpose in `testExportRefusedWhenTheRightServiceIsUnavailable()` below;
	 * every other export test wants the granted path.
	 *
	 * @param bool $allowed False to have the right service refuse the caller.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) One helper for the two
	 *     verdicts keeps the container wiring in one place.
	 */
	private function grantExport(bool $allowed = true, ?\OCA\OpenRegister\Service\Export\ExportRunRecorder $runs = null): void {
		$rightService = $this->createMock(originalClassName: \OCA\OpenRegister\Service\Export\ExportRightService::class);
		$refusal = null;
		if ($allowed === false) {
			$refusal = new \OCA\OpenRegister\Service\Export\ExportRefusedException(
				'export-right-missing',
				'no export for you',
				403
			);
		}

		$rightService->method('refusalFor')->willReturn($refusal);

		$recorder = $this->createMock(originalClassName: \OCA\OpenRegister\Service\Export\ExportAuditRecorder::class);

		$this->container->method('get')->willReturnCallback(
			static function (string $id) use ($rightService, $recorder, $runs) {
				if ($id === \OCA\OpenRegister\Service\Export\ExportRightService::class) {
					return $rightService;
				}

				if ($id === \OCA\OpenRegister\Service\Export\ExportRunRecorder::class && $runs !== null) {
					return $runs;
				}

				if ($id === \OCA\OpenRegister\Service\Export\ExportAuditRecorderInterface::class) {
					return $recorder;
				}

				return 'current-user';
			}
		);
	}//end grantExport()

	// =========================================================================
	// export() — CSV export path
	// =========================================================================

	public function testExportReturnsCsvDownloadResponse(): void {
		$this->grantExport();
		$registerEntity = $this->getMockBuilder(\OCA\OpenRegister\Db\Register::class)
			->addMethods(['getSlug'])
			->getMock();
		$registerEntity->method('getSlug')->willReturn('my-register');

		$schemaEntity = $this->getMockBuilder(\OCA\OpenRegister\Db\Schema::class)
			->addMethods(['getSlug'])
			->getMock();
		$schemaEntity->method('getSlug')->willReturn('my-schema');

		$this->registerMapper->method('find')->willReturn($registerEntity);
		$this->schemaMapper->method('find')->willReturn($schemaEntity);

		$this->request->method('getParams')->willReturn(['format' => 'csv']);
		$this->request->method('getParam')->willReturnCallback(function (string $key, $default = null) {
			if ($key === 'format') {
				return 'csv';
			}
			return $default;
		});

		$user = $this->createMock(\OCP\IUser::class);
		$this->userSession->method('getUser')->willReturn($user);

		$this->exportService->method('exportToCsv')->willReturn('col1,col2\nval1,val2');

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		// export() reuses the entities setRegister()/setSchema() already resolved
		// rather than re-resolving the refs globally for the filename — the
		// re-resolution ignored the register and could name the file after another
		// app's same-slug schema.
		$this->objectService->method('getCurrentRegisterEntity')->willReturn($registerEntity);
		$this->objectService->method('getCurrentSchemaEntity')->willReturn($schemaEntity);

		$result = $this->controller->export('1', '2', $this->objectService);

		$this->assertInstanceOf(\OCP\AppFramework\Http\DataDownloadResponse::class, $result);
		$this->assertStringContainsString('.csv', $result->getHeaders()['Content-Disposition'] ?? '');
	}

	public function testAnApiExportIsRecordedAsARun(): void {
		$runs = $this->createMock(originalClassName: \OCA\OpenRegister\Service\Export\ExportRunRecorder::class);
		$runs->expects($this->once())->method('record')->with(
			$this->callback(static fn (...$a): bool => true)
		)->willReturnCallback(
			function (...$args) {
				$this->assertSame('api', $args[0]->source);
				$this->assertSame('csv', $args[1]->format);
				$this->assertSame(1, $args[2]->downloadCount);
				$this->assertNull($args[3]);
				return new \OCA\OpenRegister\Db\ExportRun();
			}
		);
		$this->grantExport(true, $runs);
		$registerEntity = $this->getMockBuilder(\OCA\OpenRegister\Db\Register::class)
			->addMethods(['getSlug'])
			->getMock();
		$registerEntity->method('getSlug')->willReturn('my-register');

		$schemaEntity = $this->getMockBuilder(\OCA\OpenRegister\Db\Schema::class)
			->addMethods(['getSlug'])
			->getMock();
		$schemaEntity->method('getSlug')->willReturn('my-schema');

		$this->registerMapper->method('find')->willReturn($registerEntity);
		$this->schemaMapper->method('find')->willReturn($schemaEntity);

		$this->request->method('getParams')->willReturn(['format' => 'csv']);
		$this->request->method('getParam')->willReturnCallback(function (string $key, $default = null) {
			if ($key === 'format') {
				return 'csv';
			}
			return $default;
		});

		$user = $this->createMock(\OCP\IUser::class);
		$this->userSession->method('getUser')->willReturn($user);

		$this->exportService->method('exportToCsv')->willReturn('col1,col2\nval1,val2');

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		// export() reuses the entities setRegister()/setSchema() already resolved
		// rather than re-resolving the refs globally for the filename — the
		// re-resolution ignored the register and could name the file after another
		// app's same-slug schema.
		$this->objectService->method('getCurrentRegisterEntity')->willReturn($registerEntity);
		$this->objectService->method('getCurrentSchemaEntity')->willReturn($schemaEntity);

		$result = $this->controller->export('1', '2', $this->objectService);

		$this->assertInstanceOf(\OCP\AppFramework\Http\DataDownloadResponse::class, $result);
		$this->assertStringContainsString('.csv', $result->getHeaders()['Content-Disposition'] ?? '');
	}

	// =========================================================================
	// export() — Excel export path (default)
	// =========================================================================

	public function testExportReturnsExcelDownloadResponseByDefault(): void {
		$this->grantExport();
		$registerEntity = $this->getMockBuilder(\OCA\OpenRegister\Db\Register::class)
			->addMethods(['getSlug'])
			->getMock();
		$registerEntity->method('getSlug')->willReturn('test-reg');

		$schemaEntity = $this->getMockBuilder(\OCA\OpenRegister\Db\Schema::class)
			->addMethods(['getSlug'])
			->getMock();
		$schemaEntity->method('getSlug')->willReturn('test-schema');

		$this->registerMapper->method('find')->willReturn($registerEntity);
		$this->schemaMapper->method('find')->willReturn($schemaEntity);

		$this->request->method('getParams')->willReturn([]);
		$this->request->method('getParam')->willReturnCallback(function (string $key, $default = null) {
			if ($key === 'type') {
				return 'excel';
			}
			return $default;
		});

		$user = $this->createMock(\OCP\IUser::class);
		$this->userSession->method('getUser')->willReturn($user);

		$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
		$spreadsheet->getActiveSheet()->setCellValue('A1', 'test');
		$this->exportService->method('exportToExcel')->willReturn($spreadsheet);

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		// export() reuses the entities setRegister()/setSchema() already resolved
		// rather than re-resolving the refs globally for the filename — the
		// re-resolution ignored the register and could name the file after another
		// app's same-slug schema.
		$this->objectService->method('getCurrentRegisterEntity')->willReturn($registerEntity);
		$this->objectService->method('getCurrentSchemaEntity')->willReturn($schemaEntity);

		$result = $this->controller->export('1', '2', $this->objectService);

		$this->assertInstanceOf(\OCP\AppFramework\Http\DataDownloadResponse::class, $result);
		$this->assertMatchesRegularExpression('/filename=test-reg_test-schema_\d{4}-\d{2}-\d{2}_\d{6}\.xlsx$/', $result->getHeaders()['Content-Disposition']);
		$this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $result->getHeaders()['Content-Type']);
	}

	/**
	 * ODS carries the same sheet the Excel export builds, written as an
	 * OpenDocument spreadsheet.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md
	 */
	public function testExportReturnsOdsWithTheSameSheetAsExcel(): void {
		$this->grantExport();
		$registerEntity = $this->getMockBuilder(\OCA\OpenRegister\Db\Register::class)
			->addMethods(['getSlug'])
			->getMock();
		$registerEntity->method('getSlug')->willReturn('zaken');

		$schemaEntity = $this->getMockBuilder(\OCA\OpenRegister\Db\Schema::class)
			->addMethods(['getSlug'])
			->getMock();
		$schemaEntity->method('getSlug')->willReturn('meldingen');

		$this->registerMapper->method('find')->willReturn($registerEntity);
		$this->schemaMapper->method('find')->willReturn($schemaEntity);

		$this->request->method('getParams')->willReturn([]);
		$this->request->method('getParam')->willReturnCallback(function (string $key, $default = null) {
			if ($key === 'format') {
				return 'ods';
			}
			return $default;
		});

		$user = $this->createMock(\OCP\IUser::class);
		$this->userSession->method('getUser')->willReturn($user);

		$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
		$spreadsheet->getActiveSheet()->setTitle('meldingen');
		$spreadsheet->getActiveSheet()->fromArray([['id', 'status', '_toegewezen_aan'], ['m-1', 'nieuw', 'Jan'], ['m-2', 'afgehandeld', 'Piet']]);
		$this->exportService->expects($this->once())->method('exportToExcel')->willReturn($spreadsheet);

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('getCurrentRegisterEntity')->willReturn($registerEntity);
		$this->objectService->method('getCurrentSchemaEntity')->willReturn($schemaEntity);

		$result = $this->controller->export('1', '2', $this->objectService);

		$this->assertInstanceOf(\OCP\AppFramework\Http\DataDownloadResponse::class, $result);
		$this->assertMatchesRegularExpression('/filename=zaken_meldingen_\d{4}-\d{2}-\d{2}_\d{6}\.ods$/', $result->getHeaders()['Content-Disposition']);
		$this->assertSame('application/vnd.oasis.opendocument.spreadsheet', $result->getHeaders()['Content-Type']);

		// The bytes are a real OpenDocument spreadsheet holding the same rows.
		$path = tempnam(sys_get_temp_dir(), 'ods');
		file_put_contents($path, $result->render());
		$read = (new \PhpOffice\PhpSpreadsheet\Reader\Ods())->load($path);
		unlink($path);
		$this->assertSame(
			[['id', 'status', '_toegewezen_aan'], ['m-1', 'nieuw', 'Jan'], ['m-2', 'afgehandeld', 'Piet']],
			$read->getActiveSheet()->toArray()
		);
		$this->assertSame('meldingen', $read->getActiveSheet()->getTitle());
	}

	// =========================================================================
	// export() — Register/Schema with null slugs uses fallback names
	// =========================================================================

	public function testExportUsesDefaultSlugsWhenNull(): void {
		$this->grantExport();
		$registerEntity = $this->getMockBuilder(\OCA\OpenRegister\Db\Register::class)
			->addMethods(['getSlug'])
			->getMock();
		$registerEntity->method('getSlug')->willReturn(null);

		$schemaEntity = $this->getMockBuilder(\OCA\OpenRegister\Db\Schema::class)
			->addMethods(['getSlug'])
			->getMock();
		$schemaEntity->method('getSlug')->willReturn(null);

		$this->registerMapper->method('find')->willReturn($registerEntity);
		$this->schemaMapper->method('find')->willReturn($schemaEntity);

		$this->request->method('getParams')->willReturn(['format' => 'csv']);
		$this->request->method('getParam')->willReturnCallback(function (string $key, $default = null) {
			if ($key === 'format') {
				return 'csv';
			}
			return $default;
		});

		$user = $this->createMock(\OCP\IUser::class);
		$this->userSession->method('getUser')->willReturn($user);

		$this->exportService->method('exportToCsv')->willReturn('data');

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		// export() reuses the entities setRegister()/setSchema() already resolved
		// rather than re-resolving the refs globally for the filename — the
		// re-resolution ignored the register and could name the file after another
		// app's same-slug schema.
		$this->objectService->method('getCurrentRegisterEntity')->willReturn($registerEntity);
		$this->objectService->method('getCurrentSchemaEntity')->willReturn($schemaEntity);

		$result = $this->controller->export('1', '2', $this->objectService);

		$this->assertInstanceOf(\OCP\AppFramework\Http\DataDownloadResponse::class, $result);
		$this->assertMatchesRegularExpression('/filename=register_schema_\d{4}-\d{2}-\d{2}_\d{6}\.csv$/', $result->getHeaders()['Content-Disposition']);
		$this->assertSame('text/csv', $result->getHeaders()['Content-Type']);
	}

	// =========================================================================
	// export() — format=csv via 'type' param fallback
	// =========================================================================

	public function testExportCsvViaTypeParam(): void {
		$this->grantExport();
		$registerEntity = $this->getMockBuilder(\OCA\OpenRegister\Db\Register::class)
			->addMethods(['getSlug'])
			->getMock();
		$registerEntity->method('getSlug')->willReturn('reg');

		$schemaEntity = $this->getMockBuilder(\OCA\OpenRegister\Db\Schema::class)
			->addMethods(['getSlug'])
			->getMock();
		$schemaEntity->method('getSlug')->willReturn('sch');

		$this->registerMapper->method('find')->willReturn($registerEntity);
		$this->schemaMapper->method('find')->willReturn($schemaEntity);

		$this->request->method('getParams')->willReturn(['type' => 'csv']);
		$this->request->method('getParam')->willReturnCallback(function (string $key, $default = null) {
			if ($key === 'format') {
				return null;
			}
			if ($key === 'type') {
				return 'csv';
			}
			return $default;
		});

		$user = $this->createMock(\OCP\IUser::class);
		$this->userSession->method('getUser')->willReturn($user);

		$this->exportService->method('exportToCsv')->willReturn('csv-content');

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		// export() reuses the entities setRegister()/setSchema() already resolved
		// rather than re-resolving the refs globally for the filename — the
		// re-resolution ignored the register and could name the file after another
		// app's same-slug schema.
		$this->objectService->method('getCurrentRegisterEntity')->willReturn($registerEntity);
		$this->objectService->method('getCurrentSchemaEntity')->willReturn($schemaEntity);

		$result = $this->controller->export('1', '2', $this->objectService);

		$this->assertInstanceOf(\OCP\AppFramework\Http\DataDownloadResponse::class, $result);
		$this->assertMatchesRegularExpression('/filename=reg_sch_\d{4}-\d{2}-\d{2}_\d{6}\.csv$/', $result->getHeaders()['Content-Disposition']);
		$this->assertSame('text/csv', $result->getHeaders()['Content-Type']);
	}

	// =========================================================================
	// export() — PDF export path
	// =========================================================================

	public function testExportReturnsPdfDownloadResponse(): void {
		$this->grantExport();
		$registerEntity = $this->getMockBuilder(\OCA\OpenRegister\Db\Register::class)
			->addMethods(['getSlug'])
			->getMock();
		$registerEntity->method('getSlug')->willReturn('my-register');

		$schemaEntity = $this->getMockBuilder(\OCA\OpenRegister\Db\Schema::class)
			->addMethods(['getSlug'])
			->getMock();
		$schemaEntity->method('getSlug')->willReturn('my-schema');

		$this->registerMapper->method('find')->willReturn($registerEntity);
		$this->schemaMapper->method('find')->willReturn($schemaEntity);

		$this->request->method('getParams')->willReturn(['format' => 'pdf']);
		$this->request->method('getParam')->willReturnCallback(function (string $key, $default = null) {
			if ($key === 'format') {
				return 'pdf';
			}
			return $default;
		});

		$user = $this->createMock(\OCP\IUser::class);
		$this->userSession->method('getUser')->willReturn($user);

		$this->exportService->method('exportToPdf')->willReturn("%PDF-1.7\n...fake pdf bytes...");

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		// export() reuses the entities setRegister()/setSchema() already resolved
		// rather than re-resolving the refs globally for the filename — the
		// re-resolution ignored the register and could name the file after another
		// app's same-slug schema.
		$this->objectService->method('getCurrentRegisterEntity')->willReturn($registerEntity);
		$this->objectService->method('getCurrentSchemaEntity')->willReturn($schemaEntity);

		$result = $this->controller->export('1', '2', $this->objectService);

		$this->assertInstanceOf(\OCP\AppFramework\Http\DataDownloadResponse::class, $result);
		$this->assertSame('application/pdf', $result->getHeaders()['Content-Type'] ?? null);
		$this->assertStringContainsString('.pdf', $result->getHeaders()['Content-Disposition'] ?? '');
	}

	/**
	 * The verb refuses on the plain export endpoint, not only in the service.
	 *
	 * This is the endpoint an integration calls, and it was ungated: a
	 * principal holding read could take the whole schema as a file. The
	 * refusal names the verb rather than the record.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	public function testExportIsRefusedWhenTheCallerDoesNotHoldTheVerb(): void {
		$this->grantExport(allowed: false);
		$this->primeExportEntities();

		$result = $this->controller->export('1', '2', $this->objectService);

		$this->assertInstanceOf(expected: \OCP\AppFramework\Http\JSONResponse::class, actual: $result);
		$this->assertSame(expected: 403, actual: $result->getStatus());
		$this->assertSame(expected: 'export', actual: $result->getData()['verb']);
		$this->assertSame(expected: 'export-right-missing', actual: $result->getData()['rule']);
	}//end testExportIsRefusedWhenTheCallerDoesNotHoldTheVerb()

	/**
	 * A right service that cannot be resolved refuses rather than exporting.
	 *
	 * The container is left unprogrammed here on purpose. An export that ran
	 * with no verb check and nothing to say one was missing is exactly the
	 * hole this change closes, so "the check could not run" has to be loud.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	public function testExportRefusedWhenTheRightServiceIsUnavailable(): void {
		$this->primeExportEntities();

		$result = $this->controller->export('1', '2', $this->objectService);

		$this->assertInstanceOf(expected: \OCP\AppFramework\Http\JSONResponse::class, actual: $result);
		$this->assertSame(expected: 503, actual: $result->getStatus());
		$this->assertSame(expected: 'right-service-unavailable', actual: $result->getData()['rule']);
	}//end testExportRefusedWhenTheRightServiceIsUnavailable()

	/**
	 * The register and schema an export test needs before the verb is reached.
	 *
	 * @return void
	 */
	private function primeExportEntities(): void {
		$registerEntity = $this->getMockBuilder(className: \OCA\OpenRegister\Db\Register::class)
			->addMethods(['getSlug'])
			->getMock();
		$registerEntity->method('getSlug')->willReturn('my-register');

		$schemaEntity = $this->getMockBuilder(className: \OCA\OpenRegister\Db\Schema::class)
			->addMethods(['getSlug'])
			->getMock();
		$schemaEntity->method('getSlug')->willReturn('my-schema');

		$this->request->method('getParams')->willReturn(['format' => 'csv']);
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) {
				if ($key === 'format') {
					return 'csv';
				}

				return $default;
			}
		);

		$this->userSession->method('getUser')->willReturn($this->createMock(originalClassName: \OCP\IUser::class));
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('getCurrentRegisterEntity')->willReturn($registerEntity);
		$this->objectService->method('getCurrentSchemaEntity')->willReturn($schemaEntity);
	}//end primeExportEntities()

	public function testExportPdfTooLargeReturns400(): void {
		$this->grantExport();
		$registerEntity = $this->getMockBuilder(\OCA\OpenRegister\Db\Register::class)
			->addMethods(['getSlug'])
			->getMock();
		$registerEntity->method('getSlug')->willReturn('my-register');

		$schemaEntity = $this->getMockBuilder(\OCA\OpenRegister\Db\Schema::class)
			->addMethods(['getSlug'])
			->getMock();
		$schemaEntity->method('getSlug')->willReturn('my-schema');

		$this->registerMapper->method('find')->willReturn($registerEntity);
		$this->schemaMapper->method('find')->willReturn($schemaEntity);

		$this->request->method('getParams')->willReturn(['format' => 'pdf']);
		$this->request->method('getParam')->willReturnCallback(function (string $key, $default = null) {
			if ($key === 'format') {
				return 'pdf';
			}
			return $default;
		});

		$user = $this->createMock(\OCP\IUser::class);
		$this->userSession->method('getUser')->willReturn($user);

		$this->exportService->method('exportToPdf')->willThrowException(
			new \OCA\OpenRegister\Exception\ExportTooLargeException(6000, 5000)
		);

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		// export() reuses the entities setRegister()/setSchema() already resolved
		// rather than re-resolving the refs globally for the filename — the
		// re-resolution ignored the register and could name the file after another
		// app's same-slug schema.
		$this->objectService->method('getCurrentRegisterEntity')->willReturn($registerEntity);
		$this->objectService->method('getCurrentSchemaEntity')->willReturn($schemaEntity);

		$result = $this->controller->export('1', '2', $this->objectService);

		$this->assertInstanceOf(\OCP\AppFramework\Http\JSONResponse::class, $result);
		$this->assertSame(400, $result->getStatus());
		$this->assertSame('export_too_large', $result->getData()['error']);
		$this->assertSame(6000, $result->getData()['rowCount']);
		$this->assertSame(5000, $result->getData()['maxRows']);
	}

	/**
	 * Without an office server a PDF export answers 501 with a clear message.
	 */
	public function testExportPdfWithoutOfficeServerReturns501(): void {
		$this->grantExport();
		$registerEntity = $this->getMockBuilder(\OCA\OpenRegister\Db\Register::class)
			->addMethods(['getSlug'])
			->getMock();
		$registerEntity->method('getSlug')->willReturn('my-register');

		$schemaEntity = $this->getMockBuilder(\OCA\OpenRegister\Db\Schema::class)
			->addMethods(['getSlug'])
			->getMock();
		$schemaEntity->method('getSlug')->willReturn('my-schema');

		$this->registerMapper->method('find')->willReturn($registerEntity);
		$this->schemaMapper->method('find')->willReturn($schemaEntity);

		$this->request->method('getParams')->willReturn(['format' => 'pdf']);
		$this->request->method('getParam')->willReturnCallback(function (string $key, $default = null) {
			if ($key === 'format') {
				return 'pdf';
			}
			return $default;
		});

		$user = $this->createMock(\OCP\IUser::class);
		$this->userSession->method('getUser')->willReturn($user);

		$this->exportService->method('exportToPdf')->willThrowException(
			new \OCA\OpenRegister\Exception\OfficeServerRequiredException()
		);

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		// export() reuses the entities setRegister()/setSchema() already resolved
		// rather than re-resolving the refs globally for the filename — the
		// re-resolution ignored the register and could name the file after another
		// app's same-slug schema.
		$this->objectService->method('getCurrentRegisterEntity')->willReturn($registerEntity);
		$this->objectService->method('getCurrentSchemaEntity')->willReturn($schemaEntity);

		$result = $this->controller->export('1', '2', $this->objectService);

		$this->assertInstanceOf(\OCP\AppFramework\Http\JSONResponse::class, $result);
		$this->assertSame(501, $result->getStatus());
		$this->assertSame('office_server_required', $result->getData()['error']);
		$this->assertStringContainsString('Collabora or Euro-Office', $result->getData()['message']);
	}

	/**
	 * A finished PDF export is recorded with the row count the export service answers.
	 *
	 * @return void
	 */
	public function testAPdfExportIsRecordedWithItsRowCount(): void {
		// A signed-in caller: without a session the export takes the public path.
		$this->setupRegularUser();
		$runs = $this->createMock(originalClassName: \OCA\OpenRegister\Service\Export\ExportRunRecorder::class);
		$runs->expects($this->once())->method('record')->willReturnCallback(
			function (...$args) {
				$this->assertSame('pdf', $args[1]->format);
				return new \OCA\OpenRegister\Db\ExportRun();
			}
		);
		$this->grantExport(true, $runs);
		$this->objectService->method('getCurrentRegisterEntity')->willReturn(new \OCA\OpenRegister\Db\Register());
		$this->objectService->method('getCurrentSchemaEntity')->willReturn(new \OCA\OpenRegister\Db\Schema());
		$this->request->method('getParams')->willReturn([]);
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($key === 'format') ? 'pdf' : $default
		);
		$this->exportService->method('exportToPdf')->willReturn('%PDF');
		$this->exportService->expects($this->once())->method('countExportRows')->willReturn(4);

		$result = $this->controller->export('1', '2', $this->objectService);

		$this->assertSame('%PDF', $result->render());
	}

	/**
	 * A JSON export is recorded with the format it was asked in.
	 *
	 * @return void
	 */
	public function testAJsonExportIsRecorded(): void {
		// A signed-in caller: without a session the export takes the public path.
		$this->setupRegularUser();
		$this->arrangeRecordedExport(format: 'json');
		$this->exportService->method('exportToJson')->willReturn('[]');

		$this->assertSame('[]', $this->controller->export('1', '2', $this->objectService)->render());
	}

	/**
	 * An XML export answers an `.xml` file as application/xml and records the
	 * number of `<object>` records it served.
	 *
	 * @return void
	 */
	public function testAnXmlExportIsRecordedWithItsRowCountAndNamedXml(): void {
		// A signed-in caller: without a session the export takes the public path.
		$this->setupRegularUser();
		// The controller swallows a recorder failure (it costs the row, never the
		// export), so an assertion inside the callback could never fail the test.
		// Capture what was recorded and assert after the call.
		$recorded = null;
		$runs = $this->createMock(originalClassName: \OCA\OpenRegister\Service\Export\ExportRunRecorder::class);
		$runs->expects($this->once())->method('record')->willReturnCallback(
			function (...$args) use (&$recorded) {
				$recorded = $args[1];
				return new \OCA\OpenRegister\Db\ExportRun();
			}
		);
		$this->grantExport(true, $runs);
		$this->objectService->method('getCurrentRegisterEntity')->willReturn(new \OCA\OpenRegister\Db\Register());
		$this->objectService->method('getCurrentSchemaEntity')->willReturn(new \OCA\OpenRegister\Db\Schema());
		$this->request->method('getParams')->willReturn([]);
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($key === 'format') ? 'xml' : $default
		);
		// A nested property named `object` must not count as a record.
		$xml = (new \OCA\OpenRegister\Service\Export\XmlObjectWriter())->write(
			[['@self' => ['id' => 'a'], 'object' => ['x' => 1]], ['@self' => ['id' => 'b']]]
		);
		$this->exportService->expects($this->once())->method('exportToXml')->willReturn($xml);

		$result = $this->controller->export('1', '2', $this->objectService);

		$this->assertInstanceOf(\OCP\AppFramework\Http\DataDownloadResponse::class, $result);
		$this->assertSame($xml, $result->render());
		$this->assertSame('application/xml; charset=utf-8', $result->getHeaders()['Content-Type']);
		$this->assertStringEndsWith('.xml', $result->getHeaders()['Content-Disposition']);
		$this->assertSame('xml', $recorded->format);
		$this->assertSame(2, $recorded->rowCount, 'a nested property named object is not a record');
	}

	/**
	 * An XML export runs as the signed-in caller, like the JSON export.
	 *
	 * @return void
	 */
	public function testAnXmlExportRunsAsTheSignedInCaller(): void {
		$user = $this->createMock(\OCP\IUser::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->arrangeRecordedExport(format: 'xml');
		$this->exportService->expects($this->once())->method('exportToXml')->with(
			$this->callback(static fn (ObjectExportRequest $request): bool => $request->currentUser === $user)
		)->willReturn('<?xml version="1.0" encoding="UTF-8"?><objects/>');

		$this->controller->export('1', '2', $this->objectService);
	}

	/**
	 * An Excel export is recorded and answers an xlsx file.
	 *
	 * @return void
	 */
	public function testAnExcelExportIsRecordedAndNamedXlsx(): void {
		// A signed-in caller: without a session the export takes the public path.
		$this->setupRegularUser();
		$this->arrangeRecordedExport(format: 'excel');
		$this->exportService->method('exportToExcel')->willReturn(new \PhpOffice\PhpSpreadsheet\Spreadsheet());

		$result = $this->controller->export('1', '2', $this->objectService);

		$this->assertStringEndsWith('.xlsx', $result->getHeaders()['Content-Disposition']);
	}

	/**
	 * An export of this format, whose run must be recorded once under that format.
	 *
	 * @param string $format The format.
	 *
	 * @return void
	 */
	private function arrangeRecordedExport(string $format): void {
		$runs = $this->createMock(originalClassName: \OCA\OpenRegister\Service\Export\ExportRunRecorder::class);
		$runs->expects($this->once())->method('record')->willReturnCallback(
			function (...$args) use ($format) {
				$this->assertSame($format, $args[1]->format);
				return new \OCA\OpenRegister\Db\ExportRun();
			}
		);
		$this->grantExport(true, $runs);
		$this->objectService->method('getCurrentRegisterEntity')->willReturn(new \OCA\OpenRegister\Db\Register());
		$this->objectService->method('getCurrentSchemaEntity')->willReturn(new \OCA\OpenRegister\Db\Schema());
		$this->request->method('getParams')->willReturn([]);
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($key === 'format') ? $format : $default
		);
	}

	/**
	 * Arrange an anonymous export of `publicaties`/`besluit` with these parameters.
	 *
	 * @param array<string, mixed> $params  The request parameters.
	 * @param mixed                $service What the container answers for the public export service.
	 *
	 * @return void
	 */
	private function arrangeAnonymousExport(array $params, mixed $service): void {
		$this->userSession->method('getUser')->willReturn(null);
		$register = new \OCA\OpenRegister\Db\Register();
		$register->setSlug('publicaties');
		$schema = new \OCA\OpenRegister\Db\Schema();
		$schema->setSlug('besluit');
		$this->objectService->method('getCurrentRegisterEntity')->willReturn($register);
		$this->objectService->method('getCurrentSchemaEntity')->willReturn($schema);
		$this->request->method('getParams')->willReturn($params + ['_route' => 'r']);
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);
		$this->request->method('getRequestUri')->willReturn('/api/objects/publicaties/besluit/export?format=xml');
		$this->container->method('get')->willReturnCallback(
			static function (string $id) use ($service) {
				if ($id === \OCA\OpenRegister\Service\Export\PublicExportService::class) {
					return $service;
				}

				throw new \RuntimeException('not asked for: ' . $id);
			}
		);
	}

	/**
	 * Without a session the export goes through the public export, page and format from the request,
	 * and the file carries the paging headers; the signed-in right check is not consulted.
	 *
	 * @return void
	 */
	public function testAnAnonymousExportIsServedAsAPublicPage(): void {
		$service = $this->createMock(\OCA\OpenRegister\Service\Export\PublicExportService::class);
		$service->expects($this->once())->method('export')->with(
			$this->callback(static fn (\OCA\OpenRegister\Service\Export\ExportRequest $request): bool => $request->currentUser === null
				&& $request->page()?->number === 2
				&& ($request->filters['thema'] ?? null) === 'milieu'
				&& isset($request->filters['_route']) === false),
			'xml'
		)->willReturn(
			new \OCA\OpenRegister\Service\Export\PublicExportResult(
				refusal: null,
				content: '<objects/>',
				page: new \OCA\OpenRegister\Service\Export\ExportPage(number: 2),
				total: 23500
			)
		);
		$this->arrangeAnonymousExport(params: ['format' => 'xml', 'thema' => 'milieu', '_page' => '2'], service: $service);
		$this->exportService->expects($this->never())->method('exportToXml');

		$result = $this->controller->export('publicaties', 'besluit', $this->objectService);

		$this->assertInstanceOf(\OCP\AppFramework\Http\DataDownloadResponse::class, $result);
		$this->assertSame('<objects/>', $result->render());
		$headers = $result->getHeaders();
		$this->assertSame('application/xml; charset=utf-8', $headers['Content-Type']);
		$this->assertSame('23500', $headers['X-Total-Count']);
		$this->assertSame('2', $headers['X-Page']);
		$this->assertSame('3', $headers['X-Pages']);
		$this->assertSame('</api/objects/publicaties/besluit/export?format=xml&_page=3>; rel="next"', $headers['Link']);
	}

	/**
	 * An anonymous refusal is answered as JSON with its status.
	 *
	 * @return void
	 */
	public function testAnAnonymousRefusalIsAnsweredWithItsStatus(): void {
		$service = $this->createMock(\OCA\OpenRegister\Service\Export\PublicExportService::class);
		$service->method('export')->willReturn(
			\OCA\OpenRegister\Service\Export\PublicExportResult::refused(
				refusal: new \OCA\OpenRegister\Service\Export\ExportRefusedException(rule: 'format-not-open', reason: 'open formats only', statusCode: 406)
			)
		);
		$this->arrangeAnonymousExport(params: ['format' => 'pdf'], service: $service);

		$result = $this->controller->export('publicaties', 'besluit', $this->objectService);

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(406, $result->getStatus());
		$this->assertSame('format-not-open', $result->getData()['rule']);
	}

	/**
	 * No public export service, no export: 503, never an unchecked file.
	 *
	 * @return void
	 */
	public function testAnAnonymousExportWithoutTheServiceIsRefused(): void {
		$this->arrangeAnonymousExport(params: ['format' => 'csv'], service: 'not-a-service');
		$this->exportService->expects($this->never())->method('exportToCsv');

		$result = $this->controller->export('publicaties', 'besluit', $this->objectService);

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(503, $result->getStatus());
	}

	/**
	 * A JSON export drops the route from its filters and answers a named JSON file.
	 *
	 * @return void
	 */
	public function testExportJsonGolden(): void {
		// A signed-in caller: without a session the export takes the public path.
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->grantExport();
		$registerEntity = new \OCA\OpenRegister\Db\Register();
		$registerEntity->setSlug('my-register');
		$schemaEntity = new \OCA\OpenRegister\Db\Schema();
		$this->objectService->method('getCurrentRegisterEntity')->willReturn($registerEntity);
		$this->objectService->method('getCurrentSchemaEntity')->willReturn($schemaEntity);

		$this->request->method('getParams')->willReturn(['_route' => 'r', 'status' => 'open']);
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($key === 'type') ? 'json' : $default
		);
		$this->exportService->expects($this->once())->method('exportToJson')->with(
			new ObjectExportRequest(register: $registerEntity, schema: $schemaEntity, filters: ['status' => 'open'], currentUser: $user)
		)->willReturn('{"results":[1,2]}');

		$result = $this->controller->export('1', '2', $this->objectService);

		$this->assertInstanceOf(\OCP\AppFramework\Http\DataDownloadResponse::class, $result);
		$this->assertSame('{"results":[1,2]}', $result->render());
		$this->assertSame('application/json', $result->getHeaders()['Content-Type']);
		$this->assertMatchesRegularExpression(
			'/^attachment; filename=my-register_schema_\d{4}-\d{2}-\d{2}_\d{6}\.json$/',
			$result->getHeaders()['Content-Disposition']
		);
	}

	/**
	 * A JSON export runs as the signed-in caller, so the service can honour an admin's `_multi`.
	 *
	 * @return void
	 */
	public function testAJsonExportRunsAsTheSignedInCaller(): void {
		$user = $this->createMock(\OCP\IUser::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->arrangeRecordedExport(format: 'json');
		$this->exportService->expects($this->once())->method('exportToJson')->with(
			$this->callback(static fn (ObjectExportRequest $request): bool => $request->currentUser === $user)
		)->willReturn('[]');

		$this->assertSame('[]', $this->controller->export('1', '2', $this->objectService)->render());
	}

	/**
	 * A PDF export counts its recorded rows as the signed-in caller, the same scope the served file used.
	 *
	 * @return void
	 */
	public function testAPdfExportCountsItsRowsAsTheSignedInCaller(): void {
		$user = $this->createMock(\OCP\IUser::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->arrangeRecordedExport(format: 'pdf');
		$asCaller = $this->callback(static fn (ObjectExportRequest $request): bool => $request->currentUser === $user);
		$this->exportService->expects($this->once())->method('exportToPdf')->with($asCaller)->willReturn('%PDF');
		$this->exportService->expects($this->once())->method('countExportRows')->with($asCaller)->willReturn(4);

		$this->assertSame('%PDF', $this->controller->export('1', '2', $this->objectService)->render());
	}
}
