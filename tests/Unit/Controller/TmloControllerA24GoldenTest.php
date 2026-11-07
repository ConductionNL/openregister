<?php

/**
 * Golden outcomes of the TMLO export gate, register scope and error mapping.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Controller\TmloController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Export\ExportGate;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\TmloService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\TmloControllerFactory;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins allowed and refused outcomes (status, body, headers) of all three TMLO actions.
 */
class TmloControllerA24GoldenTest extends TestCase {
	private IRequest&MockObject $request;
	private TmloService&MockObject $tmlo;
	private ObjectService&MockObject $objects;
	private RegisterMapper&MockObject $registers;
	private SchemaMapper&MockObject $schemas;
	private LoggerInterface&MockObject $logger;
	private ExportGate&MockObject $gate;
	private TmloController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->tmlo = $this->createMock(TmloService::class);
		$this->objects = $this->createMock(ObjectService::class);
		$this->registers = $this->createMock(RegisterMapper::class);
		$this->schemas = $this->createMock(SchemaMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->gate = $this->createMock(ExportGate::class);
		$this->controller = TmloControllerFactory::create(
			'openregister',
			$this->request,
			$this->tmlo,
			$this->objects,
			$this->registers,
			$this->schemas,
			$this->logger,
			$this->gate
		);
	}

	private function scope(): array {
		$register = new Register();
		$register->setId(1);
		$register->setSlug('zaken');
		$register->setSchemas([7]);
		$schema = new Schema();
		$schema->setId(7);
		$this->registers->method('find')->with('zaken')->willReturn($register);
		$this->schemas->method('findInIds')->willReturn($schema);
		return [$register, $schema];
	}

	private function refusal(): JSONResponse {
		return new JSONResponse(['error' => 'EXPORT_REFUSED'], 403);
	}

	public function testARefusedSingleExportReadsNoObject(): void {
		[$register, $schema] = $this->scope();
		$refusal = $this->refusal();
		$this->gate->expects($this->once())->method('refusalFor')->with($schema, 'tmlo-single', 1)->willReturn($refusal);
		$this->objects->expects($this->never())->method('find');
		$this->tmlo->expects($this->never())->method('generateMdtoXml');

		$this->assertSame($refusal, $this->controller->exportSingle('zaken', 'zaak', 'u1'));
	}

	public function testARefusedBatchExportReadsNoParams(): void {
		[$register, $schema] = $this->scope();
		$refusal = $this->refusal();
		$this->gate->expects($this->once())->method('refusalFor')->with($schema, 'tmlo-batch', 1)->willReturn($refusal);
		$this->request->expects($this->never())->method('getParams');

		$this->assertSame($refusal, $this->controller->exportBatch('zaken', 'zaak'));
	}

	public function testAnAllowedSingleExportAnswersXml(): void {
		$this->scope();
		$this->gate->method('refusalFor')->willReturn(null);
		$object = new ObjectEntity();
		$this->objects->method('find')->willReturn($object);
		$this->tmlo->method('generateMdtoXml')->with($object)->willReturn('<mdto/>');

		$response = $this->controller->exportSingle('zaken', 'zaak', 'u1');

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertSame([200, '<mdto/>'], [$response->getStatus(), $response->getData()]);
		$this->assertSame('application/xml; charset=UTF-8', $response->getHeaders()['Content-Type']);
	}

	public function testAnAllowedBatchExportFiltersOnTmloAndUnderscoreParams(): void {
		$this->scope();
		$this->gate->method('refusalFor')->willReturn(null);
		$this->request->method('getParams')->willReturn(['tmlo.archiefstatus' => 'actief', '_limit' => 5, 'other' => 'x']);
		$this->objects->expects($this->once())->method('findAll')
			->with(['filters' => ['tmlo.archiefstatus' => 'actief', '_limit' => 5, 'register' => 1, 'schema' => 7]])
			->willReturn(['results' => ['a']]);
		$this->tmlo->method('generateBatchMdtoXml')->with(['a'])->willReturn('<batch/>');

		$response = $this->controller->exportBatch('zaken', 'zaak');

		$this->assertSame([200, '<batch/>'], [$response->getStatus(), $response->getData()]);
		$this->assertSame('application/xml; charset=UTF-8', $response->getHeaders()['Content-Type']);
	}

	public function testABatchResultWithoutResultsKeyIsExportedWhole(): void {
		$this->scope();
		$this->gate->method('refusalFor')->willReturn(null);
		$this->request->method('getParams')->willReturn([]);
		$this->objects->method('findAll')->willReturn(['x', 'y']);
		$this->tmlo->expects($this->once())->method('generateBatchMdtoXml')->with(['x', 'y'])->willReturn('');

		$this->controller->exportBatch('zaken', 'zaak');
	}

