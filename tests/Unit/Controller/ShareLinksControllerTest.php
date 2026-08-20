<?php

/**
 * Contract tests for {@see \OCA\OpenRegister\Controller\ShareLinksController}.
 *
 * Covers the shareable-files listing: the `{results,total}` envelope built from
 * the object's resolved UUID, the 404 when the register/schema/id tuple does
 * not resolve, and the service-exception → HTTP-status mapping.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/integration-shares/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\ShareLinksController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ShareLinkService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * ShareLinksControllerTest.
 */
class ShareLinksControllerTest extends TestCase {

	/**
	 * HTTP request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Share link service mock.
	 *
	 * @var ShareLinkService&MockObject
	 */
	private ShareLinkService&MockObject $service;

	/**
	 * Object resolver mock.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * Controller under test.
	 *
	 * @var ShareLinksController
	 */
	private ShareLinksController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(ShareLinkService::class);
		$this->objectService = $this->createMock(ObjectService::class);

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('setObject')->willReturnSelf();

		$this->controller = new ShareLinksController(
			'openregister',
			$this->request,
			$this->service,
			$this->objectService
		);
	}//end setUp()

	/**
	 * Make the object resolver answer with an object carrying the given uuid.
	 *
	 * @param string $uuid The resolved object's uuid.
	 *
	 * @return void
	 */
	private function resolvesTo(string $uuid): void {
		$object = $this->createMock(ObjectEntity::class);
		$object->method('getUuid')->willReturn($uuid);
		$this->objectService->method('getObject')->willReturn($object);
	}//end resolvesTo()

	public function testFilesReturnsTheShareableFilesForTheResolvedObject(): void {
		$this->resolvesTo('uuid-1');

		$this->service->expects($this->once())
			->method('getShareableFiles')
			->with('uuid-1')
			->willReturn(
				[
					['fileId' => 10, 'name' => 'besluit.pdf'],
					['fileId' => 11, 'name' => 'bijlage.docx'],
				]
			);

		$response = $this->controller->files('zaken', 'zaak', 'uuid-1');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(2, $response->getData()['total']);
		$this->assertSame('besluit.pdf', $response->getData()['results'][0]['name']);
	}//end testFilesReturnsTheShareableFilesForTheResolvedObject()

	public function testFilesReturns404WhenTheObjectDoesNotResolve(): void {
		$this->objectService->method('getObject')->willReturn(null);
		$this->service->expects($this->never())->method('getShareableFiles');

		$response = $this->controller->files('zaken', 'zaak', 'nope');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame('Object not found', $response->getData()['error']);
	}//end testFilesReturns404WhenTheObjectDoesNotResolve()

	public function testFilesMapsAServiceExceptionCodeToTheHttpStatus(): void {
		$this->resolvesTo('uuid-1');
		$this->service->method('getShareableFiles')
			->willThrowException(new Exception('Folder unavailable', 503));

		$response = $this->controller->files('zaken', 'zaak', 'uuid-1');

		$this->assertSame(503, $response->getStatus());
		$this->assertSame('Folder unavailable', $response->getData()['error']);
	}//end testFilesMapsAServiceExceptionCodeToTheHttpStatus()
}//end class
