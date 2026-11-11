<?php

/**
 * ReportsController maps load and render outcomes onto HTTP responses.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\OfficeServerRequiredException;
use OCA\OpenRegister\Search\Controller\ReportsController;
use OCA\OpenRegister\Search\Controller\Support\ReportsControllerServices;
use OCA\OpenRegister\Search\Service\Reporting\ReportRenderService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Behaviour of the on-demand render and preview endpoints.
 */
class ReportsControllerTest extends TestCase {

	private IRequest&MockObject $request;
	private ReportRenderService&MockObject $renderService;
	private MagicObjectFinderInterface&MockObject $objectMapper;
	private LoggerInterface&MockObject $logger;
	private ReportsController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->renderService = $this->createMock(ReportRenderService::class);
		$this->objectMapper = $this->createMock(MagicObjectFinderInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->controller = new ReportsController(
			$this->request,
			new ReportsControllerServices($this->renderService, $this->objectMapper, $this->logger)
		);
	}

	private function rendered(): array {
		return ['bytes' => 'BYTES', 'filename' => 'woo.xlsx', 'mime' => 'application/vnd.ms-excel'];
	}

	public function testRenderLoadsNumericIdAsIntAndLowercasesTheFormat(): void {
		$dashboard = new ObjectEntity();
		$this->request->method('getParam')->with('format', 'xlsx')->willReturn('PDF');
		$this->objectMapper->expects($this->once())->method('find')->with($this->identicalTo(42))->willReturn($dashboard);
		$this->renderService->expects($this->once())->method('render')->with($dashboard, 'pdf')->willReturn($this->rendered());

		$response = $this->controller->render(' 42 ');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame('BYTES', $response->render());
		$this->assertSame('application/vnd.ms-excel', $response->getHeaders()['Content-Type']);
		$this->assertStringStartsWith('attachment;', $response->getHeaders()['Content-Disposition']);
		$this->assertStringContainsString('woo.xlsx', $response->getHeaders()['Content-Disposition']);
	}

	public function testRenderLoadsAUuidAsAString(): void {
		$this->request->method('getParam')->willReturn('xlsx');
		$this->objectMapper->expects($this->once())->method('find')->with($this->identicalTo('5f0c-uuid'))->willReturn(new ObjectEntity());
		$this->renderService->method('render')->willReturn($this->rendered());

		$this->assertInstanceOf(DataDownloadResponse::class, $this->controller->render('5f0c-uuid'));
	}

	public function testRenderOfAnUnknownDashboardIs404(): void {
		$this->request->method('getParam')->willReturn('xlsx');
		$this->objectMapper->method('find')->willThrowException(new DoesNotExistException('x'));
		$this->renderService->expects($this->never())->method('render');

		$response = $this->controller->render('missing');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Dashboard not found', 'identifier' => 'missing'], $response->getData());
	}

	public function testRenderLoadFailureIsAGeneric500AndLogsTheDetail(): void {
		$this->request->method('getParam')->willReturn('xlsx');
		$this->objectMapper->method('find')->willThrowException(new RuntimeException('SQLSTATE secret'));
		$this->logger->expects($this->once())->method('error')
			->with('[ReportsController] Failed to load dashboard', ['identifier' => 'd', 'error' => 'SQLSTATE secret']);

		$response = $this->controller->render('d');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Failed to load dashboard'], $response->getData());
	}

	public function testInvalidFormatIs422WithTheValidationMessage(): void {
		$this->request->method('getParam')->willReturn('docx');
		$this->objectMapper->method('find')->willReturn(new ObjectEntity());
		$this->renderService->method('render')->willThrowException(new InvalidArgumentException('Unsupported render format "docx"'));

		$response = $this->controller->render('1');

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(['error' => 'Unsupported render format "docx"'], $response->getData());
	}

	public function testMissingOfficeServerIs501WithItsErrorCode(): void {
		$this->request->method('getParam')->willReturn('pdf');
		$this->objectMapper->method('find')->willReturn(new ObjectEntity());
		$exception = new OfficeServerRequiredException();
		$this->renderService->method('render')->willThrowException($exception);

		$response = $this->controller->render('1');

		$this->assertSame(501, $response->getStatus());
		$this->assertSame($exception->toResponseData(), $response->getData());
	}

	public function testOtherRenderFailureIsAGeneric500AndLogsTheDetail(): void {
		$this->request->method('getParam')->willReturn('csv');
		$this->objectMapper->method('find')->willReturn(new ObjectEntity());
		$this->renderService->method('render')->willThrowException(new RuntimeException('/var/www/secret'));
		$this->logger->expects($this->once())->method('error')
			->with('[ReportsController] Render failed', ['identifier' => '7', 'format' => 'csv', 'error' => '/var/www/secret']);

		$response = $this->controller->render('7');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Render failed; see server logs for details'], $response->getData());
	}

	public function testPreviewRendersHtmlInline(): void {
		$dashboard = new ObjectEntity();
		$this->objectMapper->expects($this->once())->method('find')->with($this->identicalTo(3))->willReturn($dashboard);
		$this->renderService->expects($this->once())->method('render')->with($dashboard, 'html')
			->willReturn(['bytes' => '<p>hi</p>', 'filename' => 'woo.html', 'mime' => 'text/html']);

		$response = $this->controller->preview('3');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame('<p>hi</p>', $response->render());
		$this->assertSame('inline; filename="woo.html"', $response->getHeaders()['Content-Disposition']);
		$this->assertSame('text/html', $response->getHeaders()['Content-Type']);
	}

	public function testPreviewOfAnUnknownDashboardIs404(): void {
		$this->objectMapper->method('find')->willThrowException(new DoesNotExistException('x'));
		$this->renderService->expects($this->never())->method('render');

		$response = $this->controller->preview('slug');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Dashboard not found', 'identifier' => 'slug'], $response->getData());
	}
}
