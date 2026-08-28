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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
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
	 * Object service mock — `summary()` counts through it.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

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
		$this->objectService = $this->createMock(ObjectService::class);

		$this->controller = new TmloController(
			'openregister',
			$this->request,
			$this->tmloService,
			$this->objectService,
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
	/**
	 * `summary()` returns a per-status count, and does so at all.
	 *
	 * THIS ENDPOINT ANSWERED 500 ON EVERY REQUEST. It called
	 * `findAll(register: …, schema: …, filters: …)` — three named arguments
	 * that do not exist on `findAll(array $config, bool $_rbac, bool
	 * $_multitenancy)` — so PHP raised `Error: Unknown named parameter
	 * $register`. `Error` is not an `Exception`, so it escaped all three catch
	 * blocks in the method and surfaced as an uncaught fatal.
	 *
	 * There was no test on `summary()` at all, which is how a method that
	 * cannot succeed even once got shipped and stayed shipped.
	 *
	 * @return void
	 */
	public function testSummaryCountsEachArchiefstatus(): void {
		// REAL entities, not mocks: Nextcloud's `Entity` resolves getId()/
		// setId() through __call, so PHPUnit cannot configure them — it refuses
		// with "method ... does not exist".
		$register = new Register();
		$register->setId(1);
		$register->setSchemas([7]);
		$this->registerMapper->method('find')->willReturn($register);
		$this->tmloService->method('isTmloEnabled')->willReturn(true);

		$schema = new Schema();
		$schema->setId(7);
		$this->schemaMapper->method('findInIds')->willReturn($schema);

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		// One count call per status, four distinct answers.
		$this->objectService->expects($this->exactly(4))
			->method('count')
			->willReturnOnConsecutiveCalls(3, 5, 7, 11);

		$response = $this->controller->summary('zaken', 'zaak');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([3, 5, 7, 11], array_values($response->getData()));
	}//end testSummaryCountsEachArchiefstatus()

	/**
	 * The counts are SCOPED to the requested register and schema.
	 *
	 * `ObjectService::count()` reads the service's current context. Without
	 * `setRegister()`/`setSchema()` first, `MagicMapper::countAll()` sums every
	 * register/schema table on the instance — so all four statuses would report
	 * the same instance-wide total and look plausible while being nonsense.
	 *
	 * @return void
	 */
	public function testSummaryScopesTheCountToTheRegisterAndSchema(): void {
		// REAL entities, not mocks: Nextcloud's `Entity` resolves getId()/
		// setId() through __call, so PHPUnit cannot configure them — it refuses
		// with "method ... does not exist".
		$register = new Register();
		$register->setId(1);
		$register->setSchemas([7]);
		$this->registerMapper->method('find')->willReturn($register);
		$this->tmloService->method('isTmloEnabled')->willReturn(true);

		$schema = new Schema();
		$schema->setId(7);
		$this->schemaMapper->method('findInIds')->willReturn($schema);

		$this->objectService->expects($this->once())
			->method('setRegister')->with($register)->willReturnSelf();
		$this->objectService->expects($this->once())
			->method('setSchema')->with($schema)->willReturnSelf();
		$this->objectService->method('count')->willReturn(0);

		$this->controller->summary('zaken', 'zaak');
	}//end testSummaryScopesTheCountToTheRegisterAndSchema()

	/**
	 * A register without TMLO enabled is refused, not counted.
	 *
	 * @return void
	 */
	public function testSummaryRefusesARegisterWithoutTmlo(): void {
		$register = new Register();
		$register->setId(1);
		$this->registerMapper->method('find')->willReturn($register);
		$this->tmloService->method('isTmloEnabled')->willReturn(false);

		$this->objectService->expects($this->never())->method('count');

		$response = $this->controller->summary('zaken', 'zaak');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testSummaryRefusesARegisterWithoutTmlo()
	/**
	 * `exportSingle()` reaches the XML generator instead of fatalling.
	 *
	 * Same defect class as `summary()`: the call named `identifier:` when the
	 * parameter is `$id`, so PHP raised `Error: Unknown named parameter
	 * $identifier` — not an `Exception`, so it escaped all three catch blocks
	 * and the endpoint answered 500.
	 *
	 * It also handed `find()` the Register and Schema ENTITIES for parameters
	 * typed `string|int|null`, which is a TypeError even once the name is
	 * right. Both are asserted here: the ids reach `find()`, not the objects.
	 *
	 * @return void
	 */
	public function testExportSingleFindsTheObjectByIdAndScopeIds(): void {
		$register = new Register();
		$register->setId(1);
		$register->setSchemas([7]);
		$this->registerMapper->method('find')->willReturn($register);
		$this->tmloService->method('isTmloEnabled')->willReturn(true);

		$schema = new Schema();
		$schema->setId(7);
		$this->schemaMapper->method('findInIds')->willReturn($schema);

		$this->objectService->expects($this->once())
			->method('find')
			->with('uuid-1', [], false, 1, 7)
			->willReturn(new ObjectEntity());

		$this->tmloService->expects($this->once())
			->method('generateMdtoXml')
			->willReturn('<mdto/>');

		$response = $this->controller->exportSingle('zaken', 'zaak', 'uuid-1');

		$this->assertNotSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}//end testExportSingleFindsTheObjectByIdAndScopeIds()

	/**
	 * `exportBatch()` passes ONE config array, with the scope inside `filters`.
	 *
	 * `findAll()` is `findAll(array $config, bool $_rbac, bool $_multitenancy)`.
	 * The previous call passed `register:`/`schema:`/`filters:` as named
	 * arguments that do not exist on it — the third instance of this fatal in
	 * one file.
	 *
	 * The register and schema must travel INSIDE `filters`, because that is
	 * where `prepareFindAllConfig()` looks when it sets the service context. A
	 * config that omits them would search every register on the instance.
	 *
	 * @return void
	 */
	public function testExportBatchScopesTheQueryInsideFilters(): void {
		$register = new Register();
		$register->setId(1);
		$register->setSchemas([7]);
		$this->registerMapper->method('find')->willReturn($register);
		$this->tmloService->method('isTmloEnabled')->willReturn(true);

		$schema = new Schema();
		$schema->setId(7);
		$this->schemaMapper->method('findInIds')->willReturn($schema);

		$captured = null;
		$this->objectService->expects($this->once())
			->method('findAll')
			->willReturnCallback(function (array $config = []) use (&$captured) {
				$captured = $config;
				return [];
			});
		$this->tmloService->method('generateBatchMdtoXml')->willReturn('<mdto/>');

		$this->controller->exportBatch('zaken', 'zaak');

		$this->assertSame(1, $captured['filters']['register'] ?? null, 'register id must scope the query');
		$this->assertSame(7, $captured['filters']['schema'] ?? null, 'schema id must scope the query');
	}//end testExportBatchScopesTheQueryInsideFilters()
}//end class
