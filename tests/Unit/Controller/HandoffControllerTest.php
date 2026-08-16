<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Controller\HandoffController}.
 *
 * Covers the REST error contract: availability pass-through, 202 for parked
 * results, and the typed error mapping — 404 handoff-not-declared, 409
 * handoff-provider-unavailable (never a 5xx), 403 on RBAC refusal, 400 on
 * validation failure.
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
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\HandoffController;
use OCA\OpenRegister\Exception\HandoffException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Handoff\HandoffService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * HandoffControllerTest.
 */
class HandoffControllerTest extends TestCase {

	private HandoffService&MockObject $handoffService;

	private HandoffController $controller;

	protected function setUp(): void {
		$this->handoffService = $this->createMock(HandoffService::class);
		$this->controller = new HandoffController(
			appName: 'openregister',
			request: $this->createMock(IRequest::class),
			handoffService: $this->handoffService,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end setUp()

	/**
	 * Availability passes the service result through under `handoffs`.
	 *
	 * @return void
	 */
	public function testAvailabilityPassesServiceResultThrough(): void {
		$availability = [
			[
				'id' => 'to-case',
				'state' => 'available',
			],
		];
		$this->handoffService->method('listAvailability')->willReturn($availability);

		$response = $this->controller->availability(register: 'r', schema: 's', id: 'o');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['handoffs' => $availability], $response->getData());

	}//end testAvailabilityPassesServiceResultThrough()

	/**
	 * Executed → 200 with the service result; parked → 202.
	 *
	 * @return void
	 */
	public function testExecuteStatusCodes(): void {
		$this->handoffService->method('execute')->willReturn(['status' => 'executed']);
		$response = $this->controller->execute(register: 'r', schema: 's', id: 'o', handoffId: 'h');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		$this->setUp();
		$this->handoffService->method('execute')->willReturn(['status' => 'parked']);
		$response = $this->controller->execute(register: 'r', schema: 's', id: 'o', handoffId: 'h');
		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());

	}//end testExecuteStatusCodes()

	/**
	 * Typed error mapping: not-declared → 404, provider-unavailable → 409
	 * (never 5xx), RBAC refusal → 403, validation → 400.
	 *
	 * @return void
	 */
	public function testExecuteTypedErrorMapping(): void {
		$cases = [
			[
				new HandoffException(errorCode: HandoffException::NOT_DECLARED, message: 'nope'),
				Http::STATUS_NOT_FOUND,
				HandoffException::NOT_DECLARED,
			],
			[
				new HandoffException(errorCode: HandoffException::PROVIDER_UNAVAILABLE, message: 'absent'),
				Http::STATUS_CONFLICT,
				HandoffException::PROVIDER_UNAVAILABLE,
			],
			[
				new NotAuthorizedException(message: 'denied'),
				Http::STATUS_FORBIDDEN,
				'forbidden',
			],
			[
				new ValidationException(message: 'bad payload'),
				Http::STATUS_BAD_REQUEST,
				'validation',
			],
		];

		foreach ($cases as [$exception, $expectedStatus, $expectedError]) {
			$this->setUp();
			$this->handoffService->method('execute')->willThrowException($exception);

			$response = $this->controller->execute(register: 'r', schema: 's', id: 'o', handoffId: 'h');

			$this->assertSame($expectedStatus, $response->getStatus());
			$this->assertSame($expectedError, $response->getData()['error']);
		}

	}//end testExecuteTypedErrorMapping()
}//end class