	public function testASchemaOutsideTheRegisterIsA404WithItsDiagnosis(): void {
		$register = new Register();
		$register->setId(1);
		$register->setSchemas([7]);
		$this->registers->method('find')->willReturn($register);
		$this->schemas->method('findInIds')->willReturn(null);
		$this->schemas->method('countBySlug')->willReturn(2);
		$this->gate->expects($this->never())->method('refusalFor');
		$this->tmlo->method('isTmloEnabled')->willReturn(true);

		foreach (['single', 'batch', 'summary'] as $kind) {
			$response = match ($kind) {
				'single' => $this->controller->exportSingle('zaken', 'zaak', 'u1'),
				'batch' => $this->controller->exportBatch('zaken', 'zaak'),
				'summary' => $this->controller->summary('zaken', 'zaak'),
			};
			$this->assertSame(404, $response->getStatus());
			$this->assertNotSame('Register or schema not found', $response->getData()['error']);
		}
	}

	public function testSingleExportMapsInvalidArgumentAndFailure(): void {
		$this->scope();
		$this->gate->method('refusalFor')->willReturn(null);
		$this->objects->method('find')->willReturnOnConsecutiveCalls(
			$this->throwException(new InvalidArgumentException('bad id')),
			$this->throwException(new Exception('db'))
		);
		$this->logger->expects($this->once())->method('error')->with('MDTO export failed: db', $this->arrayHasKey('exception'));

		$invalid = $this->controller->exportSingle('zaken', 'zaak', 'u1');
		$failed = $this->controller->exportSingle('zaken', 'zaak', 'u1');

		$this->assertSame([422, ['error' => 'bad id']], [$invalid->getStatus(), $invalid->getData()]);
		$this->assertSame([500, ['error' => 'MDTO export failed: db']], [$failed->getStatus(), $failed->getData()]);
	}

	public function testBatchExportMapsFailureAndLetsInvalidArgumentBe500(): void {
		$this->scope();
		$this->gate->method('refusalFor')->willReturn(null);
		$this->request->method('getParams')->willReturn([]);
		$this->objects->method('findAll')->willThrowException(new InvalidArgumentException('bad'));
		$this->logger->expects($this->once())->method('error')->with('MDTO batch export failed: bad', $this->arrayHasKey('exception'));

		$response = $this->controller->exportBatch('zaken', 'zaak');

		$this->assertSame([500, ['error' => 'MDTO batch export failed: bad']], [$response->getStatus(), $response->getData()]);
	}

	public function testSummaryRefusesWithoutTmloBeforeResolvingTheSchema(): void {
		$register = new Register();
		$this->registers->method('find')->willReturn($register);
		$this->tmlo->method('isTmloEnabled')->with($register)->willReturn(false);
		$this->schemas->expects($this->never())->method('findInIds');

		$response = $this->controller->summary('zaken', 'zaak');

		$this->assertSame([400, ['error' => 'TMLO is not enabled on this register']], [$response->getStatus(), $response->getData()]);
	}

	public function testSummaryCountsPerStatusInOrderAndMapsFailures(): void {
		$this->scope();
		$this->tmlo->method('isTmloEnabled')->willReturn(true);
		$seen = [];
		$this->objects->method('count')->willReturnCallback(
			static function (array $config) use (&$seen): int {
				$seen[] = $config['filters']['tmlo.archiefstatus'];
				if (count($seen) > 4) {
					throw new Exception('count');
				}
				return count($seen);
			}
		);
		$this->logger->expects($this->once())->method('error')->with('TMLO summary failed: count', $this->arrayHasKey('exception'));

		$ok = $this->controller->summary('zaken', 'zaak');
		$failed = $this->controller->summary('zaken', 'zaak');

		$this->assertSame(
			[
				TmloService::ARCHIEFSTATUS_ACTIEF => 1,
				TmloService::ARCHIEFSTATUS_SEMI_STATISCH => 2,
				TmloService::ARCHIEFSTATUS_OVERGEBRACHT => 3,
				TmloService::ARCHIEFSTATUS_VERNIETIGD => 4,
			],
			$ok->getData()
		);
		$this->assertSame([500, ['error' => 'TMLO summary failed: count']], [$failed->getStatus(), $failed->getData()]);
	}

	public function testSummaryOfAnUnknownRegisterIs404(): void {
		$this->registers->method('find')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('x'));

		$response = $this->controller->summary('zaken', 'zaak');

		$this->assertSame([404, ['error' => 'Register or schema not found']], [$response->getStatus(), $response->getData()]);
	}
}
