<?php

/**
 * ObjectsController export recording golden test.
 *
 * Pins what an ad-hoc API export writes to the audit trail and to the export
 * area: the completed entry with its register, schema, format and row count,
 * the run the export area keeps, and the refused entry with its rule.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ExportRun;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Export\ExportAuditRecorderInterface;
use OCA\OpenRegister\Service\Export\ExportRefusedException;
use OCA\OpenRegister\Service\Export\ExportRightService;
use OCA\OpenRegister\Service\Export\ExportRunRecorder;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectsControllerFactory;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

class ObjectsControllerExportRecordingGoldenTest extends TestCase {

	/** @var list<array<string, mixed>> */
	private array $recorded = [];

	public function testACompletedCsvExportIsRecordedWithItsTableFormatAndRowCount(): void {
		$controller = $this->controller(refusal: null);

		$result = $controller->export('1', '2', $this->objectService());

		$this->assertInstanceOf(DataDownloadResponse::class, $result);
		$this->assertSame(
			[
				['completed', 'ad-hoc', 1, 'csv', null, 7, 19, null],
				['run', 'api', 'anna', 'ad-hoc', 'csv', 1, 'my-register', 'my-schema', 1, null],
			],
			$this->recorded
		);
	}//end testACompletedCsvExportIsRecordedWithItsTableFormatAndRowCount()

	public function testARefusedExportIsRecordedWithItsRuleAndTable(): void {
		$controller = $this->controller(refusal: new ExportRefusedException('export-right-missing', 'no export for you', 403));

		$result = $controller->export('1', '2', $this->objectService());

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(403, $result->getStatus());
		$this->assertSame([['refused', 'ad-hoc', 'export-right-missing', 'no export for you', 7, 19, null]], $this->recorded);
	}//end testARefusedExportIsRecordedWithItsRuleAndTable()

	private function objectService(): ObjectService {
		$register = $this->getMockBuilder(Register::class)->addMethods(['getSlug'])->getMock();
		$register->method('getSlug')->willReturn('my-register');
		$register->setId(7);
		$schema = $this->getMockBuilder(Schema::class)->addMethods(['getSlug'])->getMock();
		$schema->method('getSlug')->willReturn('my-schema');
		$schema->setId(19);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('getCurrentRegisterEntity')->willReturn($register);
		$objectService->method('getCurrentSchemaEntity')->willReturn($schema);

		return $objectService;
	}//end objectService()

	private function controller(?ExportRefusedException $refusal): ObjectsController {
		$rights = $this->createMock(ExportRightService::class);
		$rights->method('refusalFor')->willReturn($refusal);

		$audit = $this->createMock(ExportAuditRecorderInterface::class);
		$audit->method('recordCompleted')->willReturnCallback(
			function (...$args): void {
				$this->recorded[] = array_merge(['completed'], array_values($args));
			}
		);
		$audit->method('recordRefused')->willReturnCallback(
			function (...$args): void {
				$this->recorded[] = array_merge(['refused'], array_values($args));
			}
		);

		$runs = $this->createMock(ExportRunRecorder::class);
		$runs->method('record')->willReturnCallback(
			function ($origin, $content, $file, $retention): ExportRun {
				$this->recorded[] = [
					'run', $origin->source, $origin->actor, $origin->profile,
					$content->format, $content->rowCount, $content->registerName, $content->schemaName,
					$file->downloadCount, $retention,
				];
				return new ExportRun();
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => match ($id) {
				ExportRightService::class => $rights,
				ExportAuditRecorderInterface::class => $audit,
				ExportRunRecorder::class => $runs,
				default => null,
			}
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['format' => 'csv']);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($key === 'format') ? 'csv' : $default
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$exporter = $this->createMock(\OCA\OpenRegister\Kernel\Contracts\ObjectExporterInterface::class);
		$exporter->method('exportToCsv')->willReturn("col1,col2\nval1,val2");

		return ObjectsControllerFactory::create(
			appName: 'openregister',
			request: $request,
			config: $this->createMock(IAppConfig::class),
			appManager: $this->createMock(IAppManager::class),
			container: $container,
			registerMapper: $this->createMock(RegisterMapper::class),
			schemaMapper: $this->createMock(SchemaMapper::class),
			auditTrailMapper: $this->createMock(AuditTrailMapper::class),
			objectService: $this->createMock(ObjectService::class),
			userSession: $session,
			groupManager: $this->createMock(IGroupManager::class),
			exportService: $exporter,
			importService: $this->createMock(\OCA\OpenRegister\Kernel\Contracts\ObjectImporterInterface::class),
			logger: new NullLogger(),
		);
	}//end controller()
}//end class
