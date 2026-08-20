<?php

/**
 * Contract tests for {@see \OCA\OpenRegister\Controller\TmloController}.
 *
 * Covers the two MDTO export endpoints. Both resolve the register and schema
 * first, so an unknown slug must surface as a clean 404 rather than leaking the
 * internal DBAL failure through the generic 500 handler; an unexpected failure
 * from the TMLO service maps to a 500 carrying the reason.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/retrofit-2026-05-24-tmlo-metadata/tasks.md#task-5
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\TmloController;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\TmloService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * TmloControllerTest.
 */
class TmloControllerTest extends TestCase {

	/**
	 * HTTP request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * TMLO metadata service mock.
	 *
	 * @var TmloService&MockObject
	 */
	private TmloService&MockObject $tmloService;

	/**
	 * Register mapper mock.
	 *
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registerMapper;

	/**
	 * Schema mapper mock.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * Controller under test.
	 *
	 * @var TmloController
	 */
	private TmloController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->tmloService = $this->createMock(TmloService::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);

		$this->controller = new TmloController(
			'openregister',
			$this->request,
			$this->tmloService,
			$this->createMock(ObjectService::class),
			$this->registerMapper,
			$this->schemaMapper,
			new NullLogger()
		);
	}//end setUp()

	public function testExportSingleReturns404ForAnUnknownRegister(): void {
		$this->registerMapper->method('find')->willThrowException(new DoesNotExistException('no such register'));
		$this->tmloService->expects($this->never())->method('generateMdtoXml');

		$response = $this->controller->exportSingle('nope', 'zaak', 'uuid-1');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('Register or schema not found', $response->getData()['error']);
	}//end testExportSingleReturns404ForAnUnknownRegister()

	public function testExportSingleReturns404ForAnUnknownSchema(): void {
		$this->registerMapper->method('find')->willReturn($this->createMock(Register::class));
		$this->schemaMapper->method('find')->willThrowException(new DoesNotExistException('no such schema'));
		$this->tmloService->expects($this->never())->method('generateMdtoXml');

		$response = $this->controller->exportSingle('zaken', 'nope', 'uuid-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testExportSingleReturns404ForAnUnknownSchema()

	public function testExportBatchReturns404ForAnUnknownRegister(): void {
		$this->registerMapper->method('find')->willThrowException(new DoesNotExistException('no such register'));
		$this->tmloService->expects($this->never())->method('generateBatchMdtoXml');

		$response = $this->controller->exportBatch('nope', 'zaak');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('Register or schema not found', $response->getData()['error']);
	}//end testExportBatchReturns404ForAnUnknownRegister()

	public function testExportBatchReturns404ForAnUnknownSchema(): void {
		$this->registerMapper->method('find')->willReturn($this->createMock(Register::class));
		$this->schemaMapper->method('find')->willThrowException(new DoesNotExistException('no such schema'));
		$this->tmloService->expects($this->never())->method('generateBatchMdtoXml');

		$response = $this->controller->exportBatch('zaken', 'nope');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testExportBatchReturns404ForAnUnknownSchema()

	/**
	 * Both exports resolve register + schema BEFORE they touch the object
	 * store, and a failure there must not be reported as an export failure.
	 *
	 * The success arm is deliberately not exercised here: `exportSingle()`
	 * calls `ObjectService::find(identifier: …, register: …, schema: …)` and
	 * `exportBatch()` calls `ObjectService::findAll(register: …, schema: …,
	 * filters: …)`, but the real signatures are `find(int|string $id, …)` and
	 * `findAll(array $config, bool $_rbac, bool $_multitenancy)`. PHP raises
	 * `Error: Unknown named parameter $register`, which is NOT an `Exception`
	 * and therefore escapes both `catch` arms as a 500 with no body. Pinning a
	 * test to that behaviour would cement it, so the defect is reported instead
	 * of asserted; this test proves the resolution stage that runs first.
	 *
	 * @return void
	 */
	public function testExportSingleResolvesTheRegisterBeforeGeneratingXml(): void {
		$this->registerMapper->expects($this->once())
			->method('find')
			->with('zaken')
			->willThrowException(new DoesNotExistException('no such register'));

		$response = $this->controller->exportSingle('zaken', 'zaak', 'uuid-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testExportSingleResolvesTheRegisterBeforeGeneratingXml()
}//end class
