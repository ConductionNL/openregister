<?php

/**
 * MergeControllerReverseNotFoundTest
 *
 * A merge reversal naming an id nothing resolves to (an unknown mergeOperation,
 * or an object the caller may not read) answers 404, not an uncaught 500.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/mdm-merge-engine/tasks.md#5.1
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\MergeController;
use OCA\OpenRegister\Controller\Support\MergeControllerServices;
use OCA\OpenRegister\Service\Merge\MergeService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \OCA\OpenRegister\Controller\MergeController
 */
class MergeControllerReverseNotFoundTest extends TestCase {

	/**
	 * The merge service the controller delegates to.
	 *
	 * @var MergeService&MockObject
	 */
	private MergeService&MockObject $mergeService;

	/**
	 * The controller under test.
	 *
	 * @var MergeController
	 */
	private MergeController $controller;

	/**
	 * Build the controller with an anonymous session.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mergeService = $this->createMock(MergeService::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$this->controller = new MergeController(
			$this->createMock(IRequest::class),
			new MergeControllerServices(
				$this->mergeService,
				$userSession
			)
		);
	}//end setUp()

	/**
	 * An id that resolves to nothing is a 404 carrying the lookup's message.
	 *
	 * @return void
	 */
	public function testReverseMapsDoesNotExistToNotFound(): void {
		$this->mergeService->method('reverseMerge')->willThrowException(
			new DoesNotExistException('Object with identifier \'op-uuid\' not found in any magic table')
		);

		$response = $this->controller->reverse('op-uuid');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(
			['error' => 'Object with identifier \'op-uuid\' not found in any magic table'],
			$response->getData()
		);
	}//end testReverseMapsDoesNotExistToNotFound()

	/**
	 * A resolvable id still returns the service's result unchanged.
	 *
	 * @return void
	 */
	public function testReverseReturnsTheServiceResultWhenFound(): void {
		$this->mergeService->expects($this->once())
			->method('reverseMerge')
			->with('op-uuid', '')
			->willReturn(['id' => 'op-uuid', 'reversible' => false]);

		$response = $this->controller->reverse('op-uuid');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'op-uuid', 'reversible' => false], $response->getData());
	}//end testReverseReturnsTheServiceResultWhenFound()
}//end class
