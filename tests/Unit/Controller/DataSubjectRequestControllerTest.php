<?php

/**
 * Contract tests for {@see \OCA\OpenRegister\Controller\DataSubjectRequestController}.
 *
 * Covers the four consumable data-subject-rights endpoints backed by
 * {@see \OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService}: subject
 * discovery (art-15/20), the portable access export, the processing
 * restriction (art-18) and the objection (art-21) — including the 422 for a
 * missing `subject`, the 400 for a missing `object`, and the 404 the service
 * signals with a null result (absent, unauthorised or immutable).
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
 * @spec openspec/changes/gdpr-data-subject-rights/tasks.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\DataSubjectRequestController;
use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * DataSubjectRequestControllerTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class DataSubjectRequestControllerTest extends TestCase {

	/**
	 * HTTP request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Consumable DSR service mock.
	 *
	 * @var DataSubjectRequestService&MockObject
	 */
	private DataSubjectRequestService&MockObject $service;

	/**
	 * Controller under test.
	 *
	 * @var DataSubjectRequestController
	 */
	private DataSubjectRequestController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(DataSubjectRequestService::class);

		$this->controller = new DataSubjectRequestController(
			'openregister',
			$this->request,
			$this->service
		);
	}//end setUp()

	/**
	 * Stub the request params from a simple map.
	 *
	 * @param array<string,mixed> $params The params the request should answer.
	 *
	 * @return void
	 */
	private function withParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				return ($params[$key] ?? $default);
			}
		);
	}//end withParams()

	public function testSubjectDataReturnsTheDiscoveredRowsWithACount(): void {
		$this->withParams(['subject' => 'bsn:123', 'type' => 'person', 'mode' => 'ilike']);

		$this->service->expects($this->once())
			->method('findSubjectData')
			->with('bsn:123', 'person', 'ilike')
			->willReturn([['uuid' => 'a'], ['uuid' => 'b']]);

		$response = $this->controller->subjectData();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('bsn:123', $data['subject']);
		$this->assertSame(2, $data['count']);
		$this->assertCount(2, $data['results']);
	}//end testSubjectDataReturnsTheDiscoveredRowsWithACount()

	public function testSubjectDataRejectsAMissingSubjectWith422(): void {
		$this->withParams([]);
		$this->service->expects($this->never())->method('findSubjectData');

		$response = $this->controller->subjectData();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame('Missing required parameter: subject', $response->getData()['error']);
	}//end testSubjectDataRejectsAMissingSubjectWith422()

	public function testAccessExportReturnsTheAssembledBundle(): void {
		$this->withParams(['subject' => 'bsn:123']);

		$this->service->expects($this->once())
			->method('assembleAccessExport')
			->with('bsn:123', null)
			->willReturn(['subject' => 'bsn:123', 'objects' => [['uuid' => 'a']], 'generatedAt' => '2026-08-16']);

		$response = $this->controller->accessExport();

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('bsn:123', $data['subject']);
		$this->assertCount(1, $data['objects']);
	}//end testAccessExportReturnsTheAssembledBundle()

	public function testAccessExportRejectsAMissingSubjectWith422(): void {
		$this->withParams([]);
		$this->service->expects($this->never())->method('assembleAccessExport');

		$response = $this->controller->accessExport();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}//end testAccessExportRejectsAMissingSubjectWith422()

	public function testRestrictForwardsTheFlagAndReasonAndReturnsTheResult(): void {
		$this->withParams(['object' => 'uuid-1', 'restricted' => 'true', 'reason' => 'dispute']);

		$this->service->expects($this->once())
			->method('setRestriction')
			->with('uuid-1', true, 'dispute')
			->willReturn(['object' => 'uuid-1', 'restricted' => true]);

		$response = $this->controller->restrict();

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['restricted']);
	}//end testRestrictForwardsTheFlagAndReasonAndReturnsTheResult()

	public function testRestrictRejectsAMissingObjectWith400(): void {
		$this->withParams([]);
		$this->service->expects($this->never())->method('setRestriction');

		$response = $this->controller->restrict();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('object is required', $response->getData()['error']);
	}//end testRestrictRejectsAMissingObjectWith400()

	public function testRestrictReturns404WhenTheServiceRefusesTheObject(): void {
		$this->withParams(['object' => 'uuid-nope', 'restricted' => 'true']);
		$this->service->method('setRestriction')->willReturn(null);

		$response = $this->controller->restrict();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testRestrictReturns404WhenTheServiceRefusesTheObject()

	public function testObjectionForwardsTheFlagAndReasonAndReturnsTheResult(): void {
		$this->withParams(['object' => 'uuid-1', 'objected' => 'true', 'reason' => 'direct marketing']);

		$this->service->expects($this->once())
			->method('setObjection')
			->with('uuid-1', true, 'direct marketing')
			->willReturn(['object' => 'uuid-1', 'objected' => true]);

		$response = $this->controller->objection();

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['objected']);
	}//end testObjectionForwardsTheFlagAndReasonAndReturnsTheResult()

	public function testObjectionReturns404WhenTheServiceRefusesTheObject(): void {
		$this->withParams(['object' => 'uuid-nope']);
		$this->service->method('setObjection')->willReturn(null);

		$response = $this->controller->objection();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertStringContainsString('not authorised', $response->getData()['error']);
	}//end testObjectionReturns404WhenTheServiceRefusesTheObject()
}//end class
