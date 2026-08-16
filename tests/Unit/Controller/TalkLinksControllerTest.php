<?php

/**
 * Contract tests for {@see \OCA\OpenRegister\Controller\TalkLinksController}.
 *
 * Covers the Talk room picker source: the `{results,total}` envelope, the
 * optional `search` filter forwarded to the service, and the graceful 501
 * returned when the Nextcloud Talk app is not installed (the leaf degrades,
 * it never fatals).
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
 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\TalkLinksController;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\TalkLinkService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * TalkLinksControllerTest.
 */
class TalkLinksControllerTest extends TestCase {

	/**
	 * HTTP request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Talk link service mock.
	 *
	 * @var TalkLinkService&MockObject
	 */
	private TalkLinkService&MockObject $service;

	/**
	 * Controller under test.
	 *
	 * @var TalkLinksController
	 */
	private TalkLinksController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(TalkLinkService::class);

		$this->controller = new TalkLinksController(
			'openregister',
			$this->request,
			$this->service,
			$this->createMock(ObjectService::class)
		);
	}//end setUp()

	public function testRoomsReturnsTheRoomsTheCallerParticipatesIn(): void {
		$this->service->method('isTalkAvailable')->willReturn(true);
		$this->request->method('getParam')->willReturn(null);

		$this->service->expects($this->once())
			->method('getAvailableRoomsForUser')
			->with(null)
			->willReturn(
				[
					['token' => 'abc123', 'displayName' => 'Team'],
					['token' => 'def456', 'displayName' => 'Zaak 42'],
				]
			);

		$response = $this->controller->rooms();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(2, $response->getData()['total']);
		$this->assertSame('abc123', $response->getData()['results'][0]['token']);
	}//end testRoomsReturnsTheRoomsTheCallerParticipatesIn()

	public function testRoomsForwardsTheSearchFilterToTheService(): void {
		$this->service->method('isTalkAvailable')->willReturn(true);
		$this->request->method('getParam')->willReturn('zaak');

		$this->service->expects($this->once())
			->method('getAvailableRoomsForUser')
			->with('zaak')
			->willReturn([['token' => 'def456', 'displayName' => 'Zaak 42']]);

		$response = $this->controller->rooms();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(1, $response->getData()['total']);
	}//end testRoomsForwardsTheSearchFilterToTheService()

	public function testRoomsReturns501WhenTalkIsNotInstalled(): void {
		$this->service->method('isTalkAvailable')->willReturn(false);
		$this->service->expects($this->never())->method('getAvailableRoomsForUser');

		$response = $this->controller->rooms();

		$this->assertSame(501, $response->getStatus());
		$this->assertSame('APP_NOT_AVAILABLE', $response->getData()['code']);
	}//end testRoomsReturns501WhenTalkIsNotInstalled()
}//end class
